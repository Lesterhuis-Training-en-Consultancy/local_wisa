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
 * Safe mapping metadata for the preview page.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Collects preview-safe metadata without fetching source records.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preview_mapping {
    /** @var array Generic targets shown when a source does not expose mapping metadata. */
    private const FALLBACK_TARGETS = [
        'course' => ['idnumber', 'shortname', 'fullname', 'startdate', 'enddate', 'category', 'templatekey'],
        'user' => [
            'idnumber', 'username', 'firstname', 'lastname', 'email', 'city', 'country', 'lang', 'description',
            'institution', 'department', 'phone1', 'phone2', 'address',
        ],
        'enrolment' => ['courseidnumber', 'useridnumber', 'role', 'startdate', 'enddate'],
        'unenrolment' => ['courseidnumber', 'useridnumber'],
    ];

    /**
     * Return mapping rows for the active source or the generic source contract.
     *
     * @param source_interface $source Active SIS source adapter.
     * @return array Safe mapping metadata rows.
     */
    public static function get_rows(source_interface $source): array {
        if (!$source instanceof mapping_provider_interface) {
            return self::get_fallback_rows();
        }

        $rows = [];
        foreach ($source->get_effective_map() as $recordtype => $targets) {
            if (!is_string($recordtype) || !is_array($targets)) {
                continue;
            }
            foreach ($targets as $target => $mapping) {
                if (!is_string($target) || !is_array($mapping) || !is_string($mapping['source'] ?? null)) {
                    continue;
                }
                $defaultsource = $mapping['defaultsource'] ?? null;
                $rows[] = [
                    'recordtype' => $recordtype,
                    'target' => $target,
                    'source' => $mapping['source'],
                    'defaultsource' => is_string($defaultsource) ? $defaultsource : null,
                    'overridden' => !empty($mapping['overridden']),
                    'fallback' => false,
                ];
            }
        }

        return $rows;
    }

    /**
     * Build rows from the generic source contract.
     *
     * @return array Generic mapping rows.
     */
    private static function get_fallback_rows(): array {
        $rows = [];
        foreach (self::FALLBACK_TARGETS as $recordtype => $targets) {
            foreach ($targets as $target) {
                $rows[] = [
                    'recordtype' => $recordtype,
                    'target' => $target,
                    'source' => $target,
                    'defaultsource' => null,
                    'overridden' => false,
                    'fallback' => true,
                ];
            }
        }
        return $rows;
    }
}
