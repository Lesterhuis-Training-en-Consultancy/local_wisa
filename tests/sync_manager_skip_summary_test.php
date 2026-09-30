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
 * Persisted skip-summary tests for sync manager runs.
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
require_once(__DIR__ . '/fixtures/sync_testcase.php');
require_once(__DIR__ . '/fixtures/sync_manager_test_case.php');

use local_wisa\tests\fake_api_client;
use local_wisa\tests\sis_fixtures;

/**
 * Verifies persisted run summaries expose existing skip counters.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_wisa
 * @covers     \local_wisa\sync_manager
 */
final class sync_manager_skip_summary_test extends sync_manager_test_case {
    /**
     * Verify persisted summaries use stable labels for all tracked skip counters.
     *
     * @return void
     */
    public function test_last_run_summary_includes_course_enrolment_and_unenrolment_skips(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->disable_except(['courses:courses', 'enrolments:enrolments', 'unenrolments:unenrolments']);
        set_config('schoolyear_scope', 'current', 'local_wisa');
        [$windowstart, $windowend] = schoolyear_window::calculate();
        $course = $this->create_course_with_idnumber('SUMMARY-ACTIVE-COURSE');
        $user = $this->create_user_with_idnumber('SUMMARY-ACTIVE-USER');
        $this->manually_enrol_user($course, $user);
        $source = new fake_api_client([
            'courses' => [sis_fixtures::course([
                'idnumber' => 'SUMMARY-OUTSIDE-COURSE',
                'shortname' => 'SUMMARY-OUTSIDE-COURSE',
                'startdate' => 100,
                'enddate' => 200,
            ])],
            'enrolments' => [
                sis_fixtures::enrolment([
                    'courseidnumber' => $course->idnumber,
                    'useridnumber' => $user->idnumber,
                    'startdate' => $windowstart,
                    'enddate' => $windowend,
                ]),
                sis_fixtures::enrolment([
                    'courseidnumber' => 'SUMMARY-MISSING-COURSE',
                    'useridnumber' => $user->idnumber,
                    'startdate' => $windowstart,
                    'enddate' => $windowend,
                ]),
                sis_fixtures::enrolment([
                    'courseidnumber' => 'SUMMARY-OUTSIDE-ENROLMENT',
                    'useridnumber' => $user->idnumber,
                    'startdate' => 300,
                    'enddate' => 400,
                ]),
            ],
            'unenrolments' => [sis_fixtures::unenrolment([
                'courseidnumber' => 'SUMMARY-MISSING-UNENROLMENT-COURSE',
                'useridnumber' => $user->idnumber,
            ])],
        ]);

        $this->assertTrue((new sync_manager($source, null, 'sissource_wisa'))->run_full_sync());

        $summary = (string)get_config('local_wisa', 'last_run_summary');
        $this->assertStringContainsString('courses c=0 u=0 f=0 s=1', $summary);
        $this->assertStringContainsString('enrol c=0 u=0 f=0 s=3', $summary);
        $this->assertStringContainsString('unenrol ok=0 f=0 s=1', $summary);
        $this->assertSame(0, preg_match('/\busers\b[^|]*\bs=\d+/', $summary));
    }

    /**
     * Enable only the requested fixture tuples.
     *
     * @param array $enabled Tuple keys in stream:phase form.
     * @return void
     */
    private function disable_except(array $enabled): void {
        foreach (fake_api_client::get_stream_registry() as $descriptor) {
            foreach ($descriptor['phases'] as $phase) {
                $tuple = $descriptor['key'] . ':' . $phase;
                set_config(
                    'stream_' . $descriptor['key'] . '_' . $phase . '_enabled',
                    in_array($tuple, $enabled, true) ? 1 : 0,
                    'sissource_wisa'
                );
            }
        }
    }
}
