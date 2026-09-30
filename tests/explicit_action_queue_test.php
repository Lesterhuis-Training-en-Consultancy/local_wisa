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
 * Explicit action queue lifecycle tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests the source-free explicit action queue lifecycle.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_wisa
 * @covers     \local_wisa\explicit_action_queue
 */
final class explicit_action_queue_test extends \advanced_testcase {
    /**
     * Return the sole queued task of the requested class.
     *
     * @param string $classname Fully qualified task class name.
     * @return \core\task\adhoc_task Queued task.
     */
    private function queued_task(string $classname): \core\task\adhoc_task {
        $tasks = \core\task\manager::get_adhoc_tasks($classname);
        $this->assertCount(1, $tasks);
        return reset($tasks);
    }

    /**
     * Assert that queue metadata contains exactly one safe action value.
     *
     * @param \core\task\adhoc_task $task Queued task.
     * @param string $action Expected action name.
     * @param int $userid Requesting user id.
     * @return void
     */
    private function assert_action_task(\core\task\adhoc_task $task, string $action, int $userid): void {
        $this->assertSame($userid, (int)$task->get_userid());
        $this->assertSame(['action' => $action], (array)$task->get_custom_data());
    }

    /**
     * Install and upgrade scripts must never start a SIS source action.
     *
     * @return void
     */
    public function test_install_and_upgrade_remain_source_free(): void {
        $install = file_get_contents(__DIR__ . '/../db/install.xml');
        $upgrade = file_get_contents(__DIR__ . '/../db/upgrade.php');

        foreach (['source_factory', 'sync_manager', 'run_full_sync', 'queue_adhoc_task'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $install);
            $this->assertStringNotContainsString($forbidden, $upgrade);
        }
    }

    /**
     * Scheduled and reconciliation tasks must remain gated before approval.
     *
     * @return void
     */
    public function test_scheduled_and_reconcile_tasks_remain_gated_before_approval(): void {
        $this->resetAfterTest();
        set_config('initial_load_done', 0, 'local_wisa');
        set_config('enable_reconcile', 1, 'local_wisa');

        $this->expectOutputRegex('/initial full load not yet approved; skipping scheduled sync\..*'
            . 'initial full load not yet approved; skipping reconciliation\./s');
        (new \local_wisa\task\sync_task())->execute();
        (new \local_wisa\task\reconcile_task())->execute();
    }

    /**
     * Explicit non-initial actions must only queue task metadata under the requester.
     *
     * @return void
     */
    public function test_preview_connection_and_manual_sync_queue_without_source_work(): void {
        $this->resetAfterTest();
        $user = self::getDataGenerator()->create_user();

        $this->assertTrue(explicit_action_queue::queue_preview((int)$user->id));
        $this->assert_action_task(
            $this->queued_task(\local_wisa\task\preview_task::class),
            'preview',
            (int)$user->id
        );

        $this->assertTrue(explicit_action_queue::queue_manual_sync((int)$user->id));
        $this->assert_action_task(
            $this->queued_task(\local_wisa\task\manual_sync_task::class),
            'manual_sync',
            (int)$user->id
        );
    }

    /**
     * A connection test must queue one validated descriptor health-check tuple.
     *
     * @return void
     */
    public function test_connection_test_queues_fixed_selected_tuple_data(): void {
        $this->resetAfterTest();
        set_config('active_source', 'wisa', 'local_wisa');
        $user = self::getDataGenerator()->create_user();

        $this->assertTrue(explicit_action_queue::queue_connection_test(
            (int)$user->id,
            'sissource_wisa',
            'teacher_accounts'
        ));

        $task = $this->queued_task(\local_wisa\task\connection_test_task::class);
        $this->assertSame((int)$user->id, (int)$task->get_userid());
        $this->assertSame([
            'component' => 'sissource_wisa',
            'stream' => 'teacher_accounts',
            'phase' => 'users',
        ], (array)$task->get_custom_data());
        $this->assertSame('sissource_wisa', get_config('local_wisa', 'last_connection_test_source'));
        $this->assertSame('teacher_accounts', get_config('local_wisa', 'last_connection_test_stream'));
        $this->assertSame('users', get_config('local_wisa', 'last_connection_test_phase'));
        $this->assertSame('query_teachers', get_config('local_wisa', 'last_connection_test_transport'));
    }

    /**
     * Invalid source components must be rejected before a task is queued.
     *
     * @return void
     */
    public function test_connection_test_rejects_invalid_component_before_queue(): void {
        $this->resetAfterTest();
        set_config('active_source', 'wisa', 'local_wisa');
        $user = self::getDataGenerator()->create_user();

        $this->expectException(\coding_exception::class);
        explicit_action_queue::queue_connection_test((int)$user->id, 'wisa', 'courses');
    }

    /**
     * Unknown stream keys must be rejected before a task is queued.
     *
     * @return void
     */
    public function test_connection_test_rejects_invalid_stream_before_queue(): void {
        $this->resetAfterTest();
        set_config('active_source', 'wisa', 'local_wisa');
        $user = self::getDataGenerator()->create_user();

        $this->expectException(\coding_exception::class);
        explicit_action_queue::queue_connection_test((int)$user->id, 'sissource_wisa', 'missing_stream');
    }

