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
 * Upgrade steps for local_wisa.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../classes/role_resolver.php');

/**
 * Run the local_wisa upgrade steps.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool
 */
function xmldb_local_wisa_upgrade($oldversion) {
    if ($oldversion < 2026070600) {
        // Existing installations had WISA connection/query settings on the parent
        // component. Keep WISA as the default active source and move those settings
        // to the new sissource_wisa subplugin component if the target value is empty.
        set_config('active_source', 'wisa', 'local_wisa');

        $migrate = [
            'api_url',
            'api_user',
            'api_pass',
            'institute_num',
            'query_courses',
            'query_students',
            'query_teachers',
            'query_enrolments',
            'query_unenrolments',
        ];
        foreach ($migrate as $name) {
            $oldvalue = get_config('local_wisa', $name);
            if ($oldvalue !== false && get_config('sissource_wisa', $name) === false) {
                set_config($name, $oldvalue, 'sissource_wisa');
            }
            unset_config($name, 'local_wisa');
        }

        upgrade_plugin_savepoint(true, 2026070600, 'local', 'wisa');
    }

    if ($oldversion < 2026080501) {
        global $DB;

        $configured = get_config('local_wisa', 'rolemap');
        $rolemap = $configured === false ? null : \local_wisa\role_resolver::parse_rolemap((string)$configured);
        if ($rolemap === null) {
            $rolemap = \local_wisa\role_resolver::default_rolemap();
            foreach (['student' => 'student_role', 'teacher' => 'teacher_role'] as $token => $setting) {
                $roleid = (int)get_config('local_wisa', $setting);
                $shortname = $roleid > 0 ? $DB->get_field('role', 'shortname', ['id' => $roleid]) : false;
                if ($shortname !== false && trim((string)$shortname) !== '') {
                    $rolemap[$token] = $shortname;
                    $rolemap[$token === 'student' ? 'cursist' : 'leerkracht'] = $shortname;
                }
            }
            $persisted = set_config('rolemap', json_encode($rolemap), 'local_wisa');
        } else {
            $persisted = true;
        }

        if ($persisted) {
            unset_config('student_role', 'local_wisa');
            unset_config('teacher_role', 'local_wisa');
        }

        if (!$persisted) {
            return false;
        }

        upgrade_plugin_savepoint(true, 2026080501, 'local', 'wisa');
    }

    if ($oldversion < 2026080503) {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_wisa_course_provision');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('sourcecomponent', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('courseidnumber', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
        $table->add_field('jobid', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null);
        $table->add_field('tempshortname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('desiredshortname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('desiredfullname', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('categoryid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('templateid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('startdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('enddate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('executionuserid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('tempprecallabsent', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('followupqueued', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lasterror', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('sourcecomponent_courseidnumber', XMLDB_KEY_UNIQUE, ['sourcecomponent', 'courseidnumber']);
        $table->add_key('jobid', XMLDB_KEY_UNIQUE, ['jobid']);
        $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $table->add_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026080503, 'local', 'wisa');
    }

    if ($oldversion < 2026080701) {
        upgrade_plugin_savepoint(true, 2026080701, 'local', 'wisa');
    }

    if ($oldversion < 2026082800) {
        if (
            get_config('local_wisa', 'force_password_change') === false &&
            !set_config('force_password_change', 1, 'local_wisa')
        ) {
            return false;
        }

        upgrade_plugin_savepoint(true, 2026082800, 'local', 'wisa');
    }

    return true;
}
