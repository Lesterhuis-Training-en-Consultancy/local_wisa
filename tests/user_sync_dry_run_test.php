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
 * User synchronisation dry-run tests.
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
 * User synchronisation dry-run tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_sync_dry_run_test extends sync_testcase {
    /**
     * Dry runs leave both core and custom profile data unchanged.
     *
     * @return void
     */
    public function test_dry_run_does_not_write_core_or_profile_data(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'wisa_number',
            'name' => 'WISA number',
        ]);
        $existing = $this->create_user_with_idnumber('STUDENT-001', [
            'city' => 'Original city',
            'profile_field_wisa_number' => 'OLD-123',
        ]);
        $rows = [
            sis_fixtures::student([
                'IDNUMBER' => 'NEW-STUDENT',
                'USERNAME' => 'newstudent',
                'city' => 'New city',
                'profile_field_wisa_number' => 'NEW-123',
            ]),
            sis_fixtures::student([
                'city' => 'Changed city',
                'profile_field_wisa_number' => 'CHANGED-123',
            ]),
        ];

        $this->assertTrue((new user_sync(true))->run($rows));

        $this->assertFalse($DB->record_exists('user', ['idnumber' => 'NEW-STUDENT', 'deleted' => 0]));
        $this->assertSame('Original city', $DB->get_field('user', 'city', ['id' => $existing->id]));
        $this->assertSame('OLD-123', profile_user_record($existing->id)->wisa_number);
    }

    public function test_dry_run_does_not_create_or_update_users(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $existing = $this->create_user_with_idnumber('STUDENT-001', ['firstname' => 'Original']);
        $rows = [
            sis_fixtures::student(['IDNUMBER' => 'NEW-STUDENT', 'USERNAME' => 'newstudent']),
            sis_fixtures::student(['IDNUMBER' => 'STUDENT-001', 'USERNAME' => 'student001', 'FIRSTNAME' => 'Changed']),
        ];

        $this->assertTrue((new user_sync(true))->run($rows));

        $this->assertFalse($DB->record_exists('user', ['idnumber' => 'NEW-STUDENT', 'deleted' => 0]));
        $this->assertSame('Original', $DB->get_field('user', 'firstname', ['id' => $existing->id]));
        $this->assertNotEmpty($this->get_logs_by_status('dryrun'));
    }
}