    /**
     * A descriptor from a source other than the current active source must be rejected.
     *
     * @return void
     */
    public function test_connection_test_rejects_active_source_mismatch_before_queue(): void {
        $this->resetAfterTest();
        set_config('active_source', 'wisa', 'local_wisa');
        $user = self::getDataGenerator()->create_user();

        $this->expectException(\coding_exception::class);
        explicit_action_queue::queue_connection_test((int)$user->id, 'sissource_athenasoft', 'courses');
    }

    /**
     * Deduplication must collapse the same tuple but preserve distinct descriptor tests.
     *
     * @return void
     */
    public function test_connection_test_deduplicates_per_selected_tuple(): void {
        $this->resetAfterTest();
        set_config('active_source', 'wisa', 'local_wisa');
        $user = self::getDataGenerator()->create_user();

        $this->assertTrue(explicit_action_queue::queue_connection_test(
            (int)$user->id,
            'sissource_wisa',
            'student_accounts'
        ));
        $this->assertFalse(explicit_action_queue::queue_connection_test(
            (int)$user->id,
            'sissource_wisa',
            'student_accounts'
        ));
        $this->assertTrue(explicit_action_queue::queue_connection_test(
            (int)$user->id,
            'sissource_wisa',
            'teacher_accounts'
        ));

        $tasks = \core\task\manager::get_adhoc_tasks(\local_wisa\task\connection_test_task::class);
        $this->assertCount(2, $tasks);
    }

    /**
     * Repeated approval must retain the first job and queue exactly one initial-load task.
     *
     * @return void
     */
    public function test_duplicate_initial_load_approval_queues_one_owned_task(): void {
        $this->resetAfterTest();
        $user = self::getDataGenerator()->create_user();
        set_config('dry_run', 1, 'local_wisa');
        set_config('initial_load_done', 0, 'local_wisa');

        $first = explicit_action_queue::queue_initial_load((int)$user->id);
        $second = explicit_action_queue::queue_initial_load((int)$user->id);

        $this->assertTrue($first['queued']);
        $this->assertFalse($second['queued']);
        $this->assertSame($first['jobid'], $second['jobid']);
        $this->assertSame($first['jobid'], get_config('local_wisa', 'initial_load_jobid'));
        $this->assertSame('1', get_config('local_wisa', 'initial_load_queued'));
        $this->assertSame('0', get_config('local_wisa', 'dry_run'));
        $task = $this->queued_task(\local_wisa\task\initial_load_task::class);
        $this->assertSame((int)$user->id, (int)$task->get_userid());
        $this->assertSame(['jobid' => $first['jobid']], (array)$task->get_custom_data());
    }

    /**
     * An initial-load flag with no matching queued task must be reconciled as stale.
     *
     * @return void
     */
    public function test_stale_initial_load_state_is_reconciled_before_a_new_approval(): void {
        $this->resetAfterTest();
        set_config('initial_load_queued', 1, 'local_wisa');
        set_config('initial_load_jobid', 'stalejob', 'local_wisa');
        set_config('initial_load_queued_time', 12345, 'local_wisa');

        $this->assertTrue(explicit_action_queue::reconcile_initial_load());
        $this->assertSame('0', get_config('local_wisa', 'initial_load_queued'));
        $this->assertFalse(get_config('local_wisa', 'initial_load_jobid'));
        $this->assertFalse(get_config('local_wisa', 'initial_load_queued_time'));
    }

    /**
     * An exhausted initial-load task must no longer keep queued state current.
     *
     * @return void
     */
    public function test_exhausted_initial_load_task_is_reconciled_as_stale(): void {
        global $DB;

        $this->resetAfterTest();
        $user = self::getDataGenerator()->create_user();
        explicit_action_queue::queue_initial_load((int)$user->id);
        $task = $this->queued_task(\local_wisa\task\initial_load_task::class);
        $DB->update_record('task_adhoc', (object)[
            'id' => $task->get_id(),
            'attemptsavailable' => 0,
        ]);

        $this->assertTrue(explicit_action_queue::reconcile_initial_load());
        $this->assertSame('0', get_config('local_wisa', 'initial_load_queued'));
        $this->assertFalse(get_config('local_wisa', 'initial_load_jobid'));
    }

    /**
     * Initial-load task matching must support Moodle versions without attempt metadata.
     *
     * @return void
     */
    public function test_initial_load_task_attempt_check_is_moodle40_compatible(): void {
        $source = file_get_contents(__DIR__ . '/../classes/explicit_action_queue.php');

        $this->assertMatchesRegularExpression(
            '/!method_exists\(\$task, \'get_attempts_available\'\)\s*\|\|\s*'
                . '\$task->get_attempts_available\(\) > 0/',
            $source
        );
    }

    /**
     * A task that does not own the current job must not clear its queued state.
     *
     * @return void
     */
    public function test_only_the_owning_initial_load_task_can_clear_queued_state(): void {
        $this->resetAfterTest();
        set_config('initial_load_queued', 1, 'local_wisa');
        set_config('initial_load_jobid', 'newjob', 'local_wisa');

        explicit_action_queue::complete_initial_load('oldjob');
        $this->assertSame('1', get_config('local_wisa', 'initial_load_queued'));
        $this->assertSame('newjob', get_config('local_wisa', 'initial_load_jobid'));

        explicit_action_queue::complete_initial_load('newjob');
        $this->assertSame('0', get_config('local_wisa', 'initial_load_queued'));
        $this->assertFalse(get_config('local_wisa', 'initial_load_jobid'));
    }
}
