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
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GNU GPL v3 or later
 */

namespace local_wisa\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_wisa_log', [
            'timecreated' => 'privacy:metadata:local_wisa_log:timecreated',
            'action' => 'privacy:metadata:local_wisa_log:action',
            'objecttype' => 'privacy:metadata:local_wisa_log:objecttype',
            'objectid' => 'privacy:metadata:local_wisa_log:objectid',
            'status' => 'privacy:metadata:local_wisa_log:status',
            'message' => 'privacy:metadata:local_wisa_log:message',
        ], 'privacy:metadata:local_wisa_log');

        $collection->add_external_location_link('wisa_api', [
            'username' => 'privacy:metadata:wisa_api:username',
            'firstname' => 'privacy:metadata:wisa_api:firstname',
            'lastname' => 'privacy:metadata:wisa_api:lastname',
            'email' => 'privacy:metadata:wisa_api:email',
        ], 'privacy:metadata:wisa_api');

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        $user = $DB->get_record('user', ['id' => $userid], 'id, username, idnumber');
        if ($user && self::user_has_logs($user)) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }
        $sql = "SELECT u.id
                FROM {user} u
                JOIN {local_wisa_log} l
                  ON l.objectid = u.username OR l.objectid = u.idnumber
                WHERE u.deleted = 0";
        $userlist->add_from_sql('id', $sql, []);
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        $systemctxid = \context_system::instance()->id;
        if (!in_array($systemctxid, $contextlist->get_contextids())) {
            return;
        }
        $user = $contextlist->get_user();
        $records = self::get_user_logs($user);
        if (!$records) {
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
        writer::with_context(\context_system::instance())->export_data(
            [get_string('pluginname', 'local_wisa')],
            (object)['logs' => $rows]
        );
    }

    public static function delete_data_for_all_users_in_context(\context $context) {
        // Per-user records cannot be safely separated from operational system logs;
        // the log table is global by design.
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $user = $contextlist->get_user();
        self::delete_user_logs($user);
    }

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
    }

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

    private static function user_has_logs($user): bool {
        global $DB;
        $params = [];
        $where = self::user_log_clause($user, $params);
        if (!$where) {
            return false;
        }
        return $DB->record_exists_select('local_wisa_log', $where, $params);
    }

    private static function get_user_logs($user): array {
        global $DB;
        $params = [];
        $where = self::user_log_clause($user, $params);
        if (!$where) {
            return [];
        }
        return $DB->get_records_select('local_wisa_log', $where, $params, 'timecreated ASC');
    }

    private static function delete_user_logs($user): void {
        global $DB;
        $params = [];
        $where = self::user_log_clause($user, $params);
        if ($where) {
            $DB->delete_records_select('local_wisa_log', $where, $params);
        }
    }
}
