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
 * AthenaSoft record identities and course identifiers.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;

/**
 * Builds stable, safe record identities for AthenaSoft mapping.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait source_record_identity_trait {
    /**
     * Build a safe AthenaSoft row identity for missing-column warnings.
     *
     * @param array $row Raw AthenaSoft row.
     * @param string $recordtype Generic record type.
     * @return string Safe source-record identity.
     */
    private function record_identity(array $row, string $recordtype): string {
        if ($recordtype === 'course') {
            return 'course:' . (string)($row['NummerIdCursus'] ?? '');
        }
        if ($recordtype === 'user') {
            return 'user:' . (string)($row['userNummerId'] ?? '');
        }
        return $recordtype . ':' . (string)($row['NummerIdCursus'] ?? '') . '/' .
            (string)($row['userNummerId'] ?? '');
    }

    /**
     * Build a configured AthenaSoft course idnumber.
     *
     * @param int $courseid AthenaSoft course number ID.
     * @return string Generic course idnumber.
     */
    private function course_idnumber(int $courseid): string {
        return 'AS-' . (int)get_config('sissource_athenasoft', 'institution') . '-' . $courseid;
    }
}
