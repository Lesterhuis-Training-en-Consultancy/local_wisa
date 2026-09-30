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
 * Source adapter tests for sissource_wisa.
 *
 * @package    sissource_wisa
 * @category   test
 * @copyright  2026 Sebsoft
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(dirname(__DIR__, 3) . '/tests/fixtures/wisa_fixtures.php');

use local_wisa\tests\wisa_fixtures;

/**
 * Tests WISA-to-generic source record mapping.
 *
 * @group sissource_wisa
 * @group local_wisa
 * @covers     \sissource_wisa\source
 */
final class source_test extends \advanced_testcase {
    public function test_source_maps_wisa_rows_to_generic_contract(): void {
        $this->resetAfterTest();
        unset_config('fieldmap', 'sissource_wisa');

        $api = new class extends api_client {
            /**
             * Avoid reading config in this test double.
             */
            public function __construct() {
                // Nothing to initialise.
            }

            /**
             * Return raw WISA rows without making an HTTP request.
             *
             * @param string $transport WISA transport identifier.
             * @param int|null $effectivesince Effective lower bound.
             * @return array|false Raw source rows.
             */
            public function fetch_transport(string $transport, ?int $effectivesince): array|false {
                if ($transport === 'query_courses') {
                    return [wisa_fixtures::course()];
                }
                if ($transport === 'query_students') {
                    return [wisa_fixtures::student(['IDNUMBER' => '', 'USERNAME' => 'fallbackuser'])];
                }
                if ($transport === 'query_teachers') {
                    return [wisa_fixtures::teacher()];
                }
                if ($transport === 'query_enrolments') {
                    return [wisa_fixtures::enrolment(['ROL' => 'lkr'])];
                }
                if ($transport === 'query_unenrolments') {
                    return [wisa_fixtures::unenrolment()];
                }
                return false;
            }
        };

        $source = new source($api);

        $this->assertSame([
            'idnumber' => 'WISA-COURSE-001',
            'shortname' => 'WISA C001',
            'fullname' => 'WISA Course 001',
            'startdate' => '2026-09-01',
            'enddate' => '2027-06-30',
            'category' => '',
        ], $this->fetch_rows($source, 'courses', 'courses')[0]);
        $this->assertArrayNotHasKey('templatekey', $this->fetch_rows($source, 'courses', 'courses')[0]);
        $this->assertArrayNotHasKey('templatekey', $source->get_effective_map()['course']);
        $this->assertNotContains([
            'recordtype' => 'course',
            'target' => 'templatekey',
            'source' => 'templatekey',
            'defaultsource' => null,
            'overridden' => false,
            'fallback' => false,
        ], \local_wisa\preview_mapping::get_rows($source));
        $this->assertSame('fallbackuser', $this->fetch_rows($source, 'student_accounts', 'users')[0]['idnumber']);
        $this->assertSame('teacher', $this->fetch_rows($source, 'enrolments', 'enrolments')[0]['role']);
        $this->assertArrayNotHasKey('feedtype', $this->fetch_rows($source, 'enrolments', 'enrolments')[0]);
        $this->assertSame('WISA-COURSE-001', $this->fetch_rows($source, 'unenrolments', 'unenrolments')[0]['courseidnumber']);
    }

    /**
     * WISA retains custom role tokens while normalizing only compatible aliases.
     *
     * @return void
     */
    public function test_enrolment_role_token_is_preserved_without_feedtype(): void {
        $api = new class extends api_client {
            /**
             * Avoid loading API configuration in this test double.
             *
             * @return void
             */
            public function __construct() {
            }

            /**
             * Return custom and blank-role WISA enrolment rows.
             *
             * @param string $transport WISA transport identifier.
             * @param int|null $effectivesince Effective lower bound.
             * @return array|false Raw source rows.
             */
            public function fetch_transport(string $transport, ?int $effectivesince): array|false {
                return [
                    wisa_fixtures::enrolment(['ROL' => 'mentor']),
                    wisa_fixtures::enrolment(['ROL' => '']),
                ];
            }
        };

        $enrolments = $this->fetch_rows(new source($api), 'enrolments', 'enrolments');

        $this->assertSame('mentor', $enrolments[0]['role']);
        $this->assertSame('student', $enrolments[1]['role']);
        $this->assertArrayNotHasKey('feedtype', $enrolments[0]);
    }

