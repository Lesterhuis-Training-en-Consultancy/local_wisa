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
 * Adhoc task that runs the approved first full load in the background.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\task;

/**
 * One-shot background task queued from the preview/approval page when an admin
 * approves the first full load. Running it through cron (instead of synchronously
 * in the web request) means a large initial load cannot hit the web-server
 * timeout. sync_manager::run_full_sync() opens the gate (initial_load_done) itself
 * on a successful live run, so from then on the scheduled delta sync takes over.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class initial_load_task extends \core\task\adhoc_task {
    /**
     * Return the adhoc task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_initial_load', 'local_wisa');
    }

    /**
     * Execute the approved first full load.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $jobid = is_object($data) && isset($data->jobid) ? (string)$data->jobid : '';
        try {
            mtrace('local_wisa: starting approved first full load in the background...');
            if ((new \local_wisa\sync_manager())->run_full_sync(true, true)) {
                mtrace('local_wisa: first full load finished.');
            } else {
                mtrace('local_wisa: first full load did not complete; initial-load gate remains closed.');
            }
        } finally {
            \local_wisa\explicit_action_queue::complete_initial_load($jobid);
        }
    }
}
