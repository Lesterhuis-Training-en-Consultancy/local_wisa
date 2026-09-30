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
 * Provisioning task queue operations.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Provides provisioning task queue operations.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait provisioning_task_queue_trait {
    /**
     * Queue the task that carries only the durable provision identity.
     *
     * @param \stdClass $record Pending provision record.
     * @return void
     */
    protected function queue_task(\stdClass $record): void {
        $task = new \local_wisa\task\provision_course_task();
        $task->set_userid((int)$record->executionuserid);
        $task->set_custom_data([
            'provisionid' => (int)$record->id,
            'jobid' => $record->jobid,
        ]);
        if (!\core\task\manager::queue_adhoc_task($task, true)) {
            throw new \coding_exception('Unable to queue the local_wisa course provision task.');
        }
    }

    /**
     * Remove every stale provision task associated with one provision record before replacement.
     *
     * @param int $provisionid Provision record ID.
     * @return void
     */
    private function remove_stale_provision_tasks(int $provisionid): void {
        global $DB;

        foreach (\core\task\manager::get_adhoc_tasks(\local_wisa\task\provision_course_task::class) as $task) {
            $data = $task->get_custom_data();
            if (
                is_object($data) && isset($data->provisionid)
                    && (int)$data->provisionid === $provisionid
            ) {
                if (method_exists('\\core\\task\\manager', 'delete_adhoc_task')) {
                    \core\task\manager::delete_adhoc_task($task->get_id());
                } else {
                    // Moodle 4.0 has no manager API for deleting a single adhoc task.
                    $DB->delete_records('task_adhoc', ['id' => $task->get_id()]);
                }
            }
        }
    }

    /**
     * Return whether one exact provision task remains runnable in this Moodle version.
     *
     * @param \stdClass $record Persisted provision record.
     * @return bool Whether an exact runnable task exists.
     */
    private function has_runnable_provision_task(\stdClass $record): bool {
        foreach (\core\task\manager::get_adhoc_tasks(\local_wisa\task\provision_course_task::class) as $task) {
            $data = $task->get_custom_data();
            if (
                !is_object($data) || !isset($data->provisionid, $data->jobid)
                    || (int)$data->provisionid !== (int)$record->id
                    || !hash_equals($record->jobid, (string)$data->jobid)
                    || (int)$task->get_userid() !== (int)$record->executionuserid
            ) {
                continue;
            }
            if (method_exists($task, 'get_attempts_available') && $task->get_attempts_available() <= 0) {
                continue;
            }
            return true;
        }
        return false;
    }
}
