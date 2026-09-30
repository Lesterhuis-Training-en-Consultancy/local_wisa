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
 * AthenaSoft source stream contract tests.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/source_test_case.php');

use local_wisa\source_stream_envelope;
use local_wisa\source_stream_registry;
use sissource_athenasoft\tests\athenasoft_fixtures;

/**
 * Verifies the AthenaSoft stream registry and tuple fetch API.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_stream_test extends source_test_case {
    /**
     * Every descriptor label is available from the adapter in English and Dutch.
     *
     * @return void
     */
    public function test_registry_descriptor_labels_exist_in_english_and_dutch(): void {
        $labels = array_column(source::get_stream_registry(), 'label');
        foreach (['en', 'nl'] as $language) {
            $string = [];
            require(__DIR__ . '/../lang/' . $language . '/sissource_athenasoft.php');
            foreach ($labels as $label) {
                $this->assertArrayHasKey($label, $string, $language . ': ' . $label);
                $this->assertNotSame('', trim($string[$label]), $language . ': ' . $label);
            }
        }
    }

    /**
     * The static registry describes script 5 as full and scripts 6 and 7 as independent deltas.
     *
     * @return void
     */
    public function test_static_registry_declares_full_courses_and_independent_delta_transports(): void {
        $registry = source::get_stream_registry();

        $this->assertSame(['courses', 'placements', 'linked_teachers'], array_keys($registry));
        $this->assertSame('script_courses', $registry['courses']['transport']);
        $this->assertSame(['courses' => 'full'], $registry['courses']['watermarkmode']);
        $this->assertSame('script_placements', $registry['placements']['transport']);
        $this->assertSame(
            ['users' => 'delta', 'enrolments' => 'delta', 'unenrolments' => 'delta'],
            $registry['placements']['watermarkmode']
        );
        $this->assertSame('script_teachers', $registry['linked_teachers']['transport']);
        $this->assertSame(['users' => 'delta', 'enrolments' => 'delta'], $registry['linked_teachers']['watermarkmode']);
        $this->assertSame($registry, source_stream_registry::validate($registry));
    }

    /**
     * Script 5 is always fetched in full mode and maps the requested course tuple.
     *
     * @return void
     */
    public function test_fetch_streams_returns_full_course_result_without_a_watermark(): void {
        $api = $this->api_with_responses([5 => [athenasoft_fixtures::course()]]);
        $this->configure_athenasoft_defaults();
        $source = new source($api);
        $registry = source::get_stream_registry();
        $request = source_stream_envelope::build_request($registry['courses'], 'courses', 1700000000, false);

        $results = $source->fetch_streams([$request]);

        $this->assertSame([5], $api->scriptids);
        $this->assertSame('success', $results[0]['status']);
        $this->assertSame('courses', $results[0]['stream']);
        $this->assertSame('courses', $results[0]['phase']);
        $this->assertSame('AS-39222-56', $results[0]['rows'][0]['idnumber']);
        $this->assertNull($request['watermark']);
        $this->assertNull($request['effective_since']);
    }

    /**
     * Markerless requested rows are admitted by their independent student and teacher transports.
     *
     * @return void
     */
    public function test_fetch_streams_admits_markerless_rows_for_requested_delta_transports(): void {
        $api = $this->api_with_responses([
            6 => [athenasoft_fixtures::student(['cursist' => athenasoft_fixtures::REMOVE_KEY])],
            7 => [athenasoft_fixtures::teacher(['leerkracht' => athenasoft_fixtures::REMOVE_KEY])],
        ]);
        $this->configure_athenasoft_defaults();
        $source = new source($api);
        $registry = source::get_stream_registry();
        $requests = [
            source_stream_envelope::build_request($registry['placements'], 'users', 1700000000, false),
            source_stream_envelope::build_request($registry['linked_teachers'], 'users', 1700000000, false),
        ];

        $results = source_stream_envelope::validate_results($requests, $source->fetch_streams($requests));

        $this->assertSame([6, 7], $api->scriptids);
        $this->assertSame('420623', $results['placements:users']['rows'][0]['idnumber']);
        $this->assertSame('428449', $results['linked_teachers:users']['rows'][0]['idnumber']);
    }

    /**
     * Enrolment roles are stream-defined and no legacy feed classification leaks into rows.
     *
     * @return void
     */
    public function test_fetch_streams_returns_independent_roles_without_feedtype(): void {
        $api = $this->api_with_responses([
            6 => [athenasoft_fixtures::student([
                'cursist' => athenasoft_fixtures::REMOVE_KEY,
                'mapped_role' => 'arbitrary_source_role',
            ]), ],
            7 => [athenasoft_fixtures::teacher([
                'leerkracht' => athenasoft_fixtures::REMOVE_KEY,
                'mapped_role' => 'another_source_role',
            ]), ],
        ]);
        $this->configure_athenasoft_defaults([
            'fieldmap' => '{"enrolment":{"role":"mapped_role"}}',
        ]);
        $source = new source($api);
        $registry = source::get_stream_registry();
        $requests = [
            source_stream_envelope::build_request($registry['placements'], 'enrolments', 1700000000, false),
            source_stream_envelope::build_request($registry['linked_teachers'], 'enrolments', 1700000000, false),
        ];

        $results = source_stream_envelope::validate_results($requests, $source->fetch_streams($requests));

        $student = $results['placements:enrolments']['rows'][0];
        $teacher = $results['linked_teachers:enrolments']['rows'][0];
        $this->assertSame('arbitrary_source_role', $student['role']);
        $this->assertSame('another_source_role', $teacher['role']);
        $this->assertArrayNotHasKey('feedtype', $student);
        $this->assertArrayNotHasKey('feedtype', $teacher);
    }

    /**
     * One failed transport cannot hide another transport's successful tuple result.
     *
     * @return void
     */
    public function test_fetch_streams_isolates_partial_transport_failures(): void {
        $api = $this->api_with_responses([6 => false, 7 => [athenasoft_fixtures::teacher()]]);
        $this->configure_athenasoft_defaults();
        $source = new source($api);
        $registry = source::get_stream_registry();
        $requests = [
            source_stream_envelope::build_request($registry['placements'], 'users', 1700000000, false),
            source_stream_envelope::build_request($registry['linked_teachers'], 'users', 1700000000, false),
        ];

        $results = source_stream_envelope::validate_results($requests, $source->fetch_streams($requests));

        $this->assertSame('failed', $results['placements:users']['status']);
        $this->assertSame('transport_failed', $results['placements:users']['errorcode']);
        $this->assertSame('success', $results['linked_teachers:users']['status']);
        $this->assertSame('428449', $results['linked_teachers:users']['rows'][0]['idnumber']);
    }

    /**
     * A failed linked-teacher transport must not discard placement rows.
     *
     * @return void
     */
    public function test_fetch_streams_isolates_reverse_partial_transport_failures(): void {
        $api = $this->api_with_responses([6 => [athenasoft_fixtures::student()], 7 => false]);
        $this->configure_athenasoft_defaults();
        $source = new source($api);
        $registry = source::get_stream_registry();
        $requests = [
            source_stream_envelope::build_request($registry['placements'], 'users', 1700000000, false),
            source_stream_envelope::build_request($registry['linked_teachers'], 'users', 1700000000, false),
        ];

        $results = source_stream_envelope::validate_results($requests, $source->fetch_streams($requests));

        $this->assertSame('success', $results['placements:users']['status']);
        $this->assertSame('420623', $results['placements:users']['rows'][0]['idnumber']);
        $this->assertSame('failed', $results['linked_teachers:users']['status']);
        $this->assertSame('transport_failed', $results['linked_teachers:users']['errorcode']);
    }

    /**
     * One placement transport response serves valid phases despite an invalid sibling tuple.
     *
     * @return void
     */
    public function test_fetch_streams_fetches_shared_placement_transport_once(): void {
        $api = $this->api_with_responses([6 => [athenasoft_fixtures::student([
            'uitschrijvingsdatum' => '2026-10-01',
        ]), ], ]);
        $this->configure_athenasoft_defaults();
        $source = new source($api);
        $registry = source::get_stream_registry();
        $invalid = source_stream_envelope::build_request($registry['placements'], 'users', 1700000000, false);
        $invalid['stream'] = 'linked_teachers';
        $requests = [
            $invalid,
            source_stream_envelope::build_request($registry['placements'], 'users', 1700000000, false),
            source_stream_envelope::build_request($registry['placements'], 'enrolments', 1700000000, false),
            source_stream_envelope::build_request($registry['placements'], 'unenrolments', 1700000000, false),
        ];

        $results = source_stream_envelope::validate_results($requests, $source->fetch_streams($requests));

        $this->assertSame([6], $api->scriptids);
        $this->assertSame('malformed', $results['linked_teachers:users']['status']);
        $this->assertSame('invalid_request', $results['linked_teachers:users']['errorcode']);
        $this->assertCount(1, $results['placements:users']['rows']);
        $this->assertCount(1, $results['placements:enrolments']['rows']);
        $this->assertCount(1, $results['placements:unenrolments']['rows']);
    }

    /**
     * Duplicate valid tuples become one malformed result without AthenaSoft transport work.
     *
     * @return void
     */
    public function test_fetch_streams_preflights_duplicate_tuples_before_transport(): void {
        $api = $this->api_with_responses([6 => [athenasoft_fixtures::student()], 7 => [athenasoft_fixtures::teacher()]]);
        $this->configure_athenasoft_defaults();
        $source = new source($api);
        $registry = source::get_stream_registry();
        $request = source_stream_envelope::build_request($registry['placements'], 'users', 1700000000, false);

        $results = $source->fetch_streams([$request, $request]);

        $this->assertSame([], $api->scriptids);
        $this->assertCount(1, $results);
        $this->assertSame('malformed', $results[0]['status']);
        $this->assertSame('duplicate_request', $results[0]['errorcode']);
    }

    /**
     * Full AthenaSoft tuples with delta values must be rejected before any transport call.
     *
     * @return void
     */
    public function test_fetch_streams_rejects_full_tuples_with_delta_values_before_transport_calls(): void {
        $api = $this->api_with_responses([5 => [athenasoft_fixtures::course()]]);
        $this->configure_athenasoft_defaults();
        $source = new source($api);
        $registry = source::get_stream_registry();
        $request = source_stream_envelope::build_request($registry['courses'], 'courses', null, false);
        $request['watermark'] = 1700000000;
        $request['effective_since'] = 1699999700;

        $this->expectException(\coding_exception::class);
        try {
            $source->fetch_streams([$request]);
        } finally {
            $this->assertSame([], $api->scriptids);
        }
    }

    /**
     * A mapping failure is local to its requested tuple and does not expose raw source data.
     *
     * @return void
     */
    public function test_fetch_streams_isolates_tuple_mapping_failures(): void {
        $failingvalue = new class {
            /**
             * Fail only when the mapper tries to consume the raw value.
             *
             * @return string
             */
            public function __toString(): string {
                throw new \RuntimeException('Mapping failure must be tuple-local.');
            }
        };
        $api = new class ($failingvalue) extends api_client {
            /** @var array Captured script IDs. */
            public $scriptids = [];

            /** @var object Raw value that fails during mapping. */
            private $failingvalue;

            /**
             * Avoid loading configuration in this test double.
             *
             * @param object $failingvalue Raw value that fails during mapping.
             * @return void
             */
            public function __construct(object $failingvalue) {
                $this->failingvalue = $failingvalue;
            }

            /**
             * Return one failing and one successful transport response without HTTP.
             *
             * @param int $scriptid AthenaSoft script ID.
             * @return array|false Raw source rows.
             */
            public function fetch(int $scriptid) {
                $this->scriptids[] = $scriptid;
                if ($scriptid === 6) {
                    return [[
                        'userNummerId' => $this->failingvalue,
                    ], ];
                }
                if ($scriptid === 7) {
                    return [athenasoft_fixtures::teacher()];
                }
                return false;
            }
        };
        $this->configure_athenasoft_defaults();
        $source = new source($api);
        $registry = source::get_stream_registry();
        $requests = [
            source_stream_envelope::build_request($registry['placements'], 'users', 1700000000, false),
            source_stream_envelope::build_request($registry['linked_teachers'], 'users', 1700000000, false),
        ];

        $results = source_stream_envelope::validate_results($requests, $source->fetch_streams($requests));

        $this->assertSame('malformed', $results['placements:users']['status']);
        $this->assertSame('mapping_failed', $results['placements:users']['errorcode']);
        $this->assertSame([], $results['placements:users']['rows']);
        $this->assertSame('success', $results['linked_teachers:users']['status']);
        $this->assertSame('428449', $results['linked_teachers:users']['rows'][0]['idnumber']);
    }

    /**
     * Active source files expose only the stream contract, not getter-era APIs.
     *
     * @return void
     */
    public function test_active_source_files_have_no_legacy_runtime_apis(): void {
        $classespath = __DIR__ . '/../classes';
        $contents = '';
        foreach (glob($classespath . '/*.php') as $path) {
            $contents .= (string)file_get_contents($path);
        }

        $legacyfunctionpattern = '/function\s+(?:get_courses|get_students|get_teachers|get_enrolments|'
            . 'get_unenrolments|get_enrolment_feed_status|set_sinds)\s*\(/';
        $this->assertDoesNotMatchRegularExpression(
            $legacyfunctionpattern,
            $contents
        );
        $this->assertDoesNotMatchRegularExpression('/\bfeed(?:type|_status)\b/', $contents);
    }
}
