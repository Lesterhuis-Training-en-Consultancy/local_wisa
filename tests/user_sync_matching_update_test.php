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
 * User synchronisation matching and update tests.
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
 * User synchronisation matching and update tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_sync_matching_update_test extends sync_testcase {
    public function test_user_matching_uses_idnumber_not_email(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $existing = $this->create_user_with_idnumber('EXISTING-ID', [
            'username' => 'existinguser',
            'email' => 'shared@example.org',
        ]);
        $rows = [sis_fixtures::student([
            'IDNUMBER' => 'NEW-ID',
            'USERNAME' => 'newuser',
            'EMAIL' => 'shared@example.org',
        ]), ];

        $this->assertTrue((new user_sync())->run($rows));

        $this->assertTrue($DB->record_exists('user', ['idnumber' => 'NEW-ID', 'deleted' => 0]));
        $existingafter = $DB->get_record('user', ['id' => $existing->id], '*', MUST_EXIST);
        $this->assertSame('EXISTING-ID', $existingafter->idnumber);
        $this->assertSame('existinguser', $existingafter->username);
        $this->assertCount(2, $DB->get_records('user', ['email' => 'shared@example.org', 'deleted' => 0]));
        $this->assertNotEquals($existing->id, $DB->get_field('user', 'id', ['idnumber' => 'NEW-ID', 'deleted' => 0]));
    }

    public function test_username_collision_does_not_merge_different_people(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->create_user_with_idnumber('EXISTING-ID', ['username' => 'collision']);
        $rows = [sis_fixtures::student([
            'IDNUMBER' => 'OTHER-ID',
            'USERNAME' => 'collision',
            'EMAIL' => 'other@example.org',
        ]), ];
        $stats = new sync_stats();

        $this->assertFalse((new user_sync(false, $stats))->run($rows));

        $this->assertFalse($DB->record_exists('user', ['idnumber' => 'OTHER-ID', 'deleted' => 0]));
        $this->assertSame(1, $stats->userfail);
        $logs = $DB->get_records('local_wisa_log', ['status' => 'warn'], 'id ASC');
        $this->assertStringContainsString('already exists with a different idnumber', reset($logs)->message);
    }

    public function test_existing_user_is_updated_by_idnumber_without_empty_overwrites(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $existing = $this->create_user_with_idnumber('STUDENT-001', [
            'username' => 'oldusername',
            'firstname' => 'Old',
            'lastname' => 'Name',
            'email' => 'kept@example.org',
        ]);
        $rows = [sis_fixtures::student([
            'IDNUMBER' => 'STUDENT-001',
            'USERNAME' => 'ignorednewusername',
            'FIRSTNAME' => 'New',
            'LASTNAME' => '',
            'EMAIL' => '',
        ]), ];
        $stats = new sync_stats();

        $this->assertTrue((new user_sync(false, $stats))->run($rows));

        $user = $DB->get_record('user', ['id' => $existing->id], '*', MUST_EXIST);
        $this->assertSame('oldusername', $user->username);
        $this->assertSame('New', $user->firstname);
        $this->assertSame('Name', $user->lastname);
        $this->assertSame('kept@example.org', $user->email);
        $this->assertSame(1, $stats->userupdate);
    }
}
