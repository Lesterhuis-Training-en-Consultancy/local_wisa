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
 * AthenaSoft delta filtering tests.
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
 * Tests tuple-local effective-since filtering.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_watermark_test extends source_test_case {
    /**
     * The effective lower bound includes equal timestamps and excludes earlier rows.
     *
     * @return void
     */
    public function test_delta_filtering_is_inclusive_at_the_effective_since_boundary(): void {
        $api = $this->api_with_responses([6 => [
            athenasoft_fixtures::student(['userNummerId' => 1, 'lastUpdatedOn' => '2023-11-14 22:08:20']),
            athenasoft_fixtures::student(['userNummerId' => 2, 'lastUpdatedOn' => '2023-11-14 22:08:19']),
            athenasoft_fixtures::student(['userNummerId' => 3, 'lastUpdatedOn' => null, 'createdOn' => '2023-11-14 22:08:20']),
        ], ]);
        $this->configure_athenasoft_defaults();
        $source = new source($api);
        $registry = source::get_stream_registry();
        $request = source_stream_envelope::build_request($registry['placements'], 'users', 1700000000, false);

        $results = source_stream_envelope::validate_results([$request], $source->fetch_streams([$request]));

        $this->assertSame(1699999700, $request['effective_since']);
        $this->assertSame(['1', '3'], array_column($results['placements:users']['rows'], 'idnumber'));
    }
}
