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
 * Source fixture for source-stream parent runtime tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Call-counting stream source used by parent runtime tests.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_stream_runtime_test_source implements source_interface {
    /** @var array Batches received by the source. */
    public $requests = [];

    /**
     * Return one enabled delta user stream.
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

    /**
     * Return successful empty results for each requested tuple.
     *
     * @param array $requests Source-stream request envelopes.
     * @return array Source-stream result envelopes.
     */
    public function fetch_streams(array $requests): array {
        $this->requests[] = $requests;
        $results = [];
        foreach ($requests as $request) {
            $results[] = source_stream_envelope::build_result(
                $request['stream'],
                $request['phase'],
                $request['transport'],
                'success',
                [],
                ''
            );
        }
        return $results;
    }
}
