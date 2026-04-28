<?php
namespace local_wisa\sync;

defined('MOODLE_INTERNAL') || die();

use local_wisa\api_client;
use local_wisa\logger;
use local_wisa\sync_stats;

class course_sync {
    private $api;
    private $default_category_id;
    private $dryrun;
    private $stats;

    public function __construct(api_client $api, bool $dryrun = false, ?sync_stats $stats = null) {
        $this->api = $api;
        $this->dryrun = $dryrun;
        $this->stats = $stats ?: new sync_stats();
        $this->default_category_id = get_config('local_wisa', 'default_category');
    }

    public function run() {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $courses = $this->api->get_courses();
        if ($courses === false) {
            logger::log('sync_courses', 'system', 'api', 'fail', 'Failed to fetch courses from WISA.');
            return;
        }

        logger::log('sync_courses', 'system', 'count', 'info', 'Fetched ' . count($courses) . ' courses from WISA.');

        foreach ($courses as $wisa_course) {
            try {
                $this->process_course($wisa_course);
            } catch (\Throwable $e) {
                $klas = $wisa_course['KLAS_ID'] ?? 'unknown';
                logger::log('sync_course', 'course', $klas, 'fail', "Process error: " . $e->getMessage());
                $this->stats->course_fail++;
            }
        }
    }

    private function process_course($wisa_course) {
        global $DB;

        $klas_id = $wisa_course['KLAS_ID'];
        $shortname = $wisa_course['SHORTNAME'];
        $fullname = $wisa_course['FULLNAME'];
        $startdate = strtotime($wisa_course['BEGINDATUM']);
        $enddate = strtotime($wisa_course['EINDDATUM']);

        $existing_course = $DB->get_record('course', ['idnumber' => $klas_id]);

        if ($existing_course) {
            $update = new \stdClass();
            $update->id = $existing_course->id;
            $changed = false;

            if ($existing_course->fullname !== $fullname) {
                $update->fullname = $fullname;
                $changed = true;
            }
            if ($existing_course->shortname !== $shortname) {
                if (!$DB->record_exists('course', ['shortname' => $shortname])) {
                    $update->shortname = $shortname;
                    $changed = true;
                } else {
                    logger::log('sync_course', 'course', $klas_id, 'warn', "Duplicate shortname $shortname skipped.");
                }
            }
            // Moodle requires startdate when enddate is set; pass both if either differs.
            if ($existing_course->startdate != $startdate || $existing_course->enddate != $enddate) {
                $update->startdate = $startdate ?: $existing_course->startdate;
                $update->enddate = $enddate;
                $changed = true;
            }

            if ($changed) {
                if ($this->dryrun) {
                    logger::log('sync_course', 'course', $klas_id, 'dryrun', "Would update course $fullname.");
                } else {
                    update_course($update);
                    logger::log('sync_course', 'course', $klas_id, 'update', "Updated course $fullname.");
                }
                $this->stats->course_update++;
            }

        } else {
            $new_course = new \stdClass();
            $new_course->fullname = $fullname;
            $new_course->shortname = $shortname;
            $new_course->idnumber = $klas_id;
            $new_course->startdate = $startdate;
            $new_course->enddate = $enddate;
            $new_course->category = $this->default_category_id;
            $new_course->visible = 1;

            if ($DB->record_exists('course', ['shortname' => $shortname])) {
                $new_course->shortname = $shortname . '_' . $klas_id;
                logger::log('sync_course', 'course', $klas_id, 'warn', "Duplicate shortname $shortname. Appended ID.");
            }

            try {
                if ($this->dryrun) {
                    logger::log('sync_course', 'course', $klas_id, 'dryrun', "Would create course $fullname.");
                } else {
                    create_course($new_course);
                    logger::log('sync_course', 'course', $klas_id, 'create', "Created course $fullname.");
                }
                $this->stats->course_create++;
            } catch (\Exception $e) {
                logger::log('sync_course', 'course', $klas_id, 'fail', "Failed to create course: " . $e->getMessage());
                $this->stats->course_fail++;
            }
        }
    }
}
