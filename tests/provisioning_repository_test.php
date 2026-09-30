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
 * Provisioning repository state tests.
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
final class provisioning_repository_test extends provisioning_repository_test_case {
    /**
     * New records own generated job identities and source-key uniqueness.
     *
     * @return void
     */
    public function test_create_pending_persists_job_owned_payload_and_unique_source_identity(): void {
        global $DB;

        $this->resetAfterTest();
        $repository = new provisioning_repository();
        $record = $this->create_pending($repository);

        $this->assertSame(provisioning_repository::STATUS_PENDING, $record->status);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $record->jobid);
        $this->assertSame('sissource_athenasoft', $record->sourcecomponent);
        $this->assertSame('AS-COURSE-001', $record->courseidnumber);
        $this->assertSame('local_wisa_tmp_' . $record->jobid, $record->tempshortname);
        $this->assertSame('AS-course-AS-COURSE-001', $record->desiredshortname);
        $this->assertSame('Provisioned course AS-COURSE-001', $record->desiredfullname);
        $this->assertSame(42, (int)$record->categoryid);
        $this->assertSame(73, (int)$record->templateid);
        $this->assertNull($record->courseid);
        $this->assertSame(100, (int)$record->startdate);
        $this->assertSame(200, (int)$record->enddate);
        $this->assertSame(101, (int)$record->executionuserid);
        $this->assertSame(0, (int)$record->attempts);
        $this->assertSame(0, (int)$record->tempprecallabsent);
        $this->assertSame(0, (int)$record->followupqueued);
        $this->assertNull($record->lasterror);

        try {
            $this->create_pending($repository);
            $this->fail('Expected a duplicate source component and course identity to be rejected.');
        } catch (\dml_write_exception $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $duplicatejob = clone $record;
        unset($duplicatejob->id);
        $duplicatejob->sourcecomponent = 'sissource_wisa';
        $duplicatejob->courseidnumber = 'WISA-COURSE-001';
        try {
            $DB->insert_record('local_wisa_course_provision', $duplicatejob);
            $this->fail('Expected a duplicate job identity to be rejected.');
        } catch (\dml_write_exception $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
    }

    /**
     * Only pending records may run and only running records may reach terminal states.
     *
     * @return void
     */
    public function test_state_machine_allows_only_pending_running_terminal_path_and_terminal_is_immutable(): void {
        $this->resetAfterTest();
        $repository = new provisioning_repository();
        $pending = $this->create_pending($repository);

        $this->assert_invalid_transition(function () use ($repository, $pending): void {
            $repository->mark_terminal((int)$pending->id, provisioning_repository::STATUS_READY);
        });

        $running = $repository->mark_running((int)$pending->id);
        $this->assertSame(provisioning_repository::STATUS_RUNNING, $running->status);

        $ready = $repository->mark_terminal((int)$running->id, provisioning_repository::STATUS_READY);
        $this->assertSame(provisioning_repository::STATUS_READY, $ready->status);
        $this->assertTrue(provisioning_repository::is_terminal($ready->status));
        $this->assertTrue(provisioning_repository::is_terminal(provisioning_repository::STATUS_FALLBACK_READY));
        $this->assertTrue(provisioning_repository::is_terminal(provisioning_repository::STATUS_FAILED));
        $this->assertFalse(provisioning_repository::is_terminal(provisioning_repository::STATUS_RUNNING));

        $this->assert_invalid_transition(function () use ($repository, $ready): void {
            $repository->mark_running((int)$ready->id);
        });
        $this->assert_invalid_transition(function () use ($repository, $ready): void {
            $repository->mark_terminal((int)$ready->id, provisioning_repository::STATUS_FALLBACK_READY);
        });
    }

    /**
     * State changes take the per-record lock before reading or writing state.
     *
     * @return void
     */
    public function test_state_transitions_and_retries_use_per_provision_locks(): void {
        $this->resetAfterTest();
        $statesource = file_get_contents(__DIR__ . '/../classes/provisioning_state_repository_trait.php');
        $source = $statesource . file_get_contents(__DIR__ . '/../classes/provisioning_retry_repository_trait.php');
        $this->assertGreaterThanOrEqual(3, substr_count($source, '$this->get_provision_lock($id)'));
        $reflection = new \ReflectionMethod(provisioning_repository::class, 'get_provision_lock');
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }
        $lock = $reflection->invoke(new provisioning_repository(), 1);
        $this->assertStringContainsString('self::LOCK_PREFIX . $id, 0', $statesource);
        $lock->release();
    }
}
