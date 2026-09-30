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
 * Runs an explicit SIS connection test in the adhoc task queue.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\task;

/**
 * Persists only safe connection status and count after source work completes.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connection_test_task extends \core\task\adhoc_task {
    /**
     * Execute a connection test and persist its safe summary.
     *
     * @return void
     */
    public function execute(): void {
        $start = microtime(true);
        $component = '';
        $stream = '';
        $phase = '';
        $transport = '';
        try {
            $data = (array)$this->get_custom_data();
            if (
                array_keys($data) !== ['component', 'stream', 'phase'] ||
                    !is_string($data['component']) || !is_string($data['stream']) || !is_string($data['phase'])
            ) {
                throw new \coding_exception('Invalid connection-test task data.');
            }
            $component = $data['component'];
            $stream = $data['stream'];
            $phase = $data['phase'];
            $registry = \local_wisa\source_factory::get_registry_for_component($component);
            if (!isset($registry[$stream]) || $registry[$stream]['key'] !== $stream) {
                throw new \coding_exception('Invalid connection-test task descriptor.');
            }
            $descriptor = $registry[$stream];
            if ($descriptor['healthcheckphase'] !== $phase || !in_array($phase, $descriptor['phases'], true)) {
                throw new \coding_exception('Invalid connection-test task phase.');
            }
            $transport = $descriptor['transport'];
            $request = \local_wisa\source_stream_envelope::build_request($descriptor, $phase, null, true);
            if (
                $request['stream'] !== $stream || $request['phase'] !== $phase ||
                    $request['transport'] !== $transport
            ) {
                throw new \coding_exception('Invalid connection-test request tuple.');
            }

            $state = new \local_wisa\source_stream_state($component);
            $tuplesnapshot = $state->get_tuple($stream, $phase);
            $source = \local_wisa\source_factory::get_source_for_component($component);
            try {
                $fetched = $source->fetch_streams([$request]);
            } finally {
                if ($tuplesnapshot !== $state->get_tuple($stream, $phase)) {
                    throw new \coding_exception('The connection test changed source-stream state.');
                }
            }
            $results = \local_wisa\source_stream_envelope::validate_results([$request], $fetched);
            $result = $results[$stream . ':' . $phase];
            $status = $result['status'] === 'success' ? 'success' : 'failed';
            $count = count($result['rows']);
            self::record_status($component, $stream, $phase, $transport, $status, $count);
            \local_wisa\event_logger::admin_action(\local_wisa\event\admin_test_connection_run::class, [
                'source' => $component,
                'component' => $component,
                'stream' => $stream,
                'phase' => $phase,
                'transport' => $transport,
                'status' => $status,
                'duration_ms' => (int)round((microtime(true) - $start) * 1000),
                'count' => $count,
            ]);
        } catch (\Throwable $exception) {
            if ($component !== '' && $stream !== '' && $phase !== '' && $transport !== '') {
                self::record_status($component, $stream, $phase, $transport, 'failed', 0);
                \local_wisa\event_logger::admin_action(\local_wisa\event\admin_test_connection_run::class, [
                    'source' => $component,
                    'component' => $component,
                    'stream' => $stream,
                    'phase' => $phase,
                    'transport' => $transport,
                    'status' => 'failed',
                    'duration_ms' => (int)round((microtime(true) - $start) * 1000),
                    'count' => 0,
                ]);
            } else {
                set_config('last_connection_test_status', 'failed', 'local_wisa');
                set_config('last_connection_test_time', time(), 'local_wisa');
            }
            throw new \coding_exception('The connection test failed.');
        }
    }

    /**
     * Persist one redacted connection-test summary.
     *
     * @param string $component Source component.
     * @param string $stream Stream key.
     * @param string $phase Health-check phase.
     * @param string $transport Transport key.
     * @param string $status Safe result status.
     * @param int $count Returned row count.
     * @return void
     */
    private static function record_status(
        string $component,
        string $stream,
        string $phase,
        string $transport,
        string $status,
        int $count
    ): void {
        set_config('last_connection_test_source', $component, 'local_wisa');
        set_config('last_connection_test_stream', $stream, 'local_wisa');
        set_config('last_connection_test_phase', $phase, 'local_wisa');
        set_config('last_connection_test_transport', $transport, 'local_wisa');
        set_config('last_connection_test_status', $status, 'local_wisa');
        set_config('last_connection_test_count', $count, 'local_wisa');
        set_config('last_connection_test_time', time(), 'local_wisa');
    }
}
