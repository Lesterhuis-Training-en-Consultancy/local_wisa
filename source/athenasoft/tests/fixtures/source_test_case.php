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
 * Shared fixture for AthenaSoft source mapping tests.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/athenasoft_fixtures.php');
require_once(__DIR__ . '/capturing_api_client.php');

use sissource_athenasoft\tests\capturing_api_client;

/**
 * Creates deterministic AthenaSoft source instances.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class source_test_case extends \advanced_testcase {
    /**
     * Create a source with canned API responses.
     *
     * @param array $responses Responses keyed by script ID.
     * @param array $configoverrides Config overrides.
     * @return source
     */
    protected function source_with_responses(array $responses, array $configoverrides = []): source {
        $api = $this->api_with_responses($responses);
        $this->configure_athenasoft_defaults($configoverrides);
        return new source($api);
    }

    /**
     * Create a capturing API client with canned responses.
     *
     * @param array $responses Responses keyed by script ID.
     * @return capturing_api_client
     */
    protected function api_with_responses(array $responses): capturing_api_client {
        $api = new capturing_api_client();
        foreach ($responses as $scriptid => $response) {
            if ($response === false) {
                $api->set_response((int)$scriptid, 'Failure', 500);
            } else {
                $api->set_response((int)$scriptid, $response);
            }
        }
        return $api;
    }

    /**
     * Configure deterministic AthenaSoft settings for source tests.
     *
     * @param array $overrides Config overrides.
     * @return void
     */
    protected function configure_athenasoft_defaults(array $overrides = []): void {
        $this->resetAfterTest();
        $config = array_merge([
            'api_url' => 'https://api-test.example.be/script',
            'api_key' => \core\encryption::encrypt('api-key-secret'),
            'auth_user' => \core\encryption::encrypt('apiuser'),
            'auth_cred' => \core\encryption::encrypt('authcredsecret'),
            'institution' => '39222', 'periode' => '20262027', 'script_courses' => '5',
            'script_placements' => '6', 'script_teachers' => '7', 'extra_params' => '',
            'rolemap_cursist' => 'student', 'rolemap_leerkracht' => 'teacher', 'fieldmap' => '',
        ], $overrides);
        set_config('debug_logging', 0, 'local_wisa');
        foreach ($config as $key => $value) {
            set_config($key, $value, 'sissource_athenasoft');
        }
    }
}
