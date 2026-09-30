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
 * Shared fixture for AthenaSoft API client tests.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/capturing_api_client.php');

use sissource_athenasoft\tests\capturing_api_client;

/**
 * Shared AthenaSoft API client test fixture.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class api_client_test_case extends \advanced_testcase {
    /** @var bool Whether forced settings existed before the test. */
    private $hadforcedsettings;

    /** @var array Original forced settings. */
    private $forcedsettings;

    /**
     * Preserve forced settings because database resets do not restore config.php state.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->hadforcedsettings = property_exists($CFG, 'forced_plugin_settings');
        $this->forcedsettings = $this->hadforcedsettings ? $CFG->forced_plugin_settings : [];
    }

    /**
     * Restore forced settings after each test.
     *
     * @return void
     */
    protected function tearDown(): void {
        global $CFG;

        if ($this->hadforcedsettings) {
            $CFG->forced_plugin_settings = $this->forcedsettings;
        } else {
            unset($CFG->forced_plugin_settings);
        }
        parent::tearDown();
    }

    /**
     * Configure deterministic AthenaSoft settings.
     *
     * @return void
     */
    protected function configure_athenasoft_defaults(): void {
        set_config('debug_logging', 0, 'local_wisa');
        set_config('api_url', 'https://api-test.example.be/script', 'sissource_athenasoft');
        set_config('api_key', \core\encryption::encrypt('api-key-secret'), 'sissource_athenasoft');
        set_config('auth_user', \core\encryption::encrypt('apiuser'), 'sissource_athenasoft');
        set_config('auth_cred', \core\encryption::encrypt('authcredsecret'), 'sissource_athenasoft');
        set_config('institution', '39222', 'sissource_athenasoft');
        set_config('periode', '20262027', 'sissource_athenasoft');
        set_config('script_courses', '5', 'sissource_athenasoft');
        set_config('script_placements', '6', 'sissource_athenasoft');
        set_config('script_teachers', '7', 'sissource_athenasoft');
        set_config('extra_params', '', 'sissource_athenasoft');
        set_config('rolemap_cursist', 'student', 'sissource_athenasoft');
        set_config('rolemap_leerkracht', 'teacher', 'sissource_athenasoft');
        set_config('fieldmap', '', 'sissource_athenasoft');
    }

    /**
     * Assert a missing required config blocks request execution.
     *
     * @param string $key Config key to set as missing.
     * @param string $value Config value to set.
     * @return void
     */
    protected function assert_missing_config_returns_false_without_request(string $key, string $value = ''): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();
        set_config($key, $value, 'sissource_athenasoft');
        $api = new capturing_api_client();
        $api->set_response(5, [['ok' => true]]);

        $this->assertFalse($api->fetch(5));
        $this->assertFalse($api->executed);
        $this->assertSame([], $api->scriptids);
        $log = $DB->get_record('local_wisa_log', [
            'action' => 'api_connect',
            'status' => 'fail',
        ], '*', MUST_EXIST);
        $this->assertSame('redacted', $log->objectid);
        $this->assertSame('ATHENASOFT_API_CONFIG_MISSING', $log->message);
    }

    /**
     * Assert a HTTP status logs a specific message.
     *
     * @param int $httpcode HTTP status code.
     * @param string $expected Expected message fragment.
     * @return void
     */
    protected function assert_http_status_logs_message(int $httpcode, string $expected): void {
        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();
        $api = new capturing_api_client();
        $api->set_response(5, 'Failure', $httpcode);

        $this->assertFalse($api->fetch(5));

        $this->assertStringContainsString($expected, $this->combined_log_messages());
    }

    /**
     * Assert an error log masks a config value.
     *
     * @param string $secret Secret value.
     * @return void
     */
    protected function assert_error_log_masks_config_value(string $secret): void {
        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();
        $api = new capturing_api_client();
        $api->set_response(5, 'Failure includes ' . $secret, 500);

        $this->assertFalse($api->fetch(5));

        $this->assert_log_masks_secret($secret);
    }

    /**
     * Assert the log table does not contain the supplied secret.
     *
     * @param string $secret Secret value.
     * @return void
     */
    protected function assert_log_masks_secret(string $secret): void {
        $messages = $this->combined_log_messages();
        $this->assertStringNotContainsString($secret, $messages);
    }

    /**
     * Return all log messages as one string.
     *
     * @return string
     */
    protected function combined_log_messages(): string {
        global $DB;

        return implode("\n", $DB->get_fieldset_select('local_wisa_log', 'message', '1 = 1', []));
    }

    /**
     * Return the persisted AthenaSoft configuration without applying forced settings.
     *
     * @param string $settingname Setting name.
     * @return string|null Persisted value, or null when it is absent.
     */
    protected function get_raw_config(string $settingname): ?string {
        global $DB;

        $value = $DB->get_field('config_plugins', 'value', [
            'plugin' => 'sissource_athenasoft',
            'name' => $settingname,
        ]);
        return $value === false ? null : (string)$value;
    }

    /**
     * Build cURL options through the protected API on a real client.
     *
     * @param string $jsonbody JSON request body.
     * @return array
     */
    protected function curl_options_for_body(string $jsonbody): array {
        $api = new api_client();
        $method = new \ReflectionMethod(api_client::class, 'build_curl_options');
        $method->setAccessible(true);

        return $method->invoke($api, 'https://api-test.example.be/script', $jsonbody);
    }
}
