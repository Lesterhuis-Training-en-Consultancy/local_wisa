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
 * Comment 6 administration entrypoint tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Verifies secure provisioning and force-full administration wiring.
 *
 * @package    local_wisa
 * @category   test
 * @group      local_wisa
 * @coversNothing
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class comment6_admin_entrypoints_test extends \advanced_testcase {
    /**
     * Provisioning administration uses repository paging and bounded bulk POST actions.
     *
     * @return void
     */
    public function test_provisioning_entrypoint_wires_filter_paging_bulk_retry_and_diagnostics(): void {
        $source = file_get_contents(__DIR__ . '/../provisioning.php');

        foreach (
            [
                "optional_param('status'",
                "optional_param('page'",
                "optional_param_array('provisionids'",
                'count_admin_rows(',
                'get_admin_page(',
                'new paging_bar(',
                'provisioning_diagnostic::format(',
                'require_sesskey()',
                'local-wisa-provisioning-cards d-lg-none',
                'd-none d-lg-block',
                "get_string('provisioning_filter_status'",
                '$desktopaction =',
                '$mobileaction =',
                'new \\local_wisa\\provisioning_bulk_retry_queue()',
                'queue_selected(',
                'queue_filtered_failed(',
                'get_queued_retry_state(',
                'consume_result_for_user((int)$USER->id)',
                '$retryqueued',
                "get_string('provisioning_retry_queued'",
                '$bulkcontrolsdisabled',
                "'checked' => \$retryqueued ? 'checked' : null",
                "'disabled' => \$bulkcontrolsdisabled ? 'disabled' : null",
                "get_string('provisioning_bulk_single_flight'",
                "get_string('provisioning_bulk_wait'",
                "\$bulkcontrolsdisabled ? 'btn btn-secondary' : 'btn btn-primary'",
            ] as $required
        ) {
            $this->assertStringContainsString($required, $source);
        }
        $this->assertStringNotContainsString('retry_ids(', $source);
        $this->assertStringNotContainsString('get_admin_ids(', $source);
        $this->assertStringNotContainsString('new \\local_wisa\\provisioning_bulk_retry()', $source);
        $this->assertStringNotContainsString(
            "get_config('local_wisa', 'last_provisioning_bulk_retry_counts')",
            $source
        );
        $this->assertStringNotContainsString("\$DB->get_records('local_wisa_course_provision'", $source);
    }

    /**
     * Force-full administration queues background work behind sesskey validation.
     *
     * @return void
     */
    public function test_logs_entrypoint_wires_sesskey_protected_force_full_queue(): void {
        $source = file_get_contents(__DIR__ . '/../logs.php');

        $this->assertStringContainsString("optional_param('runforcefull'", $source);
        $this->assertStringContainsString('queue_force_full_sync((int)$USER->id)', $source);
        $this->assertStringContainsString('require_sesskey()', $source);
        $this->assertStringContainsString("'runforcefull' => 1", $source);
        $this->assertStringContainsString('$SESSION->local_wisa_action_notification', $source);
        $this->assertStringContainsString('$actionnotification', $source);
        $this->assertStringContainsString('unset($SESSION->local_wisa_action_notification)', $source);
        $this->assertStringNotContainsString('\\core\\notification::info', $source);
    }
}
