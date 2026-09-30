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
 * User synchronisation validation tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/sync_testcase.php');

use local_wisa\sync\user_sync;
use local_wisa\tests\sis_fixtures;

/**
 * User synchronisation validation tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_sync_validation_test extends sync_testcase {
    public function test_user_rows_missing_identity_are_rejected_without_creating_accounts(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $rows = [
            sis_fixtures::student(['IDNUMBER' => '', 'USERNAME' => '']),
            sis_fixtures::student(['IDNUMBER' => '', 'USERNAME' => '!!!']),
        ];
        $stats = new sync_stats();

        $this->assertFalse((new user_sync(false, $stats))->run($rows));

        $this->assertFalse($DB->record_exists('user', ['email' => 'sam.student@example.org', 'deleted' => 0]));
        $this->assertSame(2, $stats->userfail);
        $this->assertCount(2, $DB->get_records('local_wisa_log', ['action' => 'sync_user', 'status' => 'fail']));
    }

    public function test_invalid_email_is_ignored_without_blocking_user_creation(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $rows = [sis_fixtures::student([
            'IDNUMBER' => 'BADMAIL-001',
            'USERNAME' => 'badmail001',
            'EMAIL' => 'not an email',
        ]), ];

        $this->assertTrue((new user_sync())->run($rows));

        $user = $DB->get_record('user', ['idnumber' => 'BADMAIL-001', 'deleted' => 0], '*', MUST_EXIST);
        $this->assertSame('', $user->email);
        $this->assertTrue($DB->record_exists('local_wisa_log', ['action' => 'sync_user', 'status' => 'warn']));
    }
}
