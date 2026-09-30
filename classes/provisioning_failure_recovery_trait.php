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
 * Provisioning failure recovery operations.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Provides provisioning failure recovery operations.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait provisioning_failure_recovery_trait {
    /**
     * Prove that retry recovery cannot preserve or duplicate an ambiguous destination.
     *
     * @param provisioning_repository $repository Provision state repository.
     * @param \stdClass $record Persisted provision record.
     * @return void
     */
    private function prove_retry_recovery_safe(provisioning_repository $repository, \stdClass $record): void {
        global $DB;

        $temporarycourses = $this->get_temporary_courses($record);
        $desiredcourses = $this->get_desired_courses($record);
        $pointercourse = $this->get_pointer_course($record);
        if (count($desiredcourses) !== 0 || count($temporarycourses) > 1) {
            throw new \coding_exception('Provisioning retry recovery is ambiguous.');
        }
        if (count($temporarycourses) === 1) {
            $temporarycourse = reset($temporarycourses);
            if (
                (int)$record->tempprecallabsent !== 1
                    || ($record->courseid !== null && (int)$record->courseid !== (int)$temporarycourse->id)
            ) {
                throw new \coding_exception('Provisioning retry temporary course cannot be proven.');
            }
            $this->require_recovery_capabilities($record);
            require_capability('moodle/course:delete', \context_course::instance((int)$temporarycourse->id));
            if (
                !delete_course($temporarycourse, false)
                    || $DB->record_exists('course', ['shortname' => $record->tempshortname])
            ) {
                throw new \coding_exception('Provisioning retry temporary course deletion could not be proven.');
            }
            return;
        }
        if ($pointercourse !== null) {
            throw new \coding_exception('Provisioning retry destination pointer conflicts with recovery.');
        }
    }

    /**
     * Validate and return the active user bound to a task and persisted record.
     *
     * @param \stdClass $record Running provision record.
     * @param int $taskuserid Explicit task user ID.
     * @return \stdClass Active Moodle user.
     */
    private function get_execution_user(\stdClass $record, int $taskuserid): \stdClass {
        if (
            $taskuserid <= 0 || $record->executionuserid === null
                || (int)$record->executionuserid !== $taskuserid
        ) {
            throw new \coding_exception('Provisioning task execution user does not match its record.');
        }
        $user = \core_user::get_user($taskuserid, '*', MUST_EXIST);
        \core_user::require_active_user($user, true, true);
        return $user;
    }

    /**
     * Ensure capability checks and core duplication execute as the persisted task user.
     *
     * @param \stdClass $user Active Moodle user.
     * @return void
     */
    private function set_execution_user(\stdClass $user): void {
        global $USER;

        if (!isset($USER->id) || (int)$USER->id !== (int)$user->id) {
            \core\session\manager::init_empty_session();
            \core\session\manager::set_user($user);
        }
    }

    /**
     * Classify one source-locked running job before any duplicate or fallback side effect.
     *
     * @param provisioning_repository $repository Provision state repository.
     * @param \stdClass $record Running provision record.
     * @return void
     */
    private function execute_running_provision(provisioning_repository $repository, \stdClass $record): void {
        $temporarycourses = $this->get_temporary_courses($record);
        if ($temporarycourses === [] && $record->courseid === null && (int)$record->tempprecallabsent === 0) {
            $record = $this->resolve_available_shortname($repository, $record);
        }
        $desiredcourses = $this->get_desired_courses($record);
        $pointercourse = $this->get_pointer_course($record);

        if (count($temporarycourses) > 1 || count($desiredcourses) > 1) {
            $code = count($desiredcourses) > 1
                ? provisioning_diagnostic::CODE_SHORTNAME_COLLISION
                : provisioning_diagnostic::CODE_UNKNOWN;
            $this->mark_failed($repository, (int)$record->id, $code);
            return;
        }

        if (count($temporarycourses) === 1) {
            $temporarycourse = reset($temporarycourses);
            if (count($desiredcourses) !== 0) {
                $this->mark_failed(
                    $repository,
                    (int)$record->id,
                    provisioning_diagnostic::CODE_SHORTNAME_COLLISION
                );
                return;
            }
            if (
                (int)$record->tempprecallabsent !== 1
                || ($record->courseid !== null && (int)$record->courseid !== (int)$temporarycourse->id)
            ) {
                $this->mark_failed($repository, (int)$record->id, provisioning_diagnostic::CODE_UNKNOWN);
                return;
            }
            $this->require_recovery_capabilities($record);
            $finished = $this->recover_proven_temporary_course($repository, $record, $temporarycourse);
            $this->queue_followup_and_mark($repository, $finished);
            return;
        }

        if (count($desiredcourses) === 1) {
            $desiredcourse = reset($desiredcourses);
            if (
                $record->courseid === null
                    || $pointercourse === null
                    || (int)$pointercourse->id !== (int)$desiredcourse->id
                    || !$this->has_desired_identity($desiredcourse, $record)
                    || $record->templateid !== null
            ) {
                $this->mark_failed($repository, (int)$record->id, provisioning_diagnostic::CODE_UNKNOWN);
                return;
            }
            $this->require_recovery_capabilities($record);
            $finished = $repository->finalize_destination(
                (int)$record->id,
                (int)$desiredcourse->id,
                provisioning_repository::STATUS_FALLBACK_READY,
                function (\stdClass $finalizingrecord): void {
                    $this->finalize_course_identity($finalizingrecord, true);
                }
            );
            $this->queue_followup_and_mark($repository, $finished);
            return;
        }

        if ($record->courseid !== null || $pointercourse !== null || (int)$record->tempprecallabsent !== 0) {
            $this->mark_failed($repository, (int)$record->id, provisioning_diagnostic::CODE_UNKNOWN);
            return;
        }

        if ($record->templateid === null) {
            $this->execute_unmapped_fallback($repository, $record);
            return;
        }
        $this->execute_mapped_template($repository, $record);
    }
    /**
     * Handle an ordinary worker exception without coupling production code to test crash simulation.
     *
     * @param provisioning_repository $repository Provision state repository.
     * @param \stdClass $record Running provision record.
     * @param string $jobid Durable task job identity.
     * @param \Throwable $exception Provisioning failure.
     * @return void
     */
    protected function handle_provision_failure(
        provisioning_repository $repository,
        \stdClass $record,
        string $jobid,
        \Throwable $exception
    ): void {
        $code = provisioning_diagnostic::from_exception($exception);
        if ($record->templateid === null) {
            $this->mark_failed($repository, (int)$record->id, $code);
            return;
        }
        $this->recover_mapped_failure($repository, $record, $jobid, $code);
    }

    /**
     * Persist a static failed status without retaining exception details.
     *
     * @param provisioning_repository $repository Provision state repository.
     * @param int $provisionid Provision record ID.
     * @param string $code Stable diagnostic code.
     * @return void
     */
    private function mark_failed(provisioning_repository $repository, int $provisionid, string $code): void {
        $record = $repository->get($provisionid);
        if ($record->status === provisioning_repository::STATUS_RUNNING) {
            $repository->mark_terminal($provisionid, provisioning_repository::STATUS_FAILED, $code);
        }
    }
}
