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
 * WISA HTTP test double.
 *
 * @package    sissource_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_wisa;

/**
 * A Moodle curl spy with a canned response.
 */
class wisa_http_spy extends \curl {
    /** @var array Headers supplied by the adapter. */
    public $headers = [];
    /** @var array Options supplied by the adapter. */
    public $options = [];
    /** @var string URL supplied by the adapter. */
    public $url = '';
    /** @var int Simulated curl error code. */
    public $errornumber = 0;
    /** @var string|false Simulated response. */
    public $responsebody = '[]';
    /** @var int Simulated HTTP status. */
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
     * Capture a GET without contacting a real server.
     *
     * @param string $url Request URL.
     * @param array $params Optional parameters.
     * @param array $options cURL options.
     * @return string|false Response body.
     */
    public function get($url, $params = [], $options = []) {
        $this->url = $url;
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
