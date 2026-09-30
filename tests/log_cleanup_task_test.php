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
 * Log cleanup task tests for local_wisa.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/sync_testcase.php');


/**
 * Log cleanup task tests for local_wisa.
 *
 * @group local_wisa
 * @covers     \local_wisa\task\log_cleanup_task
 */
final class log_cleanup_task_test extends sync_testcase {
    public function test_log_cleanup_deletes_only_rows_older_than_retention(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        set_config('log_retention_days', 30, 'local_wisa');
        $old = (object)[
            'timecreated' => time() - (31 * DAYSECS),
            'action' => 'old',
            'objecttype' => 'system',
            'objectid' => 'old',
            'status' => 'info',
            'message' => 'old row',
        ];
        $recent = (object)[
            'timecreated' => time() - DAYSECS,
            'action' => 'recent',
            'objecttype' => 'system',
            'objectid' => 'recent',
            'status' => 'info',
            'message' => 'recent row',
        ];
        $DB->insert_record('local_wisa_log', $old);
        $DB->insert_record('local_wisa_log', $recent);

        $this->expectOutputRegex('/deleted 1 log records older than 30 days/');
        (new \local_wisa\task\log_cleanup_task())->execute();

        $this->assertFalse($DB->record_exists('local_wisa_log', ['objectid' => 'old']));
        $this->assertTrue($DB->record_exists('local_wisa_log', ['objectid' => 'recent']));
    }
}
