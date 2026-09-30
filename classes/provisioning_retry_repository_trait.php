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
 * provisioning_retry_repository_trait provisioning repository operations.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait provisioning_retry_repository_trait {
    /**
     * Prepare an authorised retry without replacing its durable job identity.
     *
     * The calling service must establish retry authorisation. It must pass true
     * for $isorphaned only after proving an active pending or running job is orphaned.
     *
     * @param int $id Provision record ID.
     * @param int $executionuserid User context for the replacement execution.
     * @param bool $isorphaned Whether an active job has been proven orphaned.
     * @param bool $resetrecoveryproof Whether service proof permits pointer and marker reset.
     * @return \stdClass Pending retry record retaining the original ownership payload.
     */
    public function prepare_authorized_retry(
        int $id,
        int $executionuserid,
        bool $isorphaned,
        bool $resetrecoveryproof = false
    ): \stdClass {
        return $this->prepare_requeue($id, $executionuserid, static function (\stdClass $record) use (
            $isorphaned,
            $resetrecoveryproof
        ): void {
            $activeorphan = $isorphaned && in_array($record->status, [
                self::STATUS_PENDING,
                self::STATUS_RUNNING,
            ], true);
            if ($record->status !== self::STATUS_FAILED && !$activeorphan) {
                throw new \coding_exception('Provisioning retry is not permitted for this state.');
            }
            if ($resetrecoveryproof) {
                $record->courseid = null;
                $record->tempprecallabsent = 0;
                $record->followupqueued = 0;
            }
        });
    }

    /**
     * Return whether a successful provision points to a course that no longer exists.
     *
     * @param \stdClass $record Persisted provision record.
     * @return bool Whether the record is a manual recovery candidate.
     */
    public static function is_recovery_candidate(\stdClass $record): bool {
        global $DB;

        return in_array($record->status, [self::STATUS_READY, self::STATUS_FALLBACK_READY], true)
            && $record->courseid !== null && (int)$record->courseid > 0
            && !$DB->record_exists('course', ['id' => (int)$record->courseid]);
    }

    /**
     * Reset a deleted successful destination after the service has authorized recovery.
     *
     * @param int $id Provision record ID.
     * @param int $executionuserid Replacement task execution user.
     * @return \stdClass Pending record retaining its saved provisioning details.
     */
    public function prepare_authorized_recovery(int $id, int $executionuserid): \stdClass {
        return $this->prepare_requeue($id, $executionuserid, static function (\stdClass $record): void {
            if (!self::is_recovery_candidate($record)) {
                throw new \coding_exception('Provisioning recovery requires a deleted successful destination.');
            }
            $record->courseid = null;
            $record->tempprecallabsent = 0;
            $record->followupqueued = 0;
        });
    }

    /**
     * Apply an authorized reset under the record lock and preserve shared retry bookkeeping.
     *
     * @param int $id Provision record ID.
     * @param int $executionuserid Replacement task execution user.
     * @param callable $prepare Validate eligibility and reset permitted fields on the locked record.
     * @return \stdClass Pending provision record.
     */
    private function prepare_requeue(int $id, int $executionuserid, callable $prepare): \stdClass {
        global $DB;

        if ($executionuserid <= 0) {
            throw new \coding_exception('Provisioning retry requires an execution user.');
        }

        $lock = $this->get_provision_lock($id);
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $record = $this->get_record($id);
                $prepare($record);

                $record->status = self::STATUS_PENDING;
                $record->executionuserid = $executionuserid;
                $record->attempts = (int)$record->attempts + 1;
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
     * Prepare a failed provision for an authorised retry.
     *
     * @param int $id Provision record ID.
     * @param int $executionuserid User context for the replacement execution.
     * @return \stdClass Pending retry record retaining the original ownership payload.
     */
    public function prepare_failed_retry(int $id, int $executionuserid): \stdClass {
        return $this->prepare_authorized_retry($id, $executionuserid, false);
    }
}
