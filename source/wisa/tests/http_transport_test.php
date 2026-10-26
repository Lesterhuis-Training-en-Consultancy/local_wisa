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
 * WISA transport tests without live credentials or network access.
 *
 * @package    sissource_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/wisa_http_spy.php');
require_once(__DIR__ . '/fixtures/wisa_http_test_client.php');

/**
 * WISA HTTPS and Moodle HTTP transport regression tests.
 *
 * @group sissource_wisa
 * @group local_wisa
 * @covers \sissource_wisa\api_client
 */
final class http_transport_test extends \advanced_testcase {
    /**
     * Assert HTTPS URL validation applies to saved and runtime configuration.
     *
     * @return void
     */
    public function test_url_validation(): void {
        $this->resetAfterTest();
        $setting = new admin_setting_https_url('sissource_wisa/api_url', 'URL', 'URL', '', PARAM_URL);
        $this->assertSame('', $setting->get_defaultsetting());
        $this->assertTrue($setting->validate(''));
        $this->assertTrue($setting->validate('https://school.example.test/QUERY/'));
        foreach ([
            'http://school.example.test/QUERY/',
            'https://user:pass@school.example.test/QUERY/',
            'https://school.example.test/QUERY/?x=1',
            'https://school.example.test/QUERY/#fragment',
            "https://school.example.test.evil/QUERY/\r\n",
            'not-a-url',
        ] as $unsafe) {
            $this->assertFalse(api_client::is_valid_api_url($unsafe));
            $this->assertIsString($setting->validate($unsafe));
        }
    }

    /**
     * Assert previously stored HTTP endpoints cannot send credentials.
     *
     * @return void
     */
    public function test_existing_http_url_fails_closed_before_request(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('api_url', 'http://school.example.test/QUERY/', 'sissource_wisa');
        set_config('api_user', \core\encryption::encrypt('user'), 'sissource_wisa');
        set_config('api_pass', \core\encryption::encrypt('password'), 'sissource_wisa');
        $client = new wisa_http_test_client();
        $this->assertFalse($client->fetch('MCVOD_C'));
        $this->assertSame('', $client->spy->url);
        $log = $DB->get_record('local_wisa_log', ['action' => 'api_connect', 'status' => 'fail'], '*', MUST_EXIST);
        $this->assertSame('WISA_API_CONFIG_MISSING', $log->message);
    }

    /**
     * Assert timeouts, TLS and no redirect survive the Moodle curl migration.
     *
     * @return void
     */
    public function test_moodle_curl_get_preserves_request_contract(): void {
        $this->resetAfterTest();
        set_config('api_url', 'https://school.example.test/QUERY/', 'sissource_wisa');
        set_config('api_user', \core\encryption::encrypt('user'), 'sissource_wisa');
        set_config('api_pass', \core\encryption::encrypt('password'), 'sissource_wisa');
        $client = new wisa_http_test_client();
        $this->assertSame([], $client->fetch('MCVOD_C'));
        $this->assertStringStartsWith('https://school.example.test/QUERY/MCVOD_C?', $client->spy->url);
        $this->assertStringContainsString('_password_=password', $client->spy->url);
        $this->assertContains('Accept-Language: en-US,en;q=0.5', $client->spy->headers);
        $this->assertSame(15, $client->spy->options['CURLOPT_CONNECTTIMEOUT']);
        $this->assertSame(180, $client->spy->options['CURLOPT_TIMEOUT']);
        $this->assertTrue($client->spy->options['CURLOPT_SSL_VERIFYPEER']);
        $this->assertSame(2, $client->spy->options['CURLOPT_SSL_VERIFYHOST']);
        $this->assertFalse($client->spy->options['CURLOPT_FOLLOWLOCATION']);
        $this->assertStringContainsString('Mozilla/', $client->spy->options['CURLOPT_USERAGENT']);
        $redacted = secure_curl::redact_url($client->spy->url);
        $this->assertStringContainsString('_username_=redacted', $redacted);
        $this->assertStringContainsString('_password_=redacted', $redacted);
        $this->assertSame('school.example.test', parse_url($redacted, PHP_URL_HOST));
        $this->assertStringNotContainsString('test-pass', $redacted);
        $client->spy->errornumber = 6;
        $this->assertFalse($client->fetch('MCVOD_C'));
    }
}
