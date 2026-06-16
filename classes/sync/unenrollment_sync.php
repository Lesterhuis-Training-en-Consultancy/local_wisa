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
 * Unenrolment synchronisation: suspends leavers reported by WISA.
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
 * Consumes the unenrolment feed (default MCVOD_UIT) — rows for cursists/teachers
 * that left a WISA class. Matched, actively-enrolled users are *suspended* in the
 * Moodle course (status ENROL_USER_SUSPENDED), not hard-unenrolled: this preserves
 * grades and history and is reversible (the enrolment sync reactivates them if they
 * return). Each row carries KLAS_ID (course idnumber) and USERNAME (user key).
 *
 * A safety valve guards against bad feed data: if a single run would suspend more
 * than the configured absolute number or share of all active enrolments, nothing is
 * suspended and an alarm is logged so an administrator can intervene.
 */
class unenrollment_sync {
    private $api;
    private $enrol_plugin;
    private $dryrun;
    private $stats;
    /** @var array Run-caches to avoid repeated DB lookups for the same key. */
    private $coursecache = [];
    private $usercache = [];
    private $instancecache = [];

    public function __construct(api_client $api, bool $dryrun = false, ?sync_stats $stats = null) {
        $this->api = $api;
        $this->dryrun = $dryrun;
        $this->stats = $stats ?: new sync_stats();
        $this->enrol_plugin = enrol_get_plugin('manual');
    }

    /** @return bool False when the fetch failed or the safety valve aborted (watermark must not advance). */
    public function run() {
        $rows = $this->api->get_unenrolments();
        if ($rows === false || !is_array($rows)) {
            logger::log('sync_unenrol', 'system', 'api', 'fail', 'Failed to fetch unenrolments from WISA.');
            return false;
        }

        logger::log('sync_unenrol', 'system', 'count', 'info',
            'Fetched ' . count($rows) . ' unenrolment rows from WISA.');

        // Pass 1: determine which currently-active enrolments would be suspended.
        $targets = [];
        foreach ($rows as $row) {
            try {
                $target = $this->resolve_target((array)$row);
                if ($target) {
                    $targets[] = $target;
                }
            } catch (\Throwable $e) {
                $key = (is_array($row) ? ($row['KLAS_ID'] ?? '?') : '?') . '/'
                     . (is_array($row) ? ($row['USERNAME'] ?? '?') : '?');
                logger::log('sync_unenrol', 'enrollment', $key, 'fail', "Process error: " . $e->getMessage());
                $this->stats->unenrol_fail++;
            }
        }

        // Safety valve: refuse an unexpectedly large suspension wave (e.g. bad feed data).
        $safe = $this->within_safety_limit(count($targets));
        if (!$safe && !$this->dryrun) {
            // Do not advance the watermark: the same rows must be offered again after
            // the administrator checks the data or raises the limit.
            return false;
        }

        // Pass 2: apply the suspensions.
        foreach ($targets as $target) {
            $this->suspend($target);
        }
        return true;
    }

    /**
     * Resolve one feed row to a suspend target, or null when there is nothing to do.
     *
     * @return array|null ['course' => ..., 'user' => ..., 'instance' => ...]
     */
    private function resolve_target($row) {
        $klas_id = trim($row['KLAS_ID'] ?? '');
        $idnumber = trim($row['USERNAME'] ?? '');

        if ($klas_id === '' || $idnumber === '') {
            $this->stats->unenrol_warn++;
            return null;
        }

        $course = $this->get_course($klas_id);
        if (!$course) {
            // Cursus niet in Moodle (buiten scope) -> niets te doen, stil overslaan.
            $this->stats->unenrol_skip++;
            return null;
        }

        $user = $this->get_user($idnumber);
        if (!$user) {
            // Geen Moodle-account -> niets ingeschreven om te suspenden.
            $this->stats->unenrol_skip++;
            return null;
        }

        $context = \context_course::instance($course->id);
        // Only act when the user is currently actively enrolled.
        if (!is_enrolled($context, $user->id, '', true)) {
            return null;
        }

        $instance = $this->get_manual_instance($course);
        if (!$instance) {
            // Enrolled via a non-manual method we do not manage; skip.
            logger::log('sync_unenrol', 'enrollment', $user->username, 'warn',
                "No manual enrol instance for {$course->shortname}; not suspended.");
            $this->stats->unenrol_warn++;
            return null;
        }

        return ['course' => $course, 'user' => $user, 'instance' => $instance];
    }

    private function suspend($target) {
        $course = $target['course'];
        $user = $target['user'];

        if ($this->dryrun) {
            logger::log('sync_unenrol', 'enrollment', $user->username, 'dryrun', "Would suspend in {$course->shortname}.");
            $this->stats->unenrol_ok++;
            return;
        }

        $this->enrol_plugin->update_user_enrol($target['instance'], $user->id, ENROL_USER_SUSPENDED);
        logger::log('sync_unenrol', 'enrollment', $user->username, 'update', "Suspended in {$course->shortname}.");
        $this->stats->unenrol_ok++;
    }

    /**
     * Is it safe to suspend $count enrolments this run? Logs an alarm and returns
     * false when the absolute or ratio limit is exceeded. A limit of 0 disables it.
     */
    private function within_safety_limit($count) {
        if ($count === 0) {
            return true;
        }
        $maxabs = (int)get_config('local_wisa', 'unenrol_safety_max');
        $maxpct = (int)get_config('local_wisa', 'unenrol_safety_pct');

        if ($maxabs > 0 && $count > $maxabs) {
            logger::log('sync_unenrol', 'system', 'safety', 'fail',
                "SAFETY STOP: $count suspensions exceed the absolute limit of $maxabs. " .
                'No users were suspended this run — check the MCVOD_UIT data or raise local_wisa | unenrol_safety_max.');
            return false;
        }

        if ($maxpct > 0) {
            $active = $this->active_manual_enrolments();
            if ($active > 0 && ($count / $active * 100) > $maxpct) {
                $pct = round($count / $active * 100, 1);
                logger::log('sync_unenrol', 'system', 'safety', 'fail',
                    "SAFETY STOP: $count suspensions = {$pct}% of $active active enrolments (limit {$maxpct}%). " .
                    'No users were suspended this run — check the MCVOD_UIT data or raise local_wisa | unenrol_safety_pct.');
                return false;
            }
        }
        return true;
    }

    /** Total number of active enrolments on manual enrol instances, site-wide. */
    private function active_manual_enrolments() {
        global $DB;
        return (int)$DB->count_records_sql(
            "SELECT COUNT(ue.id)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.enrol = 'manual' AND ue.status = :active",
            ['active' => ENROL_USER_ACTIVE]);
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

    /** Existing manual enrol instance for a course, cached per run. Never created here. */
    private function get_manual_instance($course) {
        global $DB;
        if (!array_key_exists($course->id, $this->instancecache)) {
            $this->instancecache[$course->id] =
                $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']) ?: false;
        }
        return $this->instancecache[$course->id];
    }
}
