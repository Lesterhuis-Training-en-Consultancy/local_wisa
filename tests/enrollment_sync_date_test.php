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
 * Date parsing tests for enrolment synchronisation.
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
 * Verifies enrolment date conversion and fallback behavior.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_wisa
 * @covers     \local_wisa\sync\enrollment_sync
 */
final class enrollment_sync_date_test extends enrollment_sync_test_case {
    /**
     * Verify a mapped date string and Unix timestamp set a new enrolment period.
     *
     * @return void
     */
    public function test_new_enrolment_uses_mapped_date_string_and_unix_timestamp(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $user = $this->create_user_with_idnumber('STUDENT-001');
        $startdate = '2026-09-01 08:30:00';
        $timeend = 1800000000;
        $rows = [
            sis_fixtures::enrolment(['startdate' => $startdate, 'enddate' => $timeend]),
        ];

        $this->assertTrue((new enrollment_sync('sissource_wisa'))->run($rows));

        $enrolment = $this->user_enrolment($course->id, $user->id);
        $this->assertSame(strtotime($startdate), (int)$enrolment->timestart);
        $this->assertSame($timeend, (int)$enrolment->timeend);
    }

    /**
     * Verify numeric date strings retain their Unix timestamp meaning.
     *
     * @return void
     */
    public function test_new_enrolment_uses_numeric_string_unix_timestamps(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $user = $this->create_user_with_idnumber('STUDENT-001');
        $timestart = '1800000001';
        $timeend = '1800001234';
        $rows = [
            sis_fixtures::enrolment(['startdate' => $timestart, 'enddate' => $timeend]),
        ];

        $this->assertTrue((new enrollment_sync('sissource_wisa'))->run($rows));

        $enrolment = $this->user_enrolment($course->id, $user->id);
        $this->assertSame(1800000001, (int)$enrolment->timestart);
        $this->assertSame(1800001234, (int)$enrolment->timeend);
    }

    /**
     * Verify absent and invalid dates use zero timestamps.
     *
     * @return void
     */
    public function test_new_enrolment_uses_zero_for_absent_or_invalid_dates(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $course = $this->create_course_with_idnumber('WISA-COURSE-001');
        $user = $this->create_user_with_idnumber('STUDENT-001');
        $row = sis_fixtures::enrolment(['enddate' => 'not-a-date']);
        unset($row['startdate']);
        $rows = [$row];

        $this->assertTrue((new enrollment_sync('sissource_wisa'))->run($rows));

        $enrolment = $this->user_enrolment($course->id, $user->id);
        $this->assertSame(0, (int)$enrolment->timestart);
        $this->assertSame(0, (int)$enrolment->timeend);
    }
}
