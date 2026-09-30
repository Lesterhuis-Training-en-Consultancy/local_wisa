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
 * Upgrade tests for local_wisa role-map migration.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once(__DIR__ . '/../db/upgrade.php');
require_once($CFG->libdir . '/upgradelib.php');

/**
 * Verifies role-map upgrade preserves live configuration safely.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * Existing sites default the new password policy on without replacing an explicit choice.
     *
     * @return void
     */
    public function test_force_password_change_migration_defaults_on_and_preserves_disabled_choice(): void {
        $this->resetAfterTest();
        unset_config('force_password_change', 'local_wisa');

        set_config('version', 2026082400, 'local_wisa');
        xmldb_local_wisa_upgrade(2026082400);

        $this->assertEquals(1, get_config('local_wisa', 'force_password_change'));

        set_config('force_password_change', 0, 'local_wisa');
        set_config('version', 2026082400, 'local_wisa');
        xmldb_local_wisa_upgrade(2026082400);

        $this->assertEquals(0, get_config('local_wisa', 'force_password_change'));
    }

    /**
     * Parent role IDs migrate to semantic token shortnames and remain stable on rerun.
     *
     * @return void
     */
    public function test_role_id_migration_is_idempotent_and_removes_legacy_settings(): void {
        global $DB;

        $this->resetAfterTest();
        $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
        $teacherroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        set_config('student_role', $studentroleid, 'local_wisa');
        set_config('teacher_role', $teacherroleid, 'local_wisa');
        unset_config('rolemap', 'local_wisa');

        set_config('version', 2026080500, 'local_wisa');
        xmldb_local_wisa_upgrade(2026080500);
        $firstmap = json_decode((string)get_config('local_wisa', 'rolemap'), true);

        $this->assertSame([
            'student' => 'student',
            'teacher' => 'editingteacher',
            'cursist' => 'student',
            'leerkracht' => 'editingteacher',
        ], $firstmap);
        $this->assertFalse(get_config('local_wisa', 'student_role'));
        $this->assertFalse(get_config('local_wisa', 'teacher_role'));

        set_config('version', 2026080500, 'local_wisa');
        xmldb_local_wisa_upgrade(2026080500);

        $this->assertSame($firstmap, json_decode((string)get_config('local_wisa', 'rolemap'), true));
    }

    /**
     * A valid new role map is never replaced by legacy configuration.
     *
     * @return void
     */
    public function test_role_id_migration_preserves_valid_existing_rolemap(): void {
        $this->resetAfterTest();
        $rolemap = '{"advisor":"manager"}';
        set_config('rolemap', $rolemap, 'local_wisa');
        set_config('student_role', 5, 'local_wisa');
        set_config('teacher_role', 3, 'local_wisa');

        set_config('version', 2026080500, 'local_wisa');
        xmldb_local_wisa_upgrade(2026080500);

        $this->assertSame($rolemap, get_config('local_wisa', 'rolemap'));
        $this->assertFalse(get_config('local_wisa', 'student_role'));
        $this->assertFalse(get_config('local_wisa', 'teacher_role'));
    }

    /**
     * The provisioning table upgrade is idempotent and matches the install schema contract.
     *
     * @return void
     */
    public function test_provision_table_upgrade_is_idempotent_and_matches_install_schema(): void {
        global $DB;

        $this->resetAfterTest();
        $table = new \xmldb_table('local_wisa_course_provision');
        $dbman = $DB->get_manager();
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        set_config('version', 2026080502, 'local_wisa');
        xmldb_local_wisa_upgrade(2026080502);
        set_config('version', 2026080502, 'local_wisa');
        xmldb_local_wisa_upgrade(2026080502);

        $this->assertTrue($dbman->table_exists($table));
        foreach (
            [
            'id', 'sourcecomponent', 'courseidnumber', 'status', 'jobid', 'tempshortname',
            'desiredshortname', 'desiredfullname', 'categoryid', 'templateid', 'courseid',
            'startdate', 'enddate', 'executionuserid', 'attempts', 'tempprecallabsent',
            'followupqueued', 'lasterror', 'timecreated', 'timemodified',
            ] as $fieldname
        ) {
            $this->assertTrue($dbman->field_exists($table, new \xmldb_field($fieldname)));
        }

        $indexes = $DB->get_indexes('local_wisa_course_provision');
        $this->assertContains([
            'columns' => ['sourcecomponent', 'courseidnumber'],
            'unique' => true,
        ], array_map(static function (array $index): array {
            return [
                'columns' => $index['columns'],
                'unique' => (bool)$index['unique'],
            ];
        }, $indexes));
        $this->assertContains([
            'columns' => ['jobid'],
            'unique' => true,
        ], array_map(static function (array $index): array {
            return [
                'columns' => $index['columns'],
                'unique' => (bool)$index['unique'],
            ];
        }, $indexes));
        $this->assertContains([
            'columns' => ['status'],
            'unique' => false,
        ], array_map(static function (array $index): array {
            return [
                'columns' => $index['columns'],
                'unique' => (bool)$index['unique'],
            ];
        }, $indexes));
        $this->assertContains([
            'columns' => ['courseid'],
            'unique' => false,
        ], array_map(static function (array $index): array {
            return [
                'columns' => $index['columns'],
                'unique' => (bool)$index['unique'],
            ];
        }, $indexes));

        $installxml = file_get_contents(__DIR__ . '/../db/install.xml');
        $this->assertStringContainsString('<TABLE NAME="local_wisa_course_provision"', $installxml);
        $this->assertStringContainsString('TYPE="unique" FIELDS="sourcecomponent, courseidnumber"', $installxml);
        $this->assertStringContainsString('TYPE="unique" FIELDS="jobid"', $installxml);
        $this->assertStringNotContainsString('TYPE="foreign"', \core_text::strtolower($installxml));
    }
}
