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
 * AthenaSoft course record mapping.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;

use local_wisa\logger;

/**
 * Maps AthenaSoft course rows to generic course records.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait source_course_mapping_trait {
    /**
     * Map raw AthenaSoft course rows.
     *
     * @param array $rows Raw course rows.
     * @return array Mapped course rows.
     */
    private function map_courses(array $rows): array {
        $mapped = [];
        $excluded = 0;
        foreach ($rows as $row) {
            $row = (array)$row;
            if (!$this->is_empty($row['afgelastingsdatum'] ?? null)) {
                $excluded++;
                continue;
            }
            $mapped[] = $this->map_course($row);
        }

        if ($excluded > 0) {
            logger::log(
                'source_fetch',
                'system',
                'athenasoft_courses',
                'info',
                'Excluded ' . $excluded . ' cancelled AthenaSoft course rows.'
            );
        }

        return $mapped;
    }

    /**
     * Map one course row.
     *
     * @param array $row Raw row.
     * @return array Generic course record.
     */
    private function map_course(array $row): array {
        $mapped = $this->mapper->map_record('course', $row, $this->record_identity($row, 'course'));
        $course = [];
        if (array_key_exists('idnumber', $mapped)) {
            $course['idnumber'] = $this->course_idnumber((int)$mapped['idnumber']);
        }
        if (array_key_exists('shortname', $mapped)) {
            $commercialname = trim((string)$mapped['shortname']);
            $ovname = trim((string)($row['ovNaam'] ?? ''));
            $shortname = $commercialname !== '' ? $commercialname : $ovname;
            if ($shortname === '') {
                $shortname = 'AS-' . (int)($mapped['idnumber'] ?? 0);
            }
            $course['shortname'] = $shortname;
        }
        if (array_key_exists('fullname', $mapped)) {
            $fullcommercialname = trim((string)$mapped['fullname']);
            $oname = trim((string)($row['oNaam'] ?? ''));
            $course['fullname'] = $fullcommercialname !== '' ? $fullcommercialname : $oname;
        }
        if (array_key_exists('startdate', $mapped)) {
            $course['startdate'] = $mapped['startdate'];
        }
        if (array_key_exists('enddate', $mapped)) {
            $enddatemoodle = $mapped['enddate'];
            $course['enddate'] = !$this->is_empty($enddatemoodle) ? $enddatemoodle : ($row['einddatum'] ?? '');
        }
        if (array_key_exists('category', $mapped)) {
            $course['category'] = $mapped['category'];
        }
        if (array_key_exists('templatekey', $mapped)) {
            $course['templatekey'] = trim((string)$mapped['templatekey']);
        }

        return $course;
    }
}
