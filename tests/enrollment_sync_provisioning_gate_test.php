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
 * Provisioning-gate tests for enrolment synchronisation.
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
 * Verifies the enrolment provisioning gate contract.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_wisa
 * @covers     \local_wisa\sync\enrollment_sync
 */
final class enrollment_sync_provisioning_gate_test extends enrollment_sync_test_case {
    /**
     * Pending source-key provisioning blocks a student row before the Moodle course lookup.
     *
     * @return void
     */
    public function test_pending_provisioning_blocks_student_enrolment_and_reports_status(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $student = $this->create_user_with_idnumber('STUDENT-001');
        $this->create_provision_record('WISA-COURSE-001', provisioning_repository::STATUS_PENDING);
        $stats = new sync_stats();
        $sync = new enrollment_sync('sissource_wisa', false, $stats);

        $this->assertTrue($sync->run([sis_fixtures::enrolment()]));

        $this->assertTrue($sync->has_blocking_provisions());
        $this->assertSame(1, $stats->enrolskip);
        $this->assertSame(0, $stats->enrolcreate);
        $this->assertSame(0, $DB->count_records('user_enrolments', ['userid' => $student->id]));
        $warnings = array_values($this->get_logs_by_status('warn'));
        $this->assertCount(1, $warnings);
        $this->assertSame('redacted', $warnings[0]->objectid);
        $this->assertSame('Source course provisioning blocks this enrolment row.', $warnings[0]->message);
    }

    /**
     * Failed source-key provisioning blocks teacher rows without creating enrolments.
     *
     * @return void
     */
    public function test_failed_provisioning_blocks_teacher_enrolment_and_reports_status(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $teacher = $this->create_user_with_idnumber('TEACHER-001');
        $this->create_provision_record('WISA-COURSE-001', provisioning_repository::STATUS_FAILED);
        $stats = new sync_stats();
        $rows = [sis_fixtures::enrolment(['useridnumber' => 'TEACHER-001', 'role' => 'teacher'])];
        $sync = new enrollment_sync('sissource_wisa', false, $stats);

        $this->assertTrue($sync->run($rows));

        $this->assertFalse($this->user_enrolment($course->id, $teacher->id));
        $this->assertSame(1, $stats->enrolskip);
        $this->assertTrue($sync->has_blocking_provisions());
    }

    /**
     * Successful terminal provisioning states allow their related enrolment rows.
     *
     * @return void
     */
    public function test_successful_terminal_provisioning_allows_enrolments(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $readycourse = $this->create_course_with_idnumber('WISA-COURSE-001');
        $fallbackcourse = $this->create_course_with_idnumber('WISA-COURSE-002');
        $readystudent = $this->create_user_with_idnumber('STUDENT-001');
        $fallbackteacher = $this->create_user_with_idnumber('TEACHER-001');
        $this->create_provision_record('WISA-COURSE-001', provisioning_repository::STATUS_READY);
        $this->create_provision_record('WISA-COURSE-002', provisioning_repository::STATUS_FALLBACK_READY);
        $stats = new sync_stats();
        $rows = [
            sis_fixtures::enrolment(),
            sis_fixtures::enrolment([
                'courseidnumber' => 'WISA-COURSE-002',
                'useridnumber' => 'TEACHER-001',
                'role' => 'teacher',
            ]),
        ];
        $sync = new enrollment_sync('sissource_wisa', false, $stats);

        $this->assertTrue($sync->run($rows));

        $this->assertNotFalse($this->user_enrolment($readycourse->id, $readystudent->id));
        $this->assertNotFalse($this->user_enrolment($fallbackcourse->id, $fallbackteacher->id));
        $this->assertSame(2, $stats->enrolcreate);
        $this->assertFalse($sync->has_blocking_provisions());
    }

    /**
     * Missing provisioning state preserves ordinary enrolment behaviour.
     *
     * @return void
     */
    public function test_missing_provisioning_state_does_not_block_enrolment(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $student = $this->create_user_with_idnumber('STUDENT-001');
        $stats = new sync_stats();
        $sync = new enrollment_sync('sissource_wisa', false, $stats);

        $this->assertTrue($sync->run([sis_fixtures::enrolment()]));

        $this->assertNotFalse($this->user_enrolment($course->id, $student->id));
        $this->assertSame(1, $stats->enrolcreate);
        $this->assertFalse($sync->has_blocking_provisions());
    }

    /**
     * Every blocked row is reported by the tuple-local provisioning outcome.
     *
     * @return void
     */
    public function test_multiple_blocked_rows_mark_the_current_tuple_blocked(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->create_provision_record('WISA-COURSE-001', provisioning_repository::STATUS_PENDING);
        $this->create_provision_record('WISA-COURSE-002', provisioning_repository::STATUS_RUNNING);
        $stats = new sync_stats();
        $rows = [
            sis_fixtures::enrolment(),
            sis_fixtures::enrolment([
                'courseidnumber' => 'WISA-COURSE-002',
                'useridnumber' => 'TEACHER-001',
                'role' => 'teacher',
            ]),
        ];
        $sync = new enrollment_sync('sissource_wisa', false, $stats);

        $this->assertTrue($sync->run($rows));

        $this->assertSame(2, $stats->enrolskip);
        $this->assertTrue($sync->has_blocking_provisions());
    }

    /**
     * Role tokens do not control tuple-local provisioning state.
     *
     * @return void
     */
    public function test_unknown_roles_do_not_clear_a_tuple_provisioning_block(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->create_provision_record('WISA-COURSE-001', provisioning_repository::STATUS_PENDING);
        $stats = new sync_stats();
        $rows = [
            sis_fixtures::enrolment(['role' => 'unknown']),
            sis_fixtures::enrolment(['role' => 'missing']),
        ];
        $sync = new enrollment_sync('sissource_wisa', false, $stats);

        $this->assertTrue($sync->run($rows));

        $this->assertSame(0, $stats->enrolwarn);
        $this->assertSame(2, $stats->enrolskip);
        $this->assertTrue($sync->has_blocking_provisions());
    }
}
