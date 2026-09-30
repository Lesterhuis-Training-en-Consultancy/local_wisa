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
 * Source-stream migration contract tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/source_stream_migration_conflict_setting_store.php');

/**
 * Verifies source-free migration from legacy aliases to tuple settings.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_stream_migrator_test extends \advanced_testcase {
    /**
     * A declared alias migrates only its enablement and raw positive watermark.
     *
     * @return void
     */
    public function test_migrate_component_persists_source_scoped_tuple_state_without_source_construction(): void {
        $this->resetAfterTest();
        set_config('legacy_users_enabled', 0, 'local_wisa');
        set_config('legacy_users_watermark', 1700000000, 'local_wisa');

        $migrated = source_stream_migrator::migrate_component('sissource_fixture', $this->registry());

        $this->assertTrue($migrated);
        $this->assertSame('0', get_config('sissource_fixture', 'stream_accounts_users_enabled'));
        $this->assertSame(1700000000, (int)get_config('sissource_fixture', 'stream_accounts_users_watermark'));
        $this->assertSame('1', get_config('sissource_fixture', 'stream_migration_v1_complete'));
        $this->assertFalse(get_config('sissource_fixture', 'stream_migration_v1_conflict'));
    }

    /**
     * A divergent tuple clears any stale canonical watermark before recording its conflict.
     *
     * @return void
     */
    public function test_migrate_component_clears_stale_watermark_for_a_conflicting_tuple(): void {
        $this->resetAfterTest();
        set_config('legacy_students_enabled', 1, 'local_wisa');
        set_config('legacy_teachers_enabled', 0, 'local_wisa');
        set_config('stream_enrolments_enrolments_watermark', 1700000000, 'sissource_fixture');

        $this->assertTrue(source_stream_migrator::migrate_component('sissource_fixture', $this->conflict_registry()));

        $this->assertSame('0', get_config('sissource_fixture', 'stream_enrolments_enrolments_enabled'));
        $this->assertFalse(get_config('sissource_fixture', 'stream_enrolments_enrolments_watermark'));
        $this->assertNotFalse(get_config('sissource_fixture', source_stream_migrator::CONFLICT_SNAPSHOT));
        $this->assertFalse(get_config('sissource_fixture', source_stream_migrator::COMPLETION_MARKER));
    }

    /**
     * A conflicting tuple retains the conservative pre-marker watermark minimum.
     *
     * @return void
     */
    public function test_migrate_component_preserves_pre_marker_watermark_minimum_for_conflicting_positive_aliases(): void {
        $this->resetAfterTest();
        set_config('legacy_students_enabled', 1, 'local_wisa');
        set_config('legacy_teachers_enabled', 0, 'local_wisa');
        set_config('legacy_students_watermark', 1700000300, 'local_wisa');
        set_config('legacy_teachers_watermark', 1700000000, 'local_wisa');
        set_config('stream_enrolments_enrolments_watermark', 1600000000, 'sissource_fixture');

        $this->assertTrue(source_stream_migrator::migrate_component('sissource_fixture', $this->conflict_registry()));

        $this->assertSame('0', get_config('sissource_fixture', 'stream_enrolments_enrolments_enabled'));
        $this->assertSame('1600000000', get_config(
            'sissource_fixture',
            'stream_enrolments_enrolments_watermark'
        ));
        $snapshot = json_decode((string)get_config('sissource_fixture', source_stream_migrator::CONFLICT_SNAPSHOT), true);
        $this->assertSame(1700000300, $snapshot[0]['watermarks']['enrol_students']);
        $this->assertSame(1700000000, $snapshot[0]['watermarks']['enrol_teachers']);
        $this->assertFalse(get_config('sissource_fixture', source_stream_migrator::COMPLETION_MARKER));
    }

    /**
     * Missing or invalid conflicting alias watermarks clear the canonical value.
     *
     * @dataProvider conflicting_invalid_watermark_provider
     * @param string|null $teacherwatermark Missing or invalid teacher watermark.
     * @return void
     */
    public function test_migrate_component_clears_and_verifies_canonical_watermark_for_missing_or_invalid_conflict_input(
        ?string $teacherwatermark
    ): void {
        $this->resetAfterTest();
        set_config('legacy_students_enabled', 1, 'local_wisa');
        set_config('legacy_teachers_enabled', 0, 'local_wisa');
        set_config('legacy_students_watermark', 1700000000, 'local_wisa');
        if ($teacherwatermark !== null) {
            set_config('legacy_teachers_watermark', $teacherwatermark, 'local_wisa');
        }
        set_config('stream_enrolments_enrolments_watermark', 1600000000, 'sissource_fixture');

        $this->assertTrue(source_stream_migrator::migrate_component('sissource_fixture', $this->conflict_registry()));

        $this->assertFalse(get_config('sissource_fixture', 'stream_enrolments_enrolments_watermark'));
        $snapshot = json_decode((string)get_config('sissource_fixture', source_stream_migrator::CONFLICT_SNAPSHOT), true);
        $this->assertSame(1700000000, $snapshot[0]['watermarks']['enrol_students']);
        $this->assertNull($snapshot[0]['watermarks']['enrol_teachers']);
    }

    /**
     * A failed final completion-marker verification restores the whole initial migration state.
     *
     * @return void
     */
    public function test_migrate_component_rolls_back_when_completion_marker_verification_fails(): void {
        $state = [
            'local_wisa/legacy_users_enabled' => '0',
            'local_wisa/legacy_users_watermark' => '1700000000',
            'sissource_wisa/stream_accounts_users_enabled' => '1',
            'sissource_wisa/stream_accounts_users_watermark' => '1600000000',
            'sissource_wisa/' . source_stream_migrator::CONFLICT_SNAPSHOT => 'pre-call-conflict',
        ];
        $store = new \local_wisa\tests\source_stream_migration_conflict_setting_store(
            $state,
            'verify-completion-marker'
        );

        try {
            source_stream_migrator::migrate_component('sissource_wisa', $this->registry(), $store);
            $this->fail('Completion marker verification should fail.');
        } catch (\coding_exception $exception) {
            $this->assertSame(
                'Coding error detected, it must be fixed by a programmer: Unable to persist source stream migration state.',
                $exception->getMessage()
            );
        }

        $this->assertSame('1', $store->get('sissource_wisa', 'stream_accounts_users_enabled'));
        $this->assertSame('1600000000', $store->get('sissource_wisa', 'stream_accounts_users_watermark'));
        $this->assertSame('pre-call-conflict', $store->get(
            'sissource_wisa',
            source_stream_migrator::CONFLICT_SNAPSHOT
        ));
        $this->assertNull($store->get('sissource_wisa', source_stream_migrator::COMPLETION_MARKER));
        $this->assertSame(1, $store->begincount);
        $this->assertSame(0, $store->commitcount);
        $this->assertSame(1, $store->rollbackcount);
        $this->assertSame(
            'write:sissource_wisa:stream_migration_v1_complete:1',
            $store->operations[count($store->operations) - 1]
        );
    }

    /**
     * Return missing, nonpositive, and malformed conflict watermark inputs.
     *
     * @return array
     */
    public static function conflicting_invalid_watermark_provider(): array {
        return [
            'missing' => [null],
            'nonpositive' => ['0'],
            'malformed' => ['not-a-timestamp'],
        ];
    }

    /**
     * Return a valid source registry with one legacy alias.
     *
     * @return array
     */
    private function registry(): array {
        return [
            'accounts' => [
                'key' => 'accounts',
                'label' => 'stream_accounts',
                'transport' => 'query_accounts',
                'phases' => ['users'],
                'legacyaliases' => [
                    'users' => [[
                        'name' => 'legacy_users',
                        'enabledby' => [[
                            'component' => 'local_wisa',
                            'key' => 'legacy_users_enabled',
                            'absentdefault' => true,
                        ], ],
                        'watermark' => [
                            'component' => 'local_wisa',
                            'key' => 'legacy_users_watermark',
                        ],
                    ], ],
                ],
                'defaultenabled' => ['users' => true],
                'watermarkmode' => ['users' => 'delta'],
                'healthcheckphase' => 'users',
            ],
        ];
    }

    /**
     * Return a valid registry with divergent enrolment aliases.
     *
     * @return array
     */
    private function conflict_registry(): array {
        return [
            'enrolments' => [
                'key' => 'enrolments',
                'label' => 'stream_enrolments',
                'transport' => 'query_enrolments',
                'phases' => ['enrolments'],
                'legacyaliases' => [
                    'enrolments' => [
                        [
                            'name' => 'enrol_students',
                            'enabledby' => [[
                                'component' => 'local_wisa',
                                'key' => 'legacy_students_enabled',
                                'absentdefault' => true,
                            ], ],
                            'watermark' => [
                                'component' => 'local_wisa',
                                'key' => 'legacy_students_watermark',
                            ],
                        ],
                        [
                            'name' => 'enrol_teachers',
                            'enabledby' => [[
                                'component' => 'local_wisa',
                                'key' => 'legacy_teachers_enabled',
                                'absentdefault' => true,
                            ], ],
                            'watermark' => [
                                'component' => 'local_wisa',
                                'key' => 'legacy_teachers_watermark',
                            ],
                        ],
                    ],
                ],
                'defaultenabled' => ['enrolments' => true],
                'watermarkmode' => ['enrolments' => 'delta'],
                'healthcheckphase' => 'enrolments',
            ],
        ];
    }
}
