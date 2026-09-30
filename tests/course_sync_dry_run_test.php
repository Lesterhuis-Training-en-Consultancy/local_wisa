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
 * Course synchronisation dry-run tests.
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
 * Course synchronisation dry-run tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_sync_dry_run_test extends course_sync_test_case {
    public function test_enabled_keyed_course_dry_run_logs_intent_without_writes(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        self::setAdminUser();
        set_config('enable_course_provisioning', '1', 'local_wisa');
        $sync = new course_sync('sissource_wisa', true);

        $this->assertTrue($sync->run([sis_fixtures::course(['templatekey' => 'EX651'])]));

        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'WISA-COURSE-001']));
        $this->assertSame(0, $DB->count_records('local_wisa_course_provision'));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_wisa\task\provision_course_task::class));
        $this->assertFalse($sync->has_blocking_provisions());
        $logs = $this->get_logs_by_status('dryrun');
        $this->assertNotEmpty($logs);
        $this->assertStringContainsString('provision', \core_text::strtolower(reset($logs)->message));
    }

    public function test_dry_run_does_not_create_or_update_courses(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $existing = $this->create_course_with_idnumber('EXISTING-COURSE', ['fullname' => 'Original']);
        $rows = [
            sis_fixtures::course(['KLAS_ID' => 'NEW-COURSE', 'SHORTNAME' => 'NEWC', 'FULLNAME' => 'New course']),
            sis_fixtures::course(['KLAS_ID' => 'EXISTING-COURSE', 'SHORTNAME' => 'EXISTING', 'FULLNAME' => 'Changed']),
        ];

        $this->assertTrue((new course_sync('sissource_wisa', true))->run($rows));

        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'NEW-COURSE']));
        $this->assertSame('Original', $DB->get_field('course', 'fullname', ['id' => $existing->id]));
        $this->assertNotEmpty($this->get_logs_by_status('dryrun'));
    }
}
