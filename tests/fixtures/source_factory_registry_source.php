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
 * Source fixture for source-free registry discovery tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_fixture;

defined('MOODLE_INTERNAL') || die();

/**
 * Source fixture that records if parent code constructs it.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source {
    /** @var int Number of fixture constructions. */
    public static $constructions = 0;

    /**
     * Record an unexpected construction.
     *
     * @return void
     */
    public function __construct() {
        self::$constructions++;
    }

    /**
     * Return the static source-stream registry.
     *
     * @return array
     */
    public static function get_stream_registry(): array {
        return [
            'accounts' => [
                'key' => 'accounts',
                'label' => 'stream_accounts',
                'transport' => 'query_accounts',
                'phases' => ['users'],
                'legacyaliases' => ['users' => []],
                'defaultenabled' => ['users' => true],
                'watermarkmode' => ['users' => 'delta'],
                'healthcheckphase' => 'users',
            ],
        ];
    }
}
