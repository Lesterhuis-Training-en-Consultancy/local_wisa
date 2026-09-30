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
 * Invalid static source-registry fixture.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_invalid_registry;

/**
 * Records all runtime access while exposing an invalid static registry.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source implements \local_wisa\source_interface {
    /** @var int Number of static registry reads. */
    public static int $registrycalls = 0;

    /** @var int Number of adapter constructions. */
    public static int $constructions = 0;

    /** @var int Number of transport calls. */
    public static int $transportcalls = 0;

    /**
     * Reset the recorded fixture activity.
     *
     * @return void
     */
    public static function reset(): void {
        self::$registrycalls = 0;
        self::$constructions = 0;
        self::$transportcalls = 0;
    }

    /**
     * Record unexpected adapter construction.
     *
     * @return void
     */
    public function __construct() {
        self::$constructions++;
    }

    /**
     * Return a descriptor that must be rejected without exposing its secret.
     *
     * @return array Invalid source-stream registry.
     */
    public static function get_stream_registry(): array {
        self::$registrycalls++;
        return [
            'accounts' => [
                'key' => 'accounts',
                'label' => 'stream_accounts',
                'transport' => 'SECRET-INVALID-REGISTRY-TRANSPORT',
                'phases' => [],
                'legacyaliases' => [],
                'defaultenabled' => [],
                'watermarkmode' => [],
                'healthcheckphase' => 'users',
            ],
        ];
    }

    /**
     * Record an unexpected transport call.
     *
     * @param array $requests Source-stream requests.
     * @return array Empty source-stream results.
     */
    public function fetch_streams(array $requests): array {
        unset($requests);
        self::$transportcalls++;
        return [];
    }
}
