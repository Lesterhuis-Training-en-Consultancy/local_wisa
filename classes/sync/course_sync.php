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
 * Course synchronisation from a SIS source.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\sync;

use local_wisa\logger;
use local_wisa\provisioning_repository;
use local_wisa\sync_stats;

/**
 * Synchronises generic SIS course records into Moodle courses.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_sync {
    use course_provisioning_trait;
    use course_category_trait;

    /** @var string Source Frankenstyle component for durable provision identities. */
    private string $sourcecomponent;

    /** @var int Default Moodle course category id. */
    private $defaultcategoryid;

    /** @var string Category handling mode. */
    private $categorymode;

    /** @var bool Whether to avoid database mutations. */
    private $dryrun;

    /** @var sync_stats Per-run statistics. */
    private $stats;

    /** @var array|null [start timestamp, end timestamp] school-year window, or null = no filter. */
    private $window;

    /** @var bool Whether this run encountered an active or failed provision. */
    private bool $blockingprovisions = false;

    /**
     * Construct the course synchronisation service.
     *
     * @param string $sourcecomponent Source Frankenstyle component.
     * @param bool $dryrun Whether to avoid database mutations.
     * @param sync_stats|null $stats Per-run statistics.
     * @param array|null $window School-year window.
     */
    public function __construct(string $sourcecomponent, bool $dryrun = false, ?sync_stats $stats = null, ?array $window = null) {
        $this->sourcecomponent = $sourcecomponent;
        $this->dryrun = $dryrun;
        $this->stats = $stats ?: new sync_stats();
        $this->defaultcategoryid = get_config('local_wisa', 'default_category');
        $this->categorymode = get_config('local_wisa', 'category_mode') ?: 'fixed';
        $this->window = $window;
    }

    /**
     * Run the course synchronisation.
     *
     * @param array $courses Source rows for one stream-phase tuple.
     * @return bool Whether processing did not add a course failure.
     */
    public function run(array $courses): bool {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->blockingprovisions = false;
        $coursefailbefore = $this->stats->coursefail;

        logger::log('sync_courses', 'system', 'count', 'info', 'Processing ' . count($courses) . ' source stream rows.');

        foreach ($courses as $courserecord) {
            try {
                $this->process_course((array)$courserecord);
            } catch (\Throwable $e) {
                logger::log('sync_course', 'course', 'redacted', 'fail', 'COURSE_ROW_PROCESSING_FAILED');
                $this->stats->coursefail++;
            }
        }
        return $this->stats->coursefail === $coursefailbefore;
    }

    /**
     * Return whether this run found a provision that blocks synchronous course creation.
     *
     * @return bool Whether a pending, running, or failed provision was found or queued.
     */
    public function has_blocking_provisions(): bool {
        return $this->blockingprovisions;
    }

    /**
     * Create or update one generic course row.
     *
     * @param array $record Generic course record.
     */
    private function process_course($record) {
        global $DB;

        $idnumber = trim($record['idnumber'] ?? '');
        $shortname = trim($record['shortname'] ?? '');
        $fullname = trim($record['fullname'] ?? '');
        $startdate = $this->to_ts($record['startdate'] ?? '');
        $enddate = $this->to_ts($record['enddate'] ?? '');

        if ($idnumber === '' || $shortname === '' || $fullname === '') {
            logger::log(
                'sync_course',
                'course',
                'redacted',
                'fail',
                'COURSE_ROW_REQUIRED_FIELDS_MISSING'
            );
            $this->stats->coursefail++;
            return;
        }

        if ($this->window !== null) {
            if (($enddate && $enddate < $this->window[0]) || ($startdate && $startdate > $this->window[1])) {
                $this->stats->courseskip++;
                return;
            }
        }

        $existingcourse = $DB->get_record('course', ['idnumber' => $idnumber]);
        $existingprovision = (new provisioning_repository())->get_by_source_course(
            $this->sourcecomponent,
            $idnumber
        );
        if ($existingprovision !== null) {
            $completedprovision = in_array($existingprovision->status, [
                provisioning_repository::STATUS_READY,
                provisioning_repository::STATUS_FALLBACK_READY,
            ], true) && $existingprovision->courseid !== null
                && $DB->record_exists('course', ['id' => (int)$existingprovision->courseid])
                && $existingcourse
                && (int)$existingprovision->courseid === (int)$existingcourse->id;
            if (!$existingcourse || !$completedprovision) {
                $this->handle_existing_provision($existingprovision, $idnumber, $existingcourse ?: null);
                return;
            }
        }

        if ($existingcourse) {
            $update = new \stdClass();
            $update->id = $existingcourse->id;
            $changed = false;

            if ($existingcourse->fullname !== $fullname) {
                $update->fullname = $fullname;
                $changed = true;
            }
            if ($existingcourse->shortname !== $shortname) {
                if (!$DB->record_exists('course', ['shortname' => $shortname])) {
                    $update->shortname = $shortname;
                    $changed = true;
                } else {
                    logger::log('sync_course', 'course', 'redacted', 'warn', 'COURSE_SHORTNAME_DUPLICATE');
                }
            }
            if ($existingcourse->startdate != $startdate || $existingcourse->enddate != $enddate) {
                $update->startdate = $startdate ?: $existingcourse->startdate;
                $update->enddate = $enddate;
                $changed = true;
            }

            if ($changed) {
                if ($this->dryrun) {
                    logger::log('sync_course', 'course', $idnumber, 'dryrun', "Would update course $fullname.");
                } else {
                    update_course($update);
                    logger::log('sync_course', 'course', $idnumber, 'update', "Updated course $fullname.");
                }
                $this->stats->courseupdate++;
            }
        } else {
            $newcourse = new \stdClass();
            $newcourse->fullname = $fullname;
            $newcourse->shortname = $shortname;
            $newcourse->idnumber = $idnumber;
            $newcourse->startdate = $startdate;
            $newcourse->enddate = $enddate;
            $newcourse->category = $this->resolve_category_id($record);
            $newcourse->visible = 1;

            if ($DB->record_exists('course', ['shortname' => $shortname])) {
                $newcourse->shortname = $shortname . '_' . $idnumber;
                logger::log('sync_course', 'course', 'redacted', 'warn', 'COURSE_SHORTNAME_DUPLICATE');
            }

            $templatekey = trim((string)($record['templatekey'] ?? ''));
            if ($this->should_queue_provisioning($templatekey)) {
                $this->queue_course_provision($newcourse, $templatekey);
                return;
            }

            try {
                if ($this->dryrun) {
                    logger::log('sync_course', 'course', $idnumber, 'dryrun', "Would create course $fullname.");
                } else {
                    create_course($newcourse);
                    logger::log('sync_course', 'course', $idnumber, 'create', "Created course $fullname.");
                }
                $this->stats->coursecreate++;
            } catch (\Exception $e) {
                logger::log('sync_course', 'course', 'redacted', 'fail', 'COURSE_CREATE_FAILED');
                $this->stats->coursefail++;
            }
        }
    }

    /**
     * Parse a source date to a Unix timestamp.
     *
     * @param string|int $value Date value or timestamp.
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
        $ts = strtotime($value);
        return $ts !== false ? $ts : 0;
    }
}
