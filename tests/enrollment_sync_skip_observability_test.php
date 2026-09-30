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
 * Skip observability tests for enrolment synchronisation.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/enrollment_sync_test_case.php');

use local_wisa\sync\enrollment_sync;
use local_wisa\tests\sis_fixtures;

/**
 * Verifies safe, counted enrolment skip outcomes.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_wisa
 * @covers     \local_wisa\sync\enrollment_sync
 */
final class enrollment_sync_skip_observability_test extends enrollment_sync_test_case {
    /**
     * Verify an already active enrolment is counted and logged without identities.
     *
     * @return void
     */
    public function test_already_active_enrolment_is_counted_and_logged_safely(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $courseidnumber = 'ACTIVE-ENROLMENT-COURSE';
        $useridnumber = 'ACTIVE-ENROLMENT-USER';
        $course = $this->create_course_with_idnumber($courseidnumber);
        $user = $this->create_user_with_idnumber($useridnumber);
        $this->manually_enrol_user($course, $user);
        $stats = new sync_stats();

        $this->assertTrue((new enrollment_sync('sissource_wisa', false, $stats))->run([
            sis_fixtures::enrolment([
                'courseidnumber' => $courseidnumber,
                'useridnumber' => $useridnumber,
            ]),
        ]));

        $this->assertSame(1, $stats->enrolskip);
        $this->assert_safe_skip_log('ENROLMENT_ALREADY_ACTIVE', [
            $courseidnumber,
            $useridnumber,
            $user->username,
            'sissource_wisa',
        ]);
    }

    /**
     * Verify a missing course is counted and logged without source identities.
     *
     * @return void
     */
    public function test_missing_course_enrolment_is_counted_and_logged_safely(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $courseidnumber = 'MISSING-ENROLMENT-COURSE';
        $useridnumber = 'MISSING-COURSE-USER';
        $this->create_user_with_idnumber($useridnumber);
        $stats = new sync_stats();

        $this->assertTrue((new enrollment_sync('sissource_wisa', false, $stats))->run([
            sis_fixtures::enrolment([
                'courseidnumber' => $courseidnumber,
                'useridnumber' => $useridnumber,
            ]),
        ]));

        $this->assertSame(1, $stats->enrolskip);
        $this->assert_safe_skip_log('ENROLMENT_COURSE_NOT_FOUND', [
            $courseidnumber,
            $useridnumber,
            'sissource_wisa',
        ]);
    }

    /**
     * Verify an enrolment outside the school-year window is counted and safely logged.
     *
     * @return void
     */
    public function test_outside_schoolyear_enrolment_is_counted_and_logged_safely(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $courseidnumber = 'OUTSIDE-SCHOOLYEAR-COURSE';
        $useridnumber = 'OUTSIDE-SCHOOLYEAR-USER';
        $stats = new sync_stats();

        $this->assertTrue((new enrollment_sync('sissource_wisa', false, $stats, [100, 200]))->run([
            sis_fixtures::enrolment([
                'courseidnumber' => $courseidnumber,
                'useridnumber' => $useridnumber,
                'startdate' => 300,
                'enddate' => 400,
            ]),
        ]));

        $this->assertSame(1, $stats->enrolskip);
        $this->assert_safe_skip_log('ENROLMENT_OUTSIDE_SCHOOLYEAR', [
            $courseidnumber,
            $useridnumber,
            'sissource_wisa',
        ]);
    }

    /**
     * Assert a unique skip log contains only its stable safe code.
     *
     * @param string $code Expected stable skip code.
     * @param array $identities Source identities excluded from the log.
     * @return void
     */
    private function assert_safe_skip_log(string $code, array $identities): void {
        global $DB;

        $records = array_filter(
            $DB->get_records('local_wisa_log', ['action' => 'sync_enrol'], 'id ASC'),
            function (\stdClass $record) use ($code): bool {
                return $record->message === $code;
            }
        );
        $this->assertCount(1, $records);
        $record = array_values($records)[0];
        $this->assertSame('skip', $record->status);
        $this->assertSame('redacted', $record->objectid);
        $haystack = implode(' ', [
            $record->action,
            $record->objecttype,
            $record->objectid,
            $record->status,
            $record->message,
        ]);
        foreach ($identities as $identity) {
            $this->assertStringNotContainsString($identity, $haystack);
        }
    }
}
