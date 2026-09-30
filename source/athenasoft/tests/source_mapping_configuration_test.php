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
 * AthenaSoft stream mapping configuration tests.
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
use sissource_athenasoft\tests\athenasoft_fixtures;

/**
 * Tests configured mapping through tuple fetches.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_mapping_configuration_test extends source_test_case {
    /**
     * Field-map overrides apply independently to user and enrolment tuples.
     *
     * @return void
     */
    public function test_fieldmap_override_maps_user_and_enrolment_targets(): void {
        $api = $this->api_with_responses([6 => [athenasoft_fixtures::student([
            'number_id' => 'N-420623',
            'mapped_start' => '2026-09-01',
            'mapped_end' => '2027-06-30',
            'mapped_role' => 'editingteacher',
        ]), ], ]);
        $this->configure_athenasoft_defaults([
            'fieldmap' => json_encode([
                'user' => ['profile_field_number_id' => 'number_id'],
                'enrolment' => [
                    'startdate' => 'mapped_start',
                    'enddate' => 'mapped_end',
                    'role' => 'mapped_role',
                ],
            ]),
        ]);
        $source = new source($api);
        $registry = source::get_stream_registry();
        $requests = [
            source_stream_envelope::build_request($registry['placements'], 'users', null, false),
            source_stream_envelope::build_request($registry['placements'], 'enrolments', null, false),
        ];

        $results = source_stream_envelope::validate_results($requests, $source->fetch_streams($requests));

        $this->assertSame('N-420623', $results['placements:users']['rows'][0]['profile_field_number_id']);
        $this->assertSame('2026-09-01', $results['placements:enrolments']['rows'][0]['startdate']);
        $this->assertSame('editingteacher', $results['placements:enrolments']['rows'][0]['role']);
        $this->assertTrue($source->get_effective_map()['user']['profile_field_number_id']['overridden']);
    }

    /**
     * Raw fields remain available to configured mappings regardless of password-like names.
     *
     * @return void
     */
    public function test_fieldmap_can_use_legacy_password_spelling_as_an_ordinary_source(): void {
        $api = $this->api_with_responses([5 => [athenasoft_fixtures::course([
            'pasword' => 'Configured shortname',
        ])]]);
        $this->configure_athenasoft_defaults([
            'fieldmap' => '{"course":{"shortname":"pasword"}}',
        ]);
        $source = new source($api);
        $registry = source::get_stream_registry();
        $requests = [
            source_stream_envelope::build_request($registry['courses'], 'courses', null, false),
        ];

        $results = source_stream_envelope::validate_results($requests, $source->fetch_streams($requests));

        $this->assertSame('Configured shortname', $results['courses:courses']['rows'][0]['shortname']);
    }
}
