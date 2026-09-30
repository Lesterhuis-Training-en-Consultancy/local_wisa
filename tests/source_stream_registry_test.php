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
 * Source-stream registry validation tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Verifies source-free stream-descriptor validation.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_stream_registry_test extends \advanced_testcase {
    /**
     * A valid static registry remains unchanged when validation is repeated.
     *
     * @return void
     */
    public function test_valid_static_registry_is_deterministic_and_source_free(): void {
        $registry = $this->valid_registry();
        $reordered = $registry;
        $reordered['student_accounts'] = array_reverse($reordered['student_accounts'], true);

        $first = source_stream_registry::validate($registry);
        $second = source_stream_registry::validate($registry);
        $third = source_stream_registry::validate($reordered);

        $this->assertSame($registry, $first);
        $this->assertSame($first, $second);
        $this->assertSame($reordered, $third);
    }

    /**
     * Descriptor keys must be unique and match their associative registry keys.
     *
     * @return void
     */
    public function test_registry_rejects_duplicate_descriptor_keys(): void {
        $registry = $this->valid_registry();
        $registry['staff_accounts'] = $registry['student_accounts'];

        $this->expectException(\coding_exception::class);
        source_stream_registry::validate($registry);
    }

    /**
     * Every descriptor phase must be supported and fully configured.
     *
     * @return void
     */
    public function test_registry_rejects_invalid_phase_and_missing_phase_configuration(): void {
        $registry = $this->valid_registry();
        $registry['student_accounts']['phases'] = ['users', 'unknown'];

        $this->expectException(\coding_exception::class);
        source_stream_registry::validate($registry);
    }

    /**
     * Aliases must not collide and every enabled condition must be complete.
     *
     * @return void
     */
    public function test_registry_rejects_alias_collisions_and_malformed_conditions(): void {
        $registry = $this->valid_registry();
        $registry['staff_accounts'] = $this->valid_descriptor('staff_accounts', 'query_staff');
        $registry['staff_accounts']['legacyaliases']['users'][0]['name'] = 'students';

        $this->expectException(\coding_exception::class);
        source_stream_registry::validate($registry);
    }

    /**
     * Enabled-by conditions must be non-empty complete conjunctions.
     *
     * @return void
     */
    public function test_registry_rejects_empty_enabled_by_conjunction(): void {
        $registry = $this->valid_registry();
        $registry['student_accounts']['legacyaliases']['users'][0]['enabledby'] = [];

        $this->expectException(\coding_exception::class);
        source_stream_registry::validate($registry);
    }

    /**
     * Invalid watermark modes and health-check phases cannot reach runtime.
     *
     * @return void
     */
    public function test_registry_rejects_invalid_watermark_mode_and_health_check_phase(): void {
        $registry = $this->valid_registry();
        $registry['student_accounts']['watermarkmode']['users'] = 'incremental';

        $this->expectException(\coding_exception::class);
        source_stream_registry::validate($registry);
    }

    /**
     * The health-check phase must be one of the descriptor phases.
     *
     * @return void
     */
    public function test_registry_rejects_health_check_phase_outside_descriptor_phases(): void {
        $registry = $this->valid_registry();
        $registry['student_accounts']['healthcheckphase'] = 'courses';

        $this->expectException(\coding_exception::class);
        source_stream_registry::validate($registry);
    }

    /**
     * Required descriptor fields and phase maps reject invalid independent values.
     *
     * @dataProvider invalid_descriptor_schema_provider
     * @param string $field Descriptor field to replace.
     * @param mixed $value Invalid replacement value.
     * @return void
     */
    public function test_registry_rejects_invalid_required_descriptor_fields(string $field, $value): void {
        $registry = $this->valid_registry();
        $registry['student_accounts'][$field] = $value;

        $this->expectException(\coding_exception::class);
        source_stream_registry::validate($registry);
    }

    /**
     * Return independent invalid required descriptor field cases.
     *
     * @return array
     */
    public static function invalid_descriptor_schema_provider(): array {
        return [
            'invalid key' => ['key', 'Student Accounts'],
            'empty label' => ['label', ''],
            'empty transport' => ['transport', ''],
            'legacy alias phase map mismatch' => ['legacyaliases', []],
            'phase map mismatch' => ['defaultenabled', []],
        ];
    }

    /**
     * Return a complete valid descriptor registry.
     *
     * @return array
     */
    private function valid_registry(): array {
        return [
            'student_accounts' => $this->valid_descriptor('student_accounts', 'query_students'),
        ];
    }

    /**
     * Return one valid source-stream descriptor.
     *
     * @param string $key Descriptor key.
     * @param string $transport Adapter-local transport.
     * @return array
     */
    private function valid_descriptor(string $key, string $transport): array {
        return [
            'key' => $key,
            'label' => 'stream_' . $key,
            'transport' => $transport,
            'phases' => ['users'],
            'legacyaliases' => [
                'users' => [[
                    'name' => 'students',
                    'enabledby' => [[
                        'component' => 'local_wisa',
                        'key' => 'enable_students',
                        'absentdefault' => true,
                    ], ],
                    'watermark' => [
                        'component' => 'local_wisa',
                        'key' => 'wm_students',
                    ],
                ], ],
            ],
            'defaultenabled' => ['users' => true],
            'watermarkmode' => ['users' => 'delta'],
            'healthcheckphase' => 'users',
        ];
    }
}
