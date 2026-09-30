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
 * Force-full synchronization action tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/force_full_sync_manager_test_double.php');
require_once(__DIR__ . '/fixtures/force_full_sync_task_test_double.php');

/**
 * Verifies force-full queue, task, and watermark behavior.
 *
 * @package    local_wisa
 * @category   test
 * @group      local_wisa
 * @covers     \local_wisa\explicit_action_queue
 * @covers     \local_wisa\task\force_full_sync_task
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class force_full_sync_task_test extends \advanced_testcase {
    /**
     * Queueing is source-free, duplicate-safe, and preserves dry-run.
     *
     * @return void
     */
    public function test_queue_force_full_sync_preserves_dry_run_and_queues_once(): void {
        $this->resetAfterTest();
        $user = self::getDataGenerator()->create_user();
        set_config('siteadmins', $user->id);
        $this->setUser($user);
        set_config('dry_run', 1, 'local_wisa');

        $first = explicit_action_queue::queue_force_full_sync((int)$user->id);
        $second = explicit_action_queue::queue_force_full_sync((int)$user->id);

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame('1', get_config('local_wisa', 'dry_run'));
        $this->assertSame('queued', get_config('local_wisa', 'last_force_full_sync_status'));
        $tasks = \core\task\manager::get_adhoc_tasks(\local_wisa\task\force_full_sync_task::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertSame((int)$user->id, (int)$task->get_userid());
        $this->assertSame(['action' => 'force_full_sync'], (array)$task->get_custom_data());
    }

    /**
     * Force-full queueing is globally deduplicated across administrators.
     *
     * @return void
     */
    public function test_force_full_queue_is_globally_deduplicated_across_administrators(): void {
        $this->resetAfterTest();
        $first = self::getDataGenerator()->create_user();
        $second = self::getDataGenerator()->create_user();
        set_config('siteadmins', implode(',', [$first->id, $second->id]));

        $this->setUser($first);
        $this->assertTrue(explicit_action_queue::queue_force_full_sync((int)$first->id));
        $this->setUser($second);
        $this->assertFalse(explicit_action_queue::queue_force_full_sync((int)$second->id));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(
            \local_wisa\task\force_full_sync_task::class
        ));
    }

    /**
     * The task calls force-full without enabling force-live.
     *
     * @return void
     */
    public function test_force_full_task_calls_force_full_and_preserves_dry_run(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('dry_run', 1, 'local_wisa');
        $manager = new force_full_sync_manager_test_double();
        $task = new force_full_sync_task_test_double($manager);
        $sink = $this->redirectEvents();

        $task->execute();

        $this->assertTrue($manager->forcefull);
        $this->assertFalse($manager->forcelive);
        $this->assertSame('1', get_config('local_wisa', 'dry_run'));
        $this->assertSame('success', get_config('local_wisa', 'last_force_full_sync_status'));
        $this->assertSame('DRY-RUN', get_config('local_wisa', 'last_force_full_sync_mode'));
        $events = array_filter($sink->get_events(), function ($event): bool {
            return $event instanceof \local_wisa\event\admin_manual_sync_run;
        });
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertTrue($event->other['forcefull']);
    }

    /**
     * The task records the current failed result instead of a stale global success.
     *
     * @return void
     */
    public function test_force_full_task_uses_current_failed_status_not_stale_global_status(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('last_run_status', 'success', 'local_wisa');
        $manager = new force_full_sync_manager_test_double();
        $manager->result = false;
        $manager->status = 'failed';

        (new force_full_sync_task_test_double($manager))->execute();

        $this->assertSame('failed', get_config('local_wisa', 'last_force_full_sync_status'));
    }

    /**
     * The task preserves a current partial result.
     *
     * @return void
     */
    public function test_force_full_task_records_current_partial_status(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('last_run_status', 'success', 'local_wisa');
        $manager = new force_full_sync_manager_test_double();
        $manager->result = false;
        $manager->status = 'partial';

        (new force_full_sync_task_test_double($manager))->execute();

        $this->assertSame('partial', get_config('local_wisa', 'last_force_full_sync_status'));
    }

    /**
     * A task failure is recorded without rethrowing into automatic task retry.
     *
     * @return void
     */
    public function test_force_full_task_records_failure_without_rethrowing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $manager = new force_full_sync_manager_test_double();
        $manager->exception = new \RuntimeException('SECRET-SHOULD-NOT-RETHROW');

        (new force_full_sync_task_test_double($manager))->execute();

        $this->assertSame('failed', get_config('local_wisa', 'last_force_full_sync_status'));
    }

    /**
     * Force-full bypasses but does not clear the canonical watermark.
     *
     * @return void
     */
    public function test_force_full_request_ignores_without_clearing_watermark(): void {
        $this->resetAfterTest();
        $component = 'sissource_wisa';
        $watermarksetting = 'stream_courses_courses_watermark';
        set_config('stream_courses_courses_enabled', 1, $component);
        set_config($watermarksetting, 1000, $component);
        $registry = source_factory::get_registry_for_component($component);
        foreach ($registry as $streamkey => $descriptor) {
            foreach ($descriptor['phases'] as $phase) {
                if ($streamkey !== 'courses' || $phase !== 'courses') {
                    set_config('stream_' . $streamkey . '_' . $phase . '_enabled', 0, $component);
                }
            }
        }

        $requests = (new source_stream_request_builder(
            $component,
            $registry,
            new source_stream_state($component, false)
        ))->build(true);

        $this->assertCount(1, $requests);
        $this->assertSame(1000, $requests[0]['watermark']);
        $this->assertNull($requests[0]['effective_since']);
        $this->assertSame('1000', get_config($component, $watermarksetting));
    }
}
