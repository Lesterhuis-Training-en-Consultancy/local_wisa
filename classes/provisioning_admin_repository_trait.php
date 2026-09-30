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
 * Provisioning administration repository queries.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Provides bounded, filterable provisioning administration queries.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait provisioning_admin_repository_trait {
    /**
     * Return one deterministic administration page.
     *
     * @param string|null $status Optional provisioning status.
     * @param int $offset Zero-based record offset.
     * @param int $limit Positive page size.
     * @return \stdClass[] Provision rows keyed by ID.
     */
    public function get_admin_page(?string $status, int $offset, int $limit): array {
        global $DB;

        if ($offset < 0 || $limit <= 0) {
            throw new \coding_exception('Provisioning administration page bounds are invalid.');
        }
        [$select, $params] = self::admin_filter($status);
        return $DB->get_records_select(
            self::TABLE,
            $select,
            $params,
            'timemodified DESC, id DESC',
            '*',
            $offset,
            $limit
        );
    }

    /**
     * Count rows for an optional administration status filter.
     *
     * @param string|null $status Optional provisioning status.
     * @return int Matching row count.
     */
    public function count_admin_rows(?string $status): int {
        global $DB;

        [$select, $params] = self::admin_filter($status);
        return $DB->count_records_select(self::TABLE, $select, $params);
    }

    /**
     * Return every row ID for one validated status filter.
     *
     * @param string $status Provisioning status.
     * @return int[] Matching row IDs in deterministic order.
     */
    public function get_admin_ids(string $status): array {
        global $DB;

        [$select, $params] = self::admin_filter($status);
        return array_map('intval', $DB->get_fieldset_select(
            self::TABLE,
            'id',
            $select,
            $params,
            'timemodified DESC, id DESC'
        ));
    }

    /**
     * Build a safe status filter for administration queries.
     *
     * @param string|null $status Optional provisioning status.
     * @return array{0: string, 1: array} SQL condition and parameters.
     */
    private static function admin_filter(?string $status): array {
        if ($status === null || $status === '') {
            return ['1 = 1', []];
        }
        $validstatuses = [
            self::STATUS_PENDING,
            self::STATUS_RUNNING,
            self::STATUS_READY,
            self::STATUS_FALLBACK_READY,
            self::STATUS_FAILED,
        ];
        if (!in_array($status, $validstatuses, true)) {
            throw new \coding_exception('Provisioning administration status filter is invalid.');
        }
        return ['status = :status', ['status' => $status]];
    }
}
