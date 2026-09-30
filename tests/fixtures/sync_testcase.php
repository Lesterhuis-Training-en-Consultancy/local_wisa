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
 * Shared PHPUnit test base for local_wisa sync tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/sis_fixtures.php');
require_once(__DIR__ . '/wisa_fixtures.php');
require_once(__DIR__ . '/fake_api_client.php');

/**
 * Shared helper methods for local_wisa PHPUnit tests.
 */
abstract class sync_testcase extends \advanced_testcase {
    /**
     * Configure deterministic local_wisa defaults for a PHPUnit run.
     *
     * @return int The default category id.
     */
    protected function configure_wisa_defaults() {
        global $DB;

        $category = self::getDataGenerator()->create_category(['name' => 'WISA default category']);
        $studentrole = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
        $teacherrole = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher']);

        set_config('default_category', $category->id, 'local_wisa');
        set_config('category_mode', 'fixed', 'local_wisa');
        set_config('student_role', $studentrole ?: 5, 'local_wisa');
        set_config('teacher_role', $teacherrole ?: 3, 'local_wisa');
        set_config('schoolyear_scope', 'off', 'local_wisa');
        set_config('dry_run', 0, 'local_wisa');
        set_config('debug_logging', 0, 'local_wisa');
        set_config('enable_courses', 1, 'local_wisa');
        set_config('enable_students', 1, 'local_wisa');
        set_config('enable_teachers', 1, 'local_wisa');
        set_config('enable_enrolments', 1, 'local_wisa');
        set_config('enable_unenrolments', 1, 'local_wisa');
        set_config('enrol_teachers', 1, 'local_wisa');
        set_config('enrol_students', 1, 'local_wisa');
        set_config('unenrol_safety_max', 500, 'local_wisa');
        set_config('unenrol_safety_pct', 0, 'local_wisa');
        set_config('log_retention_days', 30, 'local_wisa');
        set_config('active_source', 'wisa', 'local_wisa');
        set_config('api_url', 'https://school.example.test/webwisad/bin/server.fcgi/QUERY/', 'sissource_wisa');
        set_config('api_user', 'apiuser', 'sissource_wisa');
        set_config('api_pass', 'apipassword', 'sissource_wisa');
        set_config('institute_num', '123456', 'sissource_wisa');
        set_config('query_courses', 'MCVOD_C', 'sissource_wisa');
        set_config('query_students', 'MCVOD_STUD', 'sissource_wisa');
        set_config('query_teachers', 'MCVOD_LKR', 'sissource_wisa');
        set_config('query_enrolments', 'MCVOD_INS', 'sissource_wisa');
        set_config('query_unenrolments', 'MCVOD_UIT', 'sissource_wisa');

        return (int)$category->id;
    }

    /**
     * Create a Moodle course with a specific idnumber.
     *
     * @param string $idnumber Course idnumber.
     * @param array $overrides Course overrides.
     * @return \stdClass
     */
    protected function create_course_with_idnumber($idnumber, array $overrides = []) {
        $record = array_merge([
            'idnumber' => $idnumber,
            'fullname' => 'Existing ' . $idnumber,
            'shortname' => 'EX ' . $idnumber,
        ], $overrides);
        return self::getDataGenerator()->create_course($record);
    }

    /**
     * Create a Moodle user with a specific idnumber.
     *
     * @param string $idnumber User idnumber.
     * @param array $overrides User overrides.
     * @return \stdClass
     */
    protected function create_user_with_idnumber($idnumber, array $overrides = []) {
        $username = isset($overrides['username']) ? $overrides['username'] : \core_text::strtolower($idnumber);
        $record = array_merge([
            'username' => $username,
            'idnumber' => $idnumber,
            'firstname' => 'Existing',
            'lastname' => $idnumber,
            'email' => $username . '@example.org',
        ], $overrides);
        return self::getDataGenerator()->create_user($record);
    }

    /**
     * Return the configured student role id.
     *
     * @return int Configured student role id.
     */
    protected function student_role_id() {
        return (int)get_config('local_wisa', 'student_role');
    }

    /**
     * Return the configured teacher role id.
     *
     * @return int Configured teacher role id.
     */
    protected function teacher_role_id() {
        return (int)get_config('local_wisa', 'teacher_role');
    }

    /**
     * Return the manual enrolment instance for a course, creating it if missing.
     *
     * @param \stdClass $course Course record.
     * @return \stdClass Manual enrol instance.
     */
    protected function manual_instance($course) {
        global $DB;

        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        if ($instance) {
            return $instance;
        }

        $plugin = enrol_get_plugin('manual');
        $instanceid = $plugin->add_instance($course);
        return $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
    }

    /**
     * Enrol a user in a course via the manual plugin.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $user User record.
     * @param int|null $roleid Role id.
     * @return \stdClass Manual enrolment instance.
     */
    protected function manually_enrol_user($course, $user, $roleid = null) {
        $instance = $this->manual_instance($course);
        $plugin = enrol_get_plugin('manual');
        $plugin->enrol_user($instance, $user->id, $roleid ?: $this->student_role_id());
        return $instance;
    }

    /**
     * Return the manual user enrolment record for a user and course.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @return \stdClass|false
     */
    protected function user_enrolment($courseid, $userid) {
        global $DB;

        return $DB->get_record_sql(
            "SELECT ue.*
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.enrol = :enrol AND e.courseid = :courseid AND ue.userid = :userid",
            ['enrol' => 'manual', 'courseid' => $courseid, 'userid' => $userid]
        );
    }

    /**
     * Assert that a role assignment exists in the course context.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @param int $roleid Role id.
     */
    protected function assert_course_role_assignment($courseid, $userid, $roleid) {
        global $DB;

        $context = \context_course::instance($courseid);
        $this->assertTrue($DB->record_exists('role_assignments', [
            'contextid' => $context->id,
            'userid' => $userid,
            'roleid' => $roleid,
        ]));
    }

    /**
     * Return log records with a given status.
     *
     * @param string $status Log status.
     * @return array Log records.
     */
    protected function get_logs_by_status($status) {
        global $DB;
        return $DB->get_records('local_wisa_log', ['status' => $status], 'id ASC');
    }
}