    /**
     * Assert WISA fieldmap overrides preserve default mapping and expose provenance.
     *
     * @return void
     */
    public function test_fieldmap_override_maps_user_field_and_keeps_defaults(): void {
        $this->resetAfterTest();
        set_config('fieldmap', '{"user":{"email":"ALT_EMAIL","profile_field_number_id":"NUMBER_ID"}}', 'sissource_wisa');

        $api = new class extends api_client {
            /**
             * Avoid loading API configuration in the source test double.
             *
             * @return void
             */
            public function __construct() {
            }

            /**
             * Return one raw WISA student row without an external request.
             *
             * @param string $transport WISA transport identifier.
             * @param int|null $effectivesince Effective lower bound.
             * @return array|false Raw source rows.
             */
            public function fetch_transport(string $transport, ?int $effectivesince): array|false {
                return [wisa_fixtures::student([
                    'EMAIL' => 'default@example.invalid',
                    'ALT_EMAIL' => 'override@example.invalid',
                    'NUMBER_ID' => 'WISA-100',
                ]), ];
            }
        };

        $source = new source($api);
        $student = $this->fetch_rows($source, 'student_accounts', 'users')[0];
        $effective = $source->get_effective_map();

        $this->assertInstanceOf(\local_wisa\mapping_provider_interface::class, $source);
        $this->assertSame('override@example.invalid', $student['email']);
        $this->assertSame('Sam', $student['firstname']);
        $this->assertSame('WISA-100', $student['profile_field_number_id']);
        $this->assertSame('ALT_EMAIL', $effective['user']['email']['source']);
        $this->assertSame('EMAIL', $effective['user']['email']['defaultsource']);
        $this->assertTrue($effective['user']['email']['overridden']);
    }

    /**
     * Assert WISA maps template keys only from an explicitly configured course column.
     *
     * @return void
     */
    public function test_fieldmap_maps_course_templatekey_only_when_explicitly_configured(): void {
        $this->resetAfterTest();
        set_config('fieldmap', '{"course":{"templatekey":"WISA_TEMPLATE"}}', 'sissource_wisa');

        $api = new class extends api_client {
            /**
             * Avoid loading API configuration in the source test double.
             *
             * @return void
             */
            public function __construct() {
            }

            /**
             * Return a raw WISA course row with a configured template source column.
             *
             * @param string $transport WISA transport identifier.
             * @param int|null $effectivesince Effective lower bound.
             * @return array|false Raw source rows.
             */
            public function fetch_transport(string $transport, ?int $effectivesince): array|false {
                return [wisa_fixtures::course(['WISA_TEMPLATE' => '  WISA-template  '])];
            }
        };

        $source = new source($api);
        $course = $this->fetch_rows($source, 'courses', 'courses')[0];
        $preview = \local_wisa\preview_mapping::get_rows($source);

        $this->assertSame('WISA-template', $course['templatekey']);
        $this->assertSame([
            'source' => 'WISA_TEMPLATE',
            'defaultsource' => null,
            'overridden' => true,
        ], $source->get_effective_map()['course']['templatekey']);
        $this->assertContains([
            'recordtype' => 'course',
            'target' => 'templatekey',
            'source' => 'WISA_TEMPLATE',
            'defaultsource' => null,
            'overridden' => true,
            'fallback' => false,
        ], $preview);
    }

    /**
     * Assert an absent configured WISA template source column warns and omits only templatekey.
     *
     * @return void
     */
    public function test_missing_configured_templatekey_column_warns_and_omits_templatekey(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('fieldmap', '{"course":{"templatekey":"WISA_TEMPLATE"}}', 'sissource_wisa');

        $api = new class extends api_client {
            /**
             * Avoid loading API configuration in the source test double.
             *
             * @return void
             */
            public function __construct() {
            }

            /**
             * Return a raw WISA course row without the configured template source column.
             *
             * @param string $transport WISA transport identifier.
             * @param int|null $effectivesince Effective lower bound.
             * @return array|false Raw source rows.
             */
            public function fetch_transport(string $transport, ?int $effectivesince): array|false {
                return [wisa_fixtures::course(['PRIVATE_VALUE' => 'must-not-be-logged'])];
            }
        };

        $course = $this->fetch_rows(new source($api), 'courses', 'courses')[0];
        $warning = $DB->get_record('local_wisa_log', ['action' => 'source_map', 'status' => 'warning']);

        $this->assertArrayNotHasKey('templatekey', $course);
        $this->assertSame('WISA-COURSE-001', $course['idnumber']);
        $this->assertStringContainsString('sissource_wisa', $warning->message);
        $this->assertStringContainsString('course.templatekey', $warning->message);
        $this->assertStringContainsString('WISA_TEMPLATE', $warning->message);
        $this->assertStringNotContainsString('must-not-be-logged', $warning->message);
    }

    /**
     * Fetch rows for one requested WISA source-stream tuple.
     *
     * @param source $source WISA source adapter.
     * @param string $stream Stream key.
     * @param string $phase Generic phase.
     * @return array Mapped tuple rows.
     */
    private function fetch_rows(source $source, string $stream, string $phase): array {
        $descriptor = source::get_stream_registry()[$stream];
        $request = \local_wisa\source_stream_envelope::build_request($descriptor, $phase, null, false);
        $results = $source->fetch_streams([$request]);
        return $results[0]['rows'];
    }
}
