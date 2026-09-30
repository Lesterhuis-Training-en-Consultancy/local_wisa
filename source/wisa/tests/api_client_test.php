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
 * API client tests for sissource_wisa.
 *
 * @package    sissource_wisa
 * @category   test
 * @copyright  2026 Sebsoft
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(dirname(__DIR__, 3) . '/tests/fixtures/sync_testcase.php');
require_once(__DIR__ . '/fixtures/capturing_api_client.php');

use sissource_wisa\tests\capturing_api_client;

/**
 * API client tests for sissource_wisa.
 *
 * @group sissource_wisa
 * @group local_wisa
 * @covers     \sissource_wisa\api_client
 */
final class api_client_test extends \local_wisa\sync_testcase {
    public function test_api_client_builds_query_url_with_raw_ampersands_and_delta(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        set_config('api_url', 'https://school.example.test/webwisad/bin/server.fcgi/QUERY', 'sissource_wisa');
        set_config('query_courses', 'CUSTOM_COURSES', 'sissource_wisa');
        set_config('api_user', \core\encryption::encrypt('apiuser'), 'sissource_wisa');
        set_config('api_pass', \core\encryption::encrypt('apipassword'), 'sissource_wisa');
        $api = new capturing_api_client('[{"ok":true}]');
        $effectivesince = make_timestamp(2026, 6, 1, 12, 34, 56);

        $this->assertSame([['ok' => true]], $api->fetch_transport('query_courses', $effectivesince));

        $url = $api->requestedurls[0];
        $this->assertStringStartsWith('https://school.example.test/webwisad/bin/server.fcgi/QUERY/CUSTOM_COURSES?', $url);
        $this->assertStringContainsString('&', $url);
        $this->assertStringNotContainsString('&amp;', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame(date('Y-m-d'), $params['werkdatum']);
        $this->assertSame('123456', $params['instellingsnummer']);
        $this->assertSame('json', $params['format']);
        $this->assertSame('apiuser', $params['_username_']);
        $this->assertSame('apipassword', $params['_password_']);
        $this->assertSame('2026-06-01 12:34:56', $params['sinds']);
    }

    /**
     * Source-stream transport requests translate their nullable epoch lower bound once.
     *
     * @return void
     */
    public function test_api_client_fetches_declared_transport_with_nullable_effective_since(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        set_config('query_courses', 'STREAM_COURSES', 'sissource_wisa');
        set_config('api_user', \core\encryption::encrypt('apiuser'), 'sissource_wisa');
        set_config('api_pass', \core\encryption::encrypt('apipassword'), 'sissource_wisa');
        $api = new capturing_api_client('[{"ok":true}]');

        $this->assertSame([['ok' => true]], $api->fetch_transport('query_courses', 1700000000));
        $this->assertSame([['ok' => true]], $api->fetch_transport('query_courses', null));

        parse_str(parse_url($api->requestedurls[0], PHP_URL_QUERY), $delta);
        parse_str(parse_url($api->requestedurls[1], PHP_URL_QUERY), $initial);
        $this->assertStringContainsString('/STREAM_COURSES?', $api->requestedurls[0]);
        $this->assertSame(date('Y-m-d H:i:s', 1700000000), $delta['sinds']);
        $this->assertSame('1900-01-01 00:00:00', $initial['sinds']);
    }

    /**
     * Forced credentials take precedence while persisted ciphertext remains unchanged.
     *
     * @return void
     */
    public function test_api_client_uses_forced_credentials_only_at_request_boundary(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $storeduser = \core\encryption::encrypt('stored-user');
        $storedpass = \core\encryption::encrypt('stored-pass');
        set_config('api_user', $storeduser, 'sissource_wisa');
        set_config('api_pass', $storedpass, 'sissource_wisa');
        $hadforcedsettings = property_exists($CFG, 'forced_plugin_settings');
        $forcedsettings = $hadforcedsettings ? $CFG->forced_plugin_settings : [];
        $CFG->forced_plugin_settings['sissource_wisa']['api_user'] = 'forced-user';
        $CFG->forced_plugin_settings['sissource_wisa']['api_pass'] = 'forced-pass';

        try {
            $api = new capturing_api_client('[{"ok":true}]');
            $this->assertSame([['ok' => true]], $api->fetch_transport('query_courses', null));

            parse_str(parse_url($api->requestedurls[0], PHP_URL_QUERY), $params);
            $this->assertSame('forced-user', $params['_username_']);
            $this->assertSame('forced-pass', $params['_password_']);
            $this->assertSame($storeduser, $this->get_raw_config('api_user'));
            $this->assertSame($storedpass, $this->get_raw_config('api_pass'));
        } finally {
            if ($hadforcedsettings) {
                $CFG->forced_plugin_settings = $forcedsettings;
            } else {
                unset($CFG->forced_plugin_settings);
            }
        }
    }

    /**
     * Encrypted and forced credentials retain the client's established trimming behavior.
     *
     * @return void
     */
    public function test_api_client_trims_resolved_encrypted_and_forced_credentials(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        set_config('api_user', \core\encryption::encrypt(' user '), 'sissource_wisa');
        set_config('api_pass', \core\encryption::encrypt(' pass '), 'sissource_wisa');
        $api = new capturing_api_client('[{"ok":true}]');

        $this->assertSame([['ok' => true]], $api->fetch_transport('query_courses', null));

        parse_str(parse_url($api->requestedurls[0], PHP_URL_QUERY), $params);
        $this->assertSame('user', $params['_username_']);
        $this->assertSame('pass', $params['_password_']);

        $hadforcedsettings = property_exists($CFG, 'forced_plugin_settings');
        $forcedsettings = $hadforcedsettings ? $CFG->forced_plugin_settings : [];
        $CFG->forced_plugin_settings['sissource_wisa']['api_user'] = ' forced-user ';
        $CFG->forced_plugin_settings['sissource_wisa']['api_pass'] = ' forced-pass ';

        try {
            $api = new capturing_api_client('[{"ok":true}]');
            $this->assertSame([['ok' => true]], $api->fetch_transport('query_courses', null));

            parse_str(parse_url($api->requestedurls[0], PHP_URL_QUERY), $params);
            $this->assertSame('forced-user', $params['_username_']);
            $this->assertSame('forced-pass', $params['_password_']);
        } finally {
            if ($hadforcedsettings) {
                $CFG->forced_plugin_settings = $forcedsettings;
            } else {
                unset($CFG->forced_plugin_settings);
            }
        }
    }

    public function test_api_client_redacts_debug_and_failure_diagnostics(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        set_config('debug_logging', 1, 'local_wisa');
        set_config('api_user', \core\encryption::encrypt('leakyuser'), 'sissource_wisa');
        set_config('api_pass', \core\encryption::encrypt('supersecret'), 'sissource_wisa');
        $api = new capturing_api_client(
            'Error for _username_=leakyuser and _password_=supersecret',
            500
        );

        $this->assertFalse($api->fetch_transport('query_courses', null));
        $transportapi = new capturing_api_client(false, 0, 'curl-error-sentinel');
        $this->assertFalse($transportapi->fetch_transport('query_courses', null));

        $this->assertStringContainsString('_username_=leakyuser', $api->requestedurls[0]);
        $this->assertStringContainsString('_password_=supersecret', $api->requestedurls[0]);

        $combined = json_encode(array_values($DB->get_records('local_wisa_log')));
        $this->assertStringContainsString('WISA_API_REQUEST', $combined);
        $this->assertStringContainsString('WISA_API_HTTP_FAILED', $combined);
        $this->assertStringContainsString('WISA_API_TRANSPORT_FAILED', $combined);
        foreach (['leakyuser', 'supersecret', 'MCVOD_C', 'Error for', 'curl-error-sentinel'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $combined);
        }
        $this->assertSame(
            'curl says _username_=[MASKED]&_password_=[MASKED]',
            $api->reveal_mask_secrets('curl says _username_=leakyuser&_password_=supersecret')
        );
    }

    public function test_api_client_rejects_missing_configuration_without_request(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        set_config('api_user', \core\encryption::encrypt('apiuser'), 'sissource_wisa');
        set_config('api_pass', '', 'sissource_wisa');
        $api = new capturing_api_client('[{"ok":true}]');

        $this->assertFalse($api->fetch_transport('query_courses', null));

        $this->assertSame([], $api->requestedurls);
        $log = $DB->get_record('local_wisa_log', [
            'action' => 'api_connect',
            'status' => 'fail',
        ], '*', MUST_EXIST);
        $this->assertSame('redacted', $log->objectid);
        $this->assertSame('WISA_API_CONFIG_MISSING', $log->message);
    }

    public function test_api_client_rejects_invalid_json_and_logs_decode_failure(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        set_config('api_user', \core\encryption::encrypt('apiuser'), 'sissource_wisa');
        set_config('api_pass', \core\encryption::encrypt('apipassword'), 'sissource_wisa');
        $api = new capturing_api_client('{not-json}', 200);

        $this->assertFalse($api->fetch_transport('query_courses', null));

        $this->assertTrue($DB->record_exists('local_wisa_log', ['action' => 'api_fetch', 'status' => 'fail']));
        $log = $DB->get_record('local_wisa_log', [
            'action' => 'api_fetch',
            'status' => 'fail',
        ], '*', MUST_EXIST);
        $this->assertSame('redacted', $log->objectid);
        $this->assertSame('WISA_API_RESPONSE_INVALID_JSON', $log->message);
    }

    public function test_api_client_rejects_scalar_json_with_stable_shape_code(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        set_config('api_user', \core\encryption::encrypt('apiuser'), 'sissource_wisa');
        set_config('api_pass', \core\encryption::encrypt('apipassword'), 'sissource_wisa');

        foreach (['null', '"value"', 'true', '123'] as $response) {
            $DB->delete_records('local_wisa_log');

            $this->assertFalse((new capturing_api_client($response, 200))->fetch_transport('query_courses', null));

            $log = $DB->get_record('local_wisa_log', [
                'action' => 'api_fetch',
                'status' => 'fail',
            ], '*', MUST_EXIST);
            $this->assertSame('redacted', $log->objectid);
            $this->assertSame('WISA_API_RESPONSE_INVALID_SHAPE', $log->message);
        }
    }

    /**
     * Return a persisted source credential without applying forced configuration.
     *
     * @param string $settingname Source credential setting name.
     * @return string|null Persisted value, or null when absent.
     */
    private function get_raw_config(string $settingname): ?string {
        global $DB;

        $value = $DB->get_field('config_plugins', 'value', [
            'plugin' => 'sissource_wisa',
            'name' => $settingname,
        ]);
        return $value === false ? null : (string)$value;
    }
}
