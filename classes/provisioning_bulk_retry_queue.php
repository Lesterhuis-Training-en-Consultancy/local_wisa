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
 * Background provisioning bulk retry queue.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Queues one globally deduplicated bulk retry coordinator.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provisioning_bulk_retry_queue {
    /** Queue serialization lock. */
    private const LOCK_NAME = 'provisioning_bulk_retry_queue';

    /**
     * Queue selected provisioning IDs.
     *
     * @param int[] $ids Selected provision IDs.
     * @param int $userid Requesting administrator ID.
     * @return bool Whether a coordinator was newly queued.
     */
    public function queue_selected(array $ids, int $userid): bool {
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids)), function (int $id): bool {
            return $id > 0;
        }));
        return $this->queue('selected', $ids, $userid);
    }

    /**
     * Queue all failed provisioning rows as resolved during task execution.
     *
     * @param int $userid Requesting administrator ID.
     * @return bool Whether a coordinator was newly queued.
     */
    public function queue_filtered_failed(int $userid): bool {
        return $this->queue('filtered_failed', [], $userid);
    }

    /**
     * Return the currently queued coordinator state for administrator display.
     *
     * @return array Queued mode and selected provision IDs, or empty state.
     */
    public function get_queued_retry_state(): array {
        $tasks = \core\task\manager::get_adhoc_tasks(
            \local_wisa\task\provisioning_bulk_retry_task::class
        );
        foreach ($tasks as $task) {
            $data = $task->get_custom_data();
            $mode = is_object($data) && isset($data->mode) ? (string)$data->mode : '';
            if ($mode === 'filtered_failed') {
                return ['mode' => $mode, 'ids' => []];
            }
            if ($mode === 'selected' && isset($data->ids) && is_array($data->ids)) {
                $ids = array_values(array_filter(
                    array_unique(array_map('intval', $data->ids)),
                    function (int $id): bool {
                        return $id > 0;
                    }
                ));
                return ['mode' => $mode, 'ids' => $ids];
            }
        }
        return ['mode' => '', 'ids' => []];
    }

    /**
     * Consume one completed result for its requesting administrator.
     *
     * @param int $userid Current administrator ID.
     * @return array|null Result status and counts, or null when unavailable or already seen.
     */
    public function consume_result_for_user(int $userid): ?array {
        $factory = \core\lock\lock_config::get_lock_factory('local_wisa');
        $lock = $factory->get_lock(self::LOCK_NAME, 10);
        if (!$lock) {
            throw new \coding_exception('Unable to acquire the provisioning bulk retry result lock.');
        }
        try {
            $resultid = (int)get_config('local_wisa', 'last_provisioning_bulk_retry_resultid');
            $resultuserid = (int)get_config('local_wisa', 'last_provisioning_bulk_retry_userid');
            $seenresultid = (int)get_config('local_wisa', 'last_provisioning_bulk_retry_seen_resultid');
            $status = (string)get_config('local_wisa', 'last_provisioning_bulk_retry_status');
            $counts = json_decode((string)get_config('local_wisa', 'last_provisioning_bulk_retry_counts'), true);
            if (
                $resultid <= 0 || $resultuserid !== $userid || $seenresultid >= $resultid
                || !in_array($status, ['success', 'failed'], true) || !is_array($counts)
            ) {
                return null;
            }
            set_config('last_provisioning_bulk_retry_seen_resultid', $resultid, 'local_wisa');
            return ['status' => $status, 'counts' => $counts];
        } finally {
            $lock->release();
        }
    }

    /**
     * Queue one coordinator under a global action lock.
     *
     * @param string $mode Selected or filtered-failed mode.
     * @param int[] $ids Selected provision IDs.
     * @param int $userid Requesting administrator ID.
     * @return bool Whether a coordinator was newly queued.
     */
    private function queue(string $mode, array $ids, int $userid): bool {
        require_capability('moodle/site:config', \context_system::instance());
        $factory = \core\lock\lock_config::get_lock_factory('local_wisa');
        $lock = $factory->get_lock(self::LOCK_NAME, 10);
        if (!$lock) {
            throw new \coding_exception('Unable to acquire the provisioning bulk retry queue lock.');
        }
        try {
            if (\core\task\manager::get_adhoc_tasks(\local_wisa\task\provisioning_bulk_retry_task::class) !== []) {
                return false;
            }
            $task = new \local_wisa\task\provisioning_bulk_retry_task();
            $task->set_userid($userid);
            $task->set_custom_data(['mode' => $mode, 'ids' => $ids]);
            if (!\core\task\manager::queue_adhoc_task($task)) {
                throw new \coding_exception('Unable to queue the provisioning bulk retry coordinator.');
            }
            set_config('last_provisioning_bulk_retry_status', 'queued', 'local_wisa');
            set_config('last_provisioning_bulk_retry_time', time(), 'local_wisa');
            unset_config('last_provisioning_bulk_retry_counts', 'local_wisa');
            unset_config('last_provisioning_bulk_retry_resultid', 'local_wisa');
            unset_config('last_provisioning_bulk_retry_userid', 'local_wisa');
            unset_config('last_provisioning_bulk_retry_seen_resultid', 'local_wisa');
            return true;
        } finally {
            $lock->release();
        }
    }
}
