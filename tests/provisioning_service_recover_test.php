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
 * Manual recovery coverage for deleted provisioned courses.
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
 * Verifies row-only recovery without changing ordinary retry eligibility.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_wisa\provisioning_service
 * @covers     \local_wisa\provisioning_repository
 * @covers     \local_wisa\task\provision_course_task
 */
final class provisioning_service_recover_test extends provisioning_service_test_case {
    /**
     * Both successful terminal states can recover a deleted destination.
     *
     * @return array Test cases.
     */
    public static function terminal_states(): array {
        return ['template' => ['ready'], 'fallback' => ['fallback_ready']];
    }

    /**
     * Recovery resets processing state and queues once without refreshing saved details.
     *
     * @dataProvider terminal_states
     * @param string $status Original terminal status.
     * @return void
     */
    public function test_recovery_resets_state_and_queues_saved_request(string $status): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        $record = $this->create_deleted_provision($context, $status);
        $service = new provisioning_service();
        $coursecount = $DB->count_records('course');
        $this->assertTrue($service->is_recovery_available($record));
        $this->assertFalse($service->is_retry_available($record));

        $recovered = $service->recover((int)$record->id, $context['userid']);

        $this->assertSame('pending', $recovered->status);
        $this->assertNull($recovered->courseid);
        $this->assertSame(0, (int)$recovered->tempprecallabsent);
        $this->assertSame(0, (int)$recovered->followupqueued);
        $this->assertSame(1, (int)$recovered->attempts);
        $this->assertNull($recovered->lasterror);
        foreach (
            ['id', 'jobid', 'sourcecomponent', 'courseidnumber', 'tempshortname', 'desiredshortname',
                'desiredfullname', 'categoryid', 'templateid', 'startdate', 'enddate', 'timecreated'] as $field
        ) {
            $this->assertEquals($record->$field, $recovered->$field, 'Preserve ' . $field);
        }
        $this->assertSame($coursecount, $DB->count_records('course'), 'The request must not create a course.');
        $task = $this->queued_task();
        $this->assertSame($context['userid'], (int)$task->get_userid());
        $this->assertSame(['provisionid' => (int)$record->id, 'jobid' => $record->jobid], (array)$task->get_custom_data());
    }

    /**
     * The existing worker creates a replacement and schedules its normal follow-up.
     *
     * @dataProvider terminal_states
     * @param string $status Original terminal status.
     * @return void
     */
    public function test_existing_worker_recreates_deleted_destination(string $status): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        $record = $this->create_deleted_provision($context, $status);
        (new provisioning_service())->recover((int)$record->id, $context['userid']);

        $this->queued_task()->execute();

        $finished = (new provisioning_repository())->get((int)$record->id);
        $this->assertSame($status, $finished->status);
        $this->assertNotEquals($record->courseid, $finished->courseid);
        $course = $DB->get_record('course', ['id' => $finished->courseid], '*', MUST_EXIST);
        $this->assertSame($record->desiredshortname, $course->shortname);
        $this->assertSame($record->courseidnumber, $course->idnumber);
        $this->assertSame(1, (int)$finished->followupqueued);
        $this->assertCount(1, $this->queued_followup_tasks());
    }

    /**
     * States outside the deleted successful destination contract are ineligible.
     *
     * @return array Test cases.
     */
    public static function ineligible_records(): array {
        return [
            'ready live course' => ['ready', 'live'],
            'fallback live course' => ['fallback_ready', 'live'],
            'pending' => ['pending', 'missing'],
            'running' => ['running', 'missing'],
            'failed' => ['failed', 'missing'],
            'ready null pointer' => ['ready', 'null'],
            'fallback zero pointer' => ['fallback_ready', 'zero'],
        ];
    }

    /**
     * Forged submissions cannot recover an ineligible record.
     *
     * @dataProvider ineligible_records
     * @param string $status Record status.
     * @param string $destination Destination fixture condition.
     * @return void
     */
    public function test_ineligible_recovery_leaves_record_and_queue_unchanged(string $status, string $destination): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        $record = $this->create_deleted_provision($context, $status);
        if ($destination !== 'missing') {
            $record->courseid = $destination === 'live' ? $context['template']->id : ($destination === 'zero' ? 0 : null);
            $DB->update_record('local_wisa_course_provision', $record);
        }
        $service = new provisioning_service();
        $this->assertFalse($service->is_recovery_available($record));

        $this->assert_recovery_rejected($service, $record, $context['userid']);

        $this->assertEquals($record, (new provisioning_repository())->get((int)$record->id));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_wisa\task\provision_course_task::class));
    }

    /**
     * Repeated POSTs cannot reset pending work or queue a second task.
     *
     * @return void
     */
    public function test_repeated_recovery_does_not_queue_twice(): void {
        $this->resetAfterTest();
        $context = $this->create_context();
        $record = $this->create_deleted_provision($context, 'ready');
        $service = new provisioning_service();
        $pending = $service->recover((int)$record->id, $context['userid']);

        $this->assert_recovery_rejected($service, $record, $context['userid']);

        $this->assertEquals($pending, (new provisioning_repository())->get((int)$record->id));
        $this->queued_task();
    }

    /**
     * Recovery cannot be submitted without the existing administration capability.
     *
     * @return void
     */
    public function test_recovery_requires_site_configuration_capability(): void {
        $this->resetAfterTest();
        $context = $this->create_context();
        $record = $this->create_deleted_provision($context, 'ready');
        $user = $this->getDataGenerator()->create_user();
        self::setUser($user);

        $this->expectException(\required_capability_exception::class);
        (new provisioning_service())->recover((int)$record->id, (int)$user->id);
    }

    /**
     * Course identities that conflict with a fresh provisioning attempt.
     *
     * @return array Test cases.
     */
    public static function conflicting_identities(): array {
        return ['temporary' => ['tempshortname'], 'shortname' => ['desiredshortname'], 'source key' => ['courseidnumber']];
    }

    /**
     * Existing courses with any destination identity must not be removed by recovery.
     *
     * @dataProvider conflicting_identities
     * @param string $field Conflicting identity field on the provision record.
     * @return void
     */
    public function test_conflicting_courses_are_not_modified(string $field): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        $record = $this->create_deleted_provision($context, 'ready');
        $coursefield = $field === 'courseidnumber' ? 'idnumber' : 'shortname';
        $course = $this->getDataGenerator()->create_course([$coursefield => $record->$field]);

        $this->assert_recovery_rejected(new provisioning_service(), $record, $context['userid']);

        $this->assertTrue($DB->record_exists('course', ['id' => $course->id]));
        $this->assertEquals($record, (new provisioning_repository())->get((int)$record->id));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_wisa\task\provision_course_task::class));
    }

    /**
     * Recovery must not replace a matching runnable task.
     *
     * @return void
     */
    public function test_runnable_task_blocks_recovery(): void {
        $this->resetAfterTest();
        $context = $this->create_context();
        $record = $this->create_deleted_provision($context, 'fallback_ready');
        $task = new \local_wisa\task\provision_course_task();
        $task->set_userid($context['userid']);
        $task->set_custom_data(['provisionid' => (int)$record->id, 'jobid' => $record->jobid]);
        $taskid = \core\task\manager::queue_adhoc_task($task);
        $service = new provisioning_service();
        $this->assertFalse($service->is_recovery_available($record));

        $this->assert_recovery_rejected($service, $record, $context['userid']);

        $this->assertEquals($record, (new provisioning_repository())->get((int)$record->id));
        $this->assertEquals($taskid, $this->queued_task()->get_id());
    }

    /**
     * A failed queue publication rolls back the destination and marker reset.
     *
     * @return void
     */
    public function test_queue_failure_rolls_back_recovery(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $context = $this->create_context();
        $record = $this->create_deleted_provision($context, 'ready');
        $service = $this->getMockBuilder(provisioning_service::class)->onlyMethods(['queue_task'])->getMock();
        $service->expects($this->once())->method('queue_task')->willThrowException(new \coding_exception('Queue unavailable.'));

        $this->assert_recovery_rejected($service, $record, $context['userid']);

        $this->assertEquals($record, (new provisioning_repository())->get((int)$record->id));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_wisa\task\provision_course_task::class));
    }

    /**
     * Ordinary retry must continue rejecting successful rows with deleted destinations.
     *
     * @dataProvider terminal_states
     * @param string $status Original terminal status.
     * @return void
     */
    public function test_ordinary_retry_does_not_recover_successful_records(string $status): void {
        $this->resetAfterTest();
        $context = $this->create_context();
        $record = $this->create_deleted_provision($context, $status);

        $this->expectException(\coding_exception::class);
        (new provisioning_service())->retry((int)$record->id, $context['userid']);
    }

    /**
     * Construct a deleted destination with successful processing markers retained.
     *
     * @param array $context Category, template and execution user.
     * @param string $status Persisted status.
     * @return \stdClass Deleted-course provision.
     */
    private function create_deleted_provision(array $context, string $status): \stdClass {
        global $DB;

        $record = $this->create_terminal_record($context, 'RECOVER-001');
        delete_course((int)$record->courseid, false);
        $record->status = $status;
        $record->templateid = $status === 'ready' ? $context['template']->id : null;
        $record->tempprecallabsent = 1;
        $record->followupqueued = 1;
        $DB->update_record('local_wisa_course_provision', $record);
        return (new provisioning_repository())->get((int)$record->id);
    }

    /**
     * Assert that a manual recovery request is rejected by its safety guards.
     *
     * @param provisioning_service $service Recovery service.
     * @param \stdClass $record Provision record.
     * @param int $userid Execution user.
     * @return void
     */
    private function assert_recovery_rejected(provisioning_service $service, \stdClass $record, int $userid): void {
        try {
            $service->recover((int)$record->id, $userid);
            $this->fail('Expected manual recovery to reject this request.');
        } catch (\coding_exception $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
    }
}
