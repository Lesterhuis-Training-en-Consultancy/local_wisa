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
 * AthenaSoft stream row policy tests.
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
 * Tests source-row sanitation through the stream API.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_row_policy_test extends source_test_case {
    /**
     * Stream mapping admits markerless rows and never exposes pasword.
     *
     * @return void
     */
    public function test_markerless_rows_are_admitted_without_sensitive_fields(): void {
        $api = $this->api_with_responses([6 => [athenasoft_fixtures::student([
            'cursist' => athenasoft_fixtures::REMOVE_KEY,
            'pasword' => 'must-not-leak',
        ]), ], ]);
        $this->configure_athenasoft_defaults();
        $source = new source($api);
        $registry = source::get_stream_registry();
        $request = source_stream_envelope::build_request($registry['placements'], 'users', null, false);

        $results = source_stream_envelope::validate_results([$request], $source->fetch_streams([$request]));

        $this->assertSame('420623', $results['placements:users']['rows'][0]['idnumber']);
        $this->assertArrayNotHasKey('pasword', $results['placements:users']['rows'][0]);
    }
}
