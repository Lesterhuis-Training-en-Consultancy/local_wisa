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
 * Generic SIS row fixtures for local_wisa tests.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\tests;

/**
 * Representative generic SIS rows used by sync tests.
 */
final class sis_fixtures {
    /**
     * Course row.
     *
     * @param array $overrides Field overrides.
     * @return array
     */
    public static function course(array $overrides = []) {
        return array_merge([
            'idnumber' => 'WISA-COURSE-001',
            'shortname' => 'WISA C001',
            'fullname' => 'WISA Course 001',
            'startdate' => '2026-09-01',
            'enddate' => '2027-06-30',
            'category' => '',
        ], self::normalise_course_overrides($overrides));
    }

    /**
     * Student row.
     *
     * @param array $overrides Field overrides.
     * @return array
     */
    public static function student(array $overrides = []) {
        return array_merge([
            'idnumber' => 'STUDENT-001',
            'username' => 'Student001',
            'firstname' => 'Sam',
            'lastname' => 'Student',
            'email' => 'sam.student@example.org',
        ], self::normalise_user_overrides($overrides));
    }

    /**
     * Teacher row.
     *
     * @param array $overrides Field overrides.
     * @return array
     */
    public static function teacher(array $overrides = []) {
        return array_merge([
            'idnumber' => 'TEACHER-001',
            'username' => 'Teacher001',
            'firstname' => 'Tina',
            'lastname' => 'Teacher',
            'email' => 'tina.teacher@example.org',
        ], self::normalise_user_overrides($overrides));
    }

    /**
     * Enrolment row.
     *
     * @param array $overrides Field overrides.
     * @return array
     */
    public static function enrolment(array $overrides = []) {
        return array_merge([
            'courseidnumber' => 'WISA-COURSE-001',
            'useridnumber' => 'STUDENT-001',
            'role' => 'student',
            'startdate' => '2026-09-01',
            'enddate' => '2027-06-30',
        ], self::normalise_enrolment_overrides($overrides));
    }

    /**
     * Unenrolment/leaver row.
     *
     * @param array $overrides Field overrides.
     * @return array
     */
    public static function unenrolment(array $overrides = []) {
        return array_merge([
            'courseidnumber' => 'WISA-COURSE-001',
            'useridnumber' => 'STUDENT-001',
        ], self::normalise_unenrolment_overrides($overrides));
    }

    /**
     * Translate legacy fixture override keys to generic course keys.
     *
     * @param array $overrides Field overrides.
     * @return array
     */
    private static function normalise_course_overrides(array $overrides): array {
        return self::rename($overrides, [
            'KLAS_ID' => 'idnumber',
            'SHORTNAME' => 'shortname',
            'FULLNAME' => 'fullname',
            'BEGINDATUM' => 'startdate',
            'EINDDATUM' => 'enddate',
            'CATEGORY' => 'category',
        ]);
    }

    /**
     * Translate legacy fixture override keys to generic user keys.
     *
     * @param array $overrides Field overrides.
     * @return array
     */
    private static function normalise_user_overrides(array $overrides): array {
        return self::rename($overrides, [
            'IDNUMBER' => 'idnumber',
            'USERNAME' => 'username',
            'FIRSTNAME' => 'firstname',
            'LASTNAME' => 'lastname',
            'EMAIL' => 'email',
        ]);
    }

    /**
     * Translate legacy fixture override keys to generic enrolment keys.
     *
     * @param array $overrides Field overrides.
     * @return array
     */
    private static function normalise_enrolment_overrides(array $overrides): array {
        return self::rename($overrides, [
            'KLAS_ID' => 'courseidnumber',
            'USERNAME' => 'useridnumber',
            'ROL' => 'role',
            'VAN' => 'startdate',
            'TOT' => 'enddate',
        ]);
    }

    /**
     * Translate legacy fixture override keys to generic unenrolment keys.
     *
     * @param array $overrides Field overrides.
     * @return array
     */
    private static function normalise_unenrolment_overrides(array $overrides): array {
        return self::rename($overrides, [
            'KLAS_ID' => 'courseidnumber',
            'USERNAME' => 'useridnumber',
        ]);
    }

    /**
     * Rename keys.
     *
     * @param array $values Original values.
     * @param array $map Old key => new key.
     * @return array
     */
    private static function rename(array $values, array $map): array {
        foreach ($map as $old => $new) {
            if (array_key_exists($old, $values)) {
                $values[$new] = $values[$old];
                unset($values[$old]);
            }
        }
        return $values;
    }
}
