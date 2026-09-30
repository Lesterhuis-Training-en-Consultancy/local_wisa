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
 * Provisioning service retry test coverage.
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
 * Verifies provisioning retry authorization and task replacement.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provisioning_service_retry_test extends provisioning_service_test_case {
    /**
     * A user without site configuration capability cannot retry a failed row.
     *
     * @return void
     */
    public function test_failed_retry_requires_site_configuration_capability(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $context = $this->create_context();
        $service = new provisioning_service();
        $record = $this->queue_record(
            $service,
            (int)$context['category']->id,
            $context['userid'],
            null
        );
        $DB->delete_records('task_adhoc', ['id' => $this->queued_task()->get_id()]);
        $repository = new provisioning_repository();
        $repository->mark_terminal(
            (int)$repository->mark_running((int)$record->id)->id,
            provisioning_repository::STATUS_FAILED,
            'Provisioning failed.'
        );
        self::setUser(self::getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        $service->retry((int)$record->id, (int)$USER->id);
    }

    /**
     * An administrator can explicitly retry a failed row without changing its durable identity.
     *
     * @return void
     */
    public function test_authorized_failed_retry_reuses_identity_and_queues_one_replacement(): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        set_config('templatemap', json_encode(['template-one' => (int)$context['template']->id]), 'local_wisa');
        $service = new provisioning_service();
        $record = $this->queue_record(
            $service,
            (int)$context['category']->id,
            $context['userid'],
            'template-one'
        );
        $DB->delete_records('task_adhoc', ['id' => $this->queued_task()->get_id()]);
        $repository = new provisioning_repository();
        $repository->mark_terminal(
            (int)$repository->mark_running((int)$record->id)->id,
            provisioning_repository::STATUS_FAILED,
            'Provisioning failed.'
        );

        $retried = $service->retry((int)$record->id, $context['userid']);

        $this->assertSame((int)$record->id, (int)$retried->id);
        $this->assertSame($record->jobid, $retried->jobid);
        $this->assertSame($record->tempshortname, $retried->tempshortname);
        $this->assertSame(provisioning_repository::STATUS_PENDING, $retried->status);
        $this->assertSame(1, (int)$retried->attempts);
        $task = $this->queued_task();
        $this->assertSame($context['userid'], (int)$task->get_userid());
        $this->assertSame([
            'provisionid' => (int)$record->id,
            'jobid' => $record->jobid,
        ], (array)$task->get_custom_data());
    }

    /**
     * Retry resets only service-proven recovery fields before queuing one replacement task.
     *
     * @return void
     */
    public function test_retry_resets_proven_missing_pointer_recovery_state_and_queues_one_replacement(): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        $service = new provisioning_service();
        $record = $this->queue_record($service, (int)$context['category']->id, $context['userid'], null, 'RETRY-RESET');
        $DB->delete_records('task_adhoc', ['id' => $this->queued_task()->get_id()]);
        $repository = new provisioning_repository();
        $course = create_course((object)[
            'category' => (int)$record->categoryid,
            'fullname' => 'Deleted retry pointer',
            'shortname' => 'DELETED-RETRY-POINTER',
        ]);
        $running = $repository->record_destination_course(
            (int)$repository->mark_temp_pre_call_absent((int)$repository->mark_running((int)$record->id)->id)->id,
            (int)$course->id
        );
        $repository->mark_terminal((int)$running->id, provisioning_repository::STATUS_FAILED, 'Provisioning failed.');
        $DB->delete_records('course', ['id' => (int)$course->id]);
        $DB->set_field('local_wisa_course_provision', 'followupqueued', 1, ['id' => $record->id]);

        $retried = $service->retry((int)$record->id, $context['userid']);

        $this->assertSame(provisioning_repository::STATUS_PENDING, $retried->status);
        $this->assertNull($retried->courseid);
        $this->assertSame(0, (int)$retried->tempprecallabsent);
        $this->assertSame(0, (int)$retried->followupqueued);
        $this->assertSame(1, (int)$retried->attempts);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(\local_wisa\task\provision_course_task::class));
    }

    /**
     * Retry ignores and removes mismatched task identities but blocks an exact runnable task.
     *
     * @return void
     */
    public function test_retry_blocks_exact_task_and_replaces_mismatched_task_identity(): void {
        global $DB;

        $this->preventResetByRollback();
        $this->resetAfterTest();
        $context = $this->create_context();
        $service = new provisioning_service();
        $record = $this->queue_record($service, (int)$context['category']->id, $context['userid'], null, 'RETRY-TASKS');
        $this->assertFalse($service->is_retry_available($record));
        try {
            $service->retry((int)$record->id, $context['userid']);
            $this->fail('Expected an exact runnable task to block retry.');
        } catch (\coding_exception $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $DB->delete_records('task_adhoc', ['id' => $this->queued_task()->get_id()]);
        $repository = new provisioning_repository();
        $repository->mark_terminal(
            (int)$repository->mark_running((int)$record->id)->id,
            provisioning_repository::STATUS_FAILED,
            'Provisioning failed.'
        );
        $staleuser = self::getDataGenerator()->create_user();
        $stale = new \local_wisa\task\provision_course_task();
        $stale->set_userid((int)$staleuser->id);
        $stale->set_custom_data([
            'provisionid' => (int)$record->id,
            'jobid' => str_repeat('a', 32),
        ]);
        \core\task\manager::queue_adhoc_task($stale, true);
        $this->assertTrue($service->is_retry_available($repository->get((int)$record->id)));

        $service->retry((int)$record->id, $context['userid']);

        $task = $this->queued_task();
        $this->assertSame($context['userid'], (int)$task->get_userid());
        $this->assertSame($record->jobid, (string)$task->get_custom_data()->jobid);
    }
}
