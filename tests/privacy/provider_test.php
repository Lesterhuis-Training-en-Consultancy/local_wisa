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
 * Privacy provider tests for local_wisa.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\privacy;


use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;

/**
 * Tests the privacy provider contract for SIS sync log rows.
 *
 * @group local_wisa
 * @covers \local_wisa\privacy\provider
 */
final class provider_test extends provider_testcase {
    /**
     * Insert one local_wisa log row.
     *
     * @param string $objectid Object id stored in the log.
     * @param string $message Log message.
     * @return int Inserted row id.
     */
    private function add_log(string $objectid, string $message = 'Sync touched a user'): int {
        global $DB;

        return $DB->insert_record('local_wisa_log', (object)[
            'timecreated' => time(),
            'action' => 'sync_user',
            'objecttype' => 'user',
            'objectid' => $objectid,
            'status' => 'info',
            'message' => $message,
        ]);
    }

    /**
     * Insert one provisioning row associated with an execution user.
     *
     * @param int $executionuserid Execution user ID.
     * @param string $suffix Unique fixture suffix.
     * @return int Inserted row ID.
     */
    private function add_provision(int $executionuserid, string $suffix): int {
        global $DB;

        return $DB->insert_record('local_wisa_course_provision', (object)[
            'sourcecomponent' => 'sissource_wisa',
            'courseidnumber' => 'PRIVACY-' . $suffix,
            'status' => 'failed',
            'jobid' => str_pad($suffix, 32, '0'),
            'tempshortname' => 'privacy-temp-' . $suffix,
            'desiredshortname' => 'privacy-' . $suffix,
            'desiredfullname' => 'Privacy fixture ' . $suffix,
            'categoryid' => 1,
            'startdate' => 0,
            'enddate' => 0,
            'executionuserid' => $executionuserid,
            'attempts' => 0,
            'tempprecallabsent' => 0,
            'followupqueued' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Metadata describes the parent plugin log table.
     */
    public function test_metadata_describes_log_table(): void {
        $collection = provider::get_metadata(new collection('local_wisa'));
        $items = $collection->get_collection();

        $tables = array_filter($items, static fn($item) => $item instanceof database_table);
        $table = reset($tables);

        $this->assertInstanceOf(database_table::class, $table);
        $this->assertSame('local_wisa_log', $table->get_name());
        $this->assertArrayHasKey('objectid', $table->get_privacy_fields());
        $this->assertArrayHasKey('message', $table->get_privacy_fields());
        $provisiontable = current(array_filter($tables, static function (database_table $item): bool {
            return $item->get_name() === 'local_wisa_course_provision';
        }));
        $this->assertInstanceOf(database_table::class, $provisiontable);
        $this->assertArrayHasKey('executionuserid', $provisiontable->get_privacy_fields());
    }

    /**
     * The provider finds system context only for users with linked log rows.
     */
    public function test_contexts_are_returned_only_when_user_has_sync_logs(): void {
        $this->resetAfterTest();
        $user = self::getDataGenerator()->create_user([
            'username' => 'student001',
            'idnumber' => 'STUDENT-001',
        ]);
        $other = self::getDataGenerator()->create_user([
            'username' => 'student002',
            'idnumber' => 'STUDENT-002',
        ]);
        $this->add_log('STUDENT-001');

        $contextlist = provider::get_contexts_for_userid($user->id);
        $othercontextlist = provider::get_contexts_for_userid($other->id);

        $this->assertSame([\context_system::instance()->id], array_map('intval', $contextlist->get_contextids()));
        $this->assertSame([], $othercontextlist->get_contextids());
    }

    /**
     * Provisioning execution ownership exposes the system privacy context.
     */
    public function test_contexts_include_users_with_provisioning_execution_rows(): void {
        $this->resetAfterTest();
        $user = self::getDataGenerator()->create_user();
        $this->add_provision((int)$user->id, 'context');

        $contextlist = provider::get_contexts_for_userid((int)$user->id);

        $this->assertSame([\context_system::instance()->id], array_map('intval', $contextlist->get_contextids()));
    }

    /**
     * The user list provider returns only affected users for the system context.
     */
    public function test_users_in_context_are_discovered_from_user_identifiers(): void {
        $this->resetAfterTest();
        $first = self::getDataGenerator()->create_user(['username' => 'firstuser', 'idnumber' => 'FIRST-ID']);
        $second = self::getDataGenerator()->create_user(['username' => 'seconduser', 'idnumber' => 'SECOND-ID']);
        $third = self::getDataGenerator()->create_user(['username' => 'thirduser', 'idnumber' => 'THIRD-ID']);
        $course = self::getDataGenerator()->create_course();
        $this->add_log('FIRST-ID');
        $this->add_log('seconduser');
        $this->add_log('system-only');

        $userlist = new userlist(\context_system::instance(), 'local_wisa');
        provider::get_users_in_context($userlist);

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $userlist->get_userids());

        $courseuserlist = new userlist(\context_course::instance($course->id), 'local_wisa');
        provider::get_users_in_context($courseuserlist);
        $this->assertSame([], $courseuserlist->get_userids());
        $this->assertNotContains($third->id, $userlist->get_userids());
    }

    /**
     * User discovery includes provisioning execution owners.
     */
    public function test_users_in_context_include_provisioning_execution_owners(): void {
        $this->resetAfterTest();
        $user = self::getDataGenerator()->create_user();
        $this->add_provision((int)$user->id, 'userlist');
        $userlist = new userlist(\context_system::instance(), 'local_wisa');

        provider::get_users_in_context($userlist);

        $this->assertContains((int)$user->id, array_map('intval', $userlist->get_userids()));
    }

    /**
     * Exports only the approved user's log rows.
     */
    public function test_export_user_data_exports_matching_logs_only(): void {
        $this->resetAfterTest();
        $user = self::getDataGenerator()->create_user(['username' => 'exportuser', 'idnumber' => 'EXPORT-ID']);
        $other = self::getDataGenerator()->create_user(['username' => 'otheruser', 'idnumber' => 'OTHER-ID']);
        $this->add_log('EXPORT-ID', 'idnumber match');
        $this->add_log('exportuser', 'username match');
        $this->add_log($other->idnumber, 'other user');

        $contextlist = new approved_contextlist($user, 'local_wisa', [\context_system::instance()->id]);
        provider::export_user_data($contextlist);

        $writer = writer::with_context(\context_system::instance());
        $data = $writer->get_data([get_string('pluginname', 'local_wisa')]);

        $this->assertCount(2, $data->logs);
        $messages = array_map(static fn($row) => $row->message, $data->logs);
        $this->assertContains('idnumber match', $messages);
        $this->assertContains('username match', $messages);
        $this->assertNotContains('other user', $messages);
    }

    /**
     * Export includes provisioning rows owned by the approved execution user.
     */
    public function test_export_user_data_includes_provisioning_execution_rows(): void {
        $this->resetAfterTest();
        $user = self::getDataGenerator()->create_user();
        $other = self::getDataGenerator()->create_user();
        $this->add_provision((int)$user->id, 'export');
        $this->add_provision((int)$other->id, 'other');

        provider::export_user_data(new approved_contextlist($user, 'local_wisa', [\context_system::instance()->id]));

        $data = writer::with_context(\context_system::instance())->get_data([get_string('pluginname', 'local_wisa')]);
        $this->assertCount(1, $data->provisioning);
        $this->assertSame('PRIVACY-export', $data->provisioning[0]->courseidnumber);
    }

    /**
     * Deleting one user respects the approved context list.
     */
    public function test_delete_data_for_user_respects_approved_system_context(): void {
        global $DB;

        $this->resetAfterTest();
        $user = self::getDataGenerator()->create_user(['username' => 'deleteuser', 'idnumber' => 'DELETE-ID']);
        $course = self::getDataGenerator()->create_course();
        $this->add_log('DELETE-ID', 'delete me');

        $wrongcontext = new approved_contextlist($user, 'local_wisa', [\context_course::instance($course->id)->id]);
        provider::delete_data_for_user($wrongcontext);
        $this->assertTrue($DB->record_exists('local_wisa_log', ['objectid' => 'DELETE-ID']));

        $systemcontext = new approved_contextlist($user, 'local_wisa', [\context_system::instance()->id]);
        provider::delete_data_for_user($systemcontext);
        $this->assertFalse($DB->record_exists('local_wisa_log', ['objectid' => 'DELETE-ID']));
    }

    /**
     * Deleting one user anonymizes only their provisioning ownership.
     */
    public function test_delete_data_for_user_anonymizes_provisioning_execution_owner(): void {
        global $DB;

        $this->resetAfterTest();
        $user = self::getDataGenerator()->create_user();
        $rowid = $this->add_provision((int)$user->id, 'delete');

        provider::delete_data_for_user(new approved_contextlist(
            $user,
            'local_wisa',
            [\context_system::instance()->id]
        ));

        $row = $DB->get_record('local_wisa_course_provision', ['id' => $rowid], '*', MUST_EXIST);
        $this->assertNull($row->executionuserid);
    }

    /**
     * Bulk delete removes approved users only.
     */
    public function test_delete_data_for_users_deletes_only_approved_user_ids(): void {
        global $DB;

        $this->resetAfterTest();
        $first = self::getDataGenerator()->create_user(['username' => 'bulkfirst', 'idnumber' => 'BULK-FIRST']);
        $second = self::getDataGenerator()->create_user(['username' => 'bulksecond', 'idnumber' => 'BULK-SECOND']);
        $this->add_log('BULK-FIRST');
        $this->add_log('BULK-SECOND');

        $userlist = new approved_userlist(\context_system::instance(), 'local_wisa', [$first->id]);
        provider::delete_data_for_users($userlist);

        $this->assertFalse($DB->record_exists('local_wisa_log', ['objectid' => 'BULK-FIRST']));
        $this->assertTrue($DB->record_exists('local_wisa_log', ['objectid' => 'BULK-SECOND']));
        $this->assertNotFalse($second);
    }

    /**
     * Bulk deletion anonymizes only approved provisioning execution owners.
     */
    public function test_delete_data_for_users_anonymizes_only_approved_provisioning_owners(): void {
        global $DB;

        $this->resetAfterTest();
        $first = self::getDataGenerator()->create_user();
        $second = self::getDataGenerator()->create_user();
        $firstrow = $this->add_provision((int)$first->id, 'bulk-first');
        $secondrow = $this->add_provision((int)$second->id, 'bulk-second');

        provider::delete_data_for_users(new approved_userlist(
            \context_system::instance(),
            'local_wisa',
            [(int)$first->id]
        ));

        $this->assertNull($DB->get_field('local_wisa_course_provision', 'executionuserid', ['id' => $firstrow]));
        $this->assertSame(
            (string)$second->id,
            $DB->get_field('local_wisa_course_provision', 'executionuserid', ['id' => $secondrow])
        );
    }

    /**
     * Context-wide delete removes personal log rows but preserves operational system rows.
     */
    public function test_delete_data_for_all_users_in_context_removes_user_logs_only(): void {
        global $DB;

        $this->resetAfterTest();
        self::getDataGenerator()->create_user(['username' => 'alluser', 'idnumber' => 'ALL-ID']);
        $this->add_log('ALL-ID', 'personal row');
        $this->add_log('system-only', 'operational row');

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertFalse($DB->record_exists('local_wisa_log', ['objectid' => 'ALL-ID']));
        $this->assertTrue($DB->record_exists('local_wisa_log', ['objectid' => 'system-only']));
    }

    /**
     * Context-wide deletion anonymizes every provisioning execution owner.
     */
    public function test_delete_all_users_anonymizes_provisioning_execution_owners(): void {
        global $DB;

        $this->resetAfterTest();
        $user = self::getDataGenerator()->create_user();
        $rowid = $this->add_provision((int)$user->id, 'delete-all');

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertNull($DB->get_field('local_wisa_course_provision', 'executionuserid', ['id' => $rowid]));
    }
}
