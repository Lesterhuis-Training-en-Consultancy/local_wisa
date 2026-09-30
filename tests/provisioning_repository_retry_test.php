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
 * Provisioning repository retry tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/provisioning_repository_test_case.php');

global $CFG;

require_once(__DIR__ . '/../db/upgrade.php');
require_once($CFG->libdir . '/upgradelib.php');

/**
 * Verifies the durable provisioning state machine contract.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_wisa
 * @covers     \local_wisa\provisioning_repository
 */
final class provisioning_repository_retry_test extends provisioning_repository_test_case {
    /**
     * A caller-redacted failed error is bounded, and authorised retry preserves ownership data.
     *
     * @return void
     */
    public function test_failed_retry_reuses_identity_replaces_execution_user_and_increments_attempts(): void {
        $this->resetAfterTest();
        $repository = new provisioning_repository();
        $pending = $this->create_pending($repository);
        $running = $repository->mark_running((int)$pending->id);
        $failed = $repository->mark_terminal(
            (int)$running->id,
            provisioning_repository::STATUS_FAILED,
            'Caller-redacted provisioning failure: ' . str_repeat('x', 300)
        );

        $this->assertSame(255, \core_text::strlen($failed->lasterror));
        $retried = $repository->prepare_authorized_retry((int)$failed->id, 202, false);

        $this->assertSame((int)$failed->id, (int)$retried->id);
        $this->assertSame($failed->jobid, $retried->jobid);
        $this->assertSame($failed->tempshortname, $retried->tempshortname);
        $this->assertSame($failed->sourcecomponent, $retried->sourcecomponent);
        $this->assertSame($failed->courseidnumber, $retried->courseidnumber);
        $this->assertSame($failed->desiredshortname, $retried->desiredshortname);
        $this->assertSame($failed->desiredfullname, $retried->desiredfullname);
        $this->assertSame((int)$failed->categoryid, (int)$retried->categoryid);
        $this->assertSame((int)$failed->templateid, (int)$retried->templateid);
        $this->assertSame($failed->courseid, $retried->courseid);
        $this->assertSame((int)$failed->startdate, (int)$retried->startdate);
        $this->assertSame((int)$failed->enddate, (int)$retried->enddate);
        $this->assertSame((int)$failed->tempprecallabsent, (int)$retried->tempprecallabsent);
        $this->assertSame((int)$failed->followupqueued, (int)$retried->followupqueued);
        $this->assertSame((int)$failed->timecreated, (int)$retried->timecreated);
        $this->assertGreaterThanOrEqual((int)$failed->timemodified, (int)$retried->timemodified);
        $this->assertSame(provisioning_repository::STATUS_PENDING, $retried->status);
        $this->assertSame(202, (int)$retried->executionuserid);
        $this->assertSame(1, (int)$retried->attempts);
        $this->assertNull($retried->lasterror);
    }

    /**
     * Explicit service proof is required before retry resets destination recovery fields.
     *
     * @return void
     */
    public function test_authorized_retry_resets_recovery_fields_only_when_explicitly_requested(): void {
        global $DB;

        $this->resetAfterTest();
        $repository = new provisioning_repository();
        $pending = $this->create_pending($repository);
        $running = $repository->mark_running((int)$pending->id);
        $running = $repository->mark_temp_pre_call_absent((int)$running->id);
        $running = $repository->record_destination_course((int)$running->id, 123);
        $failed = $repository->mark_terminal(
            (int)$running->id,
            provisioning_repository::STATUS_FAILED,
            'Provisioning failed.'
        );
        $DB->set_field('local_wisa_course_provision', 'followupqueued', 1, ['id' => $failed->id]);

        $retried = $repository->prepare_authorized_retry((int)$failed->id, 202, false, true);

        $this->assertNull($retried->courseid);
        $this->assertSame(0, (int)$retried->tempprecallabsent);
        $this->assertSame(0, (int)$retried->followupqueued);
    }

    /**
     * Orphaned active jobs require an explicit orphan confirmation before retry preparation.
     *
     * @return void
     */
    public function test_orphaned_pending_or_running_jobs_can_only_retry_through_explicit_authorized_path(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $repository = new provisioning_repository();
        $pending = $this->create_pending($repository);

        $this->assert_invalid_transition(function () use ($repository, $pending): void {
            $repository->prepare_authorized_retry((int)$pending->id, 202, false);
        });

        $retriedpending = $repository->prepare_authorized_retry((int)$pending->id, 202, true);
        $this->assertSame(provisioning_repository::STATUS_PENDING, $retriedpending->status);
        $this->assertSame(1, (int)$retriedpending->attempts);
        $this->assertSame(202, (int)$retriedpending->executionuserid);

        $running = $repository->mark_running((int)$retriedpending->id);
        $retriedrunning = $repository->prepare_authorized_retry((int)$running->id, 303, true);
        $this->assertSame(provisioning_repository::STATUS_PENDING, $retriedrunning->status);
        $this->assertSame(2, (int)$retriedrunning->attempts);
        $this->assertSame(303, (int)$retriedrunning->executionuserid);
    }

    /**
     * Ready and fallback records must never be retry-prepared.
     *
     * @return void
     */
    public function test_successful_terminal_records_cannot_be_retry_prepared(): void {
        $this->resetAfterTest();
        $repository = new provisioning_repository();
        $running = $repository->mark_running((int)$this->create_pending($repository)->id);
        $ready = $repository->mark_terminal((int)$running->id, provisioning_repository::STATUS_FALLBACK_READY);

        $this->assert_invalid_transition(function () use ($repository, $ready): void {
            $repository->prepare_authorized_retry((int)$ready->id, 202, true);
        });
    }
}
