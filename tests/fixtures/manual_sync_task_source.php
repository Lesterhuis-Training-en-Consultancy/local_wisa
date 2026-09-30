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
 * Source fixture for manual sync task outcome tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_manualsyncfixture;

defined('MOODLE_INTERNAL') || die();

/**
 * Fixture source for manual sync task outcome tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source implements \local_wisa\source_interface {
    /** @var array Configured result status by stream. */
    public static $outcomes = [];

    /** @var bool Whether fetching throws an unexpected exception. */
    public static $throwexception = false;

    /**
     * Return the static test stream registry.
     *
     * @return array
     */
    public static function get_stream_registry(): array {
        return [
            'students' => self::descriptor('students'),
            'teachers' => self::descriptor('teachers'),
        ];
    }

    /**
     * Return configured result envelopes for requested tuples.
     *
     * @param array $requests Source-stream request envelopes.
     * @return array
     */
    public function fetch_streams(array $requests): array {
        if (self::$throwexception) {
            throw new \RuntimeException('Unexpected manual sync fixture failure.');
        }

        $results = [];
        foreach ($requests as $request) {
            $status = self::$outcomes[$request['stream']] ?? 'success';
            $results[] = \local_wisa\source_stream_envelope::build_result(
                $request['stream'],
                $request['phase'],
                $request['transport'],
                $status,
                [],
                $status === 'success' ? '' : 'fixture_failed'
            );
        }
        return $results;
    }

    /**
     * Build one user stream descriptor.
     *
     * @param string $stream Source stream key.
     * @return array
     */
    private static function descriptor(string $stream): array {
        return [
            'key' => $stream,
            'label' => 'stream_' . $stream,
            'transport' => 'query_' . $stream,
            'phases' => ['users'],
            'legacyaliases' => ['users' => []],
            'defaultenabled' => ['users' => true],
            'watermarkmode' => ['users' => 'delta'],
            'healthcheckphase' => 'users',
        ];
    }
}
