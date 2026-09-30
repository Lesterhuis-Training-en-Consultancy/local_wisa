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
 * AthenaSoft API client request tests.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/api_client_test_case.php');

use sissource_athenasoft\tests\capturing_api_client;

/**
 * Tests AthenaSoft API request contracts.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @group      sissource_athenasoft
 * @group      local_wisa
 * @covers     \sissource_athenasoft\api_client
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class api_client_request_test extends api_client_test_case {
    /**
     * Assert POST and JSON headers in generated cURL options.
     *
     * @return void
     */
    public function test_request_is_post_with_json_content_type(): void {
        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();

        $options = $this->curl_options_for_body('{"id":5}');

        $this->assertTrue($options[CURLOPT_POST]);
        $this->assertContains('Content-Type: application/json', $options[CURLOPT_HTTPHEADER]);
        $this->assertContains('Api-Authorization-Key: api-key-secret', $options[CURLOPT_HTTPHEADER]);
    }

    /**
     * Assert the JSON request body contains required root fields.
     *
     * @return void
     */
    public function test_request_body_contains_required_fields(): void {
        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();
        $api = new capturing_api_client();
        $api->set_response(5, [['ok' => true]]);

        $this->assertSame([['ok' => true]], $api->fetch(5));

        $body = $api->decodedrequestbodies[0];
        $this->assertSame('apiuser', $body['auth']['user']);
        $this->assertSame('authcredsecret', $body['auth']['cred']);
        $this->assertSame(5, $body['id']);
        $this->assertSame('39222', (string)$body['instellingsnummer']);
        $this->assertSame('20262027', (string)$body['periode']);
    }

    /**
     * Assert forced plaintext credentials override stored ciphertext without a live request or log leakage.
     *
     * @return void
     */
    public function test_forced_credentials_override_ciphertext_without_network_or_log_leakage(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();
        $storedapikey = $this->get_raw_config('api_key');
        $storedauthuser = $this->get_raw_config('auth_user');
        $storedauthcred = $this->get_raw_config('auth_cred');
        $CFG->forced_plugin_settings['sissource_athenasoft'] = [
            'api_key' => 'forced-api-key',
            'auth_user' => 'forced-auth-user',
            'auth_cred' => 'forced-auth-cred',
        ];
        $api = new capturing_api_client();
        $api->set_response(5, 'Failure for forced-api-key and forced-auth-cred', 500);

        $this->assertFalse($api->fetch(5));

        $body = $api->decodedrequestbodies[0];
        $this->assertTrue($api->executed);
        $this->assertSame('forced-auth-user', $body['auth']['user']);
        $this->assertSame('forced-auth-cred', $body['auth']['cred']);
        $options = $this->curl_options_for_body('{"id":5}');
        $this->assertContains('Api-Authorization-Key: forced-api-key', $options[CURLOPT_HTTPHEADER]);
        $this->assertSame($storedapikey, $this->get_raw_config('api_key'));
        $this->assertSame($storedauthuser, $this->get_raw_config('auth_user'));
        $this->assertSame($storedauthcred, $this->get_raw_config('auth_cred'));
        $this->assert_log_masks_secret('forced-api-key');
        $this->assert_log_masks_secret('forced-auth-user');
        $this->assert_log_masks_secret('forced-auth-cred');
    }

    /**
     * Assert valid extra params are merged and invalid extra params are logged and ignored.
     *
     * @return void
     */
    public function test_extra_params_merged_into_request_body(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();
        set_config('extra_params', '{"campus":"A","includeCancelled":false}', 'sissource_athenasoft');
        $api = new capturing_api_client();
        $api->set_response(5, [['ok' => true]]);

        $api->fetch(5);

        $this->assertSame('A', $api->decodedrequestbodies[0]['campus']);
        $this->assertFalse($api->decodedrequestbodies[0]['includeCancelled']);

        set_config('extra_params', '{not-json}', 'sissource_athenasoft');
        $invalidapi = new capturing_api_client();
        $invalidapi->set_response(5, [['ok' => true]]);

        $invalidapi->fetch(5);

        $this->assertArrayNotHasKey('campus', $invalidapi->decodedrequestbodies[0]);
        $log = $DB->get_record('local_wisa_log', [
            'action' => 'api_config',
            'status' => 'warning',
        ], '*', MUST_EXIST);
        $this->assertSame('redacted', $log->objectid);
        $this->assertSame('ATHENASOFT_API_EXTRA_PARAMS_INVALID_JSON', $log->message);
    }

    /**
     * Assert SSL peer verification is enabled in cURL options.
     *
     * @return void
     */
    public function test_ssl_verify_peer_is_true(): void {
        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();

        $options = $this->curl_options_for_body('{"id":5}');

        $this->assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(300, $options[CURLOPT_TIMEOUT]);
        $this->assertSame(15, $options[CURLOPT_CONNECTTIMEOUT]);
        $this->assertTrue($options[CURLOPT_POST]);
    }

    /**
     * Assert redirect following is disabled in cURL options.
     *
     * @return void
     */
    public function test_follow_location_is_false(): void {
        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();

        $options = $this->curl_options_for_body('{"id":5}');

        $this->assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        $this->assertSame('{"id":5}', $options[CURLOPT_POSTFIELDS]);
    }
}
