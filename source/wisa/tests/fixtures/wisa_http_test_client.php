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
 * WISA client test fixture.
 *
 * @package    sissource_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_wisa;

/**
 * WISA client using the spy instead of a live HTTP client.
 */
class wisa_http_test_client extends api_client {
    /** @var wisa_http_spy Spy HTTP client. */
    public $spy;

    /**
     * Create a client with a spy.
     */
    public function __construct() {
        parent::__construct();
        $this->spy = new wisa_http_spy();
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
