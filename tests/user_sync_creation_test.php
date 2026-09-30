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
 * User synchronisation creation tests.
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
 * User synchronisation creation tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_sync_creation_test extends sync_testcase {
    public function test_student_user_is_created_from_wisa_row(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $stats = new sync_stats();
        $stats->userfail = 1;

        $this->assertTrue((new user_sync(false, $stats))->run([sis_fixtures::student()]));

        $user = $DB->get_record('user', ['idnumber' => 'STUDENT-001', 'deleted' => 0], '*', MUST_EXIST);
        $this->assertSame('student001', $user->username);
        $this->assertSame('Sam', $user->firstname);
        $this->assertSame('Student', $user->lastname);
        $this->assertSame('sam.student@example.org', $user->email);
        $this->assertSame('manual', $user->auth);
        $this->assertNotEmpty($user->password);
        $this->assertEquals(1, get_user_preferences('auth_forcepasswordchange', null, $user->id));
        $this->assertSame(1, $stats->usercreate);
        $this->assertSame(1, $stats->userfail);
    }

    public function test_teacher_user_is_created_from_wisa_row(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->assertTrue((new user_sync())->run([sis_fixtures::teacher()]));

        $user = $DB->get_record('user', ['idnumber' => 'TEACHER-001', 'deleted' => 0], '*', MUST_EXIST);
        $this->assertSame('teacher001', $user->username);
        $this->assertSame('Tina', $user->firstname);
        $this->assertSame('Teacher', $user->lastname);
        $this->assertSame('tina.teacher@example.org', $user->email);
    }

    /**
     * A supplied initial password is hashed and forces a first-login change by default.
     *
     * @return void
     */
    public function test_supplied_password_is_hashed_and_forced_by_default(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->enable_password_policy_that_rejects_source_password();
        $password = '3121-08-18';

        $this->assertTrue((new user_sync())->run([sis_fixtures::student(['password' => $password])]));

        $user = $DB->get_record('user', ['idnumber' => 'STUDENT-001', 'deleted' => 0], '*', MUST_EXIST);
        $this->assertNotSame($password, $user->password);
        $this->assertTrue(validate_internal_user_password($user, $password));
        $this->assertEquals(1, get_user_preferences('auth_forcepasswordchange', null, $user->id));
    }

    /**
     * Disabling the global policy leaves the supplied initial password usable without a forced change.
     *
     * @return void
     */
    public function test_supplied_password_remains_login_password_when_force_change_disabled(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->enable_password_policy_that_rejects_source_password();
        set_config('force_password_change', 0, 'local_wisa');
        $password = '3121-08-18';

        $this->assertTrue((new user_sync())->run([sis_fixtures::student(['password' => $password])]));

        $user = $DB->get_record('user', ['idnumber' => 'STUDENT-001', 'deleted' => 0], '*', MUST_EXIST);
        $this->assertTrue(validate_internal_user_password($user, $password));
        $this->assertNull(get_user_preferences('auth_forcepasswordchange', null, $user->id));
    }

    /**
     * Routine sync never changes an existing user's password or force-change preference.
     *
     * @return void
     */
    public function test_existing_user_password_and_preference_are_unchanged(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        set_config('force_password_change', 0, 'local_wisa');
        $oldpassword = 'Existing-Password-2026!';
        $newpassword = 'Replacement-Password-2026!';
        $existing = $this->create_user_with_idnumber('STUDENT-001', [
            'username' => 'student001',
            'password' => $oldpassword,
        ]);
        set_user_preference('auth_forcepasswordchange', 1, $existing->id);

        $this->assertTrue((new user_sync())->run([sis_fixtures::student([
            'firstname' => 'Updated',
            'password' => $newpassword,
        ])]));

        $user = $DB->get_record('user', ['id' => $existing->id], '*', MUST_EXIST);
        $this->assertSame('Updated', $user->firstname);
        $this->assertTrue(validate_internal_user_password($user, $oldpassword));
        $this->assertFalse(validate_internal_user_password($user, $newpassword));
        $this->assertEquals(1, get_user_preferences('auth_forcepasswordchange', null, $user->id));
    }

    /**
     * Enable a policy that the representative AthenaSoft initial password does not satisfy.
     *
     * @return void
     */
    private function enable_password_policy_that_rejects_source_password(): void {
        global $CFG;

        $CFG->passwordpolicy = 1;
        $CFG->minpasswordlength = 12;
        $CFG->minpassworddigits = 1;
        $CFG->minpasswordlower = 1;
        $CFG->minpasswordupper = 1;
        $CFG->minpasswordnonalphanum = 1;
    }

    public function test_accented_email_local_part_is_transliterated(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $rows = [sis_fixtures::student([
            'IDNUMBER' => 'ACCENT-001',
            'USERNAME' => 'Accent001',
            'FIRSTNAME' => 'Léa',
            'LASTNAME' => 'Pacquée',
            'EMAIL' => 'LPacquée@example.org',
        ]), ];

        $this->assertTrue((new user_sync())->run($rows));

        $user = $DB->get_record('user', ['idnumber' => 'ACCENT-001', 'deleted' => 0], '*', MUST_EXIST);
        $this->assertSame('lpacquee@example.org', $user->email);
    }
}
