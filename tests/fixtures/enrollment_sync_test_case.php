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
 * Shared fixture for enrolment synchronisation tests.
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
require_once(__DIR__ . '/sync_testcase.php');

/**
 * Provides the shared provisioning fixture for enrolment synchronisation tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class enrollment_sync_test_case extends sync_testcase {
    /**
     * Ensure the focused upgrade provisions the test database table when needed.
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
     * Create a provision row at the requested lifecycle status.
     *
     * @param string $courseidnumber Source course identity.
     * @param string $status Desired provisioning status.
     * @return \stdClass Persisted provision record.
     */
    protected function create_provision_record(string $courseidnumber, string $status): \stdClass {
        $repository = new provisioning_repository();
        $record = $repository->create_pending(
            'sissource_wisa',
            $courseidnumber,
            'Provisioned ' . $courseidnumber,
            'Provisioned course ' . $courseidnumber,
            1,
            null,
            null,
            0,
            0,
            null
        );
        if ($status === provisioning_repository::STATUS_PENDING) {
            return $record;
        }

        $record = $repository->mark_running((int)$record->id);
        if ($status === provisioning_repository::STATUS_RUNNING) {
            return $record;
        }

        return $repository->mark_terminal((int)$record->id, $status);
    }
}
