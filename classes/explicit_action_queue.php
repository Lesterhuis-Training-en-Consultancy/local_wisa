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
 * Queues explicit SIS source actions for background execution.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Coordinates explicit action tasks and their source-free initial-load state.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class explicit_action_queue {
    /** Lock name for initial-load state transitions. */
    private const INITIAL_LOAD_LOCK = 'initial_load_queue';

    /** Lock name for globally deduplicated force-full queueing. */
    private const FORCE_FULL_LOCK = 'force_full_sync_queue';

    /**
     * Queue a read-only SIS preview.
     *
     * @param int $userid User who requested the preview.
     * @return bool Whether a task was newly queued.
     */
    public static function queue_preview(int $userid): bool {
        return self::queue_action(\local_wisa\task\preview_task::class, 'preview', $userid);
    }

    /**
     * Queue a SIS connection test.
     *
     * @param int $userid User who requested the connection test.
     * @param string $component Active SIS source component.
     * @param string $stream Selected source-stream descriptor key.
     * @return bool Whether a task was newly queued.
     */
    public static function queue_connection_test(int $userid, string $component, string $stream): bool {
        $activecomponent = source_factory::get_active_component();
        if ($component !== $activecomponent) {
            throw new \coding_exception('The connection-test source is not active.');
        }

        $registry = source_factory::get_registry_for_component($component);
        if (!isset($registry[$stream]) || $registry[$stream]['key'] !== $stream) {
            throw new \coding_exception('The connection-test stream is invalid.');
        }
        $descriptor = $registry[$stream];
        $phase = $descriptor['healthcheckphase'];

        $task = new \local_wisa\task\connection_test_task();
        $task->set_userid($userid);
        $task->set_custom_data([
            'component' => $component,
            'stream' => $stream,
            'phase' => $phase,
        ]);
        $queued = (bool)\core\task\manager::queue_adhoc_task($task, true);
        if ($queued) {
            set_config('last_connection_test_status', 'queued', 'local_wisa');
            set_config('last_connection_test_time', time(), 'local_wisa');
            set_config('last_connection_test_source', $component, 'local_wisa');
            set_config('last_connection_test_stream', $stream, 'local_wisa');
            set_config('last_connection_test_phase', $phase, 'local_wisa');
            set_config('last_connection_test_transport', $descriptor['transport'], 'local_wisa');
        }
        return $queued;
    }

    /**
     * Queue a manual delta sync.
     *
     * @param int $userid User who requested the manual sync.
     * @return bool Whether a task was newly queued.
     */
    public static function queue_manual_sync(int $userid): bool {
        return self::queue_action(\local_wisa\task\manual_sync_task::class, 'manual_sync', $userid);
    }

    /**
     * Queue a force-full synchronization that preserves dry-run.
     *
     * @param int $userid User who requested the force-full sync.
     * @return bool Whether a task was newly queued.
     */
    public static function queue_force_full_sync(int $userid): bool {
        require_capability('moodle/site:config', \context_system::instance());
        $factory = \core\lock\lock_config::get_lock_factory('local_wisa');
        $lock = $factory->get_lock(self::FORCE_FULL_LOCK, 10);
        if (!$lock) {
            throw new \coding_exception('Unable to acquire the force-full queue lock.');
        }
        try {
            if (\core\task\manager::get_adhoc_tasks(\local_wisa\task\force_full_sync_task::class) !== []) {
                return false;
            }
            $task = new \local_wisa\task\force_full_sync_task();
            $task->set_userid($userid);
            $task->set_custom_data(['action' => 'force_full_sync']);
            if (!\core\task\manager::queue_adhoc_task($task)) {
                throw new \coding_exception('Unable to queue the force-full task.');
            }
            set_config('last_force_full_sync_status', 'queued', 'local_wisa');
            set_config('last_force_full_sync_time', time(), 'local_wisa');
            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Queue the approved first full load, or return its already queued job.
     *
     * @param int $userid User who approved the initial load.
     * @return array{queued: bool, jobid: string} Queue result and job identity.
     */
    public static function queue_initial_load(int $userid): array {
        $lock = self::get_initial_load_lock();
        try {
            self::reconcile_initial_load_locked();
            if (get_config('local_wisa', 'initial_load_done') === '1') {
                return ['queued' => false, 'jobid' => ''];
            }

            $jobid = (string)get_config('local_wisa', 'initial_load_jobid');
            if ($jobid !== '' && self::has_initial_load_task($jobid)) {
                return ['queued' => false, 'jobid' => $jobid];
            }

            $jobid = random_string(32);
            $task = new \local_wisa\task\initial_load_task();
            $task->set_userid($userid);
            $task->set_custom_data(['jobid' => $jobid]);
            if (!\core\task\manager::queue_adhoc_task($task, true)) {
                throw new \coding_exception('Unable to queue the local_wisa initial load task.');
            }

            set_config('dry_run', 0, 'local_wisa');
            set_config('initial_load_queued', 1, 'local_wisa');
            set_config('initial_load_jobid', $jobid, 'local_wisa');
            set_config('initial_load_queued_time', time(), 'local_wisa');

            return ['queued' => true, 'jobid' => $jobid];
        } finally {
            $lock->release();
        }
    }

    /**
     * Clear an initial-load state whose task no longer exists.
     *
     * @return bool Whether stale state was cleared.
     */
    public static function reconcile_initial_load(): bool {
        $lock = self::get_initial_load_lock();
        try {
            return self::reconcile_initial_load_locked();
        } finally {
            $lock->release();
        }
    }

    /**
     * Clear the queued state only when the completing task owns the current job.
     *
     * @param string $jobid Initial-load job identity from the task metadata.
     * @return void
     */
    public static function complete_initial_load(string $jobid): void {
        $lock = self::get_initial_load_lock();
        try {
            if ($jobid === '' || get_config('local_wisa', 'initial_load_jobid') !== $jobid) {
                return;
            }
            self::clear_initial_load_state();
        } finally {
            $lock->release();
        }
    }

    /**
     * Queue one non-initial explicit action with safe fixed task data.
     *
     * @param string $taskclass Fully qualified adhoc task class name.
     * @param string $action Fixed action name.
     * @param int $userid User who requested the action.
     * @return bool Whether a task was newly queued.
     */
    private static function queue_action(string $taskclass, string $action, int $userid): bool {
        $task = new $taskclass();
        $task->set_userid($userid);
        $task->set_custom_data(['action' => $action]);
        $queued = (bool)\core\task\manager::queue_adhoc_task($task, true);
        if ($queued) {
            set_config('last_' . $action . '_status', 'queued', 'local_wisa');
            set_config('last_' . $action . '_time', time(), 'local_wisa');
        }
        return $queued;
    }

    /**
     * Obtain the lock that serialises initial-load state transitions.
     *
     * @return \core\lock\lock Acquired initial-load state lock.
     */
    private static function get_initial_load_lock(): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory('local_wisa');
        $lock = $factory->get_lock(self::INITIAL_LOAD_LOCK, 10);
        if (!$lock) {
            throw new \coding_exception('Unable to acquire the local_wisa initial-load queue lock.');
        }
        return $lock;
    }

    /**
     * Reconcile stale initial-load state while holding the state lock.
     *
     * @return bool Whether stale state was cleared.
     */
    private static function reconcile_initial_load_locked(): bool {
        $queued = get_config('local_wisa', 'initial_load_queued') === '1';
        $jobid = (string)get_config('local_wisa', 'initial_load_jobid');
        if ($queued && $jobid !== '' && self::has_initial_load_task($jobid)) {
            return false;
        }
        if (!$queued && $jobid === '') {
            return false;
        }
        self::clear_initial_load_state();
        return true;
    }

    /**
     * Return whether an initial-load task is queued for one job identity.
     *
     * @param string $jobid Initial-load job identity.
     * @return bool Whether the corresponding task is queued.
     */
    private static function has_initial_load_task(string $jobid): bool {
        foreach (\core\task\manager::get_adhoc_tasks(\local_wisa\task\initial_load_task::class) as $task) {
            $data = $task->get_custom_data();
            $attemptsavailable = !method_exists($task, 'get_attempts_available')
                || $task->get_attempts_available() > 0;
            if (
                $attemptsavailable && is_object($data)
                    && isset($data->jobid) && (string)$data->jobid === $jobid
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Clear the persisted initial-load queued state.
     *
     * @return void
     */
    private static function clear_initial_load_state(): void {
        set_config('initial_load_queued', 0, 'local_wisa');
        unset_config('initial_load_jobid', 'local_wisa');
        unset_config('initial_load_queued_time', 'local_wisa');
    }
}
