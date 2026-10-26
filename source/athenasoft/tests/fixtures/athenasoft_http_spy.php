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
 * AthenaSoft HTTP test double.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;

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
     * Capture headers using Moodle's required method name.
     *
     * @param array $header Headers.
     * @return void
     */
    // @codingStandardsIgnoreStart Moodle curl requires this camel-case override.
    public function setHeader($header) {
        $this->headers = $header;
    }
    // @codingStandardsIgnoreEnd

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
