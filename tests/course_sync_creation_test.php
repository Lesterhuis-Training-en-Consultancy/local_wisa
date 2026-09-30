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
 * Course synchronisation creation tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/course_sync_test_case.php');

use local_wisa\sync\course_sync;
use local_wisa\tests\sis_fixtures;

/**
 * Course synchronisation creation tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_sync_creation_test extends course_sync_test_case {
    public function test_course_is_created_from_wisa_course_row(): void {
        global $DB;

        $this->resetAfterTest();
        $categoryid = $this->configure_wisa_defaults();

        $stats = new sync_stats();
        $stats->coursefail = 1;

        $this->assertTrue((new course_sync('sissource_wisa', false, $stats))->run([sis_fixtures::course()]));

        $course = $DB->get_record('course', ['idnumber' => 'WISA-COURSE-001'], '*', MUST_EXIST);
        $this->assertSame('WISA Course 001', $course->fullname);
        $this->assertSame('WISA C001', $course->shortname);
        $this->assertSame($categoryid, (int)$course->category);
        $this->assertSame(1, $stats->coursecreate);
        $this->assertSame(1, $stats->coursefail);
        $this->assertCount(1, $DB->get_records('course', ['idnumber' => 'WISA-COURSE-001']));
        $this->assertNotEmpty($DB->get_records('local_wisa_log', ['action' => 'sync_course', 'status' => 'create']));
    }

    public function test_disabled_keyed_course_uses_existing_empty_course_creation(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        set_config('enable_course_provisioning', '0', 'local_wisa');
        $stats = new sync_stats();
        $sync = new course_sync('sissource_wisa', false, $stats);

        $this->assertTrue($sync->run([sis_fixtures::course(['templatekey' => 'EX651'])]));

        $this->assertTrue($DB->record_exists('course', ['idnumber' => 'WISA-COURSE-001']));
        $this->assertSame(0, $DB->count_records('local_wisa_course_provision'));
        $this->assertFalse($sync->has_blocking_provisions());
        $this->assertSame(1, $stats->coursecreate);
    }

    public function test_enabled_course_without_templatekey_uses_existing_empty_course_creation(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        set_config('enable_course_provisioning', '1', 'local_wisa');
        $stats = new sync_stats();
        $sync = new course_sync('sissource_wisa', false, $stats);

        $this->assertTrue($sync->run([sis_fixtures::course()]));

        $this->assertTrue($DB->record_exists('course', ['idnumber' => 'WISA-COURSE-001']));
        $this->assertSame(0, $DB->count_records('local_wisa_course_provision'));
        $this->assertFalse($sync->has_blocking_provisions());
        $this->assertSame(1, $stats->coursecreate);
    }

    public function test_existing_course_is_updated_by_idnumber_without_moving_category(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $existingcategory = self::getDataGenerator()->create_category(['name' => 'Existing category']);
        $existing = $this->create_course_with_idnumber('WISA-COURSE-001', [
            'category' => $existingcategory->id,
            'fullname' => 'Old fullname',
            'shortname' => 'OLD-SHORT',
        ]);

        $stats = new sync_stats();

        $this->assertTrue((new course_sync('sissource_wisa', false, $stats))->run([sis_fixtures::course([
            'FULLNAME' => 'Updated WISA fullname',
            'SHORTNAME' => 'UPDATED-SHORT',
        ]), ]));

        $course = $DB->get_record('course', ['id' => $existing->id], '*', MUST_EXIST);
        $this->assertSame('Updated WISA fullname', $course->fullname);
        $this->assertSame('UPDATED-SHORT', $course->shortname);
        $this->assertSame((int)$existingcategory->id, (int)$course->category);
        $this->assertSame(1, $stats->courseupdate);
        $this->assertCount(1, $DB->get_records('course', ['idnumber' => 'WISA-COURSE-001']));
    }

    public function test_school_year_window_skips_courses_outside_scope(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $window = [make_timestamp(2026, 9, 1), make_timestamp(2027, 8, 31, 23, 59, 59)];
        $stats = new sync_stats();
        $rows = [
            sis_fixtures::course(['KLAS_ID' => 'IN-SCOPE', 'SHORTNAME' => 'IN', 'FULLNAME' => 'Inside scope']),
            sis_fixtures::course([
                'KLAS_ID' => 'OLD-SCOPE',
                'SHORTNAME' => 'OLD',
                'FULLNAME' => 'Old scope',
                'BEGINDATUM' => '2025-09-01',
                'EINDDATUM' => '2026-06-30',
            ]),
            sis_fixtures::course([
                'KLAS_ID' => 'FUTURE-SCOPE',
                'SHORTNAME' => 'FUTURE',
                'FULLNAME' => 'Future scope',
                'BEGINDATUM' => '2028-09-01',
                'EINDDATUM' => '2029-06-30',
            ]),
        ];

        $this->assertTrue((new course_sync('sissource_wisa', false, $stats, $window))->run($rows));

        $this->assertTrue($DB->record_exists('course', ['idnumber' => 'IN-SCOPE']));
        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'OLD-SCOPE']));
        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'FUTURE-SCOPE']));
        $this->assertSame(1, $stats->coursecreate);
        $this->assertSame(2, $stats->courseskip);
    }

    public function test_from_feed_category_path_is_created_for_new_course(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        set_config('category_mode', 'from_feed', 'local_wisa');
        $rows = [sis_fixtures::course([
            'KLAS_ID' => 'CAT-COURSE',
            'SHORTNAME' => 'CATC',
            'FULLNAME' => 'Categorised course',
            'CATEGORY' => 'Languages / NT2',
        ]), ];

        $this->assertTrue((new course_sync('sissource_wisa'))->run($rows));

        $parent = $DB->get_record('course_categories', ['name' => 'Languages', 'parent' => 0], '*', MUST_EXIST);
        $child = $DB->get_record('course_categories', ['name' => 'NT2', 'parent' => $parent->id], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['idnumber' => 'CAT-COURSE'], '*', MUST_EXIST);
        $this->assertSame((int)$child->id, (int)$course->category);
    }

    public function test_course_rows_missing_required_fields_are_rejected_without_creating_courses(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $rows = [
        sis_fixtures::course(['KLAS_ID' => '', 'SHORTNAME' => 'MISSID', 'FULLNAME' => 'Missing id']),
        sis_fixtures::course(['KLAS_ID' => 'MISS-SHORT', 'SHORTNAME' => '', 'FULLNAME' => 'Missing shortname']),
        sis_fixtures::course(['KLAS_ID' => 'MISS-FULL', 'SHORTNAME' => 'MF', 'FULLNAME' => '']),
        ];
        $stats = new sync_stats();

        $this->assertFalse((new course_sync('sissource_wisa', false, $stats))->run($rows));

        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'MISS-SHORT']));
        $this->assertFalse($DB->record_exists('course', ['idnumber' => 'MISS-FULL']));
        $this->assertSame(3, $stats->coursefail);
        $this->assertCount(3, $DB->get_records('local_wisa_log', ['action' => 'sync_course', 'status' => 'fail']));
    }
}
