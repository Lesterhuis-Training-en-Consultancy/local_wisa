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
 * Course synchronisation provisioning queue tests.
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
 * Course synchronisation provisioning queue tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_sync_provisioning_queue_test extends course_sync_test_case {
    public function test_enabled_keyed_course_queues_provision_without_synchronous_course(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $categoryid = $this->configure_wisa_defaults();
        self::setAdminUser();
        set_config('enable_course_provisioning', '1', 'local_wisa');
        $stats = new sync_stats();
        $sync = new course_sync('sissource_wisa', false, $stats);

        $this->assertTrue($sync->run([sis_fixtures::course(['templatekey' => 'EX651'])]));

        $provision = $DB->get_record('local_wisa_course_provision', [
            'sourcecomponent' => 'sissource_wisa',
            'courseidnumber' => 'WISA-COURSE-001',
        ], '*', MUST_EXIST);
        $this->assertSame(provisioning_repository::STATUS_PENDING, $provision->status);
        $this->assertSame($categoryid, (int)$provision->categoryid);
        $this->assertSame((int)$USER->id, (int)$provision->executionuserid);
        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'WISA-COURSE-001']));
        $this->assertTrue($sync->has_blocking_provisions());
        $this->assertSame(1, $stats->coursecreate);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(\local_wisa\task\provision_course_task::class));
    }

    public function test_pending_keyed_provision_rerun_stays_one_row_and_one_task(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        self::setAdminUser();
        set_config('enable_course_provisioning', '1', 'local_wisa');
        $rows = [sis_fixtures::course(['templatekey' => 'EX651'])];

        $this->assertTrue((new course_sync('sissource_wisa'))->run($rows));
        $stats = new sync_stats();
        $rerun = new course_sync('sissource_wisa', false, $stats);

        $this->assertTrue($rerun->run($rows));

        $this->assertSame(1, $DB->count_records('local_wisa_course_provision', [
            'sourcecomponent' => 'sissource_wisa',
            'courseidnumber' => 'WISA-COURSE-001',
        ]));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(\local_wisa\task\provision_course_task::class));
        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'WISA-COURSE-001']));
        $this->assertTrue($rerun->has_blocking_provisions());
        $this->assertSame(1, $stats->courseskip);
    }

    public function test_enabled_keyed_course_without_current_user_records_failure_without_queueing(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        set_config('enable_course_provisioning', '1', 'local_wisa');
        $originaluser = $USER;
        $USER = new \stdClass();
        $stats = new sync_stats();
        $sync = new course_sync('sissource_wisa', false, $stats);

        try {
            $this->assertFalse($sync->run([sis_fixtures::course(['templatekey' => 'EX651'])]));
        } finally {
            $USER = $originaluser;
        }

        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'WISA-COURSE-001']));
        $this->assertSame(0, $DB->count_records('local_wisa_course_provision'));
        $this->assertTrue($sync->has_blocking_provisions());
        $this->assertSame(1, $stats->coursefail);
    }

    public function test_queue_exception_without_persisted_provision_blocks_course_creation(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        self::setAdminUser();
        set_config('enable_course_provisioning', '1', 'local_wisa');
        $courseidnumber = str_repeat('Q', 101);
        $stats = new sync_stats();
        $rows = [sis_fixtures::course([
            'KLAS_ID' => $courseidnumber,
            'SHORTNAME' => 'QUEUE-LOCKED',
            'FULLNAME' => 'Queue locked course',
            'templatekey' => 'EX651',
        ]), ];
        $sync = new course_sync('sissource_wisa', false, $stats);

        $this->assertFalse($sync->run($rows));

        $this->assertFalse($DB->record_exists('course', ['idnumber' => $courseidnumber]));
        $this->assertSame(0, $DB->count_records('local_wisa_course_provision', [
            'sourcecomponent' => 'sissource_wisa',
            'courseidnumber' => $courseidnumber,
        ]));
        $this->assertTrue($sync->has_blocking_provisions());
        $this->assertSame(1, $stats->coursefail);
    }

    public function test_blocking_provisions_reset_at_the_start_of_each_run(): void {
        $this->resetAfterTest();
        $categoryid = $this->configure_wisa_defaults();
        self::setAdminUser();
        set_config('enable_course_provisioning', '1', 'local_wisa');
        $rows = [sis_fixtures::course(['templatekey' => 'EX651'])];
        $sync = new course_sync('sissource_wisa');

        $this->assertTrue($sync->run($rows));
        $this->assertTrue($sync->has_blocking_provisions());

        $repository = new provisioning_repository();
        $provision = $repository->get_by_source_course('sissource_wisa', 'WISA-COURSE-001');
        $running = $repository->mark_running((int)$provision->id);
        $destination = $this->create_course_with_idnumber('WISA-COURSE-001', [
            'category' => $categoryid,
            'shortname' => 'RESET-BLOCKING',
        ]);
        $repository->record_destination_course((int)$running->id, (int)$destination->id);
        $repository->mark_terminal((int)$running->id, provisioning_repository::STATUS_FALLBACK_READY);

        $this->assertTrue($sync->run($rows));
        $this->assertFalse($sync->has_blocking_provisions());
    }
}
