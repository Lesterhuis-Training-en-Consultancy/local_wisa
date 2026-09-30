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
 * Provisioning administration repository test coverage.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/provisioning_repository_test_case.php');

/**
 * Verifies pageable, filterable provisioning administration queries.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_wisa
 * @covers     \local_wisa\provisioning_repository
 */
final class provisioning_admin_repository_test extends provisioning_repository_test_case {
    /**
     * Later records remain reachable through a deterministic second page.
     *
     * @return void
     */
    public function test_admin_page_reaches_second_page_and_counts_all_rows(): void {
        global $DB;

        // Given.
        $this->resetAfterTest();
        $transaction = $DB->start_delegated_transaction();
        $ids = [];
        for ($sequence = 1; $sequence <= 756; $sequence++) {
            $jobid = str_pad((string)$sequence, 32, '0', STR_PAD_LEFT);
            $ids[$sequence] = $DB->insert_record('local_wisa_course_provision', (object)[
                'sourcecomponent' => 'sissource_wisa',
                'courseidnumber' => 'C6-ADMIN-' . $sequence,
                'status' => provisioning_repository::STATUS_FAILED,
                'jobid' => $jobid,
                'tempshortname' => 'local_wisa_tmp_' . $jobid,
                'desiredshortname' => 'C6-ADMIN-' . $sequence,
                'desiredfullname' => 'Provisioning administration fixture',
                'categoryid' => 1,
                'templateid' => null,
                'courseid' => null,
                'startdate' => 0,
                'enddate' => 0,
                'executionuserid' => 2,
                'attempts' => 0,
                'tempprecallabsent' => 0,
                'followupqueued' => 0,
                'lasterror' => null,
                'timecreated' => $sequence,
                'timemodified' => $sequence,
            ]);
        }
        $transaction->allow_commit();
        $repository = new provisioning_repository();

        // When.
        $page = array_values($repository->get_admin_page(null, 200, 50));
        $total = $repository->count_admin_rows(null);

        // Then.
        $this->assertSame(50, count($page));
        $this->assertSame($ids[556], (int)$page[0]->id);
        $this->assertSame($ids[507], (int)$page[49]->id);
        $this->assertSame(756, $total);
    }

    /**
     * Every repository status is an allowlisted administration filter.
     *
     * @return void
     */
    public function test_admin_queries_accept_each_supported_status_and_return_matching_ids(): void {
        // Given.
        $this->resetAfterTest();
        $repository = new provisioning_repository();
        $statuses = [
            provisioning_repository::STATUS_PENDING,
            provisioning_repository::STATUS_RUNNING,
            provisioning_repository::STATUS_READY,
            provisioning_repository::STATUS_FALLBACK_READY,
            provisioning_repository::STATUS_FAILED,
        ];

        // When.
        foreach ($statuses as $status) {
            $page = $repository->get_admin_page($status, 0, 25);
            $ids = $repository->get_admin_ids($status);

            // Then.
            $this->assertSame([], $page);
            $this->assertSame([], $ids);
            $this->assertSame(0, $repository->count_admin_rows($status));
        }
    }

    /**
     * Status filters reject values outside the repository-owned allowlist.
     *
     * @return void
     */
    public function test_admin_queries_reject_an_invalid_status_filter(): void {
        // Given.
        $this->resetAfterTest();
        $repository = new provisioning_repository();

        // When.
        $this->expectException(\coding_exception::class);
        $repository->get_admin_page('invalid-status', 0, 25);

        // Then.
    }
}
