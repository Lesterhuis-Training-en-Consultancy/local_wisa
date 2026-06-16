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
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GNU GPL v3 or later
 */

namespace local_wisa\task;

defined('MOODLE_INTERNAL') || die();

use local_wisa\sync_manager;

/**
 * One-shot background task queued from the preview/approval page when an admin
 * approves the first full load. Running it through cron (instead of synchronously
 * in the web request) means a large initial load cannot hit the web-server
 * timeout. sync_manager::run_full_sync() opens the gate (initial_load_done) itself
 * on a successful live run, so from then on the scheduled delta sync takes over.
 */
class initial_load_task extends \core\task\adhoc_task {
    public function get_name() {
        return get_string('task_initial_load', 'local_wisa');
    }

    public function execute() {
        try {
            mtrace('local_wisa: starting approved first full load in the background...');
            (new sync_manager())->run_full_sync(true, true);
            mtrace('local_wisa: first full load finished.');
        } finally {
            // Clear the "queued" flag whatever happens, so the preview page does not
            // keep showing "load running" after a crash or fatal error.
            set_config('initial_load_queued', 0, 'local_wisa');
        }
    }
}
