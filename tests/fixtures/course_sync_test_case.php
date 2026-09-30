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
 * Shared fixture for course synchronisation tests.
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
 * Shared course synchronisation test fixture.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class course_sync_test_case extends sync_testcase {
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
     * Queue one provision row for the active WISA source identity.
     *
     * @param int $categoryid Destination category ID.
     * @param string $courseidnumber Source course identity.
     * @return \stdClass Queued provision record.
     */
    protected function queue_provision(int $categoryid, string $courseidnumber): \stdClass {
        global $USER;

        return (new provisioning_service())->queue(
            'sissource_wisa',
            $courseidnumber,
            'WISA ' . $courseidnumber,
            'WISA Course ' . $courseidnumber,
            $categoryid,
            'EX651',
            make_timestamp(2026, 9, 1),
            make_timestamp(2027, 6, 30),
            (int)$USER->id
        );
    }

    /**
     * Return durable provision states that must prevent existing-course updates.
     *
     * @return array Provision status test cases.
     */
    public static function blocking_provision_status_provider(): array {
        return [
            [provisioning_repository::STATUS_PENDING],
            [provisioning_repository::STATUS_RUNNING],
            [provisioning_repository::STATUS_FAILED],
        ];
    }

    /**
     * Return successful terminal provision states.
     *
     * @return array Provision status test cases.
     */
    public static function completed_provision_status_provider(): array {
        return [
            [provisioning_repository::STATUS_READY],
            [provisioning_repository::STATUS_FALLBACK_READY],
        ];
    }
}
