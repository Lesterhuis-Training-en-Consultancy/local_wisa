<?php
namespace local_wisa\sync;

defined('MOODLE_INTERNAL') || die();

use local_wisa\api_client;
use local_wisa\logger;
use local_wisa\sync_stats;

class enrollment_sync {
    private $api;
    private $student_role_id;
    private $teacher_role_id;
    private $enrol_plugin;
    private $dryrun;
    private $stats;

    public function __construct(api_client $api, bool $dryrun = false, ?sync_stats $stats = null) {
        $this->api = $api;
        $this->dryrun = $dryrun;
        $this->stats = $stats ?: new sync_stats();
        $this->student_role_id = get_config('local_wisa', 'student_role');
        $this->teacher_role_id = get_config('local_wisa', 'teacher_role');
        $this->enrol_plugin = enrol_get_plugin('manual');
    }

    public function run() {
        global $DB;

        $courses = $DB->get_records_select('course', "idnumber IS NOT NULL AND idnumber != ''");

        logger::log('sync_enrol', 'system', 'courses', 'info', 'Processing enrollments for ' . count($courses) . ' courses.');

        foreach ($courses as $course) {
            try {
                $this->process_course_enrollments($course);
            } catch (\Throwable $e) {
                logger::log('sync_enrol', 'course', $course->idnumber, 'fail', "Process error: " . $e->getMessage());
                $this->stats->enrol_fail++;
            }
        }
    }

    private function process_course_enrollments($course) {
        $klas_id = $course->idnumber;

        $wisa_students = $this->api->fetch('MCVO_STUD', ['KLAS_ID' => $klas_id]);
        if ($wisa_students) {
            foreach ($wisa_students as $student_data) {
                $this->enrol_user($course, $student_data['USERNAME'], $this->student_role_id);
            }
        }

        $wisa_teachers = $this->api->fetch('MCVO_LKR', ['KLAS_ID' => $klas_id]);
        if ($wisa_teachers) {
            foreach ($wisa_teachers as $teacher_data) {
                $this->enrol_user($course, $teacher_data['USERNAME'], $this->teacher_role_id);
            }
        }
    }

    private function enrol_user($course, $idnumber, $roleid) {
        global $DB;

        $idnumber = trim($idnumber);
        $username = \core_text::strtolower($idnumber);

        $user = $DB->get_record('user', ['idnumber' => $idnumber, 'deleted' => 0]);
        if (!$user) {
            $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
        }

        if (!$user) {
            logger::log('sync_enrol', 'enrollment', $idnumber, 'warn', "User $idnumber not found for course {$course->shortname}.");
            $this->stats->enrol_warn++;
            return;
        }

        if (is_enrolled(\context_course::instance($course->id), $user->id)) {
            return;
        }

        try {
            if ($this->dryrun) {
                logger::log('sync_enrol', 'enrollment', $user->username, 'dryrun', "Would enrol user in {$course->shortname}.");
            } else {
                $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
                if (!$instance) {
                    $instance_id = $this->enrol_plugin->add_instance($course);
                    $instance = $DB->get_record('enrol', ['id' => $instance_id]);
                }
                $this->enrol_plugin->enrol_user($instance, $user->id, $roleid);
                logger::log('sync_enrol', 'enrollment', $user->username, 'create', "Enrolled user in {$course->shortname}.");
            }
            $this->stats->enrol_create++;
        } catch (\Exception $e) {
            logger::log('sync_enrol', 'enrollment', $user->username, 'fail', "Failed to enrol: " . $e->getMessage());
            $this->stats->enrol_fail++;
        }
    }
}
