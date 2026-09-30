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
 * Nightly full reconciliation task.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\task;


use local_wisa\sync_manager;

/**
 * Runs a full (since-1900) sync once a night as a safety net for changes the delta
 * watermark could miss (e.g. a failed earlier run, or records changed without a
 * detectable delta). Disabled by default; switch it on with the enable_reconcile
 * setting once the regular delta sync has been validated.
 */
class reconcile_task extends \core\task\scheduled_task {
    /**
     * Return the scheduled task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_reconcile', 'local_wisa');
    }

    /**
     * Execute the nightly reconciliation task.
     */
    public function execute() {
        if (get_config('local_wisa', 'initial_load_done') !== '1') {
            mtrace('local_wisa: initial full load not yet approved; skipping reconciliation.');
            return;
        }
        if (get_config('local_wisa', 'enable_reconcile') !== '1') {
            mtrace('local_wisa: nightly reconciliation is disabled; skipped.');
            return;
        }
        mtrace('local_wisa: starting nightly full reconciliation (forcefull).');
        (new sync_manager())->run_full_sync(true);
    }
}
