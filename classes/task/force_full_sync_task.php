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
 * Runs an explicit force-full SIS sync in the adhoc task queue.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\task;

/**
 * Executes force-full synchronization without changing dry-run.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class force_full_sync_task extends \core\task\adhoc_task {
    /**
     * Execute a force-full synchronization and persist its safe outcome.
     *
     * @return void
     */
    public function execute(): void {
        $start = microtime(true);
        $mode = \local_wisa\event_logger::mode_label((bool)get_config('local_wisa', 'dry_run'));
        try {
            require_capability('moodle/site:config', \context_system::instance());
            $manager = $this->create_sync_manager();
            $success = $manager->run_full_sync(true);
            $status = (string)$manager->get_run_status();
            if (!in_array($status, ['success', 'partial', 'failed'], true)) {
                $status = $success ? 'success' : 'failed';
            }
            $this->record_outcome($status, $mode, $start);
        } catch (\Throwable $exception) {
            $this->record_outcome('failed', $mode, $start);
        }
    }

    /**
     * Create the synchronization manager used by the task.
     *
     * @return \local_wisa\sync_manager Synchronization manager.
     */
    protected function create_sync_manager(): \local_wisa\sync_manager {
        return new \local_wisa\sync_manager();
    }

    /**
     * Persist and emit the bounded force-full outcome.
     *
     * @param string $status Synchronization outcome.
     * @param string $mode DRY-RUN or LIVE.
     * @param float $start Start timestamp.
     * @return void
     */
    private function record_outcome(string $status, string $mode, float $start): void {
        set_config('last_force_full_sync_status', $status, 'local_wisa');
        set_config('last_force_full_sync_mode', $mode, 'local_wisa');
        set_config('last_force_full_sync_time', time(), 'local_wisa');
        \local_wisa\event_logger::admin_action(\local_wisa\event\admin_manual_sync_run::class, [
            'source' => \local_wisa\event_logger::active_source(),
            'mode' => $mode,
            'status' => $status,
            'forcefull' => true,
            'duration_seconds' => (int)round(microtime(true) - $start),
        ]);
    }
}
