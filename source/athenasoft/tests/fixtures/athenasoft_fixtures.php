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
 * PHPUnit AthenaSoft row fixtures for sissource_athenasoft.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft\tests;

/**
 * Representative anonymised AthenaSoft rows used by the contract tests.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class athenasoft_fixtures {
    /** Marker value used by fixture overrides to remove a key. */
    public const REMOVE_KEY = '__sissource_athenasoft_remove_key__';

    /**
     * Course row as returned by AthenaSoft script 5.
     *
     * @param array $overrides Field overrides. Use self::REMOVE_KEY as a value to remove a key.
     * @return array
     */
    public static function course(array $overrides = []): array {
        return self::apply_overrides([
            'periode' => 202620271,
            'studiegebied' => 316,
            'naam' => 'Europese hoofdtalen richtgraad 1 en 2',
            'opleidingID' => 1091,
            'oNaam' => 'Spaans Richtgraad 1 (ERK A2)',
            'opleidingsvariantId' => 10733,
            'ovNaam' => 'Spaans Richtgraad 1 (ERK A2)',
            'NummerIdCursus' => 56,
            'commercieleNaam' => 'Spaans 1.1 contact ',
            'ImvIdInAdx' => null,
            'aanvangsdatum' => '2026-09-07',
            'einddatum' => '2027-05-31',
            'dateEindMoodle' => '2027-06-07',
            'hash_planning' => 'fdde25d41fd1e9f5d1f52f4b048119382d07e169',
            'afgelastingsdatum' => null,
        ], $overrides);
    }

    /**
     * Student placement row as returned by AthenaSoft script 6.
     *
     * @param array $overrides Field overrides. Use self::REMOVE_KEY as a value to remove a key.
     * @return array
     */
    public static function student(array $overrides = []): array {
        return self::apply_overrides([
            'userNummerId' => 420623,
            'familienaam' => 'Ba',
            'voornaam' => 'Ad',
            'email' => 'ietsSpeciaal',
            'NummerIdCursus' => 137,
            'pasword' => '3121-08-18',
            'institution' => 130765,
            'extraMarkerCursistOfLeerkracht' => 'extraMarkerCursistOfLeerkracht',
            'createdOn' => '2026-05-28 11:00:17',
            'lastUpdatedOn' => '2026-06-12 10:52:39',
            'cursist' => 'cursist',
            'uitschrijvingsdatum' => null,
        ], $overrides);
    }

    /**
     * Teacher row as returned by AthenaSoft script 7.
     *
     * @param array $overrides Field overrides. Use self::REMOVE_KEY as a value to remove a key.
     * @return array
     */
    public static function teacher(array $overrides = []): array {
        return self::apply_overrides([
            'userNummerId' => 428449,
            'familienaam' => 'OP',
            'voornaam' => 'FR',
            'email' => 'ietsSpeciaal',
            'NummerIdCursus' => 137,
            'pasword' => '3143-01-21',
            'institution' => 130765,
            'extraMarkerCursistOfLeerkracht' => 'extraMarkerCursistOfLeerkracht',
            'createdOn' => '2026-06-15',
            'lastUpdatedOn' => '2026-06-15 16:11:06',
            'leerkracht' => 'leerkracht',
        ], $overrides);
    }

    /**
     * Apply fixture overrides and explicit key removals.
     *
     * @param array $row Base row.
     * @param array $overrides Field overrides.
     * @return array
     */
    private static function apply_overrides(array $row, array $overrides): array {
        foreach ($overrides as $key => $value) {
            if ($value === self::REMOVE_KEY) {
                unset($row[$key]);
            } else {
                $row[$key] = $value;
            }
        }

        return $row;
    }
}
