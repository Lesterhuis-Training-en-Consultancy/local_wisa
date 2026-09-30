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
 * Source fixture that throws raw diagnostics from the course feed.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Source that throws a raw diagnostic exception from the course feed.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class throwing_course_source implements source_interface {
    /**
     * Return one valid course descriptor so the manager reaches the source call.
     *
     * @return array Source-stream registry.
     */
    public static function get_stream_registry(): array {
        return [
            'courses' => [
                'key' => 'courses',
                'label' => 'stream_courses',
                'transport' => 'query_courses',
                'phases' => ['courses'],
                'legacyaliases' => ['courses' => []],
                'defaultenabled' => ['courses' => true],
                'watermarkmode' => ['courses' => 'delta'],
                'healthcheckphase' => 'courses',
            ],
        ];
    }

    /**
     * Throw a raw diagnostic that the event boundary must redact.
     *
     * @param array $requests Source-stream requests.
     * @return array Never returns.
     */
    public function fetch_streams(array $requests): array {
        unset($requests);
        throw new \RuntimeException(
            'HTTP 500 Api-Authorization-Key=SECRET-API-KEY auth.user=SECRET-AUTH-USER '
            . 'auth.cred=SECRET-AUTH-CRED raw_payload={"pasword":"SECRET-PASWORD"}'
        );
    }
}
