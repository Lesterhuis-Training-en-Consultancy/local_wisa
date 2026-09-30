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
 * Runs an explicit SIS preview in the adhoc task queue.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\task;

/**
 * Persists only safe preview counts and status after source work completes.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_task extends \core\task\adhoc_task {
    /**
     * Execute a read-only preview and persist its safe summary.
     *
     * @return void
     */
    public function execute(): void {
        $start = microtime(true);
        try {
            $component = \local_wisa\source_factory::get_active_component();
            $source = \local_wisa\source_factory::get_source_for_component($component);
            $preview = (new \local_wisa\sync_preview($source, $component))->preview();
            $counts = $preview['counts'];
            $status = $preview['status'];
            set_config('last_preview_status', $status, 'local_wisa');
            set_config('last_preview_counts', json_encode($counts), 'local_wisa');
            set_config('last_preview_window', $preview['window'], 'local_wisa');
            set_config('last_preview_time', time(), 'local_wisa');
            \local_wisa\event_logger::admin_action(\local_wisa\event\admin_preview_run::class, [
                'source' => \local_wisa\event_logger::active_source(),
                'status' => $status,
                'duration_ms' => (int)round((microtime(true) - $start) * 1000),
                'courses_found' => $counts['courses'],
                'users_found' => $counts['users'],
            ]);
        } catch (\Throwable $exception) {
            set_config('last_preview_status', 'failed', 'local_wisa');
            set_config('last_preview_time', time(), 'local_wisa');
            throw $exception;
        }
    }
}
