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
 * PHPUnit WISA row fixtures for local_wisa.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\tests;


/**
 * Representative WISA rows used by the characterization tests.
 */
final class wisa_fixtures {
    /**
     * Course row as returned by the current MCVOD_C query.
     *
     * @param array $overrides Field overrides.
     * @return array
     */
    public static function course(array $overrides = []) {
        return array_merge([
            'KLAS_ID' => 'WISA-COURSE-001',
            'SHORTNAME' => 'WISA C001',
            'FULLNAME' => 'WISA Course 001',
            'BEGINDATUM' => '2026-09-01',
            'EINDDATUM' => '2027-06-30',
            'CATEGORY' => '',
        ], $overrides);
    }

    /**
     * Student/cursist row as returned by the current MCVOD_STUD query.
     *
     * @param array $overrides Field overrides.
     * @return array
     */
    public static function student(array $overrides = []) {
        return array_merge([
            'IDNUMBER' => 'STUDENT-001',
            'USERNAME' => 'Student001',
            'FIRSTNAME' => 'Sam',
            'LASTNAME' => 'Student',
            'EMAIL' => 'sam.student@example.org',
        ], $overrides);
    }

    /**
     * Teacher row as returned by the current MCVOD_LKR query.
     *
     * @param array $overrides Field overrides.
     * @return array
     */
    public static function teacher(array $overrides = []) {
        return array_merge([
            'IDNUMBER' => 'TEACHER-001',
            'USERNAME' => 'Teacher001',
            'FIRSTNAME' => 'Tina',
            'LASTNAME' => 'Teacher',
            'EMAIL' => 'tina.teacher@example.org',
        ], $overrides);
    }

    /**
     * Enrolment row as returned by the current MCVOD_INS query.
     *
     * @param array $overrides Field overrides.
     * @return array
     */
    public static function enrolment(array $overrides = []) {
        return array_merge([
            'KLAS_ID' => 'WISA-COURSE-001',
            'USERNAME' => 'STUDENT-001',
            'ROL' => 'student',
            'VAN' => '2026-09-01',
            'TOT' => '2027-06-30',
        ], $overrides);
    }

    /**
     * Unenrolment/leaver row as returned by the current MCVOD_UIT query.
     *
     * @param array $overrides Field overrides.
     * @return array
     */
    public static function unenrolment(array $overrides = []) {
        return array_merge([
            'KLAS_ID' => 'WISA-COURSE-001',
            'USERNAME' => 'STUDENT-001',
        ], $overrides);
    }
}
