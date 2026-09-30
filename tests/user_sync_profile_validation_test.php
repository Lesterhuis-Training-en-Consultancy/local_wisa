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
 * User synchronisation profile validation tests.
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
use local_wisa\tests\fake_api_client;
use local_wisa\tests\sis_fixtures;

/**
 * User synchronisation profile validation tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_sync_profile_validation_test extends sync_testcase {
    /**
     * Safe core and known profile fields are persisted when creating a user.
     *
     * @return void
     */
    public function test_user_creation_persists_safe_core_and_known_profile_fields(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'wisa_number',
            'name' => 'WISA number',
        ]);
        $rows = [sis_fixtures::student([
            'city' => 'Antwerp',
            'country' => 'BE',
            'lang' => 'en',
            'description' => 'SIS learner',
            'institution' => 'WISA Academy',
            'department' => 'Technology',
            'phone1' => '0123456789',
            'phone2' => '0987654321',
            'address' => 'Main Street 1',
            'profile_field_wisa_number' => 'WISA-123',
        ]), ];

        $this->assertTrue((new user_sync())->run($rows));

        $user = $DB->get_record('user', ['idnumber' => 'STUDENT-001', 'deleted' => 0], '*', MUST_EXIST);
        $this->assertSame('student001', $user->username);
        $this->assertSame('Antwerp', $user->city);
        $this->assertSame('BE', $user->country);
        $this->assertSame('en', $user->lang);
        $this->assertSame('SIS learner', $user->description);
        $this->assertSame('WISA Academy', $user->institution);
        $this->assertSame('Technology', $user->department);
        $this->assertSame('0123456789', $user->phone1);
        $this->assertSame('0987654321', $user->phone2);
        $this->assertSame('Main Street 1', $user->address);
        $this->assertSame('WISA-123', profile_user_record($user->id)->wisa_number);
    }

    /**
     * Safe core and known profile fields are persisted when updating a user.
     *
     * @return void
     */
    public function test_user_update_persists_safe_core_and_known_profile_fields(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'wisa_number',
            'name' => 'WISA number',
        ]);
        $existing = $this->create_user_with_idnumber('STUDENT-001', [
            'city' => 'Old city',
            'country' => 'NL',
            'lang' => 'en',
            'description' => 'Old description',
            'institution' => 'Old institution',
            'department' => 'Old department',
            'phone1' => '0000000000',
            'phone2' => '1111111111',
            'address' => 'Old address',
            'profile_field_wisa_number' => 'OLD-123',
        ]);
        $DB->set_field('user', 'lang', 'zz', ['id' => $existing->id]);
        $rows = [sis_fixtures::student([
            'USERNAME' => 'ignorednewusername',
            'city' => 'Antwerp',
            'country' => 'BE',
            'lang' => 'en',
            'description' => 'SIS learner',
            'institution' => 'WISA Academy',
            'department' => 'Technology',
            'phone1' => '0123456789',
            'phone2' => '0987654321',
            'address' => 'Main Street 1',
            'profile_field_wisa_number' => 'WISA-123',
        ]), ];

        $this->assertTrue((new user_sync())->run($rows));

        $user = $DB->get_record('user', ['id' => $existing->id], '*', MUST_EXIST);
        $this->assertSame('student-001', $user->username);
        $this->assertSame('Antwerp', $user->city);
        $this->assertSame('BE', $user->country);
        $this->assertSame('en', $user->lang);
        $this->assertSame('SIS learner', $user->description);
        $this->assertSame('WISA Academy', $user->institution);
        $this->assertSame('Technology', $user->department);
        $this->assertSame('0123456789', $user->phone1);
        $this->assertSame('0987654321', $user->phone2);
        $this->assertSame('Main Street 1', $user->address);
        $this->assertSame('WISA-123', profile_user_record($user->id)->wisa_number);
    }

    /**
     * Existing users receive supplied profile fields even without core changes.
     *
     * @return void
     */
    public function test_profile_only_update_is_saved_for_existing_user(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'wisa_number',
            'name' => 'WISA number',
        ]);
        $existing = $this->create_user_with_idnumber('STUDENT-001', [
            'username' => 'student001',
            'firstname' => 'Sam',
            'lastname' => 'Student',
            'email' => 'sam.student@example.org',
        ]);
        $stats = new sync_stats();
        $rows = [sis_fixtures::student([
            'profile_field_wisa_number' => 'WISA-123',
        ]), ];

        $this->assertTrue((new user_sync(false, $stats))->run($rows));

        $this->assertSame('WISA-123', profile_user_record($existing->id)->wisa_number);
        $this->assertSame(1, $stats->userupdate);
    }

    /**
     * Unknown profile fields do not prevent known profile fields from saving.
     *
     * @return void
     */
    public function test_unknown_profile_field_warns_and_skips_only_that_field(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'wisa_number',
            'name' => 'WISA number',
        ]);
        $rows = [sis_fixtures::student([
            'profile_field_wisa_number' => 'WISA-123',
            'profile_field_unknown_wisa_value' => 'ignored',
        ]), ];

        $this->assertTrue((new user_sync())->run($rows));

        $user = $DB->get_record('user', ['idnumber' => 'STUDENT-001', 'deleted' => 0], '*', MUST_EXIST);
        $this->assertSame('WISA-123', profile_user_record($user->id)->wisa_number);
        $logs = $this->get_logs_by_status('warn');
        $this->assertStringContainsString('Unknown profile field shortname', reset($logs)->message);
    }

    /**
     * A profile API error fails the feed so the sync manager retains its watermark.
     *
     * @return void
     */
    public function test_profile_save_failure_marks_user_feed_unsuccessful(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'broken_wisa_field',
            'name' => 'Broken WISA field',
        ]);
        foreach (['courses:courses', 'teacher_accounts:users', 'enrolments:enrolments', 'unenrolments:unenrolments'] as $tuple) {
            [$stream, $phase] = explode(':', $tuple);
            set_config('stream_' . $stream . '_' . $phase . '_enabled', 0, 'sissource_wisa');
        }
        set_config('stream_student_accounts_users_watermark', 1000, 'sissource_wisa');
        $api = new fake_api_client(['student_accounts' => [sis_fixtures::student([
            'profile_field_broken_wisa_field' => [],
        ]), ], ]);

        $this->assertFalse((new sync_manager($api))->run_full_sync());

        $this->assertSame(1000, (int)get_config('sissource_wisa', 'stream_student_accounts_users_watermark'));
    }
}
