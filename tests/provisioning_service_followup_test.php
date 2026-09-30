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
 * Provisioning service follow-up test coverage.
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
 * Verifies provisioning follow-up task publication and execution.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provisioning_service_followup_test extends provisioning_service_test_case {
    /**
     * Follow-up publication rolls task insertion and its marker back together after its test seam fails.
     *
     * @return void
     */
    public function test_followup_publication_rolls_back_task_and_marker_then_reconciles_once(): void {
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $context = $this->create_context();
        $record = $this->create_terminal_record($context, 'FOLLOWUP-ROLLBACK');
        $service = new provisioning_service_test_double();
        $service->set_followup_publication_checkpoint(function (): void {
            throw new \coding_exception('Simulated follow-up publication failure.');
        });

        $service->reconcile_pending_followups();

        $repository = new provisioning_repository();
        $this->assertSame(0, (int)$repository->get((int)$record->id)->followupqueued);
        $this->assertCount(0, $this->queued_followup_tasks());

        $service->set_followup_publication_checkpoint(null);
        $service->reconcile_pending_followups();
        $this->assertSame(1, (int)$repository->get((int)$record->id)->followupqueued);
        $this->assertCount(1, $this->queued_followup_tasks());

        $service->reconcile_pending_followups();
        $this->assertCount(1, $this->queued_followup_tasks());
    }

    /**
     * Reconciliation adopts an exact marker-zero follow-up task rather than inserting another one.
     *
     * @return void
     */
    public function test_followup_publication_adopts_exact_marker_zero_task(): void {
        $this->resetAfterTest();
        $context = $this->create_context();
        $record = $this->create_terminal_record($context, 'FOLLOWUP-ADOPT');
        $task = new \local_wisa\task\provision_followup_task();
        $task->set_userid($context['userid']);
        $task->set_custom_data([
            'provisionid' => (int)$record->id,
            'jobid' => $record->jobid,
        ]);
        \core\task\manager::queue_adhoc_task($task, true);

        (new provisioning_service())->reconcile_pending_followups();

        $this->assertSame(1, (int)(new provisioning_repository())->get((int)$record->id)->followupqueued);
        $this->assertCount(1, $this->queued_followup_tasks());
    }

    /**
     * A marker-zero follow-up task self-adopts before source work after releasing its source lock.
     *
     * @return void
     */
    public function test_followup_task_self_adopts_marker_zero_after_releasing_source_lock(): void {
        $this->resetAfterTest();
        $context = $this->create_context();
        $this->disable_component_streams('sissource_wisa');
        $record = $this->create_terminal_record($context, 'FOLLOWUP-SELF-ADOPT');
        $task = new provision_followup_task_test_double();
        $task->set_userid($context['userid']);
        $task->set_custom_data([
            'provisionid' => (int)$record->id,
            'jobid' => $record->jobid,
        ]);
        $task->set_before_run_callback(function (\stdClass $lockedrecord): void {
            $lock = provisioning_repository::get_source_lock(
                $lockedrecord->sourcecomponent,
                $lockedrecord->courseidnumber
            );
            $lock->release();
        });

        $task->execute();

        $this->assertSame(1, (int)(new provisioning_repository())->get((int)$record->id)->followupqueued);
    }

    /**
     * Invalid follow-up payloads must not trigger a source synchronisation.
     *
     * @return void
     */
    public function test_followup_task_ignores_invalid_identity_only_payload(): void {
        global $USER;

        $this->resetAfterTest();

        $task = new \local_wisa\task\provision_followup_task();
        $task->set_custom_data(['provisionid' => 0, 'jobid' => '']);
        $task->set_userid(0);
        $task->execute();

        self::setAdminUser();
        $staletask = new \local_wisa\task\provision_followup_task();
        $staletask->set_custom_data([
            'provisionid' => 999999,
            'jobid' => str_repeat('a', 32),
        ]);
        $staletask->set_userid((int)$USER->id);
        $staletask->execute();

        $this->assertCount(0, $this->queued_followup_tasks());
    }

    /**
     * A malformed persisted source component is rejected while locked without adopting its marker.
     *
     * @return void
     */
    public function test_followup_task_rejects_malformed_persisted_source_without_marker_mutation(): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        $record = $this->create_terminal_record($context, 'MALFORMED-FOLLOWUP-SOURCE');
        $DB->set_field('local_wisa_course_provision', 'sourcecomponent', 'invalid source!', ['id' => $record->id]);
        $task = new \local_wisa\task\provision_followup_task();
        $task->set_userid($context['userid']);
        $task->set_custom_data([
            'provisionid' => (int)$record->id,
            'jobid' => $record->jobid,
        ]);

        $task->execute();

        $this->assertSame(0, (int)(new provisioning_repository())->get((int)$record->id)->followupqueued);
    }

    /**
     * A valid but unavailable persisted source leaves marker adoption durable and propagates its factory error.
     *
     * @return void
     */
    public function test_followup_task_propagates_unavailable_valid_persisted_source_after_marker_adoption(): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        $record = $this->create_terminal_record($context, 'UNAVAILABLE-FOLLOWUP-SOURCE');
        $DB->set_field('local_wisa_course_provision', 'sourcecomponent', 'sissource_unavailable', ['id' => $record->id]);
        $task = new \local_wisa\task\provision_followup_task();
        $task->set_userid($context['userid']);
        $task->set_custom_data([
            'provisionid' => (int)$record->id,
            'jobid' => $record->jobid,
        ]);

        try {
            $task->execute();
            $this->fail('Expected the unavailable persisted source to propagate.');
        } catch (\coding_exception $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $this->assertSame(1, (int)(new provisioning_repository())->get((int)$record->id)->followupqueued);
    }

    /**
     * A follow-up task uses the terminal row's source, not the active source setting.
     *
     * @return void
     */
    public function test_followup_task_uses_persisted_source_when_active_source_differs(): void {
        $this->resetAfterTest();
        $context = $this->create_context();
        $this->disable_component_streams('sissource_athenasoft');
        set_config('active_source', 'wisa', 'local_wisa');
        $repository = new provisioning_repository();
        $record = $repository->create_pending(
            'sissource_athenasoft',
            'ATHENA-COURSE-001',
            'ATHENA-COURSE-001',
            'Athena course',
            (int)$context['category']->id,
            null,
            null,
            0,
            0,
            $context['userid']
        );
        $course = create_course((object)[
            'category' => (int)$record->categoryid,
            'fullname' => $record->desiredfullname,
            'shortname' => $record->desiredshortname,
            'idnumber' => $record->courseidnumber,
        ]);
        $record = $repository->record_destination_course(
            (int)$repository->mark_running((int)$record->id)->id,
            (int)$course->id
        );
        $record = $repository->mark_terminal((int)$record->id, provisioning_repository::STATUS_READY);
        $task = new \local_wisa\task\provision_followup_task();
        $task->set_userid($context['userid']);
        $task->set_custom_data([
            'provisionid' => (int)$record->id,
            'jobid' => $record->jobid,
        ]);

        $sink = $this->redirectEvents();
        $task->execute();

        $events = $sink->get_events();
        $this->assertCount(2, $events);
        $this->assertSame(1, (int)$repository->get((int)$record->id)->followupqueued);
        $this->assertSame('athenasoft', $events[0]->get_data()['other']['source']);
        $this->assertSame('athenasoft', $events[1]->get_data()['other']['source']);
    }

    /**
     * A suspended task user must fail before resolving the persisted source.
     *
     * @return void
     */
    public function test_followup_task_rejects_suspended_persisted_user_before_source_work(): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        $this->disable_component_streams('sissource_athenasoft');
        $user = self::getDataGenerator()->create_user();
        $repository = new provisioning_repository();
        $record = $repository->create_pending(
            'sissource_athenasoft',
            'SUSPENDED-USER-COURSE',
            'SUSPENDED-USER-COURSE',
            'Suspended user course',
            (int)$context['category']->id,
            null,
            null,
            0,
            0,
            (int)$user->id
        );
        self::setAdminUser();
        $course = create_course((object)[
            'category' => (int)$record->categoryid,
            'fullname' => $record->desiredfullname,
            'shortname' => $record->desiredshortname,
            'idnumber' => $record->courseidnumber,
        ]);
        $record = $repository->record_destination_course(
            (int)$repository->mark_running((int)$record->id)->id,
            (int)$course->id
        );
        $record = $repository->mark_terminal((int)$record->id, provisioning_repository::STATUS_READY);
        $repository->mark_followup_queued((int)$record->id);
        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        $task = new \local_wisa\task\provision_followup_task();
        $task->set_userid((int)$user->id);
        $task->set_custom_data([
            'provisionid' => (int)$record->id,
            'jobid' => $record->jobid,
        ]);

        $sink = $this->redirectEvents();
        try {
            $task->execute();
            $this->fail('Expected suspended task user to prevent source work.');
        } catch (\moodle_exception $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $this->assertCount(0, $sink->get_events());
    }

    /**
     * Disable every declared tuple without constructing or calling a source.
     *
     * @param string $component Source component.
     * @return void
     */
    private function disable_component_streams(string $component): void {
        foreach (source_factory::get_registry_for_component($component) as $descriptor) {
            foreach ($descriptor['phases'] as $phase) {
                set_config('stream_' . $descriptor['key'] . '_' . $phase . '_enabled', 0, $component);
            }
        }
    }
}
