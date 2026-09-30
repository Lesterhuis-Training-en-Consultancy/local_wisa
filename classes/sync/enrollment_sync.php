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
 * Enrolment synchronisation from a SIS source.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\sync;

use local_wisa\logger;
use local_wisa\provisioning_repository;
use local_wisa\role_resolver;
use local_wisa\sync_stats;

/**
 * Consumes generic enrolment records and enrols users via the manual enrol plugin.
 */
class enrollment_sync {
    /** @var string Source adapter Frankenstyle component name. */
    private $sourcecomponent;

    /** @var provisioning_repository Course provisioning state repository. */
    private $provisioningrepository;

    /** @var role_resolver Source-role resolver. */
    private $roleresolver;

    /** @var \enrol_manual_plugin Manual enrol plugin. */
    private $enrolplugin;

    /** @var bool Whether to avoid database mutations. */
    private $dryrun;

    /** @var sync_stats Per-run statistics. */
    private $stats;

    /** @var array|null [start timestamp, end timestamp] school-year window, or null = no filter. */
    private $window;

    /** @var bool Whether provisioning blocked this tuple. */
    private $blockingprovisions = false;

    /** @var array Course lookup cache. */
    private $coursecache = [];

    /** @var array User lookup cache. */
    private $usercache = [];

    /** @var array Enrol instance lookup cache. */
    private $instancecache = [];

    /**
     * Construct the enrolment synchronisation service.
     *
     * @param string $sourcecomponent Source Frankenstyle component.
     * @param bool $dryrun Whether to avoid database mutations.
     * @param sync_stats|null $stats Per-run statistics.
     * @param array|null $window School-year window.
     */
    public function __construct(
        string $sourcecomponent,
        bool $dryrun = false,
        ?sync_stats $stats = null,
        ?array $window = null
    ) {
        $this->sourcecomponent = $sourcecomponent;
        $this->provisioningrepository = new provisioning_repository();
        $this->dryrun = $dryrun;
        $this->stats = $stats ?: new sync_stats();
        $this->roleresolver = new role_resolver();
        $this->enrolplugin = enrol_get_plugin('manual');
        $this->window = $window;
    }

    /**
     * Run the enrolment synchronisation.
     *
     * @param array $rows Source rows for one enrolments tuple.
     * @return bool Whether the tuple processed.
     */
    public function run(array $rows): bool {
        $this->blockingprovisions = false;
        $initialfailures = $this->stats->enrolfail;
        logger::log('sync_enrol', 'system', 'count', 'info', 'Processing ' . count($rows) . ' enrolment rows.');

        foreach ($rows as $row) {
            try {
                $this->process_enrolment((array)$row);
            } catch (\Throwable $e) {
                logger::log('sync_enrol', 'enrollment', 'redacted', 'fail', 'ENROLMENT_ROW_PROCESSING_FAILED');
                $this->stats->enrolfail++;
            }
        }
        return $this->stats->enrolfail === $initialfailures;
    }

    /**
     * Return whether source-key provisioning blocked this tuple during the last run.
     *
     * @return bool Whether this tuple is blocked.
     */
    public function has_blocking_provisions(): bool {
        return $this->blockingprovisions;
    }

    /**
     * Process one generic enrolment row.
     *
     * @param array $row Generic enrolment row.
     */
    private function process_enrolment($row) {
        $courseidnumber = trim($row['courseidnumber'] ?? '');
        $useridnumber = trim($row['useridnumber'] ?? '');
        $role = trim((string)($row['role'] ?? ''));
        if ($courseidnumber === '' || $useridnumber === '') {
            logger::log(
                'sync_enrol',
                'enrollment',
                'redacted',
                'fail',
                'ENROLMENT_ROW_REQUIRED_FIELDS_MISSING'
            );
            $this->stats->enrolfail++;
            return;
        }

        if ($this->is_provisioning_blocked($courseidnumber)) {
            $this->blockingprovisions = true;
            logger::log(
                'sync_enrol',
                'enrollment',
                'redacted',
                'warn',
                'Source course provisioning blocks this enrolment row.'
            );
            $this->stats->enrolskip++;
            return;
        }

        $roleid = $this->roleresolver->resolve($role);
        if ($roleid === null) {
            $this->stats->enrolwarn++;
            return;
        }

        $timestart = $this->to_ts($row['startdate'] ?? '');
        $timeend = $this->to_ts($row['enddate'] ?? '');
        if ($this->window !== null) {
            if (($timeend && $timeend < $this->window[0]) || ($timestart && $timestart > $this->window[1])) {
                $this->record_skip('ENROLMENT_OUTSIDE_SCHOOLYEAR');
                return;
            }
        }

        $course = $this->get_course($courseidnumber);
        if (!$course) {
            $this->record_skip('ENROLMENT_COURSE_NOT_FOUND');
            return;
        }

        $user = $this->get_user($useridnumber);
        if (!$user) {
            logger::log(
                'sync_enrol',
                'enrollment',
                'redacted',
                'warn',
                'ENROLMENT_USER_NOT_FOUND'
            );
            $this->stats->enrolwarn++;
            return;
        }

        $this->enrol_user($course, $user, $roleid, $timestart, $timeend);
    }

