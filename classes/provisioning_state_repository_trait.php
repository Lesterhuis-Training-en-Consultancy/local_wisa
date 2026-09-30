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
 * Provisioning repository trait.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * provisioning_state_repository_trait provisioning repository operations.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait provisioning_state_repository_trait {
    /**
     * Persist the new-to-pending transition with a newly generated job identity.
     *
     * @param string $sourcecomponent Source plugin component name.
     * @param string $courseidnumber Source course identity.
     * @param string $desiredshortname Destination shortname after provisioning.
     * @param string $desiredfullname Destination full name after provisioning.
     * @param int $categoryid Destination category ID.
     * @param int|null $templateid Template course ID when configured.
     * @param int|null $courseid Destination course ID when already known.
     * @param int $startdate Desired course start timestamp.
     * @param int $enddate Desired course end timestamp.
     * @param int|null $executionuserid User context for later execution.
     * @return \stdClass Persisted pending provision record.
     */
    public function create_pending(
        string $sourcecomponent,
        string $courseidnumber,
        string $desiredshortname,
        string $desiredfullname,
        int $categoryid,
        ?int $templateid,
        ?int $courseid,
        int $startdate,
        int $enddate,
        ?int $executionuserid
    ): \stdClass {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $now = time();
            $jobid = self::generate_jobid();
            $record = (object)[
                'sourcecomponent' => $sourcecomponent,
                'courseidnumber' => $courseidnumber,
                'status' => self::STATUS_PENDING,
                'jobid' => $jobid,
                'tempshortname' => 'local_wisa_tmp_' . $jobid,
                'desiredshortname' => $desiredshortname,
                'desiredfullname' => $desiredfullname,
                'categoryid' => $categoryid,
                'templateid' => $templateid,
                'courseid' => $courseid,
                'startdate' => $startdate,
                'enddate' => $enddate,
                'executionuserid' => $executionuserid,
                'attempts' => 0,
                'tempprecallabsent' => 0,
                'followupqueued' => 0,
                'lasterror' => null,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $record->id = $DB->insert_record(self::TABLE, $record);
            $transaction->allow_commit();
            return $record;
        } catch (\Throwable $exception) {
            if (!$transaction->is_disposed()) {
                $transaction->rollback($exception);
            }
            throw $exception;
        }
    }

    /**
     * Advance a pending provision record to running.
     *
     * @param int $id Provision record ID.
     * @return \stdClass Persisted running provision record.
     */
    public function mark_running(int $id): \stdClass {
        return $this->transition($id, self::STATUS_PENDING, self::STATUS_RUNNING, null);
    }

    /**
     * Advance a running provision record to one immutable terminal result.
     *
     * The error must already be redacted by the caller and is stored only for
     * failed results after repository length bounding.
     *
     * @param int $id Provision record ID.
     * @param string $status Terminal provisioning status.
     * @param string|null $redactederror Caller-redacted failure description.
     * @return \stdClass Persisted terminal provision record.
     */
    public function mark_terminal(int $id, string $status, ?string $redactederror = null): \stdClass {
        if (!self::is_terminal($status)) {
            throw new \coding_exception('Provisioning terminal status is invalid.');
        }
        if ($status !== self::STATUS_FAILED && $redactederror !== null) {
            throw new \coding_exception('Provisioning success states cannot store an error.');
        }
        return $this->transition($id, self::STATUS_RUNNING, $status, $redactederror);
    }

    /**
     * Record that the job-owned temporary shortname was absent before worker execution.
     *
     * @param int $id Provision record ID.
     * @return \stdClass Persisted running provision record.
     */
    public function mark_temp_pre_call_absent(int $id): \stdClass {
        global $DB;

        $lock = $this->get_provision_lock($id);
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $record = $this->get_record($id);
                if ($record->status !== self::STATUS_RUNNING) {
                    throw new \coding_exception('Provisioning state does not permit this worker update.');
                }
                if ((int)$record->tempprecallabsent !== 1) {
                    $record->tempprecallabsent = 1;
                    $record->timemodified = time();
                    $DB->update_record(self::TABLE, $record);
                }
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
     * Return whether a state forbids ordinary lifecycle transitions.
     *
     * @param string $status Provisioning state.
     * @return bool Whether the state is terminal.
     */
    public static function is_terminal(string $status): bool {
        return in_array($status, [
            self::STATUS_READY,
            self::STATUS_FALLBACK_READY,
            self::STATUS_FAILED,
        ], true);
    }

    /**
     * Obtain the exclusive nonblocking lock for one source-owned course identity.
     *
     * @param string $sourcecomponent Source plugin component name.
     * @param string $courseidnumber Source course identity.
     * @return \core\lock\lock Acquired source identity lock.
     */
    public static function get_source_lock(string $sourcecomponent, string $courseidnumber): \core\lock\lock {
        $key = self::LOCK_PREFIX . hash('sha256', $sourcecomponent . "\0" . $courseidnumber);
        $lock = \core\lock\lock_config::get_lock_factory('local_wisa')->get_lock($key, 0);
        if (!$lock) {
            throw new \coding_exception('Provisioning source identity is already being processed.');
        }
        return $lock;
    }

    /**
     * Obtain the exclusive nonblocking lock for one destination shortname.
     *
     * @param string $shortname Desired destination shortname.
     * @return \core\lock\lock Acquired shortname lock.
     */
    public static function get_shortname_lock(string $shortname): \core\lock\lock {
        $normalized = \core_text::strtolower(trim($shortname));
        $key = self::SHORTNAME_LOCK_PREFIX . hash('sha256', $normalized);
        $lock = \core\lock\lock_config::get_lock_factory('local_wisa')->get_lock($key, 0);
        if (!$lock) {
            throw new \coding_exception('Provisioning shortname is already being provisioned.');
        }
        return $lock;
    }

    /**
     * Perform one ordinary immutable-safe lifecycle state transition.
     *
     * @param int $id Provision record ID.
     * @param string $expectedstatus Required current state.
     * @param string $nextstatus Next lifecycle state.
     * @param string|null $redactederror Caller-redacted failure description.
     * @return \stdClass Persisted transitioned provision record.
     */
    private function transition(int $id, string $expectedstatus, string $nextstatus, ?string $redactederror): \stdClass {
        global $DB;

        $lock = $this->get_provision_lock($id);
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $record = $this->get_record($id);
                if ($record->status !== $expectedstatus) {
                    throw new \coding_exception('Provisioning state transition is invalid.');
                }

                $record->status = $nextstatus;
                $record->lasterror = $nextstatus === self::STATUS_FAILED
                    ? $this->bound_redacted_error($redactederror)
                    : null;
                $record->timemodified = time();
                $DB->update_record(self::TABLE, $record);
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
     * Generate a PHP 8.0-compatible random job identity.
     *
     * @return string Thirty-two hexadecimal identity characters.
     */
    private static function generate_jobid(): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * Bound caller-redacted error text to the database field size.
     *
     * @param string|null $redactederror Caller-redacted error text.
     * @return string|null Bounded error text, or null for an empty error.
     */
    private function bound_redacted_error(?string $redactederror): ?string {
        if ($redactederror === null || $redactederror === '') {
            return null;
        }
        return \core_text::substr($redactederror, 0, self::MAX_ERROR_LENGTH);
    }

    /**
     * Obtain the exclusive lock for one provision record's state transitions.
     *
     * @param int $id Provision record ID.
     * @return \core\lock\lock Acquired provision state lock.
     */
    private function get_provision_lock(int $id): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory('local_wisa')->get_lock(self::LOCK_PREFIX . $id, 0);
        if (!$lock) {
            throw new \coding_exception('Provisioning state is already being changed.');
        }
        return $lock;
    }

    /**
     * Read a provision record that must exist for a state operation.
     *
     * @param int $id Provision record ID.
     * @return \stdClass Persisted provision record.
     */
    private function get_record(int $id): \stdClass {
        global $DB;

        return $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
    }
}
