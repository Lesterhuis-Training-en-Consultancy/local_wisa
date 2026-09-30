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
 * Capturing AthenaSoft API client for sissource_athenasoft tests.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft\tests;

/**
 * API client test double that captures POST calls without making HTTP requests.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class capturing_api_client extends \sissource_athenasoft\api_client {
    /** @var array Captured script IDs. */
    public $scriptids = [];

    /** @var array Captured request URLs. */
    public $requesturls = [];

    /** @var array Captured raw JSON request bodies. */
    public $requestbodies = [];

    /** @var array Captured decoded request bodies. */
    public $decodedrequestbodies = [];

    /** @var bool Whether execute_request() was called. */
    public $executed = false;

    /** @var array Canned response triples keyed by script ID. */
    private $responses = [];

    /**
     * Construct a no-config capturing API client.
     */
    public function __construct() {
    }

    /**
     * Configure the canned HTTP response for a script ID.
     *
     * @param int $scriptid AthenaSoft script ID.
     * @param mixed $response Response body array or raw string.
     * @param int $httpcode HTTP status code.
     * @param string $error cURL error string.
     * @return void
     */
    public function set_response(int $scriptid, $response = [], int $httpcode = 200, string $error = ''): void {
        if (is_array($response)) {
            $response = json_encode($response);
        }
        $this->responses[$scriptid] = [(string)$response, $httpcode, $error];
    }

    /**
     * Expose secret masking for direct characterisation tests.
     *
     * @param string $text Input text.
     * @return string
     */
    public function reveal_mask_secrets(string $text): string {
        return $this->mask_secrets($text);
    }

    /**
     * Capture the request and return a canned response instead of making HTTP.
     *
     * @param string $url Request URL.
     * @param string $jsonbody JSON request body.
     * @return array
     */
    protected function execute_request(string $url, string $jsonbody): array {
        $this->executed = true;
        $this->requesturls[] = $url;
        $this->requestbodies[] = $jsonbody;

        $decoded = json_decode($jsonbody, true);
        if (is_array($decoded)) {
            $this->decodedrequestbodies[] = $decoded;
            $scriptid = isset($decoded['id']) ? (int)$decoded['id'] : 0;
        } else {
            $this->decodedrequestbodies[] = null;
            $scriptid = 0;
        }
        $this->scriptids[] = $scriptid;

        if (array_key_exists($scriptid, $this->responses)) {
            return $this->responses[$scriptid];
        }

        return ['[]', 200, ''];
    }
}
