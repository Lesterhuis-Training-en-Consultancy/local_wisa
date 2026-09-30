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
 * Provisioning service reentry test coverage.
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
 * Verifies durable provisioning reentry behavior.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provisioning_service_reentry_test extends provisioning_service_test_case {
    /**
     * A process loss after mapped duplication leaves one temporary course for same-job recovery.
     *
     * @return void
     */
    public function test_same_job_reentry_recovers_one_returned_temporary_course_without_second_duplicate(): void {
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
        $duplicatecalls = 0;
        $service = new provisioning_service_test_double(function (\stdClass $running) use (&$duplicatecalls): array {
            $duplicatecalls++;
            $course = create_course((object)[
                'category' => (int)$running->categoryid,
                'fullname' => 'Temporary duplicate',
                'shortname' => $running->tempshortname,
                'idnumber' => '',
            ]);
            return ['id' => (int)$course->id];
        });
        $service->set_destination_checkpoint(function (): void {
            throw new \coding_exception('Simulated process loss after duplicate.');
        });
        $service->set_failure_handler_callback(function (): void {
        });

        $service->execute_provision((int)$record->id, $record->jobid, $context['userid']);

        $running = (new provisioning_repository())->get((int)$record->id);
        $this->assertSame(provisioning_repository::STATUS_RUNNING, $running->status);
        $this->assertSame(1, (int)$running->tempprecallabsent);
        $this->assertNull($running->courseid);
        $this->assertSame(1, $duplicatecalls);
        $this->assertSame(1, $DB->count_records('course', ['shortname' => $record->tempshortname]));

        $service->set_destination_checkpoint(null);
        $service->set_failure_handler_callback(null);
        $service->execute_provision((int)$record->id, $record->jobid, $context['userid']);

        $finished = (new provisioning_repository())->get((int)$record->id);
        $this->assertSame(provisioning_repository::STATUS_FALLBACK_READY, $finished->status);
        $this->assertSame(1, $duplicatecalls);
        $this->assertFalse($DB->record_exists('course', ['shortname' => $record->tempshortname]));
        $this->assertSame(1, $DB->count_records('course', ['shortname' => $record->desiredshortname]));
    }

    /**
     * A proved call with no temporary or desired course is terminally ambiguous and never duplicates again.
     *
     * @return void
     */
    public function test_reentry_with_pre_call_proof_and_no_artifact_fails_without_duplicate(): void {
        $this->resetAfterTest();
        $context = $this->create_context();
        set_config('templatemap', json_encode(['template-one' => (int)$context['template']->id]), 'local_wisa');
        $record = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            'template-one'
        );
        $repository = new provisioning_repository();
        $repository->mark_temp_pre_call_absent((int)$repository->mark_running((int)$record->id)->id);
        $duplicatecalls = 0;
        $service = new provisioning_service_test_double(function (\stdClass $running) use (&$duplicatecalls): array {
            $duplicatecalls++;
            return ['id' => 1];
        });

        $service->execute_provision((int)$record->id, $record->jobid, $context['userid']);

        $finished = $repository->get((int)$record->id);
        $this->assertSame(provisioning_repository::STATUS_FAILED, $finished->status);
        $this->assertSame(0, $duplicatecalls);
    }

    /**
     * A matching temporary pointer is cleared only after its sole temporary course is deleted.
     *
     * @return void
     */
    public function test_reentry_with_pointer_to_sole_temporary_course_creates_one_fallback(): void {
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
        $repository = new provisioning_repository();
        $running = $repository->mark_running((int)$record->id);
        $repository->mark_temp_pre_call_absent((int)$running->id);
        $temporary = create_course((object)[
            'category' => (int)$record->categoryid,
            'fullname' => 'Temporary duplicate',
            'shortname' => $record->tempshortname,
            'idnumber' => '',
        ]);
        $repository->record_destination_course((int)$record->id, (int)$temporary->id);

        (new provisioning_service_test_double())->execute_provision((int)$record->id, $record->jobid, $context['userid']);

        $finished = $repository->get((int)$record->id);
        $this->assertSame(provisioning_repository::STATUS_FALLBACK_READY, $finished->status);
        $this->assertNotSame((int)$temporary->id, (int)$finished->courseid);
        $this->assertFalse($DB->record_exists('course', ['shortname' => $record->tempshortname]));
        $this->assertSame(1, $DB->count_records('course', ['shortname' => $record->desiredshortname]));
    }

    /**
     * Only an unmapped job may adopt an exactly matching pointed desired destination.
     *
     * @return void
     */
    public function test_unmapped_desired_pointer_is_adopted_but_mapped_pointer_fails_without_duplication(): void {
        $this->resetAfterTest();
        $context = $this->create_context();
        $repository = new provisioning_repository();
        $unmapped = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            null,
            'UNMAPPED-ADOPT'
        );
        $unmappedcourse = create_course((object)[
            'category' => (int)$unmapped->categoryid,
            'fullname' => $unmapped->desiredfullname,
            'shortname' => $unmapped->desiredshortname,
            'idnumber' => $unmapped->courseidnumber,
            'startdate' => (int)$unmapped->startdate,
            'enddate' => (int)$unmapped->enddate,
        ]);
        $repository->record_destination_course(
            (int)$repository->mark_running((int)$unmapped->id)->id,
            (int)$unmappedcourse->id
        );

        (new provisioning_service_test_double())->execute_provision(
            (int)$unmapped->id,
            $unmapped->jobid,
            $context['userid']
        );
        $this->assertSame(
            provisioning_repository::STATUS_FALLBACK_READY,
            $repository->get((int)$unmapped->id)->status
        );

        set_config('templatemap', json_encode(['template-one' => (int)$context['template']->id]), 'local_wisa');
        $mapped = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            'template-one',
            'MAPPED-ADOPT'
        );
        $mappedcourse = create_course((object)[
            'category' => (int)$mapped->categoryid,
            'fullname' => $mapped->desiredfullname,
            'shortname' => $mapped->desiredshortname,
            'idnumber' => $mapped->courseidnumber,
            'startdate' => (int)$mapped->startdate,
            'enddate' => (int)$mapped->enddate,
        ]);
        $repository->record_destination_course(
            (int)$repository->mark_running((int)$mapped->id)->id,
            (int)$mappedcourse->id
        );
        $duplicatecalls = 0;
        $service = new provisioning_service_test_double(function (\stdClass $running) use (&$duplicatecalls): array {
            $duplicatecalls++;
            return ['id' => 1];
        });

        $service->execute_provision((int)$mapped->id, $mapped->jobid, $context['userid']);

        $this->assertSame(provisioning_repository::STATUS_FAILED, $repository->get((int)$mapped->id)->status);
        $this->assertSame(0, $duplicatecalls);
    }

    /**
     * Desired-identity conflicts and multiple temporary courses never create another destination.
     *
     * @return void
     */
    public function test_running_identity_conflicts_and_multiple_temporary_courses_fail_without_duplicate(): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        set_config('templatemap', json_encode(['template-one' => (int)$context['template']->id]), 'local_wisa');
        $repository = new provisioning_repository();
        $conflict = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            'template-one',
            'IDENTITY-CONFLICT'
        );
        $repository->mark_running((int)$conflict->id);
        create_course((object)[
            'category' => (int)$conflict->categoryid,
            'fullname' => $conflict->desiredfullname,
            'shortname' => $conflict->desiredshortname,
            'idnumber' => $conflict->courseidnumber,
        ]);
        $duplicatecalls = 0;
        $service = new provisioning_service_test_double(function (\stdClass $running) use (&$duplicatecalls): array {
            $duplicatecalls++;
            return ['id' => 1];
        });

        $service->execute_provision((int)$conflict->id, $conflict->jobid, $context['userid']);

        $this->assertSame(provisioning_repository::STATUS_FAILED, $repository->get((int)$conflict->id)->status);
        $this->assertSame(0, $duplicatecalls);

        $multiple = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            'template-one',
            'MULTIPLE-TEMPORARY'
        );
        $repository->mark_temp_pre_call_absent((int)$repository->mark_running((int)$multiple->id)->id);
        $temporary = create_course((object)[
            'category' => (int)$multiple->categoryid,
            'fullname' => 'First temporary duplicate',
            'shortname' => $multiple->tempshortname,
            'idnumber' => '',
        ]);
        $duplicate = $DB->get_record('course', ['id' => (int)$temporary->id], '*', MUST_EXIST);
        unset($duplicate->id);
        $duplicate->fullname = 'Second temporary duplicate';
        $DB->insert_record('course', $duplicate);

        $service->execute_provision((int)$multiple->id, $multiple->jobid, $context['userid']);

        $this->assertSame(provisioning_repository::STATUS_FAILED, $repository->get((int)$multiple->id)->status);
        $this->assertSame(0, $duplicatecalls);
    }

    /**
     * A stale task user or durable job identity cannot mutate an active provision row.
     *
     * @return void
     */
    public function test_stale_task_user_or_job_returns_without_mutating_pending_or_running_rows(): void {
        $this->resetAfterTest();
        $context = $this->create_context();
        $record = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            null
        );
        $service = new provisioning_service_test_double();

        $service->execute_provision((int)$record->id, $record->jobid, (int)$context['userid'] + 1);
        $this->assertSame(provisioning_repository::STATUS_PENDING, (new provisioning_repository())->get((int)$record->id)->status);

        $repository = new provisioning_repository();
        $repository->mark_running((int)$record->id);
        $service->execute_provision((int)$record->id, str_repeat('a', 32), $context['userid']);
        $this->assertSame(provisioning_repository::STATUS_RUNNING, $repository->get((int)$record->id)->status);
    }
}
