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
 * AthenaSoft user stream tests.
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
 * Tests independently mapped AthenaSoft user streams.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_user_test extends source_test_case {
    /**
     * Each user stream maps its own transport without source-role inference.
     *
     * @return void
     */
    public function test_user_streams_map_student_and_teacher_rows(): void {
        $api = $this->api_with_responses([6 => [athenasoft_fixtures::student()], 7 => [athenasoft_fixtures::teacher()]]);
        $this->configure_athenasoft_defaults([
            'fieldmap' => '{"user":{"password":"pasword"}}',
        ]);
        $source = new source($api);
        $registry = source::get_stream_registry();
        $requests = [
            source_stream_envelope::build_request($registry['placements'], 'users', null, false),
            source_stream_envelope::build_request($registry['linked_teachers'], 'users', null, false),
        ];

        $results = source_stream_envelope::validate_results($requests, $source->fetch_streams($requests));

        $this->assertSame('420623', $results['placements:users']['rows'][0]['idnumber']);
        $this->assertSame('428449', $results['linked_teachers:users']['rows'][0]['idnumber']);
        $this->assertSame('3121-08-18', $results['placements:users']['rows'][0]['password']);
        $this->assertSame('3143-01-21', $results['linked_teachers:users']['rows'][0]['password']);
        $this->assertArrayNotHasKey('pasword', $results['placements:users']['rows'][0]);
        $this->assertArrayNotHasKey('pasword', $results['linked_teachers:users']['rows'][0]);
        $this->assertArrayNotHasKey('feedtype', $results['placements:users']['rows'][0]);
    }

    /**
     * A configured password source replaces the default source at runtime.
     *
     * @return void
     */
    public function test_fieldmap_password_source_is_used_at_runtime(): void {
        $api = $this->api_with_responses([6 => [athenasoft_fixtures::student([
            'wachtwoord' => 'configured-source-password',
        ])]]);
        $this->configure_athenasoft_defaults([
            'fieldmap' => '{"user":{"password":"wachtwoord"}}',
        ]);
        $source = new source($api);
        $registry = source::get_stream_registry();
        $requests = [
            source_stream_envelope::build_request($registry['placements'], 'users', null, false),
        ];

        $results = source_stream_envelope::validate_results($requests, $source->fetch_streams($requests));
        $user = $results['placements:users']['rows'][0];
        $mapping = $source->get_effective_map()['user']['password'];

        $this->assertSame('configured-source-password', $user['password']);
        $this->assertArrayNotHasKey('pasword', $user);
        $this->assertSame('wachtwoord', $mapping['source']);
        $this->assertSame('password', $mapping['defaultsource']);
        $this->assertTrue($mapping['overridden']);
    }

    /**
     * Password uses the default password source without a fieldmap override.
     *
     * @return void
     */
    public function test_password_uses_default_source_without_fieldmap_override(): void {
        $api = $this->api_with_responses([6 => [athenasoft_fixtures::student([
            'password' => 'default-source-password',
        ])]]);
        $this->configure_athenasoft_defaults();
        $source = new source($api);
        $registry = source::get_stream_registry();
        $requests = [
            source_stream_envelope::build_request($registry['placements'], 'users', null, false),
        ];

        $results = source_stream_envelope::validate_results($requests, $source->fetch_streams($requests));
        $user = $results['placements:users']['rows'][0];

        $mapping = $source->get_effective_map()['user']['password'];

        $this->assertSame('default-source-password', $user['password']);
        $this->assertArrayNotHasKey('pasword', $user);
        $this->assertSame('password', $mapping['source']);
        $this->assertSame('password', $mapping['defaultsource']);
        $this->assertFalse($mapping['overridden']);
    }
}
