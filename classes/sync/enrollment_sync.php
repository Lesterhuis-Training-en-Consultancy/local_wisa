<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Enrolment synchronisation from a global WISA enrolment feed.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GNU GPL v3 or later
 */

namespace local_wisa\sync;

defined('MOODLE_INTERNAL') || die();

use local_wisa\api_client;
use local_wisa\logger;
use local_wisa\sync_stats;

/**
 * Consumes one global enrolment feed (default MCVOD_INS) instead of one call
 * per course. Each row carries KLAS_ID (course idnumber), USERNAME (user key)
 * and ROL (student/teacher). Users are enrolled via the manual enrol plugin;
 * a previously suspended enrolment is reactivated. Course, user and enrol-instance
 * lookups are cached per run to keep the first full load within a sane query count.
 */
class enrollment_sync {
    private $api;
    private $student_role_id;
    private $teacher_role_id;
    private $enrol_plugin;
    private $dryrun;
    private $stats;
    /** @var array|null [van_ts, tot_ts] schooljaarvenster, of null = geen filter. */
    private $window;
    /** @var bool Verwerk leraar- resp. cursist-inschrijvingen (apart schakelbaar). */
    private $do_teachers;
    private $do_students;
    /** @var array Run-caches to avoid repeated DB lookups for the same key. */
    private $coursecache = [];
    private $usercache = [];
    private $instancecache = [];

    public function __construct(api_client $api, bool $dryrun = false, ?sync_stats $stats = null,
            ?array $window = null, bool $do_teachers = true, bool $do_students = true) {
        $this->api = $api;
        $this->dryrun = $dryrun;
        $this->stats = $stats ?: new sync_stats();
        $this->student_role_id = get_config('local_wisa', 'student_role');
        $this->teacher_role_id = get_config('local_wisa', 'teacher_role');
        $this->enrol_plugin = enrol_get_plugin('manual');
        $this->window = $window;
        $this->do_teachers = $do_teachers;
        $this->do_students = $do_students;
    }

    /** @return bool False when the WISA fetch failed (so the watermark must not advance). */
    public function run() {
        $rows = $this->api->get_enrolments();
        if ($rows === false || !is_array($rows)) {
            logger::log('sync_enrol', 'system', 'api', 'fail', 'Failed to fetch enrolments from WISA.');
            return false;
        }

        logger::log('sync_enrol', 'system', 'count', 'info', 'Fetched ' . count($rows) . ' enrolment rows from WISA.');

        foreach ($rows as $row) {
            try {
                $this->process_enrolment((array)$row);
            } catch (\Throwable $e) {
                $key = (is_array($row) ? ($row['KLAS_ID'] ?? '?') : '?') . '/'
                     . (is_array($row) ? ($row['USERNAME'] ?? '?') : '?');
                logger::log('sync_enrol', 'enrollment', $key, 'fail', "Process error: " . $e->getMessage());
                $this->stats->enrol_fail++;
            }
        }
        return true;
    }

    private function process_enrolment($row) {
        $klas_id = trim($row['KLAS_ID'] ?? '');
        $idnumber = trim($row['USERNAME'] ?? '');
        $rol = \core_text::strtolower(trim($row['ROL'] ?? 'student'));
        $isteacher = ($rol === 'teacher' || $rol === 'leraar' || $rol === 'lkr');

        if ($klas_id === '' || $idnumber === '') {
            logger::log('sync_enrol', 'enrollment', "$klas_id/$idnumber", 'warn', 'Empty KLAS_ID or USERNAME, skipped.');
            $this->stats->enrol_warn++;
            return;
        }

        // Rol-filter: leraar- en cursist-inschrijvingen zijn apart in/uit te schakelen.
        if (($isteacher && !$this->do_teachers) || (!$isteacher && !$this->do_students)) {
            $this->stats->enrol_skip++;
            return;
        }

        // Schooljaarvenster: sla inschrijvingen over die volledig buiten het venster vallen.
        if ($this->window !== null) {
            $van = $this->to_ts($row['VAN'] ?? '');
            $tot = $this->to_ts($row['TOT'] ?? '');
            if (($tot && $tot < $this->window[0]) || ($van && $van > $this->window[1])) {
                $this->stats->enrol_skip++;
                return;
            }
        }

        $course = $this->get_course($klas_id);
        if (!$course) {
            // Cursus niet in Moodle (buiten schooljaar-scope of cursus-sync uit) -> stil overslaan.
            $this->stats->enrol_skip++;
            return;
        }

        $user = $this->get_user($idnumber);
        if (!$user) {
            logger::log('sync_enrol', 'enrollment', $idnumber, 'warn',
                "User $idnumber not found for course {$course->shortname}.");
            $this->stats->enrol_warn++;
            return;
        }

        $roleid = $isteacher ? $this->teacher_role_id : $this->student_role_id;

        $this->enrol_user($course, $user, $roleid);
    }

