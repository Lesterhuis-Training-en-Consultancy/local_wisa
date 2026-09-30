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
 * Source-stream sync manager contract tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once(__DIR__ . '/../db/upgrade.php');
require_once($CFG->libdir . '/upgradelib.php');
require_once(__DIR__ . '/fixtures/sync_testcase.php');
require_once(__DIR__ . '/fixtures/sync_manager_test_case.php');

use local_wisa\tests\fake_api_client;
use local_wisa\tests\sis_fixtures;

/**
 * Verifies registry-driven source-stream execution.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sync_manager_test extends sync_manager_test_case {
    /**
     * A successful tuple advances only its source-scoped watermark.
     *
     * @return void
     */
    public function test_successful_tuple_advances_only_its_own_source_scoped_watermark(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->disable_except(['student_accounts:users']);
        set_config('stream_student_accounts_users_watermark', 1700000000, 'sissource_wisa');
        set_config('stream_teacher_accounts_users_watermark', 1600000000, 'sissource_wisa');
        $source = new fake_api_client();

        $this->assertTrue((new sync_manager($source, null, 'sissource_wisa'))->run_full_sync());

        $this->assertSame(1699999700, $source->requests[0][0]['effective_since']);
        $this->assertGreaterThan(1700000000, (int)get_config('sissource_wisa', 'stream_student_accounts_users_watermark'));
        $this->assertSame(1600000000, (int)get_config('sissource_wisa', 'stream_teacher_accounts_users_watermark'));
        $this->assertSame(['student_accounts:users'], $source->calls);
    }

    /**
     * Failed tuples keep their own state while successful siblings advance.
     *
     * @return void
     */
    public function test_partial_failure_preserves_only_the_failed_tuple_watermark(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->disable_except(['student_accounts:users', 'teacher_accounts:users']);
        set_config('stream_student_accounts_users_watermark', 1000, 'sissource_wisa');
        set_config('stream_teacher_accounts_users_watermark', 1000, 'sissource_wisa');
        $source = new fake_api_client([], ['teacher_accounts' => true]);

        $this->assertFalse((new sync_manager($source, null, 'sissource_wisa'))->run_full_sync());

        $this->assertGreaterThan(1000, (int)get_config('sissource_wisa', 'stream_student_accounts_users_watermark'));
        $this->assertSame(1000, (int)get_config('sissource_wisa', 'stream_teacher_accounts_users_watermark'));
        $this->assertSame('successful', get_config('sissource_wisa', 'stream_student_accounts_users_status'));
        $this->assertSame('failed', get_config('sissource_wisa', 'stream_teacher_accounts_users_status'));
    }

    /**
     * Course and user row failures retain only their tuple watermarks while a sibling advances.
     *
     * @return void
     */
    public function test_course_and_user_row_failures_preserve_only_failed_tuple_watermarks(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->disable_except(['courses:courses', 'student_accounts:users', 'teacher_accounts:users']);
        set_config('stream_courses_courses_watermark', 1000, 'sissource_wisa');
        set_config('stream_student_accounts_users_watermark', 1000, 'sissource_wisa');
        set_config('stream_teacher_accounts_users_watermark', 1000, 'sissource_wisa');
        $source = new fake_api_client([
            'courses' => [sis_fixtures::course(['KLAS_ID' => ''])],
            'student_accounts' => [sis_fixtures::student(['IDNUMBER' => '', 'USERNAME' => ''])],
        ]);

        $this->assertFalse((new sync_manager($source, null, 'sissource_wisa'))->run_full_sync());

        $this->assertSame(1000, (int)get_config('sissource_wisa', 'stream_courses_courses_watermark'));
        $this->assertSame('failed', get_config('sissource_wisa', 'stream_courses_courses_status'));
        $this->assertSame(1000, (int)get_config('sissource_wisa', 'stream_student_accounts_users_watermark'));
        $this->assertSame('failed', get_config('sissource_wisa', 'stream_student_accounts_users_status'));
        $this->assertGreaterThan(1000, (int)get_config('sissource_wisa', 'stream_teacher_accounts_users_watermark'));
        $this->assertSame('successful', get_config('sissource_wisa', 'stream_teacher_accounts_users_status'));
    }

    /**
     * A row-processing failure retains only its tuple watermark while a sibling advances.
     *
     * @return void
     */
    public function test_row_processing_failure_preserves_only_failed_tuple_watermark(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->disable_except(['student_accounts:users', 'enrolments:enrolments']);
        set_config('stream_student_accounts_users_watermark', 1000, 'sissource_wisa');
        set_config('stream_enrolments_enrolments_watermark', 1000, 'sissource_wisa');
        $source = new fake_api_client([
            'enrolments' => [sis_fixtures::enrolment(['courseidnumber' => ''])],
        ]);

        $this->assertFalse((new sync_manager($source, null, 'sissource_wisa'))->run_full_sync());

        $this->assertGreaterThan(1000, (int)get_config('sissource_wisa', 'stream_student_accounts_users_watermark'));
        $this->assertSame(1000, (int)get_config('sissource_wisa', 'stream_enrolments_enrolments_watermark'));
    }

    /**
     * Dry runs make requests without writing tuple state or initial-load approval.
     *
     * @return void
     */
    public function test_dry_run_fetches_enabled_tuple_without_persisting_state(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->disable_except(['courses:courses']);
        set_config('dry_run', 1, 'local_wisa');
        set_config('stream_courses_courses_watermark', 1000, 'sissource_wisa');
        set_config('last_run_time', 111, 'local_wisa');
        set_config('last_run_summary', 'sentinel-summary', 'local_wisa');
        set_config('last_run_dryrun', 7, 'local_wisa');
        set_config('last_run_status', 'sentinel-status', 'local_wisa');
        $source = new fake_api_client();

        $this->assertTrue((new sync_manager($source, null, 'sissource_wisa'))->run_full_sync());

        $this->assertSame(['courses:courses'], $source->calls);
        $this->assertSame(1000, (int)get_config('sissource_wisa', 'stream_courses_courses_watermark'));
        $this->assertFalse(get_config('sissource_wisa', 'stream_courses_courses_status'));
        $this->assertNotSame('1', get_config('local_wisa', 'initial_load_done'));
        $this->assertSame('111', get_config('local_wisa', 'last_run_time'));
        $this->assertSame('sentinel-summary', get_config('local_wisa', 'last_run_summary'));
        $this->assertSame('7', get_config('local_wisa', 'last_run_dryrun'));
        $this->assertSame('sentinel-status', get_config('local_wisa', 'last_run_status'));
    }

    /**
     * Disabled tuples make no adapter request and do not approve initial load.
     *
     * @return void
     */
    public function test_no_enabled_tuple_makes_no_request_and_keeps_initial_gate_closed(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->disable_except([]);
        $source = new fake_api_client();

        $this->assertTrue((new sync_manager($source, null, 'sissource_wisa'))->run_full_sync());

        $this->assertSame([], $source->calls);
        $this->assertNotSame('1', get_config('local_wisa', 'initial_load_done'));
    }

    /**
     * An unresolved WISA enrolments conflict blocks only its request despite a tampered checkbox.
     *
     * @return void
     */
    public function test_unresolved_wisa_enrolments_conflict_skips_only_enrolments_request(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->disable_except(['student_accounts:users', 'enrolments:enrolments']);
        set_config(source_stream_migrator::CONFLICT_SNAPSHOT, json_encode([[
            'component' => 'sissource_wisa',
            'stream' => 'enrolments',
            'phase' => 'enrolments',
            'status' => 'requires_admin_resolution',
            'aliases' => ['enrol_students' => true, 'enrol_teachers' => false],
            'watermarks' => ['enrol_students' => 1700000000, 'enrol_teachers' => 1700000300],
        ], ]), 'sissource_wisa');
        $source = new fake_api_client();

        $this->assertTrue((new sync_manager($source, null, 'sissource_wisa'))->run_full_sync());

        $this->assertSame(['student_accounts:users'], $source->calls);
        $this->assertSame(['query_students'], array_column($source->requests[0], 'transport'));
    }

    /**
     * Force-full removes the lower bound without changing tuple identity.
     *
     * @return void
     */
    public function test_force_full_uses_a_null_delta_lower_bound_for_the_requested_tuple(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->disable_except(['courses:courses']);
        set_config('stream_courses_courses_watermark', 1700000000, 'sissource_wisa');
        $source = new fake_api_client();

        $this->assertTrue((new sync_manager($source, null, 'sissource_wisa'))->run_full_sync(true));

        $this->assertNull($source->requests[0][0]['effective_since']);
        $this->assertSame('courses', $source->requests[0][0]['stream']);
        $this->assertSame('courses', $source->requests[0][0]['phase']);
    }

    /**
     * Configured school-year scope skips out-of-window course and enrolment rows.
     *
     * @return void
     */
    public function test_configured_schoolyear_scope_skips_out_of_window_course_and_enrolment_rows(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->disable_except(['courses:courses', 'enrolments:enrolments']);
        set_config('schoolyear_scope', 'current', 'local_wisa');
        $course = $this->create_course_with_idnumber('OUTSIDE-ENROLMENT-COURSE');
        $user = $this->create_user_with_idnumber('OUTSIDE-ENROLMENT-USER');
        $outsideyear = (int)date('Y') - 3;
        $source = new fake_api_client([
            'courses' => [sis_fixtures::course([
                'idnumber' => 'OUTSIDE-COURSE',
                'shortname' => 'OUTSIDE-COURSE',
                'startdate' => $outsideyear . '-09-01',
                'enddate' => ($outsideyear + 1) . '-08-31',
            ]), ],
            'enrolments' => [sis_fixtures::enrolment([
                'courseidnumber' => $course->idnumber,
                'useridnumber' => $user->idnumber,
                'startdate' => $outsideyear . '-09-01',
                'enddate' => ($outsideyear + 1) . '-08-31',
            ]), ],
        ]);

        $this->assertTrue((new sync_manager($source, null, 'sissource_wisa'))->run_full_sync());

        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'OUTSIDE-COURSE']));
        $this->assertFalse($this->user_enrolment($course->id, $user->id));
    }

    /**
     * Enable only the requested tuple keys on the generic fixture registry.
     *
     * @param array $enabled Tuple keys in stream:phase form.
     * @return void
     */
    private function disable_except(array $enabled): void {
        foreach (fake_api_client::get_stream_registry() as $descriptor) {
            foreach ($descriptor['phases'] as $phase) {
                $tuple = $descriptor['key'] . ':' . $phase;
                set_config(
                    'stream_' . $descriptor['key'] . '_' . $phase . '_enabled',
                    in_array($tuple, $enabled, true) ? 1 : 0,
                    'sissource_wisa'
                );
            }
        }
    }
}
