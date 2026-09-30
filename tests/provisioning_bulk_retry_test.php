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
 * Provisioning bulk retry test coverage.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/provisioning_service_test_case.php');

/**
 * Verifies batch retry outcome contracts.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_wisa
 * @covers     \local_wisa\provisioning_bulk_retry
 */
final class provisioning_bulk_retry_test extends provisioning_service_test_case {
    /**
     * Selected retries collapse duplicate IDs and expose only stable outcomes.
     *
     * @return void
     */
    public function test_retry_ids_returns_queued_already_queued_ineligible_and_rejected_outcomes(): void {
        global $DB;

        // Given.
        $this->resetAfterTest();
        $context = $this->create_context();
        $service = new provisioning_service();
        $repository = new provisioning_repository();
        $queued = $this->create_bulk_terminal_record(
            $service,
            $repository,
            $context,
            'C6-BULK-QUEUED',
            provisioning_repository::STATUS_FAILED,
            true
        );
        $ineligible = $this->create_bulk_terminal_record(
            $service,
            $repository,
            $context,
            'C6-BULK-INELIGIBLE',
            provisioning_repository::STATUS_READY,
            true
        );
        $failed = $this->create_bulk_terminal_record(
            $service,
            $repository,
            $context,
            'C6-BULK-FAILED',
            provisioning_repository::STATUS_FAILED,
            false
        );
        $pending = $repository->create_pending(
            'sissource_wisa',
            'C6-BULK-PENDING',
            'C6-BULK-PENDING',
            'C6 bulk pending',
            (int)$context['category']->id,
            null,
            null,
            0,
            0,
            $context['userid']
        );
        $missingid = 999999;
        $bulk = new provisioning_bulk_retry($service);

        // When.
        $outcomes = $bulk->retry_ids([
            (int)$queued->id,
            (int)$queued->id,
            (int)$ineligible->id,
            (int)$failed->id,
            (int)$pending->id,
            $missingid,
        ], $context['userid']);

        // Then.
        $this->assertSame([
            (int)$queued->id => 'queued',
            (int)$ineligible->id => 'ineligible',
            (int)$failed->id => 'already_queued',
            (int)$pending->id => 'ineligible',
            $missingid => 'rejected',
        ], $outcomes);
        $this->assertCount(2, \core\task\manager::get_adhoc_tasks(\local_wisa\task\provision_course_task::class));
        $this->assertSame(0, $DB->count_records('course', ['idnumber' => 'C6-BULK-QUEUED']));
    }

    /**
     * The filtered failed-ID set can be retried through the same batch service.
     *
     * @return void
     */
    public function test_retry_ids_retries_repository_filtered_failed_rows(): void {
        // Given.
        $this->resetAfterTest();
        $context = $this->create_context();
        $service = new provisioning_service();
        $repository = new provisioning_repository();
        $first = $this->create_bulk_terminal_record(
            $service,
            $repository,
            $context,
            'C6-FILTERED-ONE',
            provisioning_repository::STATUS_FAILED,
            true
        );
        $second = $this->create_bulk_terminal_record(
            $service,
            $repository,
            $context,
            'C6-FILTERED-TWO',
            provisioning_repository::STATUS_FAILED,
            true
        );

        // When.
        $outcomes = (new provisioning_bulk_retry())->retry_ids(
            $repository->get_admin_ids(provisioning_repository::STATUS_FAILED),
            $context['userid']
        );

        // Then.
        $this->assertSame('queued', $outcomes[(int)$first->id]);
        $this->assertSame('queued', $outcomes[(int)$second->id]);
    }

    /**
     * Bulk retry retains the capability enforcement of the existing retry path.
     *
     * @return void
     */
    public function test_retry_ids_requires_site_configuration_capability(): void {
        // Given.
        $this->resetAfterTest();
        $context = $this->create_context();
        $service = new provisioning_service();
        $repository = new provisioning_repository();
        $record = $this->create_bulk_terminal_record(
            $service,
            $repository,
            $context,
            'C6-BULK-CAPABILITY',
            provisioning_repository::STATUS_FAILED,
            true
        );
        self::setUser(self::getDataGenerator()->create_user());

        // When.
        $this->expectException(\required_capability_exception::class);
        (new provisioning_bulk_retry())->retry_ids([(int)$record->id], $context['userid']);

        // Then.
    }

    /**
     * Create one terminal record with or without its original queued task.
     *
     * @param provisioning_service $service Provisioning service.
     * @param provisioning_repository $repository Provisioning repository.
     * @param array $context Provisioning fixture context.
     * @param string $courseidnumber Source course identity.
     * @param string $status Terminal status.
     * @param bool $removetask Whether the original task is removed.
     * @return \stdClass Terminal provision record.
     */
    private function create_bulk_terminal_record(
        provisioning_service $service,
        provisioning_repository $repository,
        array $context,
        string $courseidnumber,
        string $status,
        bool $removetask
    ): \stdClass {
        global $DB;

        $record = $this->queue_record(
            $service,
            (int)$context['category']->id,
            $context['userid'],
            null,
            $courseidnumber
        );
        if ($removetask) {
            $DB->delete_records('task_adhoc', ['id' => $this->queued_task()->get_id()]);
        }
        return $repository->mark_terminal(
            (int)$repository->mark_running((int)$record->id)->id,
            $status,
            $status === provisioning_repository::STATUS_FAILED ? 'C6-BULK-FAILURE' : null
        );
    }
}
