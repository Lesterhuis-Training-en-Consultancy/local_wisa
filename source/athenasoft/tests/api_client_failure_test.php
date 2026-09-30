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
 * AthenaSoft API client failure tests.
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
 * Tests AthenaSoft API failure contracts.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @group      sissource_athenasoft
 * @group      local_wisa
 * @covers     \sissource_athenasoft\api_client
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class api_client_failure_test extends api_client_test_case {
    /**
     * Assert missing api_url stops before HTTP execution.
     *
     * @return void
     */
    public function test_missing_api_url_returns_false_without_request(): void {
        $this->assert_missing_config_returns_false_without_request('api_url');
    }

    /**
     * Assert missing api_key stops before HTTP execution.
     *
     * @return void
     */
    public function test_missing_api_key_returns_false_without_request(): void {
        $this->assert_missing_config_returns_false_without_request('api_key');
    }

    /**
     * Assert missing auth_cred stops before HTTP execution.
     *
     * @return void
     */
    public function test_missing_auth_cred_returns_false_without_request(): void {
        $this->assert_missing_config_returns_false_without_request('auth_cred');
    }

    /**
     * Assert institution zero stops before HTTP execution.
     *
     * @return void
     */
    public function test_institution_zero_returns_false_without_request(): void {
        $this->assert_missing_config_returns_false_without_request('institution', '0');
    }

    /**
     * Assert periode zero stops before HTTP execution.
     *
     * @return void
     */
    public function test_periode_zero_returns_false_without_request(): void {
        $this->assert_missing_config_returns_false_without_request('periode', '0');
    }

    /**
     * Assert alternate institution zero stops before HTTP execution.
     *
     * @return void
     */
    public function test_institution_alternate_zero_returns_false_without_request(): void {
        $this->assert_missing_config_returns_false_without_request('institution', '00');
    }

    /**
     * Assert alternate periode zero stops before HTTP execution.
     *
     * @return void
     */
    public function test_periode_alternate_zero_returns_false_without_request(): void {
        $this->assert_missing_config_returns_false_without_request('periode', '-0');
    }

    /**
     * Assert cURL errors return false and mask secrets in logs.
     *
     * @return void
     */
    public function test_curl_error_returns_false_and_logs_stable_code(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();
        $api = new capturing_api_client();
        $api->set_response(5, '', 200, 'curl failed for api-key-secret and authcredsecret');

        $this->assertFalse($api->fetch(5));

        $this->assertTrue($DB->record_exists('local_wisa_log', ['action' => 'api_fetch', 'status' => 'fail']));
        $this->assertStringContainsString('ATHENASOFT_API_TRANSPORT_FAILED', $this->combined_log_messages());
        $this->assert_log_masks_secret('api-key-secret');
        $this->assert_log_masks_secret('authcredsecret');
    }

    /**
     * Assert non-200 responses return false and log a stripped, masked snippet.
     *
     * @return void
     */
    public function test_http_non_200_returns_false_and_logs_stable_code(): void {
        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();
        $api = new capturing_api_client();
        $api->set_response(5, '<b>Failure for api-key-secret</b>', 500);

        $this->assertFalse($api->fetch(5));

        $messages = $this->combined_log_messages();
        $this->assertStringContainsString('ATHENASOFT_API_HTTP_FAILED', $messages);
        $this->assertStringNotContainsString('Failure for', $messages);
        $this->assertStringNotContainsString('<b>', $messages);
        $this->assertStringNotContainsString('api-key-secret', $messages);
    }

    /**
     * Assert HTTP 404 logs the AthenaSoft IP-whitelist caveat.
     *
     * @return void
     */
    public function test_http_404_logs_access_restriction_code(): void {
        $this->assert_http_status_logs_message(404, 'ATHENASOFT_API_HTTP_404_POSSIBLE_ACCESS_RESTRICTION');
    }

    /**
     * Assert HTTP 401 logs the authentication failure caveat.
     *
     * @return void
     */
    public function test_http_401_logs_auth_failure_code(): void {
        $this->assert_http_status_logs_message(401, 'ATHENASOFT_API_HTTP_AUTH_FAILED');
    }

    /**
     * Assert HTTP 403 logs the authentication failure caveat.
     *
     * @return void
     */
    public function test_http_403_logs_auth_failure_code(): void {
        $this->assert_http_status_logs_message(403, 'ATHENASOFT_API_HTTP_AUTH_FAILED');
    }

    /**
     * Assert malformed JSON responses return false and log a decode error.
     *
     * @return void
     */
    public function test_malformed_json_response_returns_false_and_logs_stable_code(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();
        $api = new capturing_api_client();
        $api->set_response(5, '{not-json}', 200);

        $this->assertFalse($api->fetch(5));

        $this->assertTrue($DB->record_exists('local_wisa_log', ['action' => 'api_fetch', 'status' => 'fail']));
        $this->assertStringContainsString('ATHENASOFT_API_RESPONSE_INVALID_JSON', $this->combined_log_messages());
    }

    /**
     * Assert valid scalar JSON responses fail with a stable redacted shape code.
     *
     * @return void
     */
    public function test_scalar_json_response_returns_false_and_logs_stable_shape_code(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();

        foreach (['null', '"value"', 'true', '123'] as $response) {
            $DB->delete_records('local_wisa_log');
            $api = new capturing_api_client();
            $api->set_response(5, $response, 200);

            $this->assertFalse($api->fetch(5));

            $log = $DB->get_record('local_wisa_log', [
                'action' => 'api_fetch',
                'status' => 'fail',
            ], '*', MUST_EXIST);
            $this->assertSame('redacted', $log->objectid);
            $this->assertSame('ATHENASOFT_API_RESPONSE_INVALID_SHAPE', $log->message);
        }
    }
}
