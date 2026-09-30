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
 * Source fixture for queued preview task tests.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_previewtaskfixture;

/**
 * Returns non-empty results and records the preview request batch.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source implements \local_wisa\source_interface {
    /** @var array Source request batches. */
    public static array $requests = [];

    /**
     * Return the fixture stream registry.
     *
     * @return array Stream descriptors.
     */
    public static function get_stream_registry(): array {
        return [
            'courses' => self::descriptor('courses', 'courses'),
            'accounts' => self::descriptor('accounts', 'users'),
            'enrolments' => self::descriptor('enrolments', 'enrolments'),
            'unenrolments' => self::descriptor('unenrolments', 'unenrolments'),
        ];
    }

    /**
     * Record requests and return non-empty success results.
     *
     * @param array $requests Source request envelopes.
     * @return array Source result envelopes.
     */
    public function fetch_streams(array $requests): array {
        self::$requests[] = $requests;
        $counts = [
            'courses' => 2,
            'users' => 3,
            'enrolments' => 4,
            'unenrolments' => 5,
        ];
        $results = [];
        foreach ($requests as $request) {
            $results[] = \local_wisa\source_stream_envelope::build_result(
                $request['stream'],
                $request['phase'],
                $request['transport'],
                'success',
                array_fill(0, $counts[$request['phase']], ['id' => 1]),
                ''
            );
        }
        return $results;
    }

    /**
     * Build a delta stream descriptor for one generic phase.
     *
     * @param string $stream Stream key.
     * @param string $phase Generic phase.
     * @return array Stream descriptor.
     */
    private static function descriptor(string $stream, string $phase): array {
        return [
            'key' => $stream,
            'label' => 'stream_' . $stream,
            'transport' => 'query_' . $stream,
            'phases' => [$phase],
            'legacyaliases' => [$phase => []],
            'defaultenabled' => [$phase => true],
            'watermarkmode' => [$phase => 'delta'],
            'healthcheckphase' => $phase,
        ];
    }
}
