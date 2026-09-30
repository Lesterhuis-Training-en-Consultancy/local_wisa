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
 * Capturing WISA API client for sissource_wisa tests.
 *
 * @package    sissource_wisa
 * @copyright  2026 Sebsoft
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_wisa\tests;

/**
 * API client that captures the URL fetch() would request.
 */
final class capturing_api_client extends \sissource_wisa\api_client {
    /** @var array Captured request URLs. */
    public $requestedurls = [];

    /** @var string JSON response body. */
    private $response;

    /** @var int HTTP status code. */
    private $httpcode;

    /** @var string cURL error string. */
    private $error;

    /**
     * Construct a capturing API client.
     *
     * @param string $response JSON response body.
     * @param int $httpcode HTTP status code.
     * @param string $error cURL error string.
     */
    public function __construct($response = '[]', $httpcode = 200, $error = '') {
        parent::__construct();
        $this->response = $response;
        $this->httpcode = $httpcode;
        $this->error = $error;
    }

    /**
     * Capture the URL instead of making an HTTP request.
     *
     * @param string $fullurl Captured URL.
     * @return array
     */
    protected function execute_request($fullurl) {
        $this->requestedurls[] = $fullurl;
        return [$this->response, $this->httpcode, $this->error];
    }

    /**
     * Expose secret masking for direct characterization.
     *
     * @param string $text Input text.
     * @return string
     */
    public function reveal_mask_secrets($text) {
        return $this->mask_secrets($text);
    }
}
