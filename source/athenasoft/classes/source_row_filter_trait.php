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
 * AthenaSoft raw row filtering rules.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;


/**
 * Filters and sanitises AthenaSoft source rows.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait source_row_filter_trait {
    /**
     * Decide whether a row passes the since filter.
     *
     * @param array $row Raw row.
     * @return bool True when row should be included.
     */
    private function include_since(array $row): bool {
        if ($this->is_empty($this->effectivesince)) {
            return true;
        }

        $lastupdated = $row['lastUpdatedOn'] ?? null;
        if (!$this->is_empty($lastupdated)) {
            return (string)$lastupdated >= (string)$this->effectivesince;
        }

        $created = $row['createdOn'] ?? null;
        if (!$this->is_empty($created)) {
            return (string)$created >= (string)$this->effectivesince;
        }

        return true;
    }

    /**
     * Check whether a value is null or an empty string after trimming.
     *
     * @param mixed $value Value to inspect.
     * @return bool True when empty by AthenaSoft mapping rules.
     */
    private function is_empty($value): bool {
        return $value === null || trim((string)$value) === '';
    }
}
