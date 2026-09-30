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
 * Provisioning diagnostic categories.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Maps failures to stable, bounded and non-sensitive operator diagnostics.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provisioning_diagnostic {
    /** Lock or concurrency failure. */
    public const CODE_LOCK_CONCURRENCY = 'PROVISIONING_LOCK_CONCURRENCY';

    /** Destination shortname collision. */
    public const CODE_SHORTNAME_COLLISION = 'PROVISIONING_SHORTNAME_COLLISION';

    /** Invalid source record data. */
    public const CODE_INVALID_SOURCE_DATA = 'PROVISIONING_INVALID_SOURCE_DATA';

    /** Mapping or validation failure. */
    public const CODE_MAPPING_VALIDATION = 'PROVISIONING_MAPPING_VALIDATION';

    /** Moodle course creation or restore failure. */
    public const CODE_MOODLE_COURSE_CREATE_RESTORE = 'PROVISIONING_MOODLE_COURSE_CREATE_RESTORE';

    /** Source or API failure. */
    public const CODE_SOURCE_API = 'PROVISIONING_SOURCE_API';

    /** Unknown bounded failure. */
    public const CODE_UNKNOWN = 'PROVISIONING_UNKNOWN_FAILURE';

    /**
     * Return a stable diagnostic code without retaining exception contents.
     *
     * @param \Throwable $exception Provisioning exception.
     * @return string Stable diagnostic code.
     */
    public static function from_exception(\Throwable $exception): string {
        if (
            $exception instanceof \coding_exception
            && strpos($exception->getMessage(), self::CODE_SHORTNAME_COLLISION) !== false
        ) {
            return self::CODE_SHORTNAME_COLLISION;
        }
        if (
            $exception instanceof \coding_exception
            && strpos($exception->getMessage(), 'already being') !== false
        ) {
            return self::CODE_LOCK_CONCURRENCY;
        }
        if ($exception instanceof \required_capability_exception) {
            return self::CODE_UNKNOWN;
        }
        if ($exception instanceof \UnexpectedValueException) {
            return self::CODE_INVALID_SOURCE_DATA;
        }
        if ($exception instanceof \JsonException) {
            return self::CODE_MAPPING_VALIDATION;
        }
        if ($exception instanceof \dml_missing_record_exception) {
            return self::CODE_MAPPING_VALIDATION;
        }
        if ($exception instanceof \dml_write_exception || $exception instanceof \moodle_exception) {
            return self::CODE_MOODLE_COURSE_CREATE_RESTORE;
        }
        if ($exception instanceof \RuntimeException) {
            return self::CODE_SOURCE_API;
        }
        return self::CODE_UNKNOWN;
    }

    /**
     * Format one diagnostic code for authorized administration display.
     *
     * @param string $code Stable diagnostic code.
     * @return string Stable code and safe operator reason.
     */
    public static function format(string $code): string {
        $reasons = [
            self::CODE_LOCK_CONCURRENCY => 'Another provisioning operation holds the required lock.',
            self::CODE_SHORTNAME_COLLISION => 'The desired and fallback course shortnames are already in use.',
            self::CODE_INVALID_SOURCE_DATA => 'The source course data is not valid for provisioning.',
            self::CODE_MAPPING_VALIDATION => 'The course mapping or provisioning configuration is invalid.',
            self::CODE_MOODLE_COURSE_CREATE_RESTORE => 'Moodle could not create or restore the destination course.',
            self::CODE_SOURCE_API => 'The source system did not provide usable provisioning data.',
            self::CODE_UNKNOWN => 'Provisioning failed for an unknown bounded reason.',
        ];
        if (!isset($reasons[$code])) {
            $code = self::CODE_UNKNOWN;
        }
        return $code . ': ' . $reasons[$code];
    }
}
