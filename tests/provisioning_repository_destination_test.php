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
 * Provisioning repository destination tests.
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
final class provisioning_repository_destination_test extends provisioning_repository_test_case {
    /**
     * Worker state fields can change only while the provision record is running.
     *
     * @return void
     */
    public function test_worker_can_record_pre_call_absence_and_one_destination_only_while_running(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $repository = new provisioning_repository();
        $pending = $this->create_pending($repository);

        $this->assert_invalid_transition(function () use ($repository, $pending): void {
            $repository->mark_temp_pre_call_absent((int)$pending->id);
        });
        $this->assert_invalid_transition(function () use ($repository, $pending): void {
            $repository->record_destination_course((int)$pending->id, 123);
        });

        $running = $repository->mark_running((int)$pending->id);
        $precall = $repository->mark_temp_pre_call_absent((int)$running->id);
        $this->assertSame(1, (int)$precall->tempprecallabsent);
        $destination = $repository->record_destination_course((int)$running->id, 123);
        $this->assertSame(123, (int)$destination->courseid);
        $this->assertSame(123, (int)$repository->record_destination_course((int)$running->id, 123)->courseid);
        $this->assert_invalid_transition(function () use ($repository, $running): void {
            $repository->record_destination_course((int)$running->id, 0);
        });
        $this->assert_invalid_transition(function () use ($repository, $running): void {
            $repository->record_destination_course((int)$running->id, 124);
        });

        $ready = $repository->mark_terminal((int)$running->id, provisioning_repository::STATUS_READY);
        $this->assert_invalid_transition(function () use ($repository, $ready): void {
            $repository->mark_temp_pre_call_absent((int)$ready->id);
        });
        $this->assert_invalid_transition(function () use ($repository, $ready): void {
            $repository->record_destination_course((int)$ready->id, 123);
        });
    }

    /**
     * Finalization rolls the pointer and terminal state back when its course callback fails.
     *
     * @return void
     */
    public function test_composite_finalizer_commits_pointer_callback_and_terminal_state_together(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $repository = new provisioning_repository();
        $running = $repository->mark_running((int)$this->create_pending($repository)->id);

        try {
            $repository->finalize_destination(
                (int)$running->id,
                123,
                provisioning_repository::STATUS_READY,
                function (\stdClass $record): void {
                    $this->assertSame(123, (int)$record->courseid);
                    throw new \RuntimeException('Simulated finalization failure.');
                }
            );
            $this->fail('Expected the composite finalizer callback failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated finalization failure.', $exception->getMessage());
        }

        $rolledback = $repository->get((int)$running->id);
        $this->assertSame(provisioning_repository::STATUS_RUNNING, $rolledback->status);
        $this->assertNull($rolledback->courseid);

        $finished = $repository->finalize_destination(
            (int)$running->id,
            123,
            provisioning_repository::STATUS_READY,
            function (\stdClass $record): void {
                $this->assertSame(123, (int)$record->courseid);
            }
        );
        $this->assertSame(provisioning_repository::STATUS_READY, $finished->status);
        $this->assertSame(123, (int)$finished->courseid);
    }

    /**
     * Recovery may clear only the running row's exactly matched destination pointer.
     *
     * @return void
     */
    public function test_recovery_clears_only_the_exact_running_destination_pointer(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $repository = new provisioning_repository();
        $running = $repository->mark_running((int)$this->create_pending($repository)->id);
        $repository->record_destination_course((int)$running->id, 123);

        $this->assert_invalid_transition(function () use ($repository, $running): void {
            $repository->clear_recovered_destination_course((int)$running->id, 124);
        });
        $cleared = $repository->clear_recovered_destination_course((int)$running->id, 123);
        $this->assertSame(provisioning_repository::STATUS_RUNNING, $cleared->status);
        $this->assertNull($cleared->courseid);
    }

    /**
     * Follow-up reconciliation returns terminal rows until the queue marker is persisted.
     *
     * @return void
     */
    public function test_followup_queue_marker_is_terminal_only_idempotent_and_removes_row_from_reconciliation(): void {
        $this->resetAfterTest();
        $repository = new provisioning_repository();
        $ready = $repository->mark_terminal(
            (int)$repository->mark_running((int)$this->create_pending($repository)->id)->id,
            provisioning_repository::STATUS_READY
        );
        $fallback = $repository->mark_terminal(
            (int)$repository->mark_running((int)$this->create_pending($repository, 'AS-COURSE-002')->id)->id,
            provisioning_repository::STATUS_FALLBACK_READY
        );

        $pendingfollowup = $repository->get_terminal_rows_pending_followup();
        $this->assertSame([(int)$ready->id, (int)$fallback->id], array_map('intval', array_keys($pendingfollowup)));

        $queued = $repository->mark_followup_queued((int)$ready->id);
        $this->assertSame(1, (int)$queued->followupqueued);
        $this->assertSame(1, (int)$repository->mark_followup_queued((int)$ready->id)->followupqueued);
        $this->assertSame([(int)$fallback->id], array_map('intval', array_keys($repository->get_terminal_rows_pending_followup())));

        $failed = $repository->mark_terminal(
            (int)$repository->mark_running((int)$this->create_pending($repository, 'AS-COURSE-003')->id)->id,
            provisioning_repository::STATUS_FAILED
        );
        $this->assert_invalid_transition(function () use ($repository, $failed): void {
            $repository->mark_followup_queued((int)$failed->id);
        });
    }
}
