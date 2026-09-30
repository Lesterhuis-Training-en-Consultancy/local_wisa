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
 * AthenaSoft course stream tests.
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
 * Tests the script 5 full course stream.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_course_test extends source_test_case {
    /**
     * Script 5 supplies a full, unbounded course stream.
     *
     * @return void
     */
    public function test_courses_stream_maps_script5_rows_without_a_canonical_watermark(): void {
        $api = $this->api_with_responses([5 => [athenasoft_fixtures::course()]]);
        $this->configure_athenasoft_defaults();
        $source = new source($api);
        $registry = source::get_stream_registry();
        $request = source_stream_envelope::build_request($registry['courses'], 'courses', 1700000000, false);

        $results = source_stream_envelope::validate_results([$request], $source->fetch_streams([$request]));

        $this->assertNull($request['watermark']);
        $this->assertNull($request['effective_since']);
        $this->assertSame('AS-39222-56', $results['courses:courses']['rows'][0]['idnumber']);
        $this->assertSame('Spaans 1.1 contact', $results['courses:courses']['rows'][0]['shortname']);
        $this->assertSame('10733', $results['courses:courses']['rows'][0]['templatekey']);
        $this->assertSame(
            'opleidingsvariantId',
            $source->get_effective_map()['course']['templatekey']['source']
        );
    }

    /**
     * Cancelled script 5 rows remain excluded from the full course stream.
     *
     * @return void
     */
    public function test_courses_stream_excludes_cancelled_rows(): void {
        global $DB;

        $api = $this->api_with_responses([5 => [
            athenasoft_fixtures::course(),
            athenasoft_fixtures::course(['NummerIdCursus' => 99, 'afgelastingsdatum' => '2026-08-01']),
        ], ]);
        $this->configure_athenasoft_defaults();
        $source = new source($api);
        $registry = source::get_stream_registry();
        $request = source_stream_envelope::build_request($registry['courses'], 'courses', null, false);

        $results = source_stream_envelope::validate_results([$request], $source->fetch_streams([$request]));

        $this->assertCount(1, $results['courses:courses']['rows']);
        $this->assertTrue($DB->record_exists('local_wisa_log', ['action' => 'source_fetch', 'status' => 'info']));
    }
}
