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
 * Unenrolment synchronisation from a SIS source.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\sync;

use local_wisa\logger;
use local_wisa\sync_stats;

/**
 * Suspends leavers reported by a SIS source, with a safety valve.
 */
class unenrollment_sync {
    /** @var \enrol_manual_plugin Manual enrol plugin. */
    private $enrolplugin;

    /** @var bool Whether to avoid database mutations. */
    private $dryrun;

    /** @var sync_stats Per-run statistics. */
    private $stats;

    /** @var array Course lookup cache. */
    private $coursecache = [];

    /** @var array User lookup cache. */
    private $usercache = [];

    /** @var array Enrol instance lookup cache. */
    private $instancecache = [];

    /**
     * Construct the unenrolment synchronisation service.
     *
     * @param bool $dryrun Whether to avoid database mutations.
     * @param sync_stats|null $stats Per-run statistics.
     */
    public function __construct(bool $dryrun = false, ?sync_stats $stats = null) {
        $this->dryrun = $dryrun;
        $this->stats = $stats ?: new sync_stats();
        $this->enrolplugin = enrol_get_plugin('manual');
    }

    /**
     * Run the unenrolment synchronisation.
     *
     * @param array $rows Source rows for one unenrolments tuple.
     * @return bool False when the safety valve aborts.
     */
    public function run(array $rows): bool {
        $initialfailures = $this->stats->unenrolfail;
        logger::log(
            'sync_unenrol',
            'system',
            'count',
            'info',
            'Processing ' . count($rows) . ' unenrolment rows.'
        );

        $targets = [];
        foreach ($rows as $row) {
            try {
                $target = $this->resolve_target((array)$row);
                if ($target) {
                    $targets[] = $target;
                }
            } catch (\Throwable $e) {
                logger::log('sync_unenrol', 'enrollment', 'redacted', 'fail', 'UNENROLMENT_ROW_PROCESSING_FAILED');
                $this->stats->unenrolfail++;
            }
        }

        $safe = $this->within_safety_limit(count($targets));
        if (!$safe && !$this->dryrun) {
            return false;
        }

        foreach ($targets as $target) {
            $this->suspend($target);
        }
        return $this->stats->unenrolfail === $initialfailures;
    }

    /**
     * Resolve one source row to a suspend target.
     *
     * @param array $row Generic unenrolment row.
     * @return array|null ['course' => ..., 'user' => ..., 'instance' => ...]
     */
    private function resolve_target($row) {
        $courseidnumber = trim($row['courseidnumber'] ?? '');
        $useridnumber = trim($row['useridnumber'] ?? '');

        if ($courseidnumber === '' || $useridnumber === '') {
            $this->stats->unenrolfail++;
            return null;
        }

        $course = $this->get_course($courseidnumber);
        if (!$course) {
            $this->stats->unenrolskip++;
            return null;
        }

        $user = $this->get_user($useridnumber);
        if (!$user) {
            $this->stats->unenrolskip++;
            return null;
        }

        $context = \context_course::instance($course->id);
        if (!is_enrolled($context, $user->id, '', true)) {
            return null;
        }

        $instance = $this->get_manual_instance($course);
        if (!$instance) {
            logger::log(
                'sync_unenrol',
                'enrollment',
                'redacted',
                'warn',
                'UNENROLMENT_MANUAL_INSTANCE_UNAVAILABLE'
            );
            $this->stats->unenrolwarn++;
            return null;
        }

        return ['course' => $course, 'user' => $user, 'instance' => $instance];
    }

    /**
     * Suspend one resolved enrolment target.
     *
     * @param array $target Resolved target.
     */
    private function suspend($target) {
        $course = $target['course'];
        $user = $target['user'];

        if ($this->dryrun) {
            logger::log('sync_unenrol', 'enrollment', $user->username, 'dryrun', "Would suspend in {$course->shortname}.");
            $this->stats->unenrolok++;
            return;
        }

        $this->enrolplugin->update_user_enrol($target['instance'], $user->id, ENROL_USER_SUSPENDED);
        logger::log('sync_unenrol', 'enrollment', $user->username, 'update', "Suspended in {$course->shortname}.");
        $this->stats->unenrolok++;
    }

    /**
     * Return whether a suspension count is within configured safety limits.
     *
     * @param int $count Number of enrolments to suspend.
     * @return bool
     */
    private function within_safety_limit($count) {
        if ($count === 0) {
            return true;
        }
        $maxabs = (int)get_config('local_wisa', 'unenrol_safety_max');
        $maxpct = (int)get_config('local_wisa', 'unenrol_safety_pct');

        if ($maxabs > 0 && $count > $maxabs) {
            logger::log(
                'sync_unenrol',
                'system',
                'redacted',
                'fail',
                'UNENROLMENT_SAFETY_LIMIT_EXCEEDED'
            );
            return false;
        }

        if ($maxpct > 0) {
            $active = $this->active_manual_enrolments();
            if ($active > 0 && ($count / $active * 100) > $maxpct) {
                logger::log(
                    'sync_unenrol',
                    'system',
                    'redacted',
                    'fail',
                    'UNENROLMENT_SAFETY_LIMIT_EXCEEDED'
                );
                return false;
            }
        }
        return true;
    }

    /**
     * Count active site-wide manual enrolments.
     *
     * @return int
     */
    private function active_manual_enrolments() {
        global $DB;
        return (int)$DB->count_records_sql(
            "SELECT COUNT(ue.id)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.enrol = 'manual' AND ue.status = :active",
            ['active' => ENROL_USER_ACTIVE]
        );
    }

    /**
     * Look up a course by idnumber.
     *
     * @param string $idnumber Course idnumber.
     * @return object|false
     */
    private function get_course($idnumber) {
        global $DB;
        if (!array_key_exists($idnumber, $this->coursecache)) {
            $this->coursecache[$idnumber] = $DB->get_record('course', ['idnumber' => $idnumber]) ?: false;
        }
        return $this->coursecache[$idnumber];
    }

    /**
     * Look up a user by idnumber and fallback username.
     *
     * @param string $idnumber User idnumber.
     * @return object|false
     */
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

    /**
     * Return the existing manual enrol instance for a course.
     *
     * @param object $course Moodle course record.
     * @return object|false
     */
    private function get_manual_instance($course) {
        global $DB;
        if (!array_key_exists($course->id, $this->instancecache)) {
            $this->instancecache[$course->id] =
                $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']) ?: false;
        }
        return $this->instancecache[$course->id];
    }
}
