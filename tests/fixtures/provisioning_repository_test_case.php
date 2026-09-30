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
 * Shared provisioning repository test fixture.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once(__DIR__ . '/../../db/upgrade.php');
require_once($CFG->libdir . '/upgradelib.php');

/**
 * Verifies the durable provisioning state machine contract.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class provisioning_repository_test_case extends \advanced_testcase {
    /**
     * Prepare the provisioning database table for the test.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        try {
            $table = new \xmldb_table('local_wisa_course_provision');
            if (!$DB->get_manager()->table_exists($table)) {
                $previousversion = get_config('local_wisa', 'version');
                try {
                    set_config('version', 2026080502, 'local_wisa');
                    xmldb_local_wisa_upgrade(2026080502);
                } finally {
                    if ($previousversion === false) {
                        unset_config('version', 'local_wisa');
                    } else {
                        set_config('version', $previousversion, 'local_wisa');
                    }
                }
            }
        } finally {
            unset($CFG->upgraderunning);
            unset_config('upgraderunning');
        }
    }

    /**
     * Create a pending provisioning record.
     *
     * @param provisioning_repository $repository Provisioning repository.
     * @param string $courseidnumber Course identifier number.
     * @return \stdClass Created provisioning record.
     */
    protected function create_pending(provisioning_repository $repository, string $courseidnumber = 'AS-COURSE-001'): \stdClass {
        return $repository->create_pending(
            'sissource_athenasoft',
            $courseidnumber,
            'AS-course-' . $courseidnumber,
            'Provisioned course ' . $courseidnumber,
            42,
            73,
            null,
            100,
            200,
            101
        );
    }

    /**
     * Assert that an operation raises an invalid transition exception.
     *
     * @param callable $operation Operation to execute.
     * @return void
     */
    protected function assert_invalid_transition(callable $operation): void {
        try {
            $operation();
            $this->fail('Expected an invalid provisioning state transition.');
        } catch (\coding_exception $exception) {
            $this->assertStringContainsString('provision', \core_text::strtolower($exception->getMessage()));
        }
    }
}
