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
 * AthenaSoft enrolment record mapping.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;


/**
 * Maps AthenaSoft enrolment and unenrolment rows.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait source_enrolment_mapping_trait {
    /**
     * Map raw student transport rows into unenrolment records.
     *
     * @param array $rows Raw student transport rows.
     * @return array Mapped unenrolment rows.
     */
    private function map_unenrolment_rows(array $rows): array {
        $mapped = [];
        foreach ($rows as $row) {
            $row = (array)$row;
            if (!$this->include_since($row)) {
                continue;
            }
            if ($this->is_empty($row['uitschrijvingsdatum'] ?? null)) {
                continue;
            }
            $mapped[] = $this->map_unenrolment($row);
        }
        return $mapped;
    }

    /**
     * Map enrolment rows.
     *
     * @param array $rows Raw rows.
     * @return array Mapped enrolment rows.
     */
    private function map_enrolment_rows(array $rows): array {
        $mapped = [];
        foreach ($rows as $row) {
            $row = (array)$row;
            if (!$this->include_since($row)) {
                continue;
            }
            $role = null;
            foreach (['cursist', 'leerkracht'] as $rolefield) {
                if (!$this->is_empty($row[$rolefield] ?? null)) {
                    $role = trim((string)$row[$rolefield]);
                    break;
                }
            }
            $mapped[] = $this->map_enrolment($row, $role);
        }

        return $mapped;
    }

    /**
     * Map one enrolment row.
     *
     * @param array $row Raw row.
     * @param string|null $role Default source role token.
     * @return array Generic enrolment record.
     */
    private function map_enrolment(array $row, ?string $role): array {
        $mapped = $this->mapper->map_record('enrolment', $row, $this->record_identity($row, 'enrolment'));
        $enrolment = [];
        if (array_key_exists('courseidnumber', $mapped)) {
            $enrolment['courseidnumber'] = $this->course_idnumber((int)$mapped['courseidnumber']);
        }
        if (array_key_exists('useridnumber', $mapped)) {
            $enrolment['useridnumber'] = (string)(int)$mapped['useridnumber'];
        }
        if (array_key_exists('role', $mapped)) {
            $enrolment['role'] = trim((string)$mapped['role']);
        } else if ($role !== null && !$this->mapper->is_override_configured('enrolment', 'role')) {
            $enrolment['role'] = $role;
        }
        foreach (['startdate', 'enddate'] as $target) {
            if (array_key_exists($target, $mapped)) {
                $enrolment[$target] = trim((string)$mapped[$target]);
            } else if (!$this->mapper->is_override_configured('enrolment', $target)) {
                $enrolment[$target] = '';
            }
        }

        return $enrolment;
    }

    /**
     * Map one unenrolment row.
     *
     * @param array $row Raw row.
     * @return array Generic unenrolment record.
     */
    private function map_unenrolment(array $row): array {
        $mapped = $this->mapper->map_record('unenrolment', $row, $this->record_identity($row, 'unenrolment'));
        $unenrolment = [];
        if (array_key_exists('courseidnumber', $mapped)) {
            $unenrolment['courseidnumber'] = $this->course_idnumber((int)$mapped['courseidnumber']);
        }
        if (array_key_exists('useridnumber', $mapped)) {
            $unenrolment['useridnumber'] = (string)(int)$mapped['useridnumber'];
        }
        return $unenrolment;
    }
}
