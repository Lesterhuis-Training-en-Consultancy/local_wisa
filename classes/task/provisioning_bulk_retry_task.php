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
 * Provisioning bulk retry coordinator task.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\task;

/**
 * Processes provisioning retries outside the request lifecycle.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provisioning_bulk_retry_task extends \core\task\adhoc_task {
    /** Maximum IDs passed to the retry service per coordinator step. */
    private const BATCH_SIZE = 50;

    /**
     * Process selected or filtered failed rows in bounded batches.
     *
     * @return void
     */
    public function execute(): void {
        $counts = ['queued' => 0, 'already_queued' => 0, 'ineligible' => 0, 'rejected' => 0];
        try {
            require_capability('moodle/site:config', \context_system::instance());
            $data = $this->get_custom_data();
            $mode = is_object($data) && isset($data->mode) ? (string)$data->mode : '';
            if ($mode === 'filtered_failed') {
                $ids = (new \local_wisa\provisioning_repository())->get_admin_ids(
                    \local_wisa\provisioning_repository::STATUS_FAILED
                );
            } else if ($mode === 'selected' && isset($data->ids) && is_array($data->ids)) {
                $ids = array_map('intval', $data->ids);
            } else {
                throw new \coding_exception('Provisioning bulk retry task data is invalid.');
            }
            $retry = new \local_wisa\provisioning_bulk_retry();
            foreach (array_chunk($ids, self::BATCH_SIZE) as $batch) {
                foreach ($retry->retry_ids($batch, (int)$this->get_userid()) as $outcome) {
                    if (isset($counts[$outcome])) {
                        $counts[$outcome]++;
                    }
                }
            }
            $status = 'success';
        } catch (\Throwable $exception) {
            $status = 'failed';
        }
        set_config('last_provisioning_bulk_retry_status', $status, 'local_wisa');
        set_config('last_provisioning_bulk_retry_counts', json_encode($counts), 'local_wisa');
        set_config('last_provisioning_bulk_retry_time', time(), 'local_wisa');
        set_config('last_provisioning_bulk_retry_userid', (int)$this->get_userid(), 'local_wisa');
        set_config('last_provisioning_bulk_retry_resultid', (int)$this->get_id(), 'local_wisa');
    }
}