    /**
     * Return whether source-key provisioning prevents an enrolment row from running.
     *
     * @param string $courseidnumber Source course identity.
     * @return bool Whether provisioning blocks the source course.
     */
    private function is_provisioning_blocked(string $courseidnumber): bool {
        $provision = $this->provisioningrepository->get_by_source_course(
            $this->sourcecomponent,
            $courseidnumber
        );
        if ($provision === null) {
            return false;
        }

        return in_array($provision->status, [
            provisioning_repository::STATUS_PENDING,
            provisioning_repository::STATUS_RUNNING,
            provisioning_repository::STATUS_FAILED,
        ], true);
    }

    /**
     * Enrol or reactivate one user in a course.
     *
     * @param object $course Moodle course record.
     * @param object $user Moodle user record.
     * @param int $roleid Role id.
     * @param int $timestart Enrolment start timestamp.
     * @param int $timeend Enrolment end timestamp.
     */
    private function enrol_user($course, $user, $roleid, $timestart, $timeend) {
        $context = \context_course::instance($course->id);

        if (is_enrolled($context, $user->id, '', true)) {
            $this->record_skip('ENROLMENT_ALREADY_ACTIVE');
            return;
        }

        try {
            if ($this->dryrun) {
                logger::log(
                    'sync_enrol',
                    'enrollment',
                    $user->username,
                    'dryrun',
                    "Would enrol/reactivate in {$course->shortname}."
                );
                $this->stats->enrolcreate++;
                return;
            }

            $instance = $this->get_manual_instance($course);
            if (!$instance) {
                logger::log(
                    'sync_enrol',
                    'enrollment',
                    'redacted',
                    'fail',
                    'ENROLMENT_MANUAL_INSTANCE_UNAVAILABLE'
                );
                $this->stats->enrolfail++;
                return;
            }

            if (is_enrolled($context, $user->id)) {
                $this->enrolplugin->update_user_enrol($instance, $user->id, ENROL_USER_ACTIVE);
                logger::log('sync_enrol', 'enrollment', $user->username, 'update', "Reactivated in {$course->shortname}.");
                $this->stats->enrolupdate++;
            } else {
                $this->enrolplugin->enrol_user($instance, $user->id, $roleid, $timestart, $timeend);
                logger::log('sync_enrol', 'enrollment', $user->username, 'create', "Enrolled in {$course->shortname}.");
                $this->stats->enrolcreate++;
            }
        } catch (\Exception $e) {
            logger::log('sync_enrol', 'enrollment', 'redacted', 'fail', 'ENROLMENT_OPERATION_FAILED');
            $this->stats->enrolfail++;
        }
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
     * Return the manual enrol instance for a course, creating it if needed.
     *
     * @param object $course Moodle course record.
     * @return object|false
     */
    private function get_manual_instance($course) {
        global $DB;
        if (!array_key_exists($course->id, $this->instancecache)) {
            $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
            if (!$instance) {
                $instanceid = $this->enrolplugin->add_instance($course);
                $instance = $instanceid ? $DB->get_record('enrol', ['id' => $instanceid]) : false;
            }
            $this->instancecache[$course->id] = $instance ?: false;
        }
        return $this->instancecache[$course->id];
    }

    /**
     * Record one safe, diagnosable enrolment skip.
     *
     * @param string $code Stable skip reason code.
     * @return void
     */
    private function record_skip(string $code): void {
        logger::log('sync_enrol', 'enrollment', 'redacted', 'skip', $code);
        $this->stats->enrolskip++;
    }

    /**
     * Parse a source date to a Unix timestamp.
     *
     * @param string|int $value Date value.
     * @return int
     */
    private function to_ts($value) {
        if (is_int($value) || is_float($value)) {
            return (int)$value;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^\d+$/', $value)) {
            return (int)$value;
        }
        $ts = strtotime($value);
        return $ts !== false ? $ts : 0;
    }
}
