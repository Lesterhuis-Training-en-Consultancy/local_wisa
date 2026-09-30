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
 * Source-stream request, result, and state tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/source_stream_migration_conflict_setting_store.php');

/**
 * Verifies tuple-local source-stream envelope and state behaviour.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_stream_envelope_test extends \advanced_testcase {
    /**
     * Delta and full requests must preserve the exact watermark contract.
     *
     * @return void
     */
    public function test_build_request_applies_one_inclusive_delta_overlap_and_never_filters_full(): void {
        $delta = source_stream_envelope::build_request($this->delta_descriptor(), 'users', 1700000000, false);
        $initial = source_stream_envelope::build_request($this->delta_descriptor(), 'users', null, false);
        $forced = source_stream_envelope::build_request($this->delta_descriptor(), 'users', 1700000000, true);
        $full = source_stream_envelope::build_request($this->full_descriptor(), 'courses', 1700000000, false);

        $this->assertSame(1700000000, $delta['watermark']);
        $this->assertSame(1699999700, $delta['effective_since']);
        $this->assertNull($initial['watermark']);
        $this->assertNull($initial['effective_since']);
        $this->assertNull($forced['effective_since']);
        $this->assertNull($full['watermark']);
        $this->assertNull($full['effective_since']);
    }

    /**
     * Shared transports retrieve once at the earliest lower bound or in full.
     *
     * @return void
     */
    public function test_transport_lower_bounds_do_not_double_subtract_or_collapse_full_requests(): void {
        $early = source_stream_envelope::build_request($this->delta_descriptor('students'), 'users', 1700000000, false);
        $late = source_stream_envelope::build_request($this->delta_descriptor('teachers'), 'users', 1700000300, false);
        $boundary = source_stream_envelope::build_request($this->delta_descriptor('boundary'), 'users', 1, false);
        $initial = source_stream_envelope::build_request($this->delta_descriptor('initial'), 'users', null, false);
        $fulldescriptor = $this->full_descriptor();
        $fulldescriptor['transport'] = 'query_users';
        $full = source_stream_envelope::build_request($fulldescriptor, 'courses', null, false);

        $this->assertSame(
            ['query_users' => 1699999700],
            source_stream_envelope::transport_lower_bounds([$early, $late])
        );
        $this->assertSame(-299, $boundary['effective_since']);
        $this->assertSame(['query_users' => -299], source_stream_envelope::transport_lower_bounds([$boundary]));
        $this->assertSame(
            ['query_users' => null],
            source_stream_envelope::transport_lower_bounds([$early, $initial])
        );
        $this->assertSame(
            ['query_courses' => null],
            source_stream_envelope::transport_lower_bounds([source_stream_envelope::build_request(
                $this->full_descriptor(),
                'courses',
                null,
                false
            ),
            ])
        );
        $this->assertSame(
            ['query_users' => null],
            source_stream_envelope::transport_lower_bounds([$early, $full])
        );
    }

    /**
     * Result validation preserves only requested tuples and stable redacted failures.
     *
     * @return void
     */
    public function test_validate_results_rejects_missing_duplicate_and_unrequested_tuples_as_malformed(): void {
        $student = source_stream_envelope::build_request($this->delta_descriptor('students'), 'users', 1700000000, false);
        $teacher = source_stream_envelope::build_request($this->delta_descriptor('teachers'), 'users', 1700000000, false);
        $results = [
            source_stream_envelope::build_result('students', 'users', 'query_users', 'success', [['idnumber' => 's1']], ''),
            source_stream_envelope::build_result('students', 'users', 'query_users', 'success', [['idnumber' => 's2']], ''),
            source_stream_envelope::build_result('unknown', 'users', 'query_users', 'failed', [], 'transport_failed'),
        ];

        $validated = source_stream_envelope::validate_results([$student, $teacher], $results);

        $this->assertSame('malformed', $validated['students:users']['status']);
        $this->assertSame('duplicate_result', $validated['students:users']['errorcode']);
        $this->assertSame('malformed', $validated['teachers:users']['status']);
        $this->assertSame('missing_result', $validated['teachers:users']['errorcode']);
        $this->assertArrayNotHasKey('unknown:users', $validated);
    }

    /**
     * Result maps accept their required fields in any source-defined array order.
     *
     * @return void
     */
    public function test_validate_results_accepts_reordered_result_fields(): void {
        $request = source_stream_envelope::build_request($this->delta_descriptor(), 'users', 1700000000, false);
        $result = array_reverse(source_stream_envelope::build_result(
            'students',
            'users',
            'query_users',
            'success',
            [['idnumber' => 's1']],
            ''
        ), true);

        $validated = source_stream_envelope::validate_results([$request], [$result]);

        $this->assertSame($result, $validated['students:users']);
    }

    /**
     * Duplicate request tuples cannot produce a transport call or success outcome.
     *
     * @return void
     */
    public function test_validate_results_marks_duplicate_request_tuples_malformed(): void {
        $request = source_stream_envelope::build_request($this->delta_descriptor(), 'users', 1700000000, false);
        $result = source_stream_envelope::build_result('students', 'users', 'query_users', 'success', [], '');

        $validated = source_stream_envelope::validate_results([$request, $request], [$result]);

        $this->assertSame('malformed', $validated['students:users']['status']);
        $this->assertSame('duplicate_request', $validated['students:users']['errorcode']);
    }

    /**
     * Duplicate tuples collapse while valid siblings remain and unrequested results are rejected.
     *
     * @return void
     */
    public function test_validate_results_collapses_duplicate_requests_and_rejects_unrequested_results(): void {
        $student = source_stream_envelope::build_request($this->delta_descriptor('students'), 'users', 1700000000, false);
        $teacher = source_stream_envelope::build_request($this->delta_descriptor('teachers'), 'users', 1700000000, false);
        $results = [
            source_stream_envelope::build_result('students', 'users', 'query_users', 'success', [['idnumber' => 's1']], ''),
            source_stream_envelope::build_result('teachers', 'users', 'query_users', 'success', [['idnumber' => 't1']], ''),
            source_stream_envelope::build_result('unknown', 'users', 'query_users', 'success', [], ''),
        ];

        $validated = source_stream_envelope::validate_results([$student, $student, $teacher], $results);

        $this->assertSame(['students:users', 'teachers:users'], array_keys($validated));
        $this->assertSame('malformed', $validated['students:users']['status']);
        $this->assertSame('duplicate_request', $validated['students:users']['errorcode']);
        $this->assertSame('success', $validated['teachers:users']['status']);
        $this->assertSame([['idnumber' => 't1']], $validated['teachers:users']['rows']);
        $this->assertArrayNotHasKey('unknown:users', $validated);
    }

    /**
     * Non-string adapter fields remain tuple-local and preserve a successful sibling.
     *
     * @return void
     */
    public function test_validate_results_isolates_non_string_result_fields(): void {
        $student = source_stream_envelope::build_request($this->delta_descriptor('students'), 'users', 1700000000, false);
        $teacher = source_stream_envelope::build_request($this->delta_descriptor('teachers'), 'users', 1700000000, false);
        $teacherresult = source_stream_envelope::build_result(
            'teachers',
            'users',
            'query_users',
            'success',
            [['idnumber' => 't1']],
            ''
        );
        $invalidtuple = source_stream_envelope::build_result('students', 'users', 'query_users', 'success', [], '');
        $invalidtuple['stream'] = new \stdClass();

        $validated = source_stream_envelope::validate_results([$student, $teacher], [$invalidtuple, $teacherresult]);

        $this->assertSame('missing_result', $validated['students:users']['errorcode']);
        $this->assertSame('success', $validated['teachers:users']['status']);
        $this->assertSame([['idnumber' => 't1']], $validated['teachers:users']['rows']);

        $invalidtransport = source_stream_envelope::build_result(
            'students',
            'users',
            'query_users',
            'success',
            [],
            ''
        );
        $invalidtransport['transport'] = new \stdClass();

        $validated = source_stream_envelope::validate_results(
            [$student, $teacher],
            [$invalidtransport, $teacherresult]
        );

        $this->assertSame('invalid_result', $validated['students:users']['errorcode']);
        $this->assertSame('success', $validated['teachers:users']['status']);
        $this->assertSame([['idnumber' => 't1']], $validated['teachers:users']['rows']);
    }

    /**
     * Delta and full state remain tuple-local and dry-runs do not persist changes.
     *
     * @return void
     */
    public function test_state_persists_delta_only_after_success_and_records_full_last_success_separately(): void {
        $this->resetAfterTest();
        $state = new source_stream_state('sissource_fixture');
        $state->record_success('students', 'users', 'delta', 1700000000);
        $state->record_success('courses', 'courses', 'full', 1700000100);
        $state->record_processing('students', 'users', 1700000001);
        $state->record_failure('students', 'users', 'failed', 'transport_failed');
        $state->record_failure('courses', 'courses', 'provisioning-blocked', 'provisioning_blocked', 1700000200, 4);

        $student = $state->get_tuple('students', 'users');
        $course = $state->get_tuple('courses', 'courses');

        $this->assertSame(1700000000, $student['watermark']);
        $this->assertSame('failed', $student['status']);
        $this->assertSame('transport_failed', $student['errorcode']);
        $this->assertSame(1700000000, $student['lastsuccess']);
        $this->assertSame(1700000001, $student['lastattempt']);
        $this->assertSame(0, $student['rowcount']);
        $this->assertNull($course['watermark']);
        $this->assertSame('provisioning-blocked', $course['status']);
        $this->assertSame('provisioning_blocked', $course['errorcode']);
        $this->assertSame(1700000100, $course['lastsuccess']);
        $this->assertSame(1700000200, $course['lastattempt']);
        $this->assertSame(4, $course['rowcount']);

        $dryrun = new source_stream_state('sissource_dryrun', true);
        $dryrun->record_success('students', 'users', 'delta', 1700000200);
        $this->assertNull($dryrun->get_watermark('students', 'users'));
    }

    /**
     * Processing state is tuple-local and does not advance a prior watermark.
     *
     * @return void
     */
    public function test_processing_state_preserves_tuple_watermark_and_last_success(): void {
        $this->resetAfterTest();
        $state = new source_stream_state('sissource_fixture');
        $state->record_success('students', 'users', 'delta', 1700000000, 2);
        $state->record_processing('students', 'users', 1700000300);

        $tuple = $state->get_tuple('students', 'users');

        $this->assertSame(1700000000, $tuple['watermark']);
        $this->assertSame('processing', $tuple['status']);
        $this->assertSame(1700000000, $tuple['lastsuccess']);
        $this->assertSame(1700000300, $tuple['lastattempt']);
        $this->assertSame(2, $tuple['rowcount']);
    }

    /**
     * A failed watermark verification must roll back the complete prior success tuple.
     *
     * @return void
     */
    public function test_success_state_rolls_back_complete_tuple_when_watermark_verification_fails(): void {
        $this->resetAfterTest();
        $component = 'sissource_wisa';
        $prefix = 'stream_enrolments_enrolments_';
        $expected = [
            'watermark' => '1600000000',
            'status' => 'failed',
            'errorcode' => 'transport_failed',
            'lastsuccess' => '1600000010',
            'lastattempt' => '1600000020',
            'rowcount' => '3',
        ];
        $initialvalues = [];
        foreach ($expected as $suffix => $value) {
            $initialvalues[$component . '/' . $prefix . $suffix] = $value;
        }
        $store = new \local_wisa\tests\source_stream_migration_conflict_setting_store(
            $initialvalues,
            'verify-watermark-write'
        );
        $state = new source_stream_state($component, false, $store);

        try {
            $state->record_success('enrolments', 'enrolments', 'delta', 1700000000, 7);
            $this->fail('A failed success-state write must throw a coding exception.');
        } catch (\coding_exception $exception) {
            $this->assertStringContainsString('Unable to persist source stream state.', $exception->getMessage());
        }

        $actual = [];
        foreach (array_keys($expected) as $suffix) {
            $actual[$suffix] = $store->get($component, $prefix . $suffix);
        }
        $this->assertSame($expected, $actual);
        $this->assertSame([
            'write:sissource_wisa:stream_enrolments_enrolments_status:successful',
            'write:sissource_wisa:stream_enrolments_enrolments_errorcode:',
            'write:sissource_wisa:stream_enrolments_enrolments_lastsuccess:1700000000',
            'write:sissource_wisa:stream_enrolments_enrolments_lastattempt:1700000000',
            'write:sissource_wisa:stream_enrolments_enrolments_rowcount:7',
            'write:sissource_wisa:stream_enrolments_enrolments_watermark:1700000000',
        ], $store->operations);
    }

    /**
     * Return a representative delta descriptor.
     *
     * @param string $key Stream key.
     * @return array
     */
    private function delta_descriptor(string $key = 'students'): array {
        return [
            'key' => $key,
            'transport' => 'query_users',
            'phases' => ['users'],
            'watermarkmode' => ['users' => 'delta'],
        ];
    }

    /**
     * Return a representative full descriptor.
     *
     * @return array
     */
    private function full_descriptor(): array {
        return [
            'key' => 'courses',
            'transport' => 'query_courses',
            'phases' => ['courses'],
            'watermarkmode' => ['courses' => 'full'],
        ];
    }
}
