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
 * Provisioning service failure recovery test coverage.
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
 * Verifies provisioning failure and recovery safety.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provisioning_service_failure_recovery_test extends provisioning_service_test_case {
    /**
     * A mapped restore failure deletes the sole proved temporary course before one fallback.
     *
     * @return void
     */
    public function test_mapped_restore_failure_deletes_only_proved_temp_course_before_fallback(): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        set_config('templatemap', json_encode(['template-one' => (int)$context['template']->id]), 'local_wisa');
        $record = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            'template-one'
        );
        $service = new provisioning_service_test_double(function (\stdClass $running): array {
            create_course((object)[
                'category' => $running->categoryid,
                'fullname' => 'Temporary duplicate',
                'shortname' => $running->tempshortname,
            ]);
            throw new \moodle_exception('simulatedrestorefailure');
        });

        $service->execute_provision((int)$record->id, $record->jobid, $context['userid']);

        $finished = (new provisioning_repository())->get((int)$record->id);
        $this->assertSame(provisioning_repository::STATUS_FALLBACK_READY, $finished->status);
        $this->assertFalse($DB->record_exists('course', ['shortname' => $record->tempshortname]));
        $this->assertSame(1, $DB->count_records('course', ['shortname' => $record->desiredshortname]));
        $this->assertCount(1, $DB->get_records('local_wisa_log', [
            'action' => 'provision',
            'objectid' => $record->jobid,
            'status' => 'fallback',
        ]));
    }

    /**
     * A failed mapped duplicate with no uniquely proved temporary course cannot create a fallback.
     *
     * @return void
     */
    public function test_unproven_mapped_failure_remains_failed_without_a_destination(): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        set_config('templatemap', json_encode(['template-one' => (int)$context['template']->id]), 'local_wisa');
        $record = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            'template-one'
        );
        $service = new provisioning_service_test_double(function (\stdClass $running): array {
            throw new \moodle_exception('simulatedrestorefailure');
        });

        $service->execute_provision((int)$record->id, $record->jobid, $context['userid']);

        $finished = (new provisioning_repository())->get((int)$record->id);
        $this->assertSame(provisioning_repository::STATUS_FAILED, $finished->status);
        $this->assertSame(provisioning_diagnostic::CODE_MOODLE_COURSE_CREATE_RESTORE, $finished->lasterror);
        $this->assertNull($finished->courseid);
        $this->assertSame(0, $DB->count_records('course', ['shortname' => $record->desiredshortname]));
        $this->assertSame(0, $DB->count_records('course', ['shortname' => $record->tempshortname]));
    }

    /**
     * A finalization failure rolls back the pointer and desired identity while preserving recovery proof.
     *
     * @return void
     */
    public function test_mapped_finalization_failure_rolls_back_pointer_identity_and_terminal_state(): void {
        global $DB;

        $this->preventResetByRollback();
        $this->resetAfterTest();
        $context = $this->create_context();
        set_config('templatemap', json_encode(['template-one' => (int)$context['template']->id]), 'local_wisa');
        $record = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            'template-one'
        );
        $service = new provisioning_service_test_double(function (\stdClass $running): array {
            $course = create_course((object)[
                'category' => (int)$running->categoryid,
                'fullname' => 'Temporary duplicate',
                'shortname' => $running->tempshortname,
                'idnumber' => 'TEMPORARY-ID',
            ]);
            return ['id' => (int)$course->id];
        });
        $service->set_identity_checkpoint(function (): void {
            throw new \coding_exception('Simulated finalization process loss.');
        });
        $service->set_failure_handler_callback(function (): void {
        });

        $service->execute_provision((int)$record->id, $record->jobid, $context['userid']);

        $running = (new provisioning_repository())->get((int)$record->id);
        $temporary = $DB->get_record('course', ['shortname' => $record->tempshortname], '*', MUST_EXIST);
        $this->assertSame(provisioning_repository::STATUS_RUNNING, $running->status);
        $this->assertNull($running->courseid);
        $this->assertSame('Temporary duplicate', $temporary->fullname);
        $this->assertSame('TEMPORARY-ID', $temporary->idnumber);
        $this->assertFalse($DB->record_exists('course', ['shortname' => $record->desiredshortname]));
    }

    /**
     * Fallback creation remains fully transactional until its terminal finalization succeeds.
     *
     * @return void
     */
    public function test_fallback_failure_after_creation_rolls_back_course_proof_pointer_and_terminal_state(): void {
        global $DB;

        $this->preventResetByRollback();
        $this->resetAfterTest();
        $context = $this->create_context();
        $record = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            null
        );
        $service = new provisioning_service_test_double();
        $service->set_destination_checkpoint(function (\stdClass $running, \stdClass $course): void {
            $this->assertSame($running->tempshortname, $course->shortname);
            $this->assertSame('', $course->idnumber);
            $this->assertSame($running->desiredfullname, $course->fullname);
            $this->assertSame((int)$running->categoryid, (int)$course->category);
            $this->assertSame((int)$running->startdate, (int)$course->startdate);
            $this->assertSame((int)$running->enddate, (int)$course->enddate);
            throw new \coding_exception('Simulated fallback process loss.');
        });
        $service->set_failure_handler_callback(function (): void {
        });

        $service->execute_provision((int)$record->id, $record->jobid, $context['userid']);

        $running = (new provisioning_repository())->get((int)$record->id);
        $this->assertSame(provisioning_repository::STATUS_RUNNING, $running->status);
        $this->assertSame(0, (int)$running->tempprecallabsent);
        $this->assertNull($running->courseid);
        $this->assertFalse($DB->record_exists('course', ['shortname' => $record->tempshortname]));
        $this->assertFalse($DB->record_exists('course', ['shortname' => $record->desiredshortname]));
    }

    /**
     * Recovery refuses fallback when the persisted task user cannot delete its sole temporary course.
     *
     * @return void
     */
    public function test_temporary_recovery_without_delete_capability_fails_without_fallback(): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        $worker = self::getDataGenerator()->create_user();
        $repository = new provisioning_repository();
        $record = $repository->create_pending(
            'sissource_wisa',
            'DELETE-CAPABILITY',
            'DEST-DELETE-CAPABILITY',
            'Destination DELETE-CAPABILITY',
            (int)$context['category']->id,
            (int)$context['template']->id,
            null,
            1700000000,
            1800000000,
            (int)$worker->id
        );
        $repository->mark_temp_pre_call_absent((int)$repository->mark_running((int)$record->id)->id);
        create_course((object)[
            'category' => (int)$record->categoryid,
            'fullname' => 'Temporary duplicate',
            'shortname' => $record->tempshortname,
            'idnumber' => '',
        ]);

        (new provisioning_service_test_double())->execute_provision((int)$record->id, $record->jobid, (int)$worker->id);

        $finished = $repository->get((int)$record->id);
        $this->assertSame(provisioning_repository::STATUS_FAILED, $finished->status);
        $this->assertSame(0, $DB->count_records('course', ['shortname' => $record->desiredshortname]));
        $this->assertSame(1, $DB->count_records('course', ['shortname' => $record->tempshortname]));
    }

    /**
     * Ordinary mapped-failure handling revalidates its template before deleting a proved temporary course.
     *
     * @return void
     */
    public function test_mapped_failure_catch_revalidates_template_before_cleanup(): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        set_config('templatemap', json_encode(['template-one' => (int)$context['template']->id]), 'local_wisa');
        $record = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            'template-one',
            'MISSING-TEMPLATE-RECOVERY'
        );
        $templateid = (int)$context['template']->id;
        $service = new provisioning_service_test_double(function (\stdClass $running) use ($templateid): array {
            global $DB;

            $DB->delete_records('course', ['id' => $templateid]);
            create_course((object)[
                'category' => (int)$running->categoryid,
                'fullname' => 'Temporary duplicate',
                'shortname' => $running->tempshortname,
                'idnumber' => '',
            ]);
            throw new \moodle_exception('simulatedrestorefailure');
        });

        $service->execute_provision((int)$record->id, $record->jobid, $context['userid']);

        $finished = (new provisioning_repository())->get((int)$record->id);
        $this->assertSame(provisioning_repository::STATUS_FAILED, $finished->status);
        $this->assertSame(1, $DB->count_records('course', ['shortname' => $record->tempshortname]));
        $this->assertSame(0, $DB->count_records('course', ['shortname' => $record->desiredshortname]));
        $this->assertCount(0, $this->queued_followup_tasks());
    }

    /**
     * Running temporary recovery validates its destination category before deleting anything.
     *
     * @return void
     */
    public function test_mapped_running_recovery_revalidates_destination_category_before_cleanup(): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        set_config('templatemap', json_encode(['template-one' => (int)$context['template']->id]), 'local_wisa');
        $record = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            'template-one',
            'MISSING-CATEGORY-RECOVERY'
        );
        $repository = new provisioning_repository();
        $repository->mark_temp_pre_call_absent((int)$repository->mark_running((int)$record->id)->id);
        create_course((object)[
            'category' => (int)$record->categoryid,
            'fullname' => 'Temporary duplicate',
            'shortname' => $record->tempshortname,
            'idnumber' => '',
        ]);
        $DB->delete_records('course_categories', ['id' => (int)$record->categoryid]);

        (new provisioning_service_test_double())->execute_provision((int)$record->id, $record->jobid, $context['userid']);

        $finished = $repository->get((int)$record->id);
        $this->assertSame(provisioning_repository::STATUS_FAILED, $finished->status);
        $this->assertSame(1, $DB->count_records('course', ['shortname' => $record->tempshortname]));
        $this->assertSame(0, $DB->count_records('course', ['shortname' => $record->desiredshortname]));
        $this->assertCount(0, $this->queued_followup_tasks());
    }

    /**
     * Unmapped desired-pointer adoption requires current destination create capability.
     *
     * @return void
     */
    public function test_unmapped_desired_pointer_adoption_requires_destination_create_capability(): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        $worker = self::getDataGenerator()->create_user();
        $repository = new provisioning_repository();
        $record = $repository->create_pending(
            'sissource_wisa',
            'ADOPTION-CAPABILITY',
            'DEST-ADOPTION-CAPABILITY',
            'Destination ADOPTION-CAPABILITY',
            (int)$context['category']->id,
            null,
            null,
            1700000000,
            1800000000,
            (int)$worker->id
        );
        $desired = create_course((object)[
            'category' => (int)$record->categoryid,
            'fullname' => $record->desiredfullname,
            'shortname' => $record->desiredshortname,
            'idnumber' => $record->courseidnumber,
            'startdate' => (int)$record->startdate,
            'enddate' => (int)$record->enddate,
        ]);
        $repository->record_destination_course(
            (int)$repository->mark_running((int)$record->id)->id,
            (int)$desired->id
        );

        (new provisioning_service_test_double())->execute_provision((int)$record->id, $record->jobid, (int)$worker->id);

        $finished = $repository->get((int)$record->id);
        $this->assertSame(provisioning_repository::STATUS_FAILED, $finished->status);
        $this->assertSame((int)$desired->id, (int)$finished->courseid);
        $this->assertTrue($DB->record_exists('course', ['id' => (int)$desired->id]));
        $this->assertCount(0, $this->queued_followup_tasks());
    }
}
