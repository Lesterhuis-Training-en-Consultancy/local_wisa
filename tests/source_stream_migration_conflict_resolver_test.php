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
 * WISA source-stream migration conflict resolution tests.
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
 * Verifies source-free resolution of the WISA enrolments migration conflict.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_stream_migration_conflict_resolver_test extends \advanced_testcase {
    /**
     * Enabling the only permitted whole tuple writes its completion marker last.
     *
     * @return void
     */
    public function test_domain_resolution_requires_only_choice_not_capability_or_sesskey(): void {
        [$resolver, $store] = $this->resolver(self::conflictstate(['sissource_athenasoft' => '1']));

        $this->assertTrue($resolver->has_unresolved_wisa_enrolments());
        $this->assertTrue($resolver->resolve_wisa_enrolments(
            source_stream_migration_conflict_resolver::CHOICE_ENABLE_ENROLMENTS
        ));

        $this->assertSame('1', $store->get('sissource_wisa', 'stream_enrolments_enrolments_enabled'));
        $this->assertSame('1700000000', $store->get('sissource_wisa', 'stream_enrolments_enrolments_watermark'));
        $this->assertSame('1', $store->get('sissource_wisa', source_stream_migrator::COMPLETION_MARKER));
        $this->assertNull($store->get('sissource_wisa', source_stream_migrator::CONFLICT_SNAPSHOT));
        $this->assertNull($store->get('local_wisa', 'enable_enrolments'));
        $this->assertNull($store->get('sissource_athenasoft', 'wm_enrol_teachers'));
        $this->assertSame('preserved', $store->get('local_wisa', 'rolemap'));
        $this->assertSame([
            'write:sissource_wisa:stream_enrolments_enrolments_enabled:0',
            'write:sissource_wisa:stream_enrolments_enrolments_enabled:1',
            'write:sissource_wisa:stream_enrolments_enrolments_watermark:1700000000',
            'write:sissource_wisa:stream_migration_v1_complete:1',
            'delete:sissource_wisa:stream_migration_v1_conflict',
        ], array_slice($store->operations, 0, 5));
    }

    /**
     * Keeping the whole tuple disabled completes migration without role selection.
     *
     * @return void
     */
    public function test_keep_disabled_choice_completes_the_whole_tuple(): void {
        [$resolver, $store] = $this->resolver(self::conflictstate(['sissource_athenasoft' => '1']));

        $this->assertTrue($resolver->resolve_wisa_enrolments(
            source_stream_migration_conflict_resolver::CHOICE_KEEP_ENROLMENTS_DISABLED
        ));

        $this->assertSame('0', $store->get('sissource_wisa', 'stream_enrolments_enrolments_enabled'));
        $this->assertSame('1', $store->get('sissource_wisa', source_stream_migrator::COMPLETION_MARKER));
        $this->assertNull($store->get('sissource_wisa', source_stream_migrator::CONFLICT_SNAPSHOT));
    }

    /**
     * The default persistence boundary resolves without capability or sesskey input.
     *
     * @return void
     */
    public function test_default_store_resolution_is_capability_independent(): void {
        $this->resetAfterTest();
        set_config('stream_enrolments_enrolments_enabled', 0, 'sissource_wisa');
        set_config(source_stream_migrator::CONFLICT_SNAPSHOT, $this->conflictsnapshot(), 'sissource_wisa');
        set_config(source_stream_migrator::COMPLETION_MARKER, 1, 'sissource_athenasoft');

        $resolver = new source_stream_migration_conflict_resolver();
        $this->assertTrue($resolver->resolve_wisa_enrolments(
            source_stream_migration_conflict_resolver::CHOICE_ENABLE_ENROLMENTS
        ));

        $this->assertSame('1', get_config('sissource_wisa', 'stream_enrolments_enrolments_enabled'));
        $this->assertSame('1', get_config('sissource_wisa', source_stream_migrator::COMPLETION_MARKER));
        $this->assertFalse(get_config('sissource_wisa', source_stream_migrator::CONFLICT_SNAPSHOT));
    }

    /**
     * Invalid or unusable snapshots preserve all migration recovery state.
     *
     * @dataProvider unusable_snapshot_provider
     * @param string|null $snapshot Invalid persisted snapshot.
     * @return void
     */
    public function test_invalid_choice_or_unusable_snapshot_preserves_recovery_state(?string $snapshot): void {
        $state = self::conflictstate([]);
        if ($snapshot === null) {
            unset($state['sissource_wisa/stream_migration_v1_conflict']);
        } else {
            $state['sissource_wisa/stream_migration_v1_conflict'] = $snapshot;
        }
        [$resolver, $store] = $this->resolver($state);

        $choice = $snapshot === $this->conflictsnapshot()
            ? 'enable_enrolments_by_role'
            : source_stream_migration_conflict_resolver::CHOICE_ENABLE_ENROLMENTS;
        $this->assertFalse($resolver->resolve_wisa_enrolments($choice));

        $this->assertSame('0', $store->get('sissource_wisa', 'stream_enrolments_enrolments_enabled'));
        $this->assertNull($store->get('sissource_wisa', source_stream_migrator::COMPLETION_MARKER));
        $this->assertSame($snapshot, $store->get('sissource_wisa', source_stream_migrator::CONFLICT_SNAPSHOT));
        $this->assertSame('1', $store->get('local_wisa', 'enable_enrolments'));
        $this->assertSame($snapshot === $this->conflictsnapshot(), $resolver->has_unresolved_wisa_enrolments());
    }

    /**
     * Return missing, malformed, mismatched, and invalid-choice snapshots.
     *
     * @return array
     */
    public static function unusable_snapshot_provider(): array {
        $nonpositive = json_decode(self::conflictsnapshot(), true);
        $nonpositive[0]['watermarks']['enrol_students'] = 0;
        return [
            'missing' => [null],
            'malformed' => ['{invalid'],
            'incomplete payload' => [json_encode([[
                'component' => 'sissource_wisa',
                'stream' => 'enrolments',
                'phase' => 'enrolments',
                'status' => 'requires_admin_resolution',
                'aliases' => ['enrol_students' => true],
            ], ]), ],
            'nonpositive watermark' => [json_encode($nonpositive)],
            'mismatched tuple' => [json_encode([[
                'component' => 'sissource_wisa',
                'stream' => 'courses',
                'phase' => 'courses',
                'status' => 'requires_admin_resolution',
            ], ]), ],
            'invalid choice' => [self::conflictsnapshot()],
        ];
    }

    /**
     * A valid conflict overrides a tampered canonical tuple and starts resolution disabled.
     *
     * @return void
     */
    public function test_enabled_canonical_tuple_rejects_stale_conflict_snapshot(): void {
        $state = self::conflictstate([]);
        $state['sissource_wisa/stream_enrolments_enrolments_enabled'] = '1';
        [$resolver, $store] = $this->resolver($state);

        $this->assertTrue($resolver->has_unresolved_wisa_enrolments());
        $this->assertTrue($resolver->resolve_wisa_enrolments(
            source_stream_migration_conflict_resolver::CHOICE_ENABLE_ENROLMENTS
        ));
        $this->assertSame('1', $store->get('sissource_wisa', 'stream_enrolments_enrolments_enabled'));
        $this->assertSame('1', $store->get('sissource_wisa', source_stream_migrator::COMPLETION_MARKER));
        $this->assertNull($store->get('sissource_wisa', source_stream_migrator::CONFLICT_SNAPSHOT));
        $this->assertSame('write:sissource_wisa:stream_enrolments_enrolments_enabled:0', $store->operations[0]);
        $this->assertSame('write:sissource_wisa:stream_enrolments_enrolments_enabled:1', $store->operations[1]);
    }

    /**
     * Failed writes or verification restore disabled state and retain recovery data.
     *
     * @dataProvider failed_persistence_provider
     * @param string $failuremode Store failure mode.
     * @return void
     */
    public function test_failed_persistence_preserves_disabled_recovery_state(string $failuremode): void {
        [$resolver, $store] = $this->resolver(self::conflictstate([]), $failuremode);

        $this->assertFalse($resolver->resolve_wisa_enrolments(
            source_stream_migration_conflict_resolver::CHOICE_ENABLE_ENROLMENTS
        ));

        $this->assertSame('0', $store->get('sissource_wisa', 'stream_enrolments_enrolments_enabled'));
        $this->assertNull($store->get('sissource_wisa', source_stream_migrator::COMPLETION_MARKER));
        $this->assertSame($this->conflictsnapshot(), $store->get(
            'sissource_wisa',
            source_stream_migrator::CONFLICT_SNAPSHOT
        ));
        $this->assertSame('1', $store->get('local_wisa', 'enable_enrolments'));
    }

    /**
     * Return persistence failures that must leave the conflict recoverable.
     *
     * @return array
     */
    public static function failed_persistence_provider(): array {
        return [
            'write failure' => ['write-enabled'],
            'verification failure' => ['verify-enabled'],
        ];
    }

    /**
     * Failed canonical watermark verification rolls every resolution mutation back.
     *
     * @dataProvider failed_watermark_verification_provider
     * @param array $state Initial migration state.
     * @param string $failuremode Store failure mode.
     * @param string|null $expectedwatermark Canonical watermark after rollback.
     * @return void
     */
    public function test_failed_watermark_write_or_clear_verification_rolls_back_resolution(
        array $state,
        string $failuremode,
        ?string $expectedwatermark
    ): void {
        [$resolver, $store] = $this->resolver($state, $failuremode);

        $this->assertFalse($resolver->resolve_wisa_enrolments(
            source_stream_migration_conflict_resolver::CHOICE_ENABLE_ENROLMENTS
        ));

        $this->assertSame('0', $store->get('sissource_wisa', 'stream_enrolments_enrolments_enabled'));
        $this->assertSame($expectedwatermark, $store->get(
            'sissource_wisa',
            'stream_enrolments_enrolments_watermark'
        ));
        $this->assertNull($store->get('sissource_wisa', source_stream_migrator::COMPLETION_MARKER));
        $this->assertSame($state['sissource_wisa/stream_migration_v1_conflict'], $store->get(
            'sissource_wisa',
            source_stream_migrator::CONFLICT_SNAPSHOT
        ));
        $this->assertSame('1', $store->get('local_wisa', 'enable_enrolments'));
        $this->assertSame('preserved', $store->get('local_wisa', 'rolemap'));
    }

    /**
     * Return failed canonical watermark write and deletion verification states.
     *
     * @return array
     */
    public static function failed_watermark_verification_provider(): array {
        $clearsnapshot = json_decode(self::conflictsnapshot(), true);
        $clearsnapshot[0]['watermarks'] = [
            'enrol_students' => null,
            'enrol_teachers' => null,
        ];
        $clearstate = self::conflictstate([]);
        $clearstate['sissource_wisa/stream_migration_v1_conflict'] = json_encode($clearsnapshot);
        $clearstate['sissource_wisa/stream_enrolments_enrolments_watermark'] = '1600000000';

        return [
            'watermark write verification' => [self::conflictstate([]), 'verify-watermark-write', null],
            'watermark deletion verification' => [$clearstate, 'verify-watermark-delete', '1600000000'],
        ];
    }

    /**
     * Failed snapshot or legacy cleanup rolls every migration mutation back.
     *
     * @dataProvider failed_cleanup_provider
     * @param string $failuremode Store failure mode.
     * @return void
     */
    public function test_failed_cleanup_preserves_recovery_state(string $failuremode): void {
        [$resolver, $store] = $this->resolver(self::conflictstate(['sissource_athenasoft' => '1']), $failuremode);

        $this->assertFalse($resolver->resolve_wisa_enrolments(
            source_stream_migration_conflict_resolver::CHOICE_ENABLE_ENROLMENTS
        ));

        $this->assertSame('0', $store->get('sissource_wisa', 'stream_enrolments_enrolments_enabled'));
        $this->assertNull($store->get('sissource_wisa', source_stream_migrator::COMPLETION_MARKER));
        $this->assertSame($this->conflictsnapshot(), $store->get(
            'sissource_wisa',
            source_stream_migrator::CONFLICT_SNAPSHOT
        ));
        $this->assertSame('1', $store->get('local_wisa', 'enable_enrolments'));
    }

    /**
     * Return cleanup failures that must roll back the entire resolution.
     *
     * @return array
     */
    public static function failed_cleanup_provider(): array {
        return [
            'snapshot deletion' => ['delete-conflict'],
            'legacy deletion' => ['delete-legacy'],
        ];
    }

    /**
     * Completion remains idempotent while deferred cleanup waits for every adapter.
     *
     * @return void
     */
    public function test_resolution_retains_legacy_keys_until_every_adapter_is_complete_then_cleans_idempotently(): void {
        [$resolver, $store] = $this->resolver(self::conflictstate([]));

        $this->assertTrue($resolver->resolve_wisa_enrolments(
            source_stream_migration_conflict_resolver::CHOICE_KEEP_ENROLMENTS_DISABLED
        ));
        $this->assertSame('1', $store->get('local_wisa', 'enable_enrolments'));

        $store->set('sissource_athenasoft', source_stream_migrator::COMPLETION_MARKER, '1');
        $store->operations = [];
        $this->assertTrue($resolver->resolve_wisa_enrolments(
            source_stream_migration_conflict_resolver::CHOICE_ENABLE_ENROLMENTS
        ));

        $this->assertSame('0', $store->get('sissource_wisa', 'stream_enrolments_enrolments_enabled'));
        $this->assertSame('1', $store->get('sissource_wisa', source_stream_migrator::COMPLETION_MARKER));
        $this->assertNull($store->get('local_wisa', 'enable_enrolments'));
        $this->assertSame([], array_values(array_filter($store->operations, static function (string $operation): bool {
            return strpos($operation, 'write:') === 0;
        })));
    }

    /**
     * Construct a source-free resolver and inspectable setting store.
     *
     * @param array $state Initial source-scoped setting values.
     * @param string $failuremode Store failure mode.
     * @return array Resolver and setting store.
     */
    private function resolver(array $state, string $failuremode = ''): array {
        $store = new \local_wisa\tests\source_stream_migration_conflict_setting_store($state, $failuremode);
        return [new source_stream_migration_conflict_resolver(
            $store,
            ['sissource_wisa', 'sissource_athenasoft']
        ), $store, ];
    }

    /**
     * Return recovery state with approved legacy keys.
     *
     * @param array $markers Completed adapter markers.
     * @return array Setting values.
     */
    private static function conflictstate(array $markers): array {
        $state = [
            'sissource_wisa/stream_enrolments_enrolments_enabled' => '0',
            'sissource_wisa/stream_migration_v1_conflict' => self::conflictsnapshot(),
            'local_wisa/enable_enrolments' => '1',
            'local_wisa/enrol_students' => '1',
            'local_wisa/enrol_teachers' => '0',
            'local_wisa/wm_enrol_students' => '1700000000',
            'local_wisa/wm_enrol_teachers' => '1700000300',
            'local_wisa/rolemap' => 'preserved',
            'sissource_athenasoft/wm_enrol_teachers' => '1700000300',
        ];
        foreach ($markers as $component => $marker) {
            $state[$component . '/' . source_stream_migrator::COMPLETION_MARKER] = $marker;
        }
        return $state;
    }

    /**
     * Return a valid persisted WISA enrolments conflict snapshot.
     *
     * @return string Snapshot JSON.
     */
    private static function conflictsnapshot(): string {
        return json_encode([[
            'component' => 'sissource_wisa',
            'stream' => 'enrolments',
            'phase' => 'enrolments',
            'status' => 'requires_admin_resolution',
            'aliases' => [
                'enrol_students' => true,
                'enrol_teachers' => false,
            ],
            'watermarks' => [
                'enrol_students' => 1700000000,
                'enrol_teachers' => 1700000300,
            ],
        ], ]);
    }
}
