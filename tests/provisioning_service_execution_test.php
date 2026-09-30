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
 * Provisioning service execution test coverage.
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
 * Verifies provisioning queue and normal worker execution.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provisioning_service_execution_test extends provisioning_service_test_case {
    /**
     * A source key owns one pending row and one task with no source payload.
     *
     * @return void
     */
    public function test_queue_persists_one_pending_row_and_one_identity_only_task(): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        set_config('templatemap', json_encode(['template-one' => (int)$context['template']->id]), 'local_wisa');
        $service = new provisioning_service();

        $first = $this->queue_record($service, (int)$context['category']->id, $context['userid'], 'template-one');
        $second = $this->queue_record($service, (int)$context['category']->id, $context['userid'], 'template-one');

        $this->assertSame((int)$first->id, (int)$second->id);
        $this->assertSame(provisioning_repository::STATUS_PENDING, $first->status);
        $this->assertSame((int)$context['template']->id, (int)$first->templateid);
        $this->assertSame(1, $DB->count_records('local_wisa_course_provision'));
        $task = $this->queued_task();
        $this->assertSame($context['userid'], (int)$task->get_userid());
        $this->assertSame([
            'provisionid' => (int)$first->id,
            'jobid' => $first->jobid,
        ], (array)$task->get_custom_data());
    }

    /**
     * An invalid template map must use the logged empty-course fallback without duplication.
     *
     * @return void
     */
    public function test_invalid_template_map_creates_one_logged_empty_fallback(): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        set_config('templatemap', '{invalid json', 'local_wisa');
        $record = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            'configured-template'
        );

        $this->assertNull($record->templateid);
        $this->runAdhocTasks(\local_wisa\task\provision_course_task::class, $context['userid']);

        $finished = (new provisioning_repository())->get((int)$record->id);
        $this->assertSame(provisioning_repository::STATUS_FALLBACK_READY, $finished->status);
        $this->assertNotNull($finished->courseid);
        $this->assertSame(1, (int)$finished->followupqueued);
        $followups = $this->queued_followup_tasks();
        $this->assertCount(1, $followups);
        $this->assertSame($context['userid'], (int)reset($followups)->get_userid());
        $this->assertSame([
            'provisionid' => (int)$record->id,
            'jobid' => $record->jobid,
        ], (array)reset($followups)->get_custom_data());
        $this->assertSame(1, $DB->count_records('course', ['shortname' => $record->desiredshortname]));
        $this->assertCount(1, $DB->get_records('local_wisa_log', [
            'action' => 'provision',
            'objectid' => $record->jobid,
            'status' => 'fallback',
        ]));
    }

    /**
     * The real adhoc worker duplicates a Moodle template then applies the desired identity.
     *
     * @return void
     */
    public function test_real_adhoc_worker_duplicates_template_and_marks_ready(): void {
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

        $this->runAdhocTasks(\local_wisa\task\provision_course_task::class, $context['userid']);

        $finished = (new provisioning_repository())->get((int)$record->id);
        $destination = $DB->get_record('course', ['id' => $finished->courseid], '*', MUST_EXIST);
        $this->assertSame(provisioning_repository::STATUS_READY, $finished->status);
        $this->assertSame($record->desiredshortname, $destination->shortname);
        $this->assertSame($record->desiredfullname, $destination->fullname);
        $this->assertSame($record->courseidnumber, $destination->idnumber);
        $this->assertSame((int)$record->startdate, (int)$destination->startdate);
        $this->assertSame((int)$record->enddate, (int)$destination->enddate);
        $this->assertFalse($DB->record_exists('course', ['shortname' => $record->tempshortname]));
        $this->assertSame(1, (int)$finished->followupqueued);
        $followups = $this->queued_followup_tasks();
        $this->assertCount(1, $followups);
        $this->assertSame($context['userid'], (int)reset($followups)->get_userid());
        $this->assertSame([
            'provisionid' => (int)$record->id,
            'jobid' => $record->jobid,
        ], (array)reset($followups)->get_custom_data());
    }
}
