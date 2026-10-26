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
 * AthenaSoft transport tests without live credentials or network access.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;

defined('MOODLE_INTERNAL') || die();

/**
 * A Moodle curl spy with canned responses.
 */
class athenasoft_http_spy extends \curl {
    /** @var array Captured headers. */
    public $headers = [];
    /** @var array Captured options. */
    public $options = [];
    /** @var string Captured URL. */
    public $url = '';
    /** @var string Captured JSON body. */
    public $body = '';
    /** @var int Simulated curl error code. */
    public $errornumber = 0;
    /** @var string|false Simulated response. */
    public $responsebody = '[]';
    /** @var int Simulated status. */
    public $statuscode = 200;

    /**
     * Capture headers.
     *
     * @param array $header Headers.
     * @return void
     */
    public function setHeader($header) {
        $this->headers = $header;
    }

    /**
     * Capture POST without network access.
     *
     * @param string $url Request URL.
     * @param string $params JSON body.
     * @param array $options cURL options.
     * @return string|false Response body.
     */
    public function post($url, $params = '', $options = []) {
        $this->url = $url;
        $this->body = $params;
        $this->options = $options;
        return $this->responsebody;
    }

    /**
     * Return canned status.
     *
     * @return array HTTP info.
     */
    public function get_info() {
        return ['http_code' => $this->statuscode];
    }

    /**
     * Return canned transport error.
     *
     * @return int Error number.
     */
    public function get_errno() {
        return $this->errornumber;
    }
}

/**
 * AthenaSoft client that uses the spy.
 */
class athenasoft_http_test_client extends api_client {
    /** @var athenasoft_http_spy Spy HTTP client. */
    public $spy;

    /**
     * Set up spy.
     */
    public function __construct() {
        parent::__construct();
        $this->spy = new athenasoft_http_spy();
    }

    /**
     * Return spy client.
     *
     * @return \curl HTTP client.
     */
    protected function create_http_client(): \curl {
        return $this->spy;
    }
}

/**
 * AthenaSoft Moodle HTTP transport regression tests.
 *
 * @group sissource_athenasoft
 * @group local_wisa
 * @covers \sissource_athenasoft\api_client
 */
final class http_transport_test extends \advanced_testcase {
    /**
     * Assert JSON, key, timeouts, TLS and redirect behaviour.
     *
     * @return void
     */
    public function test_moodle_curl_post_preserves_request_contract(): void {
        $this->resetAfterTest();
        set_config('api_url', 'https://api-test.example.be/script', 'sissource_athenasoft');
        set_config('api_key', \core\encryption::encrypt('secret-key'), 'sissource_athenasoft');
        set_config('auth_user', \core\encryption::encrypt('user'), 'sissource_athenasoft');
        set_config('auth_cred', \core\encryption::encrypt('secret-cred'), 'sissource_athenasoft');
        set_config('institution', '39222', 'sissource_athenasoft');
        set_config('periode', '20262027', 'sissource_athenasoft');
        $client = new athenasoft_http_test_client();
        $this->assertSame([], $client->fetch(5));
        $this->assertSame('https://api-test.example.be/script', $client->spy->url);
        $this->assertContains('Content-Type: application/json', $client->spy->headers);
        $this->assertContains('Api-Authorization-Key: secret-key', $client->spy->headers);
        $body = json_decode($client->spy->body, true);
        $this->assertSame('secret-cred', $body['auth']['cred']);
        $this->assertSame(5, $body['id']);
        $this->assertSame(15, $client->spy->options['CURLOPT_CONNECTTIMEOUT']);
        $this->assertSame(300, $client->spy->options['CURLOPT_TIMEOUT']);
        $this->assertTrue($client->spy->options['CURLOPT_SSL_VERIFYPEER']);
        $this->assertSame(2, $client->spy->options['CURLOPT_SSL_VERIFYHOST']);
        $this->assertFalse($client->spy->options['CURLOPT_FOLLOWLOCATION']);
    }

    /**
     * Assert transport errors remain distinguishable from HTTP errors.
     *
     * @return void
     */
    public function test_transport_error_fails_closed(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('api_url', 'https://api-test.example.be/script', 'sissource_athenasoft');
        set_config('api_key', \core\encryption::encrypt('secret-key'), 'sissource_athenasoft');
        set_config('auth_user', \core\encryption::encrypt('user'), 'sissource_athenasoft');
        set_config('auth_cred', \core\encryption::encrypt('secret-cred'), 'sissource_athenasoft');
        set_config('institution', '39222', 'sissource_athenasoft');
        set_config('periode', '20262027', 'sissource_athenasoft');
        $client = new athenasoft_http_test_client();
        $client->spy->errornumber = 6;
        $this->assertFalse($client->fetch(5));
        $log = $DB->get_record('local_wisa_log', ['action' => 'api_fetch', 'status' => 'fail'], '*', MUST_EXIST);
        $this->assertSame('ATHENASOFT_API_TRANSPORT_FAILED', $log->message);
    }
}
