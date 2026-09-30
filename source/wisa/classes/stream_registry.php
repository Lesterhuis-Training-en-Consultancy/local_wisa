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
 * Static WISA source-stream descriptor registry.
 *
 * @package    sissource_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_wisa;

use local_wisa\source_stream_registry as registry_validator;

/**
 * Provides WISA's source-free stream descriptors.
 *
 * @package    sissource_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class stream_registry {
    /**
     * Return WISA's validated static stream registry.
     *
     * @return array WISA source-stream descriptors.
     */
    public static function get(): array {
        return registry_validator::validate([
            'courses' => self::descriptor(
                'courses',
                'stream_courses',
                'query_courses',
                'courses',
                [[
                    'name' => 'courses',
                    'enabledby' => [self::enabled_by('enable_courses')],
                    'watermark' => self::watermark('wm_courses'),
                ], ]
            ),
            'student_accounts' => self::descriptor(
                'student_accounts',
                'stream_student_accounts',
                'query_students',
                'users',
                [[
                    'name' => 'students',
                    'enabledby' => [self::enabled_by('enable_students')],
                    'watermark' => self::watermark('wm_students'),
                ], ]
            ),
            'teacher_accounts' => self::descriptor(
                'teacher_accounts',
                'stream_teacher_accounts',
                'query_teachers',
                'users',
                [[
                    'name' => 'teachers',
                    'enabledby' => [self::enabled_by('enable_teachers')],
                    'watermark' => self::watermark('wm_teachers'),
                ], ]
            ),
            'enrolments' => self::descriptor(
                'enrolments',
                'stream_enrolments',
                'query_enrolments',
                'enrolments',
                [
                    [
                        'name' => 'enrol_students',
                        'enabledby' => [self::enabled_by('enable_enrolments'), self::enabled_by('enrol_students')],
                        'watermark' => self::watermark('wm_enrol_students'),
                    ],
                    [
                        'name' => 'enrol_teachers',
                        'enabledby' => [self::enabled_by('enable_enrolments'), self::enabled_by('enrol_teachers')],
                        'watermark' => self::watermark('wm_enrol_teachers'),
                    ],
                ]
            ),
            'unenrolments' => self::descriptor(
                'unenrolments',
                'stream_unenrolments',
                'query_unenrolments',
                'unenrolments',
                [[
                    'name' => 'unenrolments',
                    'enabledby' => [self::enabled_by('enable_unenrolments')],
                    'watermark' => self::watermark('wm_unenrolments'),
                ], ]
            ),
        ]);
    }

    /**
     * Build one WISA delta stream descriptor.
     *
     * @param string $key Stream key.
     * @param string $label Language-string identifier.
     * @param string $transport WISA query transport.
     * @param string $phase Generic stream phase.
     * @param array $aliases Legacy aliases for the phase.
     * @return array Source-stream descriptor.
     */
    private static function descriptor(string $key, string $label, string $transport, string $phase, array $aliases): array {
        return [
            'key' => $key,
            'label' => $label,
            'transport' => $transport,
            'phases' => [$phase],
            'legacyaliases' => [$phase => $aliases],
            'defaultenabled' => [$phase => true],
            'watermarkmode' => [$phase => 'delta'],
            'healthcheckphase' => $phase,
        ];
    }

    /**
     * Build one legacy enablement condition.
     *
     * @param string $key Legacy local_wisa setting name.
     * @return array Legacy enablement condition.
     */
    private static function enabled_by(string $key): array {
        return [
            'component' => 'local_wisa',
            'key' => $key,
            'absentdefault' => true,
        ];
    }

    /**
     * Build one legacy parent watermark location.
     *
     * @param string $key Legacy local_wisa watermark setting name.
     * @return array Legacy watermark location.
     */
    private static function watermark(string $key): array {
        return [
            'component' => 'local_wisa',
            'key' => $key,
        ];
    }
}
