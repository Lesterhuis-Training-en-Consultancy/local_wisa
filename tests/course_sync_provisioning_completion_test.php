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
 * Course synchronisation provisioning completion tests.
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
 * Course synchronisation provisioning completion tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_sync_provisioning_completion_test extends course_sync_test_case {
    /**
     * Verify a completed provision for another destination prevents an existing course update.
     *
     * @dataProvider completed_provision_status_provider
     * @param string $status Existing completed provision status.
     * @return void
     */
    public function test_mismatched_completed_provision_blocks_existing_course_update(string $status): void {
        global $DB;

        $this->resetAfterTest();
        $categoryid = $this->configure_wisa_defaults();
        self::setAdminUser();
        $course = $this->create_course_with_idnumber('MISMATCHED-DESTINATION', [
            'category' => $categoryid,
            'fullname' => 'Original mismatched fullname',
            'shortname' => 'MISMATCHED-ORIGINAL',
        ]);
        $destination = self::getDataGenerator()->create_course([
            'category' => $categoryid,
            'fullname' => 'Other provision destination',
            'shortname' => 'OTHER-PROVISION-DESTINATION',
        ]);
        $queued = $this->queue_provision($categoryid, 'MISMATCHED-DESTINATION');
        $repository = new provisioning_repository();
        $running = $repository->mark_running((int)$queued->id);
        $repository->record_destination_course((int)$running->id, (int)$destination->id);
        $repository->mark_terminal((int)$running->id, $status);
        $stats = new sync_stats();
        $rows = [sis_fixtures::course([
            'KLAS_ID' => 'MISMATCHED-DESTINATION',
            'SHORTNAME' => 'MISMATCHED-UPDATED',
            'FULLNAME' => 'Updated mismatched fullname',
        ]), ];
        $sync = new course_sync('sissource_wisa', false, $stats);

        $this->assertFalse($sync->run($rows));

        $unchanged = $DB->get_record('course', ['id' => $course->id], '*', MUST_EXIST);
        $this->assertSame('Original mismatched fullname', $unchanged->fullname);
        $this->assertSame('MISMATCHED-ORIGINAL', $unchanged->shortname);
        $this->assertTrue($sync->has_blocking_provisions());
        $this->assertSame(1, $stats->coursefail);
    }

    /**
     * Return successful terminal provision states.
     *
     * @return array Provision status test cases.
     */
    public static function completed_provision_status_provider(): array {
        return parent::completed_provision_status_provider();
    }

    public function test_completed_provision_with_destination_skips_new_course_creation(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $categoryid = $this->configure_wisa_defaults();
        self::setAdminUser();
        set_config('enable_course_provisioning', '1', 'local_wisa');
        $destination = self::getDataGenerator()->create_course([
            'category' => $categoryid,
            'fullname' => 'Provisioned destination',
            'shortname' => 'PROVISIONED-DESTINATION',
        ]);
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
        $running = $repository->mark_running((int)$queued->id);
        $repository->record_destination_course((int)$running->id, (int)$destination->id);
        $repository->mark_terminal((int)$running->id, provisioning_repository::STATUS_FALLBACK_READY);
        $stats = new sync_stats();
        $sync = new course_sync('sissource_wisa', false, $stats);

        $this->assertTrue($sync->run([sis_fixtures::course(['templatekey' => 'EX651'])]));

        $this->assertTrue($DB->record_exists('course', ['id' => $destination->id]));
        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'WISA-COURSE-001']));
        $this->assertFalse($sync->has_blocking_provisions());
        $this->assertSame(1, $stats->courseskip);
    }

    public function test_completed_provision_with_missing_destination_blocks_new_course_creation(): void {
        global $DB;

        $this->resetAfterTest();
        $categoryid = $this->configure_wisa_defaults();
        self::setAdminUser();
        set_config('enable_course_provisioning', '1', 'local_wisa');
        $destination = self::getDataGenerator()->create_course([
            'category' => $categoryid,
            'fullname' => 'Missing provisioned destination',
            'shortname' => 'MISSING-PROVISIONED-DESTINATION',
        ]);
        $queued = $this->queue_provision($categoryid, 'MISSING-DESTINATION');
        $repository = new provisioning_repository();
        $running = $repository->mark_running((int)$queued->id);
        $repository->record_destination_course((int)$running->id, (int)$destination->id);
        $repository->mark_terminal((int)$running->id, provisioning_repository::STATUS_READY);
        $DB->delete_records('course', ['id' => $destination->id]);
        $stats = new sync_stats();
        $rows = [sis_fixtures::course([
            'KLAS_ID' => 'MISSING-DESTINATION',
            'SHORTNAME' => 'MISSING-DESTINATION',
            'FULLNAME' => 'Missing destination course',
            'templatekey' => 'EX651',
        ]), ];
        $sync = new course_sync('sissource_wisa', false, $stats);

        $this->assertFalse($sync->run($rows));

        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'MISSING-DESTINATION']));
        $this->assertTrue($sync->has_blocking_provisions());
        $this->assertSame(1, $stats->coursefail);
        $this->assertNotEmpty($this->get_logs_by_status('fail'));
    }
}
