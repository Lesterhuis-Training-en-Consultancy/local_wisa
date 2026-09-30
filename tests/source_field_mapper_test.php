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
 * Tests for source-neutral source field mapping.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests source field configuration and mapped-row omission behaviour.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_field_mapper_test extends \advanced_testcase {
    /**
     * Assert valid overrides map configured columns and expose safe provenance.
     *
     * @return void
     */
    public function test_valid_override_maps_column_and_exposes_provenance(): void {
        $mapper = new source_field_mapper(
            'sissource_fixture',
            $this->default_map(),
            '{"user":{"email":"alternate_email"}}'
        );

        $mapped = $mapper->map_record('user', [
            'IDNUMBER' => 'person-1',
            'USERNAME' => 'person-one',
            'FIRSTNAME' => 'Ada',
            'LASTNAME' => 'Lovelace',
            'EMAIL' => 'default@example.invalid',
            'alternate_email' => 'override@example.invalid',
        ], 'person-1');

        $this->assertSame('override@example.invalid', $mapped['email']);
        $this->assertSame('Ada', $mapped['firstname']);
        $this->assertSame([
            'source' => 'alternate_email',
            'defaultsource' => 'EMAIL',
            'overridden' => true,
        ], $mapper->get_effective_map()['user']['email']);
    }

    /**
     * Assert invalid JSON warns and retains default mappings.
     *
     * @return void
     */
    public function test_invalid_json_falls_back_to_defaults_and_warns(): void {
        global $DB;

        $this->resetAfterTest();
        $mapper = new source_field_mapper('sissource_fixture', $this->default_map(), '{not-json}');

        $mapped = $mapper->map_record('user', ['EMAIL' => 'default@example.invalid'], 'person-1');

        $this->assertSame('default@example.invalid', $mapped['email']);
        $this->assertSame('EMAIL', $mapper->get_effective_map()['user']['email']['source']);
        $this->assertTrue($DB->record_exists('local_wisa_log', [
            'action' => 'api_config',
            'status' => 'warning',
        ]));
    }

    /**
     * Assert unknown record types and targets warn without replacing defaults.
     *
     * @return void
     */
    public function test_unknown_record_type_and_target_warn_and_keep_defaults(): void {
        global $DB;

        $this->resetAfterTest();
        $mapper = new source_field_mapper(
            'sissource_fixture',
            $this->default_map(),
            '{"unknown":{"email":"wrong"},"user":{"templatekey":"template","unknown":"wrong"}}'
        );

        $mapped = $mapper->map_record('user', ['EMAIL' => 'default@example.invalid'], 'person-1');

        $this->assertSame('default@example.invalid', $mapped['email']);
        $this->assertSame('EMAIL', $mapper->get_effective_map()['user']['email']['source']);
        $this->assertArrayNotHasKey('templatekey', $mapper->get_effective_map()['user']);
        $this->assertGreaterThanOrEqual(3, $DB->count_records('local_wisa_log', [
            'action' => 'source_map',
            'status' => 'warning',
        ]));
    }

    /**
     * Assert missing configured columns omit only their configured target safely.
     *
     * @return void
     */
    public function test_absent_configured_source_warns_and_omits_only_target(): void {
        global $DB;

        $this->resetAfterTest();
        $mapper = new source_field_mapper(
            'sissource_fixture',
            $this->default_map(),
            '{"user":{"email":"alternate_email"}}'
        );

        $mapped = $mapper->map_record('user', [
            'IDNUMBER' => 'person-42',
            'USERNAME' => 'ada',
            'FIRSTNAME' => 'Ada',
            'LASTNAME' => 'Lovelace',
            'EMAIL' => 'default@example.invalid',
            'private_row_value' => 'must-not-be-logged',
        ], 'person-42');

        $warning = $DB->get_record('local_wisa_log', [
            'action' => 'source_map',
            'status' => 'warning',
        ]);

        $this->assertArrayNotHasKey('email', $mapped);
        $this->assertSame('Ada', $mapped['firstname']);
        $this->assertStringContainsString('sissource_fixture', $warning->message);
        $this->assertStringContainsString('user.email', $warning->message);
        $this->assertStringContainsString('alternate_email', $warning->message);
        $this->assertStringNotContainsString('person-42', $warning->message);
        $this->assertStringNotContainsString('must-not-be-logged', $warning->message);
    }

    /**
     * Assert missing default source columns omit only their target with a safe warning.
     *
     * @return void
     */
    public function test_absent_default_source_warns_and_omits_only_target(): void {
        global $DB;

        $this->resetAfterTest();
        $mapper = new source_field_mapper('sissource_fixture', $this->default_map(), '');

        $mapped = $mapper->map_record('user', [
            'IDNUMBER' => 'person-default-42',
            'USERNAME' => 'ada',
            'FIRSTNAME' => 'Ada',
            'LASTNAME' => 'Lovelace',
            'private_row_value' => 'must-not-be-logged',
        ], 'person-default-42');

        $warning = $DB->get_record('local_wisa_log', [
            'action' => 'source_map',
            'status' => 'warning',
        ]);

        $this->assertArrayNotHasKey('email', $mapped);
        $this->assertSame('Ada', $mapped['firstname']);
        $this->assertStringContainsString('sissource_fixture', $warning->message);
        $this->assertStringContainsString('user.email', $warning->message);
        $this->assertStringContainsString('EMAIL', $warning->message);
        $this->assertStringNotContainsString('person-default-42', $warning->message);
        $this->assertStringNotContainsString('must-not-be-logged', $warning->message);
    }

    /**
     * Assert dynamic profile fields and the course template key are valid mapping targets.
     *
     * @return void
     */
    public function test_profile_field_target_and_course_templatekey_are_mapped(): void {
        $this->resetAfterTest();
        $mapper = new source_field_mapper(
            'sissource_fixture',
            $this->default_map(),
            '{"user":{"profile_field_number_id":"NUMBER_ID"},"course":{"templatekey":"TEMPLATE"}}'
        );

        $mapped = $mapper->map_record('user', ['NUMBER_ID' => 'P-100'], 'person-100');
        $course = $mapper->map_record('course', ['TEMPLATE' => 'course-template'], 'course-100');

        $this->assertSame('P-100', $mapped['profile_field_number_id']);
        $this->assertSame([
            'source' => 'NUMBER_ID',
            'defaultsource' => null,
            'overridden' => true,
        ], $mapper->get_effective_map()['user']['profile_field_number_id']);
        $this->assertSame('course-template', $course['templatekey']);
        $this->assertSame([
            'source' => 'TEMPLATE',
            'defaultsource' => null,
            'overridden' => true,
        ], $mapper->get_effective_map()['course']['templatekey']);
    }

    /**
     * Assert profile field suffixes may begin with a digit but reject punctuation and empties.
     *
     * @return void
     */
    public function test_profile_field_target_accepts_numeric_leading_suffix_only(): void {
        global $DB;

        $this->resetAfterTest();
        $mapper = new source_field_mapper(
            'sissource_fixture',
            $this->default_map(),
            '{"user":{"profile_field_2026_id":"NUMBER_ID","profile_field_bad-name":"BAD","profile_field_":"EMPTY"}}'
        );

        $mapped = $mapper->map_record('user', [
            'IDNUMBER' => 'person-2026',
            'USERNAME' => 'person-2026',
            'FIRSTNAME' => 'Ada',
            'LASTNAME' => 'Lovelace',
            'EMAIL' => 'ada@example.invalid',
            'NUMBER_ID' => 'P-2026',
        ], 'person-2026');

        $this->assertSame('P-2026', $mapped['profile_field_2026_id']);
        $this->assertArrayNotHasKey('profile_field_bad-name', $mapper->get_effective_map()['user']);
        $this->assertArrayNotHasKey('profile_field_', $mapper->get_effective_map()['user']);
        $this->assertSame(2, $DB->count_records('local_wisa_log', [
            'action' => 'source_map',
            'status' => 'warning',
        ]));
    }

    /**
     * Password is not a source-neutral user mapping target.
     *
     * @return void
     */
    public function test_password_target_is_not_a_shared_mapping_target(): void {
        $this->assertFalse(source_field_mapper::is_valid_configuration(
            '{"user":{"password":"PASSWORD"}}'
        ));
    }

    /**
     * Return representative raw source defaults.
     *
     * @return array
     */
    private function default_map(): array {
        return [
            'course' => [
                'idnumber' => 'KLAS_ID',
                'shortname' => 'SHORTNAME',
            ],
            'user' => [
                'idnumber' => 'IDNUMBER',
                'username' => 'USERNAME',
                'firstname' => 'FIRSTNAME',
                'lastname' => 'LASTNAME',
                'email' => 'EMAIL',
            ],
            'enrolment' => [
                'courseidnumber' => 'KLAS_ID',
                'useridnumber' => 'USERNAME',
                'role' => 'ROL',
                'startdate' => 'VAN',
                'enddate' => 'TOT',
            ],
            'unenrolment' => [
                'courseidnumber' => 'KLAS_ID',
                'useridnumber' => 'USERNAME',
            ],
        ];
    }
}
