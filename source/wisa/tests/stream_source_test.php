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
 * WISA source-stream registry and fetch tests.
 *
 * @package    sissource_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(dirname(__DIR__, 3) . '/tests/fixtures/wisa_fixtures.php');

use local_wisa\source_stream_envelope;
use local_wisa\source_stream_registry;
use local_wisa\tests\wisa_fixtures;

/**
 * Verifies WISA's static source-stream registry and tuple fetch contract.
 *
 * @package    sissource_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class stream_source_test extends \advanced_testcase {
    /**
     * WISA has five independent delta descriptors and one non-role enrolment tuple.
     *
     * @return void
     */
    public function test_wisa_static_registry_defines_one_delta_enrolment_tuple(): void {
        $registry = source::get_stream_registry();

        $this->assertSame($registry, source_stream_registry::validate($registry));
        $this->assertSame([
            'courses',
            'student_accounts',
            'teacher_accounts',
            'enrolments',
            'unenrolments',
        ], array_keys($registry));
        $this->assertSame(['enrolments'], $registry['enrolments']['phases']);
        $this->assertSame('query_enrolments', $registry['enrolments']['transport']);
        $this->assertSame('delta', $registry['enrolments']['watermarkmode']['enrolments']);
        $this->assertCount(2, $registry['enrolments']['legacyaliases']['enrolments']);
    }

    /**
     * The WISA production adapter exposes only the source-stream contract without role-derived feed routing.
     *
     * @return void
     */
    public function test_wisa_source_production_removes_legacy_fetchers_and_feedtype(): void {
        $contents = file_get_contents(__DIR__ . '/../classes/source.php');

        $this->assertStringContainsString('function get_stream_registry()', $contents);
        $this->assertStringContainsString('function fetch_streams(array $requests)', $contents);
        $legacymethods = [
            'get_courses(', 'get_students(', 'get_teachers(', 'get_enrolments(',
            'get_unenrolments(', 'set_sinds(', 'feedtype',
        ];
        foreach ($legacymethods as $legacy) {
            $this->assertStringNotContainsString($legacy, $contents);
        }
    }

    /**
     * Requested WISA tuples use their declared transport and preserve role tokens only as row data.
     *
     * @return void
     */
    public function test_wisa_fetch_streams_returns_requested_tuples_without_feedtype(): void {
        $api = new class extends api_client {
            /** @var array Transport calls keyed by transport name. */
            public $calls = [];

            /**
             * Avoid loading configuration in this test double.
             *
             * @return void
             */
            public function __construct() {
            }

            /**
             * Return fixture rows without making an HTTP request.
             *
             * @param string $transport WISA transport identifier.
             * @param int|null $effectivesince Effective lower bound.
             * @return array|false Raw source rows.
             */
            public function fetch_transport(string $transport, ?int $effectivesince): array|false {
                $this->calls[] = [$transport, $effectivesince];
                if ($transport === 'query_courses') {
                    return [wisa_fixtures::course()];
                }
                if ($transport === 'query_students') {
                    return [wisa_fixtures::student()];
                }
                if ($transport === 'query_teachers') {
                    return [wisa_fixtures::teacher()];
                }
                if ($transport === 'query_enrolments') {
                    return [
                        wisa_fixtures::enrolment(['ROL' => 'lkr']),
                        wisa_fixtures::enrolment(['ROL' => 'mentor']),
                        wisa_fixtures::enrolment(['ROL' => '']),
                    ];
                }
                if ($transport === 'query_unenrolments') {
                    return [wisa_fixtures::unenrolment()];
                }
                return false;
            }
        };
        $registry = source::get_stream_registry();
        $requests = [];
        foreach ($registry as $descriptor) {
            $phase = $descriptor['phases'][0];
            $requests[] = source_stream_envelope::build_request($descriptor, $phase, 1700000000, false);
        }

        $results = (new source($api))->fetch_streams($requests);
        $validated = source_stream_envelope::validate_results($requests, $results);

        $this->assertCount(5, $api->calls);
        $this->assertSame('success', $validated['courses:courses']['status']);
        $this->assertSame('success', $validated['student_accounts:users']['status']);
        $this->assertSame('success', $validated['teacher_accounts:users']['status']);
        $this->assertSame('success', $validated['enrolments:enrolments']['status']);
        $this->assertSame('success', $validated['unenrolments:unenrolments']['status']);
        $enrolments = $validated['enrolments:enrolments']['rows'];
        $this->assertSame('teacher', $enrolments[0]['role']);
        $this->assertSame('mentor', $enrolments[1]['role']);
        $this->assertSame('student', $enrolments[2]['role']);
        $this->assertArrayNotHasKey('feedtype', $enrolments[0]);
    }

    /**
     * Structurally invalid requests abort the complete batch before a WISA transport is called.
     *
     * @return void
     */
    public function test_wisa_fetch_streams_rejects_structurally_invalid_requests_before_transport(): void {
        $api = new class extends api_client {
            /** @var array Captured transport calls. */
            public $calls = [];

            /**
             * Avoid loading configuration in this test double.
             *
             * @return void
             */
            public function __construct() {
            }

            /**
             * Capture unexpected transport work.
             *
             * @param string $transport WISA transport identifier.
             * @param int|null $effectivesince Effective lower bound.
             * @return array|false Raw source rows.
             */
            public function fetch_transport(string $transport, ?int $effectivesince): array|false {
                $this->calls[] = [$transport, $effectivesince];
                return [];
            }
        };
        $descriptor = source::get_stream_registry()['student_accounts'];
        $valid = source_stream_envelope::build_request($descriptor, 'users', 1700000000, false);
        $invalid = $valid;
        unset($invalid['effective_since']);

        try {
            (new source($api))->fetch_streams([$valid, $invalid]);
            $this->fail('A structurally invalid request must abort the batch.');
        } catch (\coding_exception $exception) {
            $this->assertSame([], $api->calls);
        }
    }

    /**
     * Duplicate valid tuples become one malformed result without WISA transport work.
     *
     * @return void
     */
    public function test_wisa_fetch_streams_preflights_duplicate_tuples_before_transport(): void {
        $api = new class extends api_client {
            /** @var array Captured transport calls. */
            public $calls = [];

            /**
             * Avoid loading configuration in this test double.
             *
             * @return void
             */
            public function __construct() {
            }

            /**
             * Capture unexpected transport work.
             *
             * @param string $transport WISA transport identifier.
             * @param int|null $effectivesince Effective lower bound.
             * @return array|false Raw source rows.
             */
            public function fetch_transport(string $transport, ?int $effectivesince): array|false {
                $this->calls[] = [$transport, $effectivesince];
                return [wisa_fixtures::student()];
            }
        };
        $descriptor = source::get_stream_registry()['student_accounts'];
        $request = source_stream_envelope::build_request($descriptor, 'users', 1700000000, false);

        $results = (new source($api))->fetch_streams([$request, $request]);

        $this->assertSame([], $api->calls);
        $this->assertCount(1, $results);
        $this->assertSame('student_accounts', $results[0]['stream']);
        $this->assertSame('users', $results[0]['phase']);
        $this->assertSame('malformed', $results[0]['status']);
        $this->assertSame('duplicate_request', $results[0]['errorcode']);
    }

    /**
     * A registry-invalid tuple alone is malformed without WISA transport work.
     *
     * @return void
     */
    public function test_wisa_fetch_streams_preflights_registry_invalid_tuple_before_transport(): void {
        $api = new class extends api_client {
            /** @var array Captured transport calls. */
            public $calls = [];

            /**
             * Avoid loading configuration in this test double.
             *
             * @return void
             */
            public function __construct() {
            }

            /**
             * Capture unexpected transport work.
             *
             * @param string $transport WISA transport identifier.
             * @param int|null $effectivesince Effective lower bound.
             * @return array|false Raw source rows.
             */
            public function fetch_transport(string $transport, ?int $effectivesince): array|false {
                $this->calls[] = [$transport, $effectivesince];
                return [];
            }
        };
        $descriptor = source::get_stream_registry()['student_accounts'];
        $invalid = source_stream_envelope::build_request($descriptor, 'users', 1700000000, false);
        $invalid['stream'] = 'teacher_accounts';

        $results = (new source($api))->fetch_streams([$invalid]);

        $this->assertSame([], $api->calls);
        $this->assertCount(1, $results);
        $this->assertSame('malformed', $results[0]['status']);
        $this->assertSame('invalid_request', $results[0]['errorcode']);
    }

    /**
     * A valid tuple sharing an invalid tuple's transport still retrieves once and succeeds.
     *
     * @return void
     */
    public function test_wisa_fetch_streams_fetches_valid_sibling_once_after_preflight(): void {
        $api = new class extends api_client {
            /** @var array Captured transport calls. */
            public $calls = [];

            /**
             * Avoid loading configuration in this test double.
             *
             * @return void
             */
            public function __construct() {
            }

            /**
             * Return the valid sibling's rows and capture the one allowed call.
             *
             * @param string $transport WISA transport identifier.
             * @param int|null $effectivesince Effective lower bound.
             * @return array|false Raw source rows.
             */
            public function fetch_transport(string $transport, ?int $effectivesince): array|false {
                $this->calls[] = [$transport, $effectivesince];
                return [wisa_fixtures::student()];
            }
        };
        $descriptor = source::get_stream_registry()['student_accounts'];
        $valid = source_stream_envelope::build_request($descriptor, 'users', 1700000000, false);
        $invalid = $valid;
        $invalid['stream'] = 'teacher_accounts';

        $results = (new source($api))->fetch_streams([$invalid, $valid]);

        $this->assertSame([['query_students', 1699999700]], $api->calls);
        $this->assertCount(2, $results);
        $this->assertSame('malformed', $results[0]['status']);
        $this->assertSame('invalid_request', $results[0]['errorcode']);
        $this->assertSame('success', $results[1]['status']);
        $this->assertSame('student_accounts', $results[1]['stream']);
        $this->assertSame('users', $results[1]['phase']);
    }

    /**
     * One enrolment tuple preserves aliases, arbitrary roles, and absent roles without role routing.
     *
     * @return void
     */
    public function test_wisa_enrolment_tuple_normalizes_only_compatibility_roles(): void {
        $this->resetAfterTest();
        $api = new class extends api_client {
            /** @var array Captured transport calls. */
            public $calls = [];

            /**
             * Avoid loading configuration in this test double.
             *
             * @return void
             */
            public function __construct() {
            }

            /**
             * Return enrolment rows from the one requested WISA transport.
             *
             * @param string $transport WISA transport identifier.
             * @param int|null $effectivesince Effective lower bound.
             * @return array|false Raw source rows.
             */
            public function fetch_transport(string $transport, ?int $effectivesince): array|false {
                $this->calls[] = [$transport, $effectivesince];
                $withoutrole = wisa_fixtures::enrolment();
                unset($withoutrole['ROL']);
                return [
                    wisa_fixtures::enrolment(['ROL' => 'teacher']),
                    wisa_fixtures::enrolment(['ROL' => 'leraar']),
                    wisa_fixtures::enrolment(['ROL' => 'lkr']),
                    wisa_fixtures::enrolment(['ROL' => 'mentor']),
                    wisa_fixtures::enrolment(['ROL' => '']),
                    $withoutrole,
                ];
            }
        };
        $descriptor = source::get_stream_registry()['enrolments'];
        $request = source_stream_envelope::build_request($descriptor, 'enrolments', 1700000000, false);

        $results = (new source($api))->fetch_streams([$request]);
        $validated = source_stream_envelope::validate_results([$request], $results);
        $rows = $validated['enrolments:enrolments']['rows'];

        $this->assertSame([['query_enrolments', 1699999700]], $api->calls);
        $this->assertSame(['teacher', 'teacher', 'teacher', 'mentor', 'student', 'student'], array_column($rows, 'role'));
        $this->assertSame(['enrolments:enrolments'], array_keys($validated));
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('feedtype', $row);
        }
    }

    /**
     * A mapping failure is local to its requested tuple and does not expose raw source data.
     *
     * @return void
     */
    public function test_wisa_fetch_streams_isolates_tuple_mapping_failures(): void {
        $this->resetAfterTest();
        unset_config('fieldmap', 'sissource_wisa');
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
            /** @var array Captured transport calls. */
            public $calls = [];

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
             * @param string $transport WISA transport identifier.
             * @param int|null $effectivesince Effective lower bound.
             * @return array|false Raw source rows.
             */
            public function fetch_transport(string $transport, ?int $effectivesince): array|false {
                $this->calls[] = [$transport, $effectivesince];
                if ($transport === 'query_students') {
                    return [wisa_fixtures::student(['IDNUMBER' => $this->failingvalue])];
                }
                if ($transport === 'query_teachers') {
                    return [wisa_fixtures::teacher()];
                }
                return false;
            }
        };
        $registry = source::get_stream_registry();
        $requests = [
            source_stream_envelope::build_request($registry['student_accounts'], 'users', 1700000000, false),
            source_stream_envelope::build_request($registry['teacher_accounts'], 'users', 1700000000, false),
        ];

        $results = source_stream_envelope::validate_results($requests, (new source($api))->fetch_streams($requests));

        $this->assertSame('malformed', $results['student_accounts:users']['status']);
        $this->assertSame('mapping_failed', $results['student_accounts:users']['errorcode']);
        $this->assertSame([], $results['student_accounts:users']['rows']);
        $this->assertSame('success', $results['teacher_accounts:users']['status']);
        $this->assertSame('TEACHER-001', $results['teacher_accounts:users']['rows'][0]['idnumber']);
    }
}
