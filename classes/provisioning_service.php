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
 * Queues and executes source-neutral course template provisioning.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Owns the source-locked provisioning queue and synchronous task lifecycle.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provisioning_service {
    use provisioning_task_queue_trait;
    use provisioning_course_gateway_trait;
    use provisioning_failure_recovery_trait;
    use provisioning_followup_queue_trait;

    /** @var \core\lock\lock[] Destination shortname locks held by this execution. */
    private $shortnamelocks = [];

    /**
     * Persist and queue a source-owned provisioning request once.
     *
     * @param string $sourcecomponent Source plugin component name.
     * @param string $courseidnumber Source course identity.
     * @param string $desiredshortname Destination shortname after provisioning.
     * @param string $desiredfullname Destination fullname after provisioning.
     * @param int $categoryid Destination category ID.
     * @param string|null $templatekey Configured source template key.
     * @param int $startdate Destination course start timestamp.
     * @param int $enddate Destination course end timestamp.
     * @param int $executionuserid Explicit adhoc task execution user ID.
     * @return \stdClass Existing or newly queued provision record.
     */
    public function queue(
        string $sourcecomponent,
        string $courseidnumber,
        string $desiredshortname,
        string $desiredfullname,
        int $categoryid,
        ?string $templatekey,
        int $startdate,
        int $enddate,
        int $executionuserid
    ): \stdClass {
        global $DB;

        if ($executionuserid <= 0) {
            throw new \coding_exception('Provisioning requires an explicit execution user.');
        }

        $repository = new provisioning_repository();
        $lock = provisioning_repository::get_source_lock($sourcecomponent, $courseidnumber);
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $existing = $repository->get_by_source_course($sourcecomponent, $courseidnumber);
                if ($existing !== null) {
                    if ($existing->status === provisioning_repository::STATUS_FAILED) {
                        throw new \coding_exception('Failed provisioning requires an explicit authorised retry.');
                    }
                    $transaction->allow_commit();
                    return $existing;
                }

                $record = $repository->create_pending(
                    $sourcecomponent,
                    $courseidnumber,
                    $desiredshortname,
                    $desiredfullname,
                    $categoryid,
                    $this->resolve_templateid($templatekey),
                    null,
                    $startdate,
                    $enddate,
                    $executionuserid
                );
                $this->queue_task($record);
                $transaction->allow_commit();
                return $record;
            } catch (\Throwable $exception) {
                if (!$transaction->is_disposed()) {
                    $transaction->rollback($exception);
                }
                throw $exception;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Return whether a provision may be retried without a runnable matching task.
     *
     * @param \stdClass $record Persisted provision record.
     * @return bool Whether retry is operationally available.
     */
    public function is_retry_available(\stdClass $record): bool {
        if (
            in_array($record->status, [
            provisioning_repository::STATUS_READY,
            provisioning_repository::STATUS_FALLBACK_READY,
            ], true)
        ) {
            return false;
        }
        if (
            !in_array($record->status, [
            provisioning_repository::STATUS_FAILED,
            provisioning_repository::STATUS_PENDING,
            provisioning_repository::STATUS_RUNNING,
            ], true)
        ) {
            return false;
        }
        return !$this->has_runnable_provision_task($record);
    }

    /**
     * Prove one durable provision can be safely reset and queue its replacement task.
     *
     * @param int $provisionid Provision record ID.
     * @param int $executionuserid Explicit replacement task execution user ID.
     * @return \stdClass Pending retry record.
     */
    public function retry(int $provisionid, int $executionuserid): \stdClass {
        return $this->requeue($provisionid, $executionuserid, false);
    }

    /**
     * Return whether a deleted successful destination can be manually recovered.
     *
     * @param \stdClass $record Persisted provision record.
     * @return bool Whether recovery is available without a matching runnable task.
     */
    public function is_recovery_available(\stdClass $record): bool {
        return provisioning_repository::is_recovery_candidate($record) && !$this->has_runnable_provision_task($record);
    }

    /**
     * Recover a deleted successful destination using its saved provisioning request.
     *
     * @param int $provisionid Provision record ID.
     * @param int $executionuserid Replacement task execution user.
     * @return \stdClass Pending recovery record.
     */
    public function recover(int $provisionid, int $executionuserid): \stdClass {
        return $this->requeue($provisionid, $executionuserid, true);
    }

    /**
     * Authorize a row reset and publish its replacement task in one source-locked transaction.
     *
     * @param int $provisionid Provision record ID.
     * @param int $executionuserid Replacement task execution user.
     * @param bool $recover Whether to recover a deleted successful destination instead of retrying.
     * @return \stdClass Pending provision record.
     */
    private function requeue(int $provisionid, int $executionuserid, bool $recover): \stdClass {
        global $DB;

        require_capability('moodle/site:config', \context_system::instance());
        if ($executionuserid <= 0) {
            throw new \coding_exception('Provisioning retry requires an execution user.');
        }
        $user = \core_user::get_user($executionuserid, '*', MUST_EXIST);
        \core_user::require_active_user($user, true, true);

        $repository = new provisioning_repository();
        $record = $repository->get($provisionid);
        $lock = provisioning_repository::get_source_lock($record->sourcecomponent, $record->courseidnumber);
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $record = $repository->get($provisionid);
                $available = $recover ? $this->is_recovery_available($record) : $this->is_retry_available($record);
                if (!$available) {
                    throw new \coding_exception('Provisioning retry is not available for this state.');
                }
                $this->set_execution_user($user);
                if ($recover) {
                    if ($this->get_temporary_courses($record) !== [] || $this->get_desired_courses($record) !== []) {
                        throw new \coding_exception('Provisioning recovery conflicts with an existing course.');
                    }
                } else {
                    $this->prove_retry_recovery_safe($repository, $record);
                }
                $this->remove_stale_provision_tasks((int)$record->id);
                if ($recover) {
                    $record = $repository->prepare_authorized_recovery((int)$record->id, $executionuserid);
                } else {
                    $record = $repository->prepare_authorized_retry(
                        (int)$record->id,
                        $executionuserid,
                        $record->status !== provisioning_repository::STATUS_FAILED,
                        true
                    );
                }
                $this->queue_task($record);
                $transaction->allow_commit();
                return $record;
            } catch (\Throwable $exception) {
                if (!$transaction->is_disposed()) {
                    $transaction->rollback($exception);
                }
                throw $exception;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Queue and mark follow-up synchronisations for terminal successful provisions.
     *
     * @return void
     */
    public function reconcile_pending_followups(): void {
        $repository = new provisioning_repository();
        foreach ($repository->get_terminal_rows_pending_followup() as $candidate) {
            try {
                $lock = provisioning_repository::get_source_lock($candidate->sourcecomponent, $candidate->courseidnumber);
                try {
                    $record = $repository->get((int)$candidate->id);
                    if (
                        !in_array($record->status, [
                        provisioning_repository::STATUS_READY,
                        provisioning_repository::STATUS_FALLBACK_READY,
                        ], true) || (int)$record->followupqueued !== 0
                    ) {
                        continue;
                    }
                    $this->queue_followup_and_mark($repository, $record);
                } finally {
                    $lock->release();
                }
            } catch (\Throwable $exception) {
                logger::log(
                    'provision_followup',
                    'course',
                    (string)$candidate->courseidnumber,
                    'fail',
                    'Unable to queue provision follow-up.'
                );
            }
        }
    }

    /**
     * Execute one persisted task payload while holding its source lock.
     *
     * @param int $provisionid Provision record ID from task metadata.
     * @param string $jobid Durable job identity from task metadata.
     * @param int $taskuserid Explicit task execution user ID.
     * @return void
     */
    public function execute_provision(int $provisionid, string $jobid, int $taskuserid): void {
        $repository = new provisioning_repository();
        $record = $repository->get($provisionid);
        if ($jobid === '' || !hash_equals($record->jobid, $jobid)) {
            return;
        }

        $lock = provisioning_repository::get_source_lock($record->sourcecomponent, $record->courseidnumber);

        try {
            $record = $repository->get($provisionid);
            if (
                !hash_equals($record->jobid, $jobid)
                    || $taskuserid <= 0
                    || $record->executionuserid === null
                    || (int)$record->executionuserid !== $taskuserid
            ) {
                return;
            }
            if ($record->status === provisioning_repository::STATUS_PENDING) {
                $record = $repository->mark_running((int)$record->id);
            } else if ($record->status !== provisioning_repository::STATUS_RUNNING) {
                return;
            }

            try {
                $this->lock_shortname($record->desiredshortname);
                $user = $this->get_execution_user($record, $taskuserid);
                $this->set_execution_user($user);
                $this->execute_running_provision($repository, $record);
            } catch (\Throwable $exception) {
                $this->handle_provision_failure($repository, $record, $jobid, $exception);
            } finally {
                $this->release_shortname_locks();
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Obtain the cross-identity destination shortname lock.
     *
     * @param string $shortname Desired destination shortname.
     * @return \core\lock\lock Acquired shortname lock.
     */
    protected function get_shortname_lock(string $shortname): \core\lock\lock {
        return provisioning_repository::get_shortname_lock($shortname);
    }

    /**
     * Retain one normalized shortname lock until execution completes.
     *
     * @param string $shortname Destination shortname.
     * @return void
     */
    protected function lock_shortname(string $shortname): void {
        $key = \core_text::strtolower(trim($shortname));
        if (isset($this->shortnamelocks[$key])) {
            return;
        }
        $this->shortnamelocks[$key] = $this->get_shortname_lock($shortname);
    }

    /**
     * Release every retained destination shortname lock.
     *
     * @return void
     */
    private function release_shortname_locks(): void {
        foreach (array_reverse($this->shortnamelocks) as $lock) {
            $lock->release();
        }
        $this->shortnamelocks = [];
    }
}
