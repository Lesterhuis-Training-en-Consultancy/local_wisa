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
 * Scheduled delta synchronisation task.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\task;


use local_wisa\sync_manager;

/**
 * Runs the regular SIS delta synchronisation.
 */
class sync_task extends \core\task\scheduled_task {
    /**
     * Return the scheduled task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_sync', 'local_wisa');
    }

    /**
     * Execute the scheduled delta synchronisation.
     */
    public function execute() {
        if (get_config('local_wisa', 'initial_load_done') !== '1') {
            mtrace('local_wisa: initial full load not yet approved; skipping scheduled sync. '
                . 'Approve it on the SIS preview page first.');
            return;
        }
        $manager = new sync_manager();
        $manager->run_full_sync();
    }
}