    private function enrol_user($course, $user, $roleid) {
        $context = \context_course::instance($course->id);

        // Already actively enrolled: nothing to do.
        if (is_enrolled($context, $user->id, '', true)) {
            return;
        }

        try {
            if ($this->dryrun) {
                logger::log('sync_enrol', 'enrollment', $user->username, 'dryrun',
                    "Would enrol/reactivate in {$course->shortname}.");
                $this->stats->enrol_create++;
                return;
            }

            $instance = $this->get_manual_instance($course);
            if (!$instance) {
                logger::log('sync_enrol', 'enrollment', $user->username, 'fail',
                    "Could not obtain a manual enrol instance for {$course->shortname}.");
                $this->stats->enrol_fail++;
                return;
            }

            if (is_enrolled($context, $user->id)) {
                // Enrolled but not active (suspended) -> reactivate.
                $this->enrol_plugin->update_user_enrol($instance, $user->id, ENROL_USER_ACTIVE);
                logger::log('sync_enrol', 'enrollment', $user->username, 'update', "Reactivated in {$course->shortname}.");
                $this->stats->enrol_update++;
            } else {
                $this->enrol_plugin->enrol_user($instance, $user->id, $roleid);
                logger::log('sync_enrol', 'enrollment', $user->username, 'create', "Enrolled in {$course->shortname}.");
                $this->stats->enrol_create++;
            }
        } catch (\Exception $e) {
            logger::log('sync_enrol', 'enrollment', $user->username, 'fail', "Failed to enrol: " . $e->getMessage());
            $this->stats->enrol_fail++;
        }
    }

    /** Course lookup by idnumber (KLAS_ID), cached per run (false = not found). */
    private function get_course($klas_id) {
        global $DB;
        if (!array_key_exists($klas_id, $this->coursecache)) {
            $this->coursecache[$klas_id] = $DB->get_record('course', ['idnumber' => $klas_id]) ?: false;
        }
        return $this->coursecache[$klas_id];
    }

    /** User lookup by idnumber then lowercased username, cached per run (false = not found). */
    private function get_user($idnumber) {
        global $DB;
        if (!array_key_exists($idnumber, $this->usercache)) {
            $user = $DB->get_record('user', ['idnumber' => $idnumber, 'deleted' => 0]);
            if (!$user) {
                $username = \core_text::strtolower($idnumber);
                $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
            }
            $this->usercache[$idnumber] = $user ?: false;
        }
        return $this->usercache[$idnumber];
    }

    /** Manual enrol instance for a course, created if missing, cached per run. */
    private function get_manual_instance($course) {
        global $DB;
        if (!array_key_exists($course->id, $this->instancecache)) {
            $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
            if (!$instance) {
                $instanceid = $this->enrol_plugin->add_instance($course);
                $instance = $instanceid ? $DB->get_record('enrol', ['id' => $instanceid]) : false;
            }
            $this->instancecache[$course->id] = $instance ?: false;
        }
        return $this->instancecache[$course->id];
    }

    /** Parse a WISA date to a unix timestamp; '' or an unparseable value yields 0. */
    private function to_ts($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return 0;
        }
        $ts = strtotime($value);
        return $ts !== false ? $ts : 0;
    }
}
