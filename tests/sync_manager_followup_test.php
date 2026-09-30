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
 * Sync manager follow-up test coverage.
 *
 * @package    local_wisa
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
require_once(__DIR__ . '/fixtures/throwing_course_source.php');

use local_wisa\tests\fake_api_client;
use local_wisa\tests\sis_fixtures;


/**
 * Sync manager follow-up tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sync_manager_followup_test extends sync_manager_test_case {
    /**
     * Test that a blocking provision retains the course watermark and gate state.
     *
     * @return void
     */
    public function test_blocking_course_provision_keeps_course_watermark_and_initial_gate_closed(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->disable_except(['courses:courses']);
        set_config('stream_courses_courses_watermark', 1000, 'sissource_wisa');
        set_config('initial_load_done', 0, 'local_wisa');
        $this->create_provision_record(provisioning_repository::STATUS_PENDING);
        $api = new fake_api_client(['courses' => [sis_fixtures::course()]]);

        $success = (new sync_manager($api))->run_full_sync();

        $this->assertFalse($success);
        $this->assertSame(1000, (int)get_config('sissource_wisa', 'stream_courses_courses_watermark'));
        $this->assertSame('0', get_config('local_wisa', 'initial_load_done'));
        $this->assertSame(['courses:courses'], $api->calls);
    }

    /**
     * Test that a blocking provision retains the enrolment watermark and gate state.
     *
     * @return void
     */
    public function test_blocking_enrolment_provision_keeps_enrolment_watermark_and_initial_gate_closed(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->disable_except(['enrolments:enrolments']);
        set_config('stream_enrolments_enrolments_watermark', 1000, 'sissource_wisa');
        set_config('initial_load_done', 0, 'local_wisa');
        $this->create_provision_record(provisioning_repository::STATUS_PENDING);
        $api = new fake_api_client(['enrolments' => [sis_fixtures::enrolment()]]);

        $success = (new sync_manager($api))->run_full_sync();

        $this->assertFalse($success);
        $this->assertSame(1000, (int)get_config('sissource_wisa', 'stream_enrolments_enrolments_watermark'));
        $this->assertSame('provisioning-blocked', get_config('sissource_wisa', 'stream_enrolments_enrolments_status'));
        $this->assertSame('0', get_config('local_wisa', 'initial_load_done'));
        $this->assertSame(['enrolments:enrolments'], $api->calls);
    }

    /**
     * Test that a failed enabled phase keeps the initial load gate closed.
     *
     * @return void
     */
    public function test_failed_enabled_phase_keeps_initial_load_gate_closed(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->disable_except(['courses:courses']);
        set_config('initial_load_done', 0, 'local_wisa');
        $api = new fake_api_client([], ['courses' => true]);

        $success = (new sync_manager($api))->run_full_sync(true, true);

        $this->assertFalse($success);
        $this->assertSame('0', get_config('local_wisa', 'initial_load_done'));
        $this->assertSame(['courses:courses'], $api->calls);
    }

    /**
     * Test that reconciliation queues one follow-up for a terminal marker row.
     *
     * @return void
     */
    public function test_reconciliation_queues_one_followup_for_terminal_marker_zero_row(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->disable_except([]);
        $record = $this->create_provision_record(provisioning_repository::STATUS_READY);
        $broken = $this->create_provision_record(
            provisioning_repository::STATUS_FALLBACK_READY,
            'BROKEN-COURSE',
            'sissource_wisa',
            0
        );
        $api = new fake_api_client();

        (new sync_manager($api))->run_full_sync();
        (new sync_manager($api))->run_full_sync();

        $tasks = \core\task\manager::get_adhoc_tasks(\local_wisa\task\provision_followup_task::class);
        $this->assertCount(1, $tasks);
        $this->assertSame([
            'provisionid' => (int)$record->id,
            'jobid' => $record->jobid,
        ], (array)reset($tasks)->get_custom_data());
        $this->assertSame(1, (int)(new provisioning_repository())->get((int)$record->id)->followupqueued);
        $this->assertSame(0, (int)(new provisioning_repository())->get((int)$broken->id)->followupqueued);
        $this->assertNotEmpty($this->get_logs_by_status('fail'));
        $this->assertSame([], $api->calls);
    }

    /**
     * Test that dry runs do not reconcile terminal provision follow-ups.
     *
     * @return void
     */
    public function test_dry_run_does_not_reconcile_terminal_provision_followups(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->disable_except([]);
        set_config('dry_run', 1, 'local_wisa');
        $record = $this->create_provision_record(provisioning_repository::STATUS_READY);
        $api = new fake_api_client();

        (new sync_manager($api))->run_full_sync();

        $this->assertSame(0, (int)(new provisioning_repository())->get((int)$record->id)->followupqueued);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_wisa\task\provision_followup_task::class));
        $this->assertSame([], $api->calls);
    }

    /**
     * Test that scheduled sync skips before initial load approval.
     *
     * @return void
     */
    public function test_scheduled_sync_skips_before_initial_load_approval(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        set_config('initial_load_done', 0, 'local_wisa');

        $this->expectOutputRegex('/initial full load not yet approved/');
        (new \local_wisa\task\sync_task())->execute();
    }

    /**
     * Test that a successful live run opens the initial load gate.
     *
     * @return void
     */
    public function test_successful_live_run_opens_initial_load_gate(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        set_config('initial_load_done', 0, 'local_wisa');

        (new sync_manager(new fake_api_client()))->run_full_sync();

        $this->assertSame('1', get_config('local_wisa', 'initial_load_done'));
    }

    /**
     * Enable only requested fake-source tuples.
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
