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
 * Privacy provider for local_wisa.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\privacy;


use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Describes, exports and deletes user-related SIS sync data.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe stored metadata for parent-owned sync logs.
     *
     * @param collection $collection Metadata collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_wisa_log', [
            'timecreated' => 'privacy:metadata:local_wisa_log:timecreated',
            'action' => 'privacy:metadata:local_wisa_log:action',
            'objecttype' => 'privacy:metadata:local_wisa_log:objecttype',
            'objectid' => 'privacy:metadata:local_wisa_log:objectid',
            'status' => 'privacy:metadata:local_wisa_log:status',
            'message' => 'privacy:metadata:local_wisa_log:message',
        ], 'privacy:metadata:local_wisa_log');
        $collection->add_database_table('local_wisa_course_provision', [
            'executionuserid' => 'privacy:metadata:local_wisa_course_provision:executionuserid',
        ], 'privacy:metadata:local_wisa_course_provision');

        return $collection;
    }

    /**
     * Return contexts containing personal data for a user.
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        $user = $DB->get_record('user', ['id' => $userid], 'id, username, idnumber');
        if ($user && (self::user_has_logs($user) || self::user_has_provisioning((int)$user->id))) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Add users with sync log entries in the supplied context.
     *
     * @param userlist $userlist User list.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }
        $sql = "SELECT u.id
                FROM {user} u
                JOIN {local_wisa_log} l
                  ON l.objectid = u.username OR (u.idnumber <> '' AND l.objectid = u.idnumber)
                WHERE u.deleted = 0";
        $userlist->add_from_sql('id', $sql, []);
        $userlist->add_from_sql(
            'id',
            'SELECT DISTINCT executionuserid AS id
               FROM {local_wisa_course_provision}
              WHERE executionuserid IS NOT NULL',
            []
        );
    }

    /**
     * Export sync log rows for an approved user context list.
     *
     * @param approved_contextlist $contextlist Approved context list.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        $systemctxid = \context_system::instance()->id;
        if (!in_array($systemctxid, $contextlist->get_contextids())) {
            return;
        }
        $user = $contextlist->get_user();
        $records = self::get_user_logs($user);
        $provisions = self::get_user_provisioning((int)$user->id);
        if (!$records && !$provisions) {
            return;
        }
        $rows = [];
        foreach ($records as $r) {
            $rows[] = (object)[
                'time' => transform::datetime($r->timecreated),
                'action' => $r->action,
                'type' => $r->objecttype,
                'objectid' => $r->objectid,
                'status' => $r->status,
                'message' => $r->message,
            ];
        }
        $provisionrows = [];
        foreach ($provisions as $record) {
            $provisionrows[] = (object)[
                'timecreated' => transform::datetime($record->timecreated),
                'timemodified' => transform::datetime($record->timemodified),
                'sourcecomponent' => $record->sourcecomponent,
                'courseidnumber' => $record->courseidnumber,
                'status' => $record->status,
                'attempts' => $record->attempts,
                'lasterror' => $record->lasterror,
            ];
        }
        writer::with_context(\context_system::instance())->export_data(
            [get_string('pluginname', 'local_wisa')],
            (object)['logs' => $rows, 'provisioning' => $provisionrows]
        );
    }

    /**
     * Delete all user-linked log rows for a context.
     *
     * Operational system rows that are not linked to a Moodle username or idnumber
     * are preserved.
     *
     * @param \context $context Context.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if (!$context instanceof \context_system) {
            return;
        }
        self::delete_all_user_logs();
        self::anonymize_all_provisioning_users();
    }

    /**
     * Delete sync log rows for one approved user.
     *
     * @param approved_contextlist $contextlist Approved context list.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $systemctxid = \context_system::instance()->id;
        if (!in_array($systemctxid, $contextlist->get_contextids())) {
            return;
        }
        $user = $contextlist->get_user();
        self::delete_user_logs($user);
        self::anonymize_provisioning_user((int)$user->id);
    }

    /**
     * Delete sync log rows for a list of approved users.
     *
     * @param approved_userlist $userlist Approved user list.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        foreach ($userlist->get_userids() as $uid) {
            $u = $DB->get_record('user', ['id' => $uid], 'username, idnumber');
            if ($u) {
                self::delete_user_logs($u);
            }
        }
        self::anonymize_provisioning_users($userlist->get_userids());
    }

    /**
     * Build a user-specific log lookup clause.
     *
     * @param object $user User record.
     * @param array $params SQL parameters to update.
     * @return string
     */
    private static function user_log_clause($user, &$params): string {
        $clauses = [];
        if (!empty($user->username)) {
            $clauses[] = 'objectid = :u1';
            $params['u1'] = $user->username;
        }
        if (!empty($user->idnumber) && $user->idnumber !== ($user->username ?? null)) {
            $clauses[] = 'objectid = :u2';
            $params['u2'] = $user->idnumber;
        }
        return $clauses ? '(' . implode(' OR ', $clauses) . ')' : '';
    }

    /**
     * Return whether a user has sync log rows.
     *
     * @param object $user User record.
     * @return bool
     */
    private static function user_has_logs($user): bool {
        global $DB;
        $params = [];
        $where = self::user_log_clause($user, $params);
        if (!$where) {
            return false;
        }
        return $DB->record_exists_select('local_wisa_log', $where, $params);
    }

    /**
     * Return whether a user owns provisioning execution state.
     *
     * @param int $userid User ID.
     * @return bool Whether a provisioning row references the user.
     */
    private static function user_has_provisioning(int $userid): bool {
        global $DB;

        return $DB->record_exists('local_wisa_course_provision', ['executionuserid' => $userid]);
    }

    /**
     * Return sync log rows for a user.
     *
     * @param object $user User record.
     * @return array
     */
    private static function get_user_logs($user): array {
        global $DB;
        $params = [];
        $where = self::user_log_clause($user, $params);
        if (!$where) {
            return [];
        }
        return $DB->get_records_select('local_wisa_log', $where, $params, 'timecreated ASC');
    }

    /**
     * Return provisioning execution state associated with one user.
     *
     * @param int $userid User ID.
     * @return \stdClass[] Provisioning rows keyed by ID.
     */
    private static function get_user_provisioning(int $userid): array {
        global $DB;

        return $DB->get_records(
            'local_wisa_course_provision',
            ['executionuserid' => $userid],
            'timecreated ASC'
        );
    }


    /**
     * Delete log rows that are linked to Moodle users by username or idnumber.
     *
     * @return void
     */
    private static function delete_all_user_logs(): void {
        global $DB;

        $ids = $DB->get_fieldset_sql(
            "SELECT DISTINCT l.id
               FROM {local_wisa_log} l
               JOIN {user} u
                 ON l.objectid = u.username OR (u.idnumber <> '' AND l.objectid = u.idnumber)"
        );
        if (!$ids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_wisa_log', "id {$insql}", $params);
    }

    /**
     * Delete sync log rows for a user.
     *
     * @param object $user User record.
     */
    private static function delete_user_logs($user): void {
        global $DB;
        $params = [];
        $where = self::user_log_clause($user, $params);
        if ($where) {
            $DB->delete_records_select('local_wisa_log', $where, $params);
        }
    }

    /**
     * Remove one user's durable provisioning association.
     *
     * @param int $userid User ID.
     * @return void
     */
    private static function anonymize_provisioning_user(int $userid): void {
        global $DB;

        $DB->set_field('local_wisa_course_provision', 'executionuserid', null, ['executionuserid' => $userid]);
    }

    /**
     * Remove durable provisioning associations for selected users.
     *
     * @param int[] $userids User IDs.
     * @return void
     */
    private static function anonymize_provisioning_users(array $userids): void {
        global $DB;

        if ($userids === []) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_map('intval', $userids), SQL_PARAMS_NAMED);
        $DB->set_field_select('local_wisa_course_provision', 'executionuserid', null, "executionuserid {$insql}", $params);
    }

    /**
     * Remove every durable provisioning user association.
     *
     * @return void
     */
    private static function anonymize_all_provisioning_users(): void {
        global $DB;

        $DB->set_field_select(
            'local_wisa_course_provision',
            'executionuserid',
            null,
            'executionuserid IS NOT NULL',
            []
        );
    }
}
