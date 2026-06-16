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
 * Scheduled log cleanup task.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GNU GPL v3 or later
 */

namespace local_wisa\task;

defined('MOODLE_INTERNAL') || die();

class log_cleanup_task extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('task_log_cleanup', 'local_wisa');
    }

    public function execute() {
        global $DB;
        $days = (int)get_config('local_wisa', 'log_retention_days');
        if ($days < 1) {
            $days = 30;
        }
        $cutoff = time() - ($days * DAYSECS);
        $count = $DB->count_records_select('local_wisa_log', 'timecreated < ?', [$cutoff]);
        if ($count > 0) {
            $DB->delete_records_select('local_wisa_log', 'timecreated < ?', [$cutoff]);
            mtrace("local_wisa: deleted $count log records older than $days days.");
        } else {
            mtrace("local_wisa: no log records older than $days days.");
        }
    }
}
