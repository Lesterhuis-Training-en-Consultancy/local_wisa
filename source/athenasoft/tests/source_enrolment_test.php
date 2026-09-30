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
 * AthenaSoft enrolment stream tests.
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
 * Tests independently requested enrolment and unenrolment tuples.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_enrolment_test extends source_test_case {
    /**
     * Script 6 applies fallback roles and inclusive delta filtering to one shared response.
     *
     * @return void
     */
    public function test_placement_enrolment_tuples_apply_fallback_roles_and_inclusive_delta_filtering(): void {
        $api = $this->api_with_responses([6 => [
            athenasoft_fixtures::student([
                'uitschrijvingsdatum' => null,
                'lastUpdatedOn' => '2023-11-14 22:08:20',
                'cursist' => athenasoft_fixtures::REMOVE_KEY,
            ]),
            athenasoft_fixtures::student([
                'userNummerId' => 425911,
                'uitschrijvingsdatum' => '2026-10-01',
                'lastUpdatedOn' => '2023-11-14 22:08:20',
            ]),
            athenasoft_fixtures::student([
                'userNummerId' => 425912,
                'uitschrijvingsdatum' => '2026-10-02',
                'lastUpdatedOn' => '2023-11-14 22:08:19',
            ]),
        ], ]);
        $this->configure_athenasoft_defaults();
        $source = new source($api);
        $registry = source::get_stream_registry();
        $requests = [
            source_stream_envelope::build_request($registry['placements'], 'enrolments', 1700000000, false),
            source_stream_envelope::build_request($registry['placements'], 'unenrolments', 1700000000, false),
        ];

        $results = source_stream_envelope::validate_results($requests, $source->fetch_streams($requests));

        $this->assertSame([6], $api->scriptids);
        $this->assertCount(2, $results['placements:enrolments']['rows']);
        $this->assertSame('425911', $results['placements:unenrolments']['rows'][0]['useridnumber']);
        $this->assertCount(1, $results['placements:unenrolments']['rows']);
        $this->assertArrayNotHasKey('role', $results['placements:enrolments']['rows'][0]);
        $this->assertSame('cursist', $results['placements:enrolments']['rows'][1]['role']);
    }
}
