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
 * Lifecycle tests for enrolment synchronisation.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/enrollment_sync_test_case.php');

use local_wisa\sync\enrollment_sync;
use local_wisa\tests\sis_fixtures;

/**
 * Verifies enrolment creation and state transitions.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_wisa
 * @covers     \local_wisa\sync\enrollment_sync
 */
final class enrollment_sync_lifecycle_test extends enrollment_sync_test_case {
    /**
     * Verify configured student and teacher roles are assigned during enrolment.
     *
     * @return void
     */
    public function test_student_and_teacher_enrolments_are_created_with_configured_roles(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $student = $this->create_user_with_idnumber('STUDENT-001');
        $teacher = $this->create_user_with_idnumber('TEACHER-001');
        $rows = [
            sis_fixtures::enrolment(['useridnumber' => 'STUDENT-001', 'role' => 'student']),
            sis_fixtures::enrolment(['useridnumber' => 'TEACHER-001', 'role' => 'teacher']),
        ];

        $this->assertTrue((new enrollment_sync('sissource_wisa'))->run($rows));

        $this->assertSame(2, $DB->count_records_sql(
            "SELECT COUNT(ue.id)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid",
            ['courseid' => $course->id]
        ));
        $this->assertSame(ENROL_USER_ACTIVE, (int)$this->user_enrolment($course->id, $student->id)->status);
        $this->assertSame(ENROL_USER_ACTIVE, (int)$this->user_enrolment($course->id, $teacher->id)->status);
        $this->assert_course_role_assignment($course->id, $student->id, $this->student_role_id());
        $this->assert_course_role_assignment($course->id, $teacher->id, $this->teacher_role_id());
    }

    /**
     * Verify repeated feed rows do not duplicate a current enrolment.
     *
     * @return void
     */
    public function test_enrolment_sync_is_idempotent(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $user = $this->create_user_with_idnumber('STUDENT-001');
        $rows = [sis_fixtures::enrolment()];

        $this->assertTrue((new enrollment_sync('sissource_wisa'))->run($rows));
        $this->assertTrue((new enrollment_sync('sissource_wisa'))->run($rows));

        $this->assertSame(1, $DB->count_records_sql(
            "SELECT COUNT(ue.id)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND ue.userid = :userid",
            ['courseid' => $course->id, 'userid' => $user->id]
        ));
        $this->assertSame(ENROL_USER_ACTIVE, (int)$this->user_enrolment($course->id, $user->id)->status);
    }

    /**
     * Verify active enrolments retain their persisted dates.
     *
     * @return void
     */
    public function test_active_enrolment_preserves_existing_dates(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $user = $this->create_user_with_idnumber('STUDENT-001');
        $timestart = 1700000000;
        $timeend = 1750000000;
        $instance = $this->manual_instance($course);
        enrol_get_plugin('manual')->enrol_user($instance, $user->id, $this->student_role_id(), $timestart, $timeend);
        $rows = [
            sis_fixtures::enrolment(['startdate' => 1800000000, 'enddate' => 1850000000]),
        ];

        $this->assertTrue((new enrollment_sync('sissource_wisa'))->run($rows));

        $enrolment = $this->user_enrolment($course->id, $user->id);
        $this->assertSame(ENROL_USER_ACTIVE, (int)$enrolment->status);
        $this->assertSame($timestart, (int)$enrolment->timestart);
        $this->assertSame($timeend, (int)$enrolment->timeend);
    }

    /**
     * Verify the feed reactivates a suspended enrolment without changing dates.
     *
     * @return void
     */
    public function test_suspended_enrolment_is_reactivated_by_enrolment_feed(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $user = $this->create_user_with_idnumber('STUDENT-001');
        $timestart = 1700000000;
        $timeend = 1750000000;
        $instance = $this->manual_instance($course);
        enrol_get_plugin('manual')->enrol_user($instance, $user->id, $this->student_role_id(), $timestart, $timeend);
        enrol_get_plugin('manual')->update_user_enrol($instance, $user->id, ENROL_USER_SUSPENDED);

        $rows = [
            sis_fixtures::enrolment(['startdate' => 1800000000, 'enddate' => 1850000000]),
        ];
        $stats = new sync_stats();
        $this->assertTrue((new enrollment_sync('sissource_wisa', false, $stats))->run($rows));

        $enrolment = $this->user_enrolment($course->id, $user->id);
        $this->assertSame(ENROL_USER_ACTIVE, (int)$enrolment->status);
        $this->assertSame($timestart, (int)$enrolment->timestart);
        $this->assertSame($timeend, (int)$enrolment->timeend);
        $this->assertSame(1, $stats->enrolupdate);
    }
}
