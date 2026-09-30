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
 * Runs an explicit SIS sync in the adhoc task queue.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\task;

/**
 * Persists only safe manual-sync status and configuration after source work completes.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manual_sync_task extends \core\task\adhoc_task {
    /**
     * Execute a manual sync and persist its safe completion state.
     *
     * @return void
     */
    public function execute(): void {
        $start = microtime(true);
        $mode = \local_wisa\event_logger::mode_label((bool)get_config('local_wisa', 'dry_run'));
        try {
            (new \local_wisa\sync_manager())->run_full_sync();
            $status = (string)get_config('local_wisa', 'last_run_status');
            set_config('last_manual_sync_status', $status, 'local_wisa');
            set_config('last_manual_sync_mode', $mode, 'local_wisa');
            set_config('last_manual_sync_time', time(), 'local_wisa');
            \local_wisa\event_logger::admin_action(\local_wisa\event\admin_manual_sync_run::class, [
                'source' => \local_wisa\event_logger::active_source(),
                'mode' => $mode,
                'status' => $status,
                'forcefull' => false,
                'duration_seconds' => (int)round(microtime(true) - $start),
            ]);
        } catch (\Throwable $exception) {
            set_config('last_manual_sync_status', 'failed', 'local_wisa');
            set_config('last_manual_sync_mode', $mode, 'local_wisa');
            set_config('last_manual_sync_time', time(), 'local_wisa');
            \local_wisa\event_logger::admin_action(\local_wisa\event\admin_manual_sync_run::class, [
                'source' => \local_wisa\event_logger::active_source(),
                'mode' => $mode,
                'status' => 'failed',
                'forcefull' => false,
                'duration_seconds' => (int)round(microtime(true) - $start),
            ]);
            throw $exception;
        }
    }
}
