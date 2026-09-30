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
 * Bulk provisioning retry service.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Queues independent retries and returns bounded per-row outcomes.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provisioning_bulk_retry {
    /** @var provisioning_service Provisioning retry service. */
    private $service;

    /**
     * Construct the bulk retry service.
     *
     * @param provisioning_service|null $service Provisioning retry service.
     * @return void
     */
    public function __construct(?provisioning_service $service = null) {
        $this->service = $service ?? new provisioning_service();
    }

    /**
     * Queue every eligible unique provision ID independently.
     *
     * @param int[] $ids Provision record IDs.
     * @param int $executionuserid Explicit retry task user.
     * @return array<int, string> Stable outcome keyed by provision ID.
     */
    public function retry_ids(array $ids, int $executionuserid): array {
        require_capability('moodle/site:config', \context_system::instance());
        $repository = new provisioning_repository();
        $outcomes = [];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            if ($id <= 0) {
                continue;
            }
            try {
                $record = $repository->get($id);
                if ($record->status !== provisioning_repository::STATUS_FAILED) {
                    $outcomes[$id] = 'ineligible';
                    continue;
                }
                if (!$this->service->is_retry_available($record)) {
                    $outcomes[$id] = 'already_queued';
                    continue;
                }
                $this->service->retry($id, $executionuserid);
                $outcomes[$id] = 'queued';
            } catch (\required_capability_exception $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $outcomes[$id] = 'rejected';
            }
        }
        return $outcomes;
    }
}
