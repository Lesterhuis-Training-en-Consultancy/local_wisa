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
 * Unenrolment synchronisation tests for local_wisa.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/sync_testcase.php');

use local_wisa\sync\unenrollment_sync;
use local_wisa\sync\enrollment_sync;
use local_wisa\tests\sis_fixtures;


/**
 * Unenrolment synchronisation tests for local_wisa.
 *
 * @group local_wisa
 * @covers     \local_wisa\sync\unenrollment_sync
 */
final class unenrollment_sync_test extends sync_testcase {
    public function test_unenrolment_suspends_instead_of_deleting(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $user = $this->create_user_with_idnumber('STUDENT-001');
        $this->manually_enrol_user($course, $user);
        $stats = new sync_stats();

        $this->assertTrue((new unenrollment_sync(false, $stats))->run([sis_fixtures::unenrolment()]));

        $ue = $this->user_enrolment($course->id, $user->id);
        $this->assertNotFalse($ue);
        $this->assertSame(ENROL_USER_SUSPENDED, (int)$ue->status);
        $this->assertSame(1, $DB->count_records('user_enrolments', ['userid' => $user->id]));
        $this->assertSame(1, $stats->unenrolok);
    }

    public function test_unenrolment_sync_is_idempotent_for_already_suspended_enrolments(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $user = $this->create_user_with_idnumber('STUDENT-001');
        $this->manually_enrol_user($course, $user);
        $rows = [sis_fixtures::unenrolment()];

        $this->assertTrue((new unenrollment_sync())->run($rows));
        $this->assertTrue((new unenrollment_sync())->run($rows));

        $this->assertSame(ENROL_USER_SUSPENDED, (int)$this->user_enrolment($course->id, $user->id)->status);
    }

    public function test_missing_unenrolment_targets_do_not_abort_run(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $user = $this->create_user_with_idnumber('STUDENT-001');
        $this->manually_enrol_user($course, $user);
        $rows = [
            sis_fixtures::unenrolment(['KLAS_ID' => 'MISSING-COURSE']),
            sis_fixtures::unenrolment(['USERNAME' => 'MISSING-USER']),
            sis_fixtures::unenrolment(),
        ];
        $stats = new sync_stats();

        $this->assertTrue((new unenrollment_sync(false, $stats))->run($rows));

        $this->assertSame(ENROL_USER_SUSPENDED, (int)$this->user_enrolment($course->id, $user->id)->status);
        $this->assertSame(1, $stats->unenrolok);
        $this->assertSame(2, $stats->unenrolskip);
    }

    public function test_unenrolment_safety_valve_blocks_mass_suspension_by_absolute_threshold(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        set_config('unenrol_safety_max', 1, 'local_wisa');
        set_config('unenrol_safety_pct', 0, 'local_wisa');
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $first = $this->create_user_with_idnumber('STUDENT-001');
        $second = $this->create_user_with_idnumber('STUDENT-002');
        $this->manually_enrol_user($course, $first);
        $this->manually_enrol_user($course, $second);
        $rows = [
            sis_fixtures::unenrolment(['USERNAME' => 'STUDENT-001']),
            sis_fixtures::unenrolment(['USERNAME' => 'STUDENT-002']),
        ];

        $this->assertFalse((new unenrollment_sync())->run($rows));

        $this->assertSame(ENROL_USER_ACTIVE, (int)$this->user_enrolment($course->id, $first->id)->status);
        $this->assertSame(ENROL_USER_ACTIVE, (int)$this->user_enrolment($course->id, $second->id)->status);
        $this->assertTrue($DB->record_exists('local_wisa_log', [
            'objectid' => 'redacted',
            'status' => 'fail',
        ]));
    }

    public function test_unenrolment_safety_valve_blocks_mass_suspension_by_percentage_threshold(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        set_config('unenrol_safety_max', 0, 'local_wisa');
        set_config('unenrol_safety_pct', 50, 'local_wisa');
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $first = $this->create_user_with_idnumber('STUDENT-001');
        $second = $this->create_user_with_idnumber('STUDENT-002');
        $this->manually_enrol_user($course, $first);
        $this->manually_enrol_user($course, $second);
        $rows = [
            sis_fixtures::unenrolment(['USERNAME' => 'STUDENT-001']),
            sis_fixtures::unenrolment(['USERNAME' => 'STUDENT-002']),
        ];

        $this->assertFalse((new unenrollment_sync())->run($rows));

        $this->assertSame(ENROL_USER_ACTIVE, (int)$this->user_enrolment($course->id, $first->id)->status);
        $this->assertSame(ENROL_USER_ACTIVE, (int)$this->user_enrolment($course->id, $second->id)->status);
    }

    public function test_dry_run_does_not_create_or_suspend_enrolments(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $student = $this->create_user_with_idnumber('STUDENT-001');
        $leaver = $this->create_user_with_idnumber('STUDENT-002');
        $this->manually_enrol_user($course, $leaver);

        $enrolrows = [sis_fixtures::enrolment(['USERNAME' => 'STUDENT-001'])];
        $unenrolrows = [sis_fixtures::unenrolment(['USERNAME' => 'STUDENT-002'])];

        $this->assertTrue((new enrollment_sync('sissource_wisa', true))->run($enrolrows));
        $this->assertTrue((new unenrollment_sync(true))->run($unenrolrows));

        $this->assertFalse($this->user_enrolment($course->id, $student->id));
        $this->assertSame(ENROL_USER_ACTIVE, (int)$this->user_enrolment($course->id, $leaver->id)->status);
        $this->assertNotEmpty($this->get_logs_by_status('dryrun'));
    }

    /**
     * Verify missing unenrolment identities fail without suspending users.
     *
     * @return void
     */
    public function test_unenrolment_rows_missing_required_identities_fail_without_suspending_users(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $student = $this->create_user_with_idnumber('STUDENT-001');
        $this->manually_enrol_user($course, $student);
        $rows = [
        sis_fixtures::unenrolment(['KLAS_ID' => '', 'USERNAME' => 'STUDENT-001']),
        sis_fixtures::unenrolment(['KLAS_ID' => 'WISA-COURSE-001', 'USERNAME' => '']),
        ];
        $stats = new sync_stats();

        $this->assertFalse((new unenrollment_sync(false, $stats))->run($rows));

        $this->assertSame(ENROL_USER_ACTIVE, (int)$this->user_enrolment($course->id, $student->id)->status);
        $this->assertSame(2, $stats->unenrolfail);
        $this->assertSame(0, $stats->unenrolwarn);
    }

    /**
     * Verify caught row exceptions fail the unenrolment tuple.
     *
     * @return void
     */
    public function test_caught_unenrolment_row_exception_marks_tuple_unsuccessful(): void {
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
        $rows = [sis_fixtures::unenrolment(['courseidnumber' => $throwingvalue])];

        $this->assertFalse((new unenrollment_sync(false, $stats))->run($rows));

        $this->assertSame(1, $stats->unenrolfail);
        $this->assertSame(0, $stats->unenrolwarn);
        $failure = $DB->get_record('local_wisa_log', [
            'action' => 'sync_unenrol',
            'status' => 'fail',
        ], '*', MUST_EXIST);
        $this->assertSame('redacted', $failure->objectid);
        $this->assertSame('UNENROLMENT_ROW_PROCESSING_FAILED', $failure->message);
        $this->assertStringNotContainsString('fixture-course', $failure->objectid . $failure->message);
        $this->assertStringNotContainsString('Fixture row conversion failed.', $failure->objectid . $failure->message);
    }
}
