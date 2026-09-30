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
 * provisioning_destination_repository_trait provisioning repository operations.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait provisioning_destination_repository_trait {
    /**
     * Record the sole destination course created or restored by the worker.
     *
     * @param int $id Provision record ID.
     * @param int $courseid Positive Moodle destination course ID.
     * @return \stdClass Persisted running provision record.
     */
    public function record_destination_course(int $id, int $courseid): \stdClass {
        global $DB;

        if ($courseid <= 0) {
            throw new \coding_exception('Provisioning destination course ID must be positive.');
        }
        $lock = $this->get_provision_lock($id);
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $record = $this->get_record($id);
                if ($record->status !== self::STATUS_RUNNING) {
                    throw new \coding_exception('Provisioning state does not permit this worker update.');
                }
                if ($record->courseid !== null && (int)$record->courseid !== $courseid) {
                    throw new \coding_exception('Provisioning destination course is already recorded.');
                }
                if ($record->courseid === null) {
                    $record->courseid = $courseid;
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
     * Atomically persist, verify and complete one successful destination.
     *
     * The caller callback applies and verifies the destination identity while
     * this delegated transaction and the provision lock remain active.
     *
     * @param int $id Provision record ID.
     * @param int $courseid Positive Moodle destination course ID.
     * @param string $status Successful terminal provisioning status.
     * @param callable $finalizecallback Destination identity finalization callback.
     * @return \stdClass Persisted terminal provision record.
     */
    public function finalize_destination(
        int $id,
        int $courseid,
        string $status,
        callable $finalizecallback
    ): \stdClass {
        global $DB;

        if ($courseid <= 0) {
            throw new \coding_exception('Provisioning destination course ID must be positive.');
        }
        if (!in_array($status, [self::STATUS_READY, self::STATUS_FALLBACK_READY], true)) {
            throw new \coding_exception('Provisioning successful terminal status is invalid.');
        }

        $lock = $this->get_provision_lock($id);
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $record = $this->get_record($id);
                if ($record->status !== self::STATUS_RUNNING) {
                    throw new \coding_exception('Provisioning state does not permit destination finalization.');
                }
                if ($record->courseid !== null && (int)$record->courseid !== $courseid) {
                    throw new \coding_exception('Provisioning destination course is already recorded.');
                }

                $record->courseid = $courseid;
                $record->timemodified = time();
                $DB->update_record(self::TABLE, $record);
                $finalizecallback($record);

                $record->status = $status;
                $record->lasterror = null;
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
     * Clear a destination pointer only after recovery proves its exact temporary course was deleted.
     *
     * @param int $id Provision record ID.
     * @param int $expectedcourseid Expected current positive destination course ID.
     * @return \stdClass Persisted running provision record.
     */
    public function clear_recovered_destination_course(int $id, int $expectedcourseid): \stdClass {
        global $DB;

        if ($expectedcourseid <= 0) {
            throw new \coding_exception('Provisioning destination course ID must be positive.');
        }

        $lock = $this->get_provision_lock($id);
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $record = $this->get_record($id);
                if (
                    $record->status !== self::STATUS_RUNNING
                        || $record->courseid === null
                        || (int)$record->courseid !== $expectedcourseid
                ) {
                    throw new \coding_exception('Provisioning destination pointer cannot be cleared during recovery.');
                }
                $record->courseid = null;
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
     * Mark a terminal successful provision record as having its follow-up queued.
     *
     * @param int $id Provision record ID.
     * @return \stdClass Persisted terminal provision record.
     */
    public function mark_followup_queued(int $id): \stdClass {
        global $DB;

        $lock = $this->get_provision_lock($id);
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $record = $this->get_record($id);
                if (!in_array($record->status, [self::STATUS_READY, self::STATUS_FALLBACK_READY], true)) {
                    throw new \coding_exception('Provisioning state does not permit this worker update.');
                }
                if ((int)$record->followupqueued !== 1) {
                    $record->followupqueued = 1;
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
     * Return one provision record by its primary identity.
     *
     * @param int $id Provision record ID.
     * @return \stdClass Persisted provision record.
     */
    public function get(int $id): \stdClass {
        return $this->get_record($id);
    }

    /**
     * Return one provision record by its source-owned course identity.
     *
     * @param string $sourcecomponent Source plugin component name.
     * @param string $courseidnumber Source course identity.
     * @return \stdClass|null Provision record, or null when not yet created.
     */
    public function get_by_source_course(string $sourcecomponent, string $courseidnumber): ?\stdClass {
        global $DB;

        $record = $DB->get_record(self::TABLE, [
            'sourcecomponent' => $sourcecomponent,
            'courseidnumber' => $courseidnumber,
        ]);
        return $record === false ? null : $record;
    }

    /**
     * Persist a collision-safe desired shortname for a running provision.
     *
     * @param int $id Provision record ID.
     * @param string $desiredshortname Available desired shortname.
     * @return \stdClass Updated running provision record.
     */
    public function set_desired_shortname(int $id, string $desiredshortname): \stdClass {
        global $DB;

        if (trim($desiredshortname) === '') {
            throw new \coding_exception('Provisioning desired shortname cannot be empty.');
        }
        $lock = $this->get_provision_lock($id);
        try {
            $record = $this->get_record($id);
            if ($record->status !== self::STATUS_RUNNING) {
                throw new \coding_exception('Provisioning state does not permit a shortname update.');
            }
            $record->desiredshortname = $desiredshortname;
            $record->timemodified = time();
            $DB->update_record(self::TABLE, $record);
            return $record;
        } finally {
            $lock->release();
        }
    }

    /**
     * Return successful terminal rows whose follow-up has not been queued.
     *
     * @return \stdClass[] Provision records keyed by ID in ascending order.
     */
    public function get_terminal_rows_pending_followup(): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal([
            self::STATUS_READY,
            self::STATUS_FALLBACK_READY,
        ], SQL_PARAMS_QM);
        $params[] = 0;
        return $DB->get_records_select(
            self::TABLE,
            "status $insql AND followupqueued = ?",
            $params,
            'id ASC'
        );
    }
}
