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
 * Runs one synchronous template duplication in the adhoc task queue.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\task;

/**
 * Delegates persisted course provisioning to the parent lifecycle service.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provision_course_task extends \core\task\adhoc_task {
    /**
     * Return the localized adhoc task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_provision_course', 'local_wisa');
    }

    /**
     * Execute a task carrying only a durable provision identity and job ID.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $provisionid = is_object($data) && isset($data->provisionid) ? (int)$data->provisionid : 0;
        $jobid = is_object($data) && isset($data->jobid) ? (string)$data->jobid : '';
        $userid = (int)$this->get_userid();
        if ($provisionid <= 0 || $jobid === '' || $userid <= 0) {
            return;
        }
        (new \local_wisa\provisioning_service())->execute_provision($provisionid, $jobid, $userid);
    }
}
