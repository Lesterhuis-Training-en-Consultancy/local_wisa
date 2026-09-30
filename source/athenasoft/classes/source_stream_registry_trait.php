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
 * AthenaSoft static source stream registry.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;

/**
 * Declares AthenaSoft's source-free stream registry and migration aliases.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait source_stream_registry_trait {
    /**
     * Return AthenaSoft's source-free stream registry.
     *
     * @return array Static stream descriptors keyed by stream key.
     */
    public static function get_stream_registry(): array {
        return [
            'courses' => self::descriptor(
                'courses',
                'stream_courses',
                'script_courses',
                ['courses'],
                ['courses' => 'full'],
                ['courses' => 'courses']
            ),
            'placements' => self::descriptor(
                'placements',
                'stream_placements',
                'script_placements',
                ['users', 'enrolments', 'unenrolments'],
                ['users' => 'delta', 'enrolments' => 'delta', 'unenrolments' => 'delta'],
                ['users' => 'students', 'enrolments' => 'enrol_students', 'unenrolments' => 'unenrolments']
            ),
            'linked_teachers' => self::descriptor(
                'linked_teachers',
                'stream_linked_teachers',
                'script_teachers',
                ['users', 'enrolments'],
                ['users' => 'delta', 'enrolments' => 'delta'],
                ['users' => 'teachers', 'enrolments' => 'enrol_teachers']
            ),
        ];
    }

    /**
     * Build one static stream descriptor with its legacy migration aliases.
     *
     * @param string $key Stream key.
     * @param string $label Language string key.
     * @param string $transport AthenaSoft script transport.
     * @param array $phases Stream phases.
     * @param array $watermarkmodes Watermark mode by phase.
     * @param array $aliases Legacy alias by phase.
     * @return array Static stream descriptor.
     */
    private static function descriptor(
        string $key,
        string $label,
        string $transport,
        array $phases,
        array $watermarkmodes,
        array $aliases
    ): array {
        $legacyaliases = [];
        $defaultenabled = [];
        foreach ($phases as $phase) {
            $alias = $aliases[$phase];
            $legacyaliases[$phase] = [[
                'name' => $alias,
                'enabledby' => self::legacy_enabled_by($alias),
                'watermark' => [
                    'component' => 'sissource_athenasoft',
                    'key' => self::legacy_watermark($alias),
                ],
            ], ];
            $defaultenabled[$phase] = true;
        }
        return [
            'key' => $key,
            'label' => $label,
            'transport' => $transport,
            'phases' => $phases,
            'legacyaliases' => $legacyaliases,
            'defaultenabled' => $defaultenabled,
            'watermarkmode' => $watermarkmodes,
            'healthcheckphase' => $phases[0],
        ];
    }

    /**
     * Return the legacy enable conditions for an alias.
     *
     * @param string $alias Legacy stream alias.
     * @return array Enable conditions.
     */
    private static function legacy_enabled_by(string $alias): array {
        if ($alias === 'enrol_students' || $alias === 'enrol_teachers') {
            return self::legacy_enrolment_conditions($alias);
        }
        return [[
            'component' => 'local_wisa',
            'key' => 'enable_' . $alias,
            'absentdefault' => true,
        ], ];
    }

    /**
     * Return the shared and role-specific legacy enrolment conditions.
     *
     * @param string $key Role-specific enable setting.
     * @return array Enable conditions.
     */
    private static function legacy_enrolment_conditions(string $key): array {
        return [[
            'component' => 'local_wisa',
            'key' => 'enable_enrolments',
            'absentdefault' => true,
        ], [
            'component' => 'local_wisa',
            'key' => $key,
            'absentdefault' => true,
        ], ];
    }

    /**
     * Return the legacy watermark key for an alias.
     *
     * @param string $alias Legacy stream alias.
     * @return string Legacy watermark key.
     */
    private static function legacy_watermark(string $alias): string {
        return $alias === 'courses' ? 'wm_courses' : 'wm_' . $alias;
    }
}
