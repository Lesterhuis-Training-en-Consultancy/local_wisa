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
 * Validation tests for enrolment synchronisation.
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
 * Verifies enrolment row validation and feed controls.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_wisa
 * @covers     \local_wisa\sync\enrollment_sync
 */
final class enrollment_sync_validation_test extends enrollment_sync_test_case {
    /**
     * Verify missing course or user rows do not stop valid enrolments.
     *
     * @return void
     */
    public function test_missing_course_or_user_enrolment_rows_do_not_abort_run(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $validuser = $this->create_user_with_idnumber('STUDENT-001');
        $rows = [
            sis_fixtures::enrolment(['KLAS_ID' => 'MISSING-COURSE', 'USERNAME' => 'STUDENT-001']),
            sis_fixtures::enrolment(['KLAS_ID' => 'WISA-COURSE-001', 'USERNAME' => 'MISSING-USER']),
            sis_fixtures::enrolment(['KLAS_ID' => 'WISA-COURSE-001', 'USERNAME' => 'STUDENT-001']),
        ];
        $stats = new sync_stats();

        $this->assertTrue((new enrollment_sync('sissource_wisa', false, $stats))->run($rows));

        $this->assertSame(ENROL_USER_ACTIVE, (int)$this->user_enrolment($course->id, $validuser->id)->status);
        $this->assertSame(1, $stats->enrolcreate);
        $this->assertSame(1, $stats->enrolskip);
        $this->assertSame(1, $stats->enrolwarn);
    }

    /**
     * Role tokens do not select an enrolment tuple.
     *
     * @return void
     */
    public function test_enrolment_rows_with_mapped_roles_share_one_tuple(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $student = $this->create_user_with_idnumber('STUDENT-001');
        $teacher = $this->create_user_with_idnumber('TEACHER-001');
        $rows = [
            sis_fixtures::enrolment(['useridnumber' => 'STUDENT-001', 'role' => 'student']),
            sis_fixtures::enrolment(['useridnumber' => 'TEACHER-001', 'role' => 'teacher']),
        ];
        $stats = new sync_stats();

        $this->assertTrue((new enrollment_sync('sissource_wisa', false, $stats))->run($rows));

        $this->assertNotFalse($this->user_enrolment($course->id, $student->id));
        $this->assertNotFalse($this->user_enrolment($course->id, $teacher->id));
        $this->assertSame(2, $stats->enrolcreate);
        $this->assertSame(0, $stats->enrolskip);
    }

    /**
     * A mapped source role never changes the tuple processing path.
     *
     * @return void
     */
    public function test_mapped_role_token_enrols_without_a_stream_classification_field(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $user = $this->create_user_with_idnumber('STUDENT-001');
        set_config('rolemap', '{"mentor":"editingteacher"}', 'local_wisa');
        $rows = [sis_fixtures::enrolment(['role' => 'mentor'])];

        $this->assertTrue((new enrollment_sync('sissource_wisa'))->run($rows));
        $this->assert_course_role_assignment($course->id, $user->id, $this->teacher_role_id());
    }

    /**
     * Unmapped tokens and mapped missing Moodle roles skip otherwise valid rows.
     *
     * @return void
     */
    public function test_unknown_tokens_and_missing_moodle_roles_skip_rows_with_warnings(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $user = $this->create_user_with_idnumber('STUDENT-001');
        set_config('rolemap', '{"missing":"not_a_moodle_role"}', 'local_wisa');
        $stats = new sync_stats();
        $rows = [
            sis_fixtures::enrolment(['role' => 'unknown']),
            sis_fixtures::enrolment(['role' => 'missing']),
        ];

        $this->assertTrue((new enrollment_sync('sissource_wisa', false, $stats))->run($rows));

        $this->assertFalse($this->user_enrolment($course->id, $user->id));
        $this->assertSame(2, $stats->enrolwarn);
        $this->assertSame(0, $stats->enrolfail);
    }

    /**
     * Verify missing enrolment identities fail the tuple without mutation.
     *
     * @return void
     */
    public function test_enrolment_rows_missing_required_identities_fail_without_mutating_enrolments(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $student = $this->create_user_with_idnumber('STUDENT-001');
        $rows = [
            sis_fixtures::enrolment(['KLAS_ID' => '', 'USERNAME' => 'STUDENT-001']),
            sis_fixtures::enrolment(['KLAS_ID' => 'WISA-COURSE-001', 'USERNAME' => '']),
        ];
        $stats = new sync_stats();

        $this->assertFalse((new enrollment_sync('sissource_wisa', false, $stats))->run($rows));

        $this->assertFalse($this->user_enrolment($course->id, $student->id));
        $this->assertSame(2, $stats->enrolfail);
        $this->assertSame(0, $stats->enrolwarn);
    }

    /**
     * Verify caught row exceptions fail the enrolment tuple.
     *
     * @return void
     */
    public function test_caught_enrolment_row_exception_marks_tuple_unsuccessful(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $stats = new sync_stats();
        $throwingvalue = new class {
            /** @var int Number of string conversions. */
            private $conversions = 0;

            /**
             * Throw once during processing, then provide a safe logging key.
             *
             * @return string
             */
            public function __toString(): string {
                $this->conversions++;
                if ($this->conversions === 1) {
                    throw new \RuntimeException('Fixture row conversion failed.');
                }
                return 'fixture-course';
            }
        };
        $rows = [sis_fixtures::enrolment(['courseidnumber' => $throwingvalue])];

        $this->assertFalse((new enrollment_sync('sissource_wisa', false, $stats))->run($rows));

        $this->assertSame(1, $stats->enrolfail);
        $this->assertSame(0, $stats->enrolwarn);
        $failure = $DB->get_record('local_wisa_log', [
            'action' => 'sync_enrol',
            'status' => 'fail',
        ], '*', MUST_EXIST);
        $this->assertSame('redacted', $failure->objectid);
        $this->assertSame('ENROLMENT_ROW_PROCESSING_FAILED', $failure->message);
        $this->assertStringNotContainsString('fixture-course', $failure->objectid . $failure->message);
        $this->assertStringNotContainsString('Fixture row conversion failed.', $failure->objectid . $failure->message);
    }
}
