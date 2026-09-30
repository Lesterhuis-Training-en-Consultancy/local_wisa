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
 * Provisioning follow-up queue operations.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Provides provisioning follow-up queue operations.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait provisioning_followup_queue_trait {
    /**
     * Recover a mapped duplication failure only when the temporary ownership proof holds.
     *
     * @param provisioning_repository $repository Provision state repository.
     * @param \stdClass $runningrecord Original running provision record.
     * @param string $jobid Durable task job identity.
     * @param string $diagnosticcode Stable failure diagnostic.
     * @return void
     */
    private function recover_mapped_failure(
        provisioning_repository $repository,
        \stdClass $runningrecord,
        string $jobid,
        string $diagnosticcode
    ): void {
        try {
            $record = $repository->get((int)$runningrecord->id);
            if (
                $record->status !== provisioning_repository::STATUS_RUNNING
                    || !hash_equals($jobid, $record->jobid)
                    || !hash_equals($runningrecord->tempshortname, $record->tempshortname)
                    || (int)$record->tempprecallabsent !== 1
            ) {
                throw new \coding_exception('Provisioning temporary course ownership cannot be proven.');
            }
            $this->require_recovery_capabilities($record);
            $course = $this->get_only_temporary_course($record);
            if (
                count($this->get_desired_courses($record)) !== 0
                    || ($record->courseid !== null && (int)$record->courseid !== (int)$course->id)
            ) {
                throw new \coding_exception('Provisioning temporary course recovery is ambiguous.');
            }
            $this->queue_followup_and_mark(
                $repository,
                $this->recover_proven_temporary_course($repository, $record, $course)
            );
        } catch (\Throwable $exception) {
            $this->mark_failed($repository, (int)$runningrecord->id, $diagnosticcode);
        }
    }

    /**
     * Delete one proved job-owned temporary course and create its transactional fallback.
     *
     * @param provisioning_repository $repository Provision state repository.
     * @param \stdClass $record Running provision record.
     * @param \stdClass $course Sole proved temporary course.
     * @return \stdClass Persisted fallback terminal provision record.
     */
    private function recover_proven_temporary_course(
        provisioning_repository $repository,
        \stdClass $record,
        \stdClass $course
    ): \stdClass {
        global $DB;

        require_capability('moodle/course:delete', \context_course::instance((int)$course->id));
        if (!delete_course($course, false)) {
            throw new \coding_exception('Provisioning temporary course deletion could not be confirmed.');
        }
        if ($DB->record_exists('course', ['shortname' => $record->tempshortname])) {
            throw new \coding_exception('Provisioning temporary course deletion could not be proven.');
        }
        if ($record->courseid !== null) {
            $record = $repository->clear_recovered_destination_course((int)$record->id, (int)$course->id);
        }
        $this->after_proven_temporary_deletion($record);
        return $this->create_fallback($repository, $record);
    }

    /**
     * Create and record the sole fallback course, then log its static outcome once.
     *
     * @param provisioning_repository $repository Provision state repository.
     * @param \stdClass $record Running provision record.
     * @return void
     */
    private function create_fallback(provisioning_repository $repository, \stdClass $record): \stdClass {
        global $CFG, $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            require_once($CFG->dirroot . '/course/lib.php');
            $this->assert_no_temporary_course($record);
            $record = $this->resolve_available_shortname($repository, $record);
            $record = $repository->mark_temp_pre_call_absent((int)$record->id);
            $course = create_course((object)[
                'category' => (int)$record->categoryid,
                'shortname' => $record->tempshortname,
                'fullname' => $record->desiredfullname,
                'idnumber' => '',
                'startdate' => (int)$record->startdate,
                'enddate' => (int)$record->enddate,
            ]);
            $this->after_destination_creation($record, $course);
            $record = $this->resolve_available_shortname($repository, $record);
            $finished = $repository->finalize_destination(
                (int)$record->id,
                (int)$course->id,
                provisioning_repository::STATUS_FALLBACK_READY,
                function (\stdClass $finalizingrecord): void {
                    $this->finalize_course_identity($finalizingrecord, true);
                }
            );
            $transaction->allow_commit();
            return $finished;
        } catch (\Throwable $exception) {
            if (!$transaction->is_disposed()) {
                $transaction->rollback($exception);
            }
            throw $exception;
        }
    }

    /**
     * Queue a durable follow-up task before persisting its terminal queue marker.
     *
     * @param provisioning_repository $repository Provisioning state repository.
     * @param \stdClass $record Successful terminal provision record.
     * @return void
     */
    private function queue_followup_and_mark(provisioning_repository $repository, \stdClass $record): void {
        global $DB;

        if ((int)$record->executionuserid <= 0) {
            throw new \coding_exception('Provision follow-up requires a persisted execution user.');
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            if (!$this->has_equivalent_followup_task($record)) {
                $task = new \local_wisa\task\provision_followup_task();
                $task->set_userid((int)$record->executionuserid);
                $task->set_custom_data([
                    'provisionid' => (int)$record->id,
                    'jobid' => $record->jobid,
                ]);
                if (!\core\task\manager::queue_adhoc_task($task, true)) {
                    throw new \coding_exception('Unable to queue the local_wisa provision follow-up task.');
                }
            }

            if (!$this->has_equivalent_followup_task($record)) {
                throw new \coding_exception('Unable to queue the local_wisa provision follow-up task.');
            }
            $this->after_followup_task_exists($record);
            $repository->mark_followup_queued((int)$record->id);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            if (!$transaction->is_disposed()) {
                $transaction->rollback($exception);
            }
            throw $exception;
        }
    }

    /**
     * Return whether the exact durable follow-up task has already been queued.
     *
     * @param \stdClass $record Successful terminal provision record.
     * @return bool Whether an equivalent task exists.
     */
    private function has_equivalent_followup_task(\stdClass $record): bool {
        foreach (\core\task\manager::get_adhoc_tasks(\local_wisa\task\provision_followup_task::class) as $task) {
            $data = $task->get_custom_data();
            if (
                is_object($data) && isset($data->provisionid, $data->jobid)
                    && (int)$data->provisionid === (int)$record->id
                    && hash_equals($record->jobid, (string)$data->jobid)
                    && (int)$task->get_userid() === (int)$record->executionuserid
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checkpoint immediately after a destination is created under its temporary identity.
     *
     * @param \stdClass $record Running provision record.
     * @param \stdClass $course Temporary destination course.
     * @return void
     */
    protected function after_destination_creation(\stdClass $record, \stdClass $course): void {
    }

    /**
     * Checkpoint immediately after desired identity application inside finalization.
     *
     * @param \stdClass $record Running provision record.
     * @param \stdClass $course Destination course.
     * @return void
     */
    protected function after_desired_identity_application(\stdClass $record, \stdClass $course): void {
    }

    /**
     * Checkpoint immediately after confirmed temporary-course deletion during recovery.
     *
     * @param \stdClass $record Running provision record.
     * @return void
     */
    protected function after_proven_temporary_deletion(\stdClass $record): void {
    }

    /**
     * Checkpoint after a durable follow-up task exists and before its row marker is persisted.
     *
     * @param \stdClass $record Terminal successful provision record.
     * @return void
     */
    protected function after_followup_task_exists(\stdClass $record): void {
    }
}
