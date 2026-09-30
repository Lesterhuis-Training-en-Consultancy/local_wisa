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
 * AthenaSoft script API client.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;


/**
 * Client for AthenaSoft JSON script endpoints.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_client {
    use api_client_configuration_trait;

    /** @var string AthenaSoft API URL. */
    private $apiurl = '';

    /** @var string API authorisation header value. */
    private $apikey = '';

    /** @var string AthenaSoft authentication user. */
    private $authuser = '';

    /** @var string AthenaSoft authentication credential. */
    private $authcred = '';

    /** @var string Institution number. */
    private $institution = '';

    /** @var string Period number. */
    private $periode = '';

    /** @var string Extra JSON request parameters. */
    private $extraparams = '';

    /** @var bool Whether debug logging is enabled. */
    private $debug = false;

    /**
     * Construct the API client from Moodle configuration.
     */
    public function __construct() {
        $this->load_config();
    }

    /**
     * Fetch rows for one AthenaSoft script ID.
     *
     * @param int $scriptid AthenaSoft script ID.
     * @return array|false Decoded response array, or false on failure.
     */
    public function fetch(int $scriptid) {
        $this->load_config();

        if (!$this->has_required_config()) {
            $this->log('api_connect', 'system', 'redacted', 'fail', 'ATHENASOFT_API_CONFIG_MISSING');
            return false;
        }

        $body = $this->build_request_body($scriptid);

        if ($this->debug) {
            $this->log('api_debug', 'system', 'redacted', 'info', 'ATHENASOFT_API_REQUEST');
        }

        [$response, $httpcode, $error] = $this->execute_request($this->apiurl, $body);

        if ($response === false || $error !== '') {
            $this->log('api_fetch', 'system', 'redacted', 'fail', 'ATHENASOFT_API_TRANSPORT_FAILED');
            return false;
        }

        if ((int)$httpcode !== 200) {
            if ((int)$httpcode === 404) {
                $message = 'ATHENASOFT_API_HTTP_404_POSSIBLE_ACCESS_RESTRICTION';
            } else if ((int)$httpcode === 401 || (int)$httpcode === 403) {
                $message = 'ATHENASOFT_API_HTTP_AUTH_FAILED';
            } else {
                $message = 'ATHENASOFT_API_HTTP_FAILED';
            }
            $this->log('api_fetch', 'system', 'redacted', 'fail', $message);
            return false;
        }

        $data = json_decode((string)$response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('api_fetch', 'system', 'redacted', 'fail', 'ATHENASOFT_API_RESPONSE_INVALID_JSON');
            return false;
        }
        if (!is_array($data)) {
            $this->log('api_fetch', 'system', 'redacted', 'fail', 'ATHENASOFT_API_RESPONSE_INVALID_SHAPE');
            return false;
        }

        return $data;
    }

    /**
     * Build the JSON body for an AthenaSoft script request.
     *
     * @param int $scriptid AthenaSoft script ID.
     * @return string JSON request body.
     */
    protected function build_request_body(int $scriptid): string {
        $body = [
            'auth' => [
                'user' => $this->authuser,
                'cred' => $this->authcred,
            ],
            'id' => $scriptid,
            'instellingsnummer' => (int)$this->institution,
            'periode' => (int)$this->periode,
        ];

        $extra = trim((string)$this->extraparams);
        if ($extra !== '') {
            $decoded = json_decode($extra, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $body = array_merge($body, $decoded);
            } else {
                $this->log(
                    'api_config',
                    'system',
                    'redacted',
                    'warning',
                    'ATHENASOFT_API_EXTRA_PARAMS_INVALID_JSON'
                );
            }
        }

        return (string)json_encode($body);
    }

    /**
     * Execute the HTTP request.
     *
     * @param string $url Request URL.
     * @param string $jsonbody JSON request body.
     * @return array Response body, HTTP status code and cURL error string.
     */
    protected function execute_request(string $url, string $jsonbody): array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        foreach ($this->build_curl_options($url, $jsonbody) as $option => $value) {
            curl_setopt($ch, $option, $value);
        }

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [$response, $httpcode, $error];
    }

    /**
     * Build cURL options for an AthenaSoft POST request.
     *
     * @param string $url Request URL.
     * @param string $jsonbody JSON request body.
     * @return array cURL options.
     */
    protected function build_curl_options(string $url, string $jsonbody): array {
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonbody,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Api-Authorization-Key: ' . $this->apikey,
            ],
        ];
    }
}
