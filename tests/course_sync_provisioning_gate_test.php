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
 * Course synchronisation provisioning gate tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/course_sync_test_case.php');

use local_wisa\sync\course_sync;
use local_wisa\tests\sis_fixtures;

/**
 * Course synchronisation provisioning gate tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_sync_provisioning_gate_test extends course_sync_test_case {
    /**
     * Verify durable blocking states prevent an existing course update.
     *
     * @dataProvider blocking_provision_status_provider
     * @param string $status Existing durable provision status.
     * @return void
     */
    public function test_blocking_provision_prevents_existing_course_update(string $status): void {
        global $DB;

        $this->resetAfterTest();
        $categoryid = $this->configure_wisa_defaults();
        self::setAdminUser();
        set_config('enable_course_provisioning', '0', 'local_wisa');
        $course = $this->create_course_with_idnumber('STATE-FIRST-EXISTING', [
            'category' => $categoryid,
            'fullname' => 'Original fullname',
            'shortname' => 'STATE-FIRST-ORIGINAL',
        ]);
        $queued = $this->queue_provision($categoryid, 'STATE-FIRST-EXISTING');
        $repository = new provisioning_repository();
        if ($status === provisioning_repository::STATUS_RUNNING) {
            $repository->mark_running((int)$queued->id);
        } else if ($status === provisioning_repository::STATUS_FAILED) {
            $running = $repository->mark_running((int)$queued->id);
            $repository->mark_terminal((int)$running->id, provisioning_repository::STATUS_FAILED, 'Provisioning failed.');
        }
        $rows = [sis_fixtures::course([
            'KLAS_ID' => 'STATE-FIRST-EXISTING',
            'SHORTNAME' => 'STATE-FIRST-UPDATED',
            'FULLNAME' => 'Updated fullname',
        ]), ];

        $sync = new course_sync('sissource_wisa');
        $this->assertSame($status !== provisioning_repository::STATUS_FAILED, $sync->run($rows));

        $unchanged = $DB->get_record('course', ['id' => $course->id], '*', MUST_EXIST);
        $this->assertSame((int)$course->id, (int)$unchanged->id);
        $this->assertSame('Original fullname', $unchanged->fullname);
        $this->assertSame('STATE-FIRST-ORIGINAL', $unchanged->shortname);
        $this->assertTrue($sync->has_blocking_provisions());
    }

    /**
     * Return durable provision states that must prevent existing-course updates.
     *
     * @return array Provision status test cases.
     */
    public static function blocking_provision_status_provider(): array {
        return parent::blocking_provision_status_provider();
    }

    public function test_disabled_provisioning_still_blocks_new_course_with_pending_provision(): void {
        global $DB;

        $this->resetAfterTest();
        $categoryid = $this->configure_wisa_defaults();
        self::setAdminUser();
        $this->queue_provision($categoryid, 'DISABLED-PENDING');
        set_config('enable_course_provisioning', '0', 'local_wisa');
        $stats = new sync_stats();
        $rows = [sis_fixtures::course([
            'KLAS_ID' => 'DISABLED-PENDING',
            'SHORTNAME' => 'DISABLED-PENDING',
            'FULLNAME' => 'Disabled pending course',
            'templatekey' => 'EX651',
        ]), ];
        $sync = new course_sync('sissource_wisa', false, $stats);

        $this->assertTrue($sync->run($rows));

        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'DISABLED-PENDING']));
        $this->assertTrue($sync->has_blocking_provisions());
        $this->assertSame(1, $stats->courseskip);
    }

    public function test_unkeyed_course_still_blocks_new_creation_with_running_provision(): void {
        global $DB;

        $this->resetAfterTest();
        $categoryid = $this->configure_wisa_defaults();
        self::setAdminUser();
        $queued = $this->queue_provision($categoryid, 'UNKEYED-RUNNING');
        (new provisioning_repository())->mark_running((int)$queued->id);
        set_config('enable_course_provisioning', '1', 'local_wisa');
        $stats = new sync_stats();
        $rows = [sis_fixtures::course([
            'KLAS_ID' => 'UNKEYED-RUNNING',
            'SHORTNAME' => 'UNKEYED-RUNNING',
            'FULLNAME' => 'Unkeyed running course',
        ]), ];
        $sync = new course_sync('sissource_wisa', false, $stats);

        $this->assertTrue($sync->run($rows));

        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'UNKEYED-RUNNING']));
        $this->assertTrue($sync->has_blocking_provisions());
        $this->assertSame(1, $stats->courseskip);
    }

    public function test_running_keyed_provision_blocks_synchronous_course_creation(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $categoryid = $this->configure_wisa_defaults();
        self::setAdminUser();
        set_config('enable_course_provisioning', '1', 'local_wisa');
        $queued = (new provisioning_service())->queue(
            'sissource_wisa',
            'WISA-COURSE-001',
            'WISA C001',
            'WISA Course 001',
            $categoryid,
            'EX651',
            make_timestamp(2026, 9, 1),
            make_timestamp(2027, 6, 30),
            (int)$USER->id
        );
        (new provisioning_repository())->mark_running((int)$queued->id);
        $stats = new sync_stats();
        $sync = new course_sync('sissource_wisa', false, $stats);

        $this->assertTrue($sync->run([sis_fixtures::course(['templatekey' => 'EX651'])]));

        $this->assertSame(1, $DB->count_records('local_wisa_course_provision', [
            'sourcecomponent' => 'sissource_wisa',
            'courseidnumber' => 'WISA-COURSE-001',
            'status' => provisioning_repository::STATUS_RUNNING,
        ]));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(\local_wisa\task\provision_course_task::class));
        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'WISA-COURSE-001']));
        $this->assertTrue($sync->has_blocking_provisions());
        $this->assertSame(1, $stats->courseskip);
    }

    public function test_failed_keyed_provision_blocks_synchronous_course_creation(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $categoryid = $this->configure_wisa_defaults();
        self::setAdminUser();
        set_config('enable_course_provisioning', '1', 'local_wisa');
        $queued = (new provisioning_service())->queue(
            'sissource_wisa',
            'WISA-COURSE-001',
            'WISA C001',
            'WISA Course 001',
            $categoryid,
            'EX651',
            make_timestamp(2026, 9, 1),
            make_timestamp(2027, 6, 30),
            (int)$USER->id
        );
        $repository = new provisioning_repository();
        $repository->mark_terminal(
            (int)$repository->mark_running((int)$queued->id)->id,
            provisioning_repository::STATUS_FAILED,
            'Provisioning failed.'
        );
        $stats = new sync_stats();
        $sync = new course_sync('sissource_wisa', false, $stats);

        $this->assertFalse($sync->run([sis_fixtures::course(['templatekey' => 'EX651'])]));

        $this->assertSame(1, $DB->count_records('local_wisa_course_provision', [
            'sourcecomponent' => 'sissource_wisa',
            'courseidnumber' => 'WISA-COURSE-001',
            'status' => provisioning_repository::STATUS_FAILED,
        ]));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(\local_wisa\task\provision_course_task::class));
        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'WISA-COURSE-001']));
        $this->assertTrue($sync->has_blocking_provisions());
        $this->assertSame(1, $stats->coursefail);
    }
}
