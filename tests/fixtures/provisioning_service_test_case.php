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
 * Shared provisioning service test fixture.
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
require_once(__DIR__ . '/provisioning_service_test_doubles.php');
require_once(__DIR__ . '/provision_followup_task_test_double.php');

/**
 * Shares provisioning service test setup and helpers.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class provisioning_service_test_case extends \advanced_testcase {
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
     * Create an execution context with a destination category and template course.
     *
     * @return array{category: \stdClass, template: \stdClass, userid: int}
     */
    protected function create_context(): array {
        global $USER;

        self::setAdminUser();
        $category = self::getDataGenerator()->create_category(['name' => 'Provisioning destination']);
        $template = self::getDataGenerator()->create_course([
            'category' => $category->id,
            'fullname' => 'Provisioning template',
            'shortname' => 'PROVISION-TEMPLATE',
        ]);
        return [
            'category' => $category,
            'template' => $template,
            'userid' => (int)$USER->id,
        ];
    }

    /**
     * Queue one source-neutral provision row for the supplied template key.
     *
     * @param provisioning_service $service Service under test.
     * @param int $categoryid Destination category ID.
     * @param int $userid Explicit task user ID.
     * @param string|null $templatekey Supplied source template key.
     * @param string $courseidnumber Source course identity.
     * @return \stdClass Pending provision record.
     */
    protected function queue_record(
        provisioning_service $service,
        int $categoryid,
        int $userid,
        ?string $templatekey,
        string $courseidnumber = 'COURSE-001'
    ): \stdClass {
        return $service->queue(
            'sissource_wisa',
            $courseidnumber,
            'DEST-' . $courseidnumber,
            'Destination ' . $courseidnumber,
            $categoryid,
            $templatekey,
            1700000000,
            1800000000,
            $userid
        );
    }

    /**
     * Create one terminal successful provision with its exact desired destination and marker zero.
     *
     * @param array $context Provisioning context with category, template, and user ID.
     * @param string $courseidnumber Source course identity.
     * @return \stdClass Terminal provision record.
     */
    protected function create_terminal_record(array $context, string $courseidnumber = 'FOLLOWUP-001'): \stdClass {
        $repository = new provisioning_repository();
        $record = $repository->create_pending(
            'sissource_wisa',
            $courseidnumber,
            'DEST-' . $courseidnumber,
            'Destination ' . $courseidnumber,
            (int)$context['category']->id,
            null,
            null,
            1700000000,
            1800000000,
            $context['userid']
        );
        $course = create_course((object)[
            'category' => (int)$record->categoryid,
            'fullname' => $record->desiredfullname,
            'shortname' => $record->desiredshortname,
            'idnumber' => $record->courseidnumber,
            'startdate' => (int)$record->startdate,
            'enddate' => (int)$record->enddate,
        ]);
        $repository->record_destination_course(
            (int)$repository->mark_running((int)$record->id)->id,
            (int)$course->id
        );
        return $repository->mark_terminal((int)$record->id, provisioning_repository::STATUS_FALLBACK_READY);
    }

    /**
     * Return the only queued provision task.
     *
     * @return \core\task\adhoc_task Provision task.
     */
    protected function queued_task(): \core\task\adhoc_task {
        $tasks = \core\task\manager::get_adhoc_tasks(\local_wisa\task\provision_course_task::class);
        $this->assertCount(1, $tasks);
        return reset($tasks);
    }

    /**
     * Return queued source-pinned provision follow-up tasks.
     *
     * @return \core\task\adhoc_task[] Follow-up tasks.
     */
    protected function queued_followup_tasks(): array {
        return \core\task\manager::get_adhoc_tasks(\local_wisa\task\provision_followup_task::class);
    }
}
