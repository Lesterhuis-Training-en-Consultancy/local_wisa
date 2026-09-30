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
 * Background provisioning bulk retry tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/provisioning_service_test_case.php');

/**
 * Verifies request-bound queueing and background retry execution.
 *
 * @package    local_wisa
 * @group      local_wisa
 * @covers     \local_wisa\provisioning_bulk_retry_queue
 * @covers     \local_wisa\task\provisioning_bulk_retry_task
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provisioning_bulk_retry_task_test extends provisioning_service_test_case {
    /**
     * No queued coordinator produces an empty display state.
     *
     * @return void
     */
    public function test_queued_retry_state_is_empty_without_a_coordinator(): void {
        $this->resetAfterTest();

        $this->assertSame(
            ['mode' => '', 'ids' => []],
            (new provisioning_bulk_retry_queue())->get_queued_retry_state()
        );
    }

    /**
     * A 225-row browser action queues one coordinator without changing rows.
     *
     * @return void
     */
    public function test_large_selected_retry_queues_one_background_task_without_row_recovery(): void {
        $this->resetAfterTest();
        $context = $this->create_context();
        $repository = new provisioning_repository();
        $ids = [];
        for ($index = 1; $index <= 225; $index++) {
            $record = $repository->create_pending(
                'sissource_wisa',
                'C6-BACKGROUND-' . $index,
                'C6-BACKGROUND-' . $index,
                'C6 background ' . $index,
                (int)$context['category']->id,
                null,
                null,
                0,
                0,
                $context['userid']
            );
            $ids[] = (int)$record->id;
            $repository->mark_terminal(
                (int)$repository->mark_running((int)$record->id)->id,
                provisioning_repository::STATUS_FAILED,
                provisioning_diagnostic::CODE_UNKNOWN
            );
        }
        $queue = new provisioning_bulk_retry_queue();
        set_config('last_provisioning_bulk_retry_resultid', 10, 'local_wisa');
        set_config('last_provisioning_bulk_retry_userid', $context['userid'], 'local_wisa');
        set_config('last_provisioning_bulk_retry_seen_resultid', 10, 'local_wisa');

        $this->assertTrue($queue->queue_selected($ids, $context['userid']));
        $this->assertFalse($queue->queue_selected($ids, $context['userid']));

        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(
            \local_wisa\task\provisioning_bulk_retry_task::class
        ));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(
            \local_wisa\task\provision_course_task::class
        ));
        $this->assertSame(
            ['mode' => 'selected', 'ids' => $ids],
            $queue->get_queued_retry_state()
        );
        $this->assertFalse(get_config('local_wisa', 'last_provisioning_bulk_retry_resultid'));
        $this->assertFalse(get_config('local_wisa', 'last_provisioning_bulk_retry_userid'));
        $this->assertFalse(get_config('local_wisa', 'last_provisioning_bulk_retry_seen_resultid'));
        $this->assertSame(225, $repository->count_admin_rows(provisioning_repository::STATUS_FAILED));
    }

    /**
     * Different administrators cannot queue duplicate coordinators.
     *
     * @return void
     */
    public function test_filtered_retry_is_globally_deduplicated_across_administrators(): void {
        $this->resetAfterTest();
        $firstadmin = self::getDataGenerator()->create_user();
        $secondadmin = self::getDataGenerator()->create_user();
        set_config('siteadmins', implode(',', [$firstadmin->id, $secondadmin->id]));
        $queue = new provisioning_bulk_retry_queue();

        $this->setUser($firstadmin);
        $this->assertTrue($queue->queue_filtered_failed((int)$firstadmin->id));
        $this->setUser($secondadmin);
        $this->assertFalse($queue->queue_filtered_failed((int)$secondadmin->id));
        $this->assertSame(
            ['mode' => 'filtered_failed', 'ids' => []],
            $queue->get_queued_retry_state()
        );
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(
            \local_wisa\task\provisioning_bulk_retry_task::class
        ));
    }

    /**
     * The coordinator processes retry work only during task execution.
     *
     * @return void
     */
    public function test_coordinator_executes_selected_retries_and_persists_counts(): void {
        $this->resetAfterTest();
        $context = $this->create_context();
        $repository = new provisioning_repository();
        $ids = [];
        for ($index = 1; $index <= 4; $index++) {
            $record = $repository->create_pending(
                'sissource_wisa',
                'C6-EXECUTE-' . $index,
                'C6-EXECUTE-' . $index,
                'C6 execute ' . $index,
                (int)$context['category']->id,
                null,
                null,
                0,
                0,
                $context['userid']
            );
            $ids[] = (int)$record->id;
            $repository->mark_terminal(
                (int)$repository->mark_running((int)$record->id)->id,
                provisioning_repository::STATUS_FAILED,
                provisioning_diagnostic::CODE_UNKNOWN
            );
        }
        $queue = new provisioning_bulk_retry_queue();
        $queue->queue_selected($ids, $context['userid']);
        $tasks = \core\task\manager::get_adhoc_tasks(
            \local_wisa\task\provisioning_bulk_retry_task::class
        );
        $task = reset($tasks);

        $task->execute();

        foreach ($ids as $id) {
            $this->assertSame(provisioning_repository::STATUS_PENDING, $repository->get($id)->status);
        }
        $this->assertCount(4, \core\task\manager::get_adhoc_tasks(
            \local_wisa\task\provision_course_task::class
        ));
        $this->assertSame('success', get_config('local_wisa', 'last_provisioning_bulk_retry_status'));
        $this->assertSame(
            ['queued' => 4, 'already_queued' => 0, 'ineligible' => 0, 'rejected' => 0],
            json_decode((string)get_config('local_wisa', 'last_provisioning_bulk_retry_counts'), true)
        );
        $otheradmin = self::getDataGenerator()->create_user();
        $this->assertNull($queue->consume_result_for_user((int)$otheradmin->id));
        $this->assertSame([
            'status' => 'success',
            'counts' => ['queued' => 4, 'already_queued' => 0, 'ineligible' => 0, 'rejected' => 0],
        ], $queue->consume_result_for_user($context['userid']));
        $this->assertNull($queue->consume_result_for_user($context['userid']));
        $this->assertSame((int)$task->get_id(), (int)get_config(
            'local_wisa',
            'last_provisioning_bulk_retry_seen_resultid'
        ));
    }
}
