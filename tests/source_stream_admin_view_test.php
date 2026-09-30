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
 * Source-stream administration view-model tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Verifies source-free source-stream administration metadata.
 *
 * @package    local_wisa
 * @category   test
 * @group      local_wisa
 * @covers     \local_wisa\source_stream_admin_view
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_stream_admin_view_test extends \advanced_testcase {
    /** Source component used by isolated tuple tests. */
    private const COMPONENT = 'sissource_adminfixture';

    /**
     * Every descriptor phase must expose source-neutral tuple metadata.
     *
     * @return void
     */
    public function test_rows_expose_every_descriptor_tuple_and_metadata_without_alias_classification(): void {
        $this->resetAfterTest();
        $registry = $this->registry();
        $view = $this->view($registry);

        $rows = $view->get_rows();

        $this->assertCount(2, $rows);
        $this->assertSame([
            'sourcecomponent' => self::COMPONENT,
            'stream' => 'accounts',
            'label' => 'stream_accounts',
            'phase' => 'users',
            'transport' => 'query_accounts',
            'watermarkmode' => 'delta',
            'enabled' => true,
            'haspriorwatermark' => false,
            'status' => 'never-run',
        ], $rows[0]);
        $this->assertSame('enrolments', $rows[1]['phase']);
        $this->assertArrayNotHasKey('role', $rows[0]);
        $this->assertArrayNotHasKey('feedtype', $rows[0]);
        $this->assertArrayNotHasKey('watermark', $rows[0]);
    }

    /**
     * Preview consumers must receive only the approved public tuple fields.
     *
     * @return void
     */
    public function test_rows_contain_only_approved_preview_tuple_fields(): void {
        $this->resetAfterTest();

        foreach ($this->view($this->registry())->get_rows() as $row) {
            $this->assertSame([
                'sourcecomponent',
                'stream',
                'label',
                'phase',
                'transport',
                'watermarkmode',
                'enabled',
                'haspriorwatermark',
                'status',
            ], array_keys($row));
            $this->assertIsBool($row['enabled']);
            $this->assertIsBool($row['haspriorwatermark']);
            foreach (
                ['role', 'feedtype', 'watermark', 'errorcode', 'lastsuccess', 'lastattempt', 'rowcount',
                    'endpoint', 'rows', ] as $forbidden
            ) {
                $this->assertArrayNotHasKey($forbidden, $row);
            }
        }
    }

    /**
     * Persisted tuple outcomes and disabled state must map to the public status vocabulary.
     *
     * @return void
     */
    public function test_rows_report_every_supported_status_and_normalize_unknown_state_as_malformed(): void {
        $this->resetAfterTest();
        $registry = $this->registry();
        $statuses = ['never-run', 'successful', 'failed', 'malformed', 'processing', 'provisioning-blocked'];

        foreach ($statuses as $status) {
            if ($status === 'never-run') {
                unset_config('stream_accounts_users_status', self::COMPONENT);
            } else {
                set_config('stream_accounts_users_status', $status, self::COMPONENT);
            }
            $this->assertSame($status, $this->view($registry)->get_rows()[0]['status']);
        }

        set_config('stream_accounts_users_status', 'unexpected', self::COMPONENT);
        $this->assertSame('malformed', $this->view($registry)->get_rows()[0]['status']);

        set_config('stream_accounts_users_enabled', 0, self::COMPONENT);
        $this->assertSame('disabled', $this->view($registry)->get_rows()[0]['status']);
    }

    /**
     * Delta rows reveal only watermark presence and full rows never report one.
     *
     * @return void
     */
    public function test_prior_watermark_is_boolean_only_and_full_mode_never_reports_presence(): void {
        $this->resetAfterTest();
        set_config('stream_accounts_users_watermark', 1700000000, self::COMPONENT);
        set_config('stream_accounts_enrolments_watermark', 1700000300, self::COMPONENT);

        $rows = $this->view($this->registry())->get_rows();

        $this->assertTrue($rows[0]['haspriorwatermark']);
        $this->assertFalse($rows[1]['haspriorwatermark']);
        $this->assertStringNotContainsString('1700000000', json_encode($rows));
        $this->assertStringNotContainsString('1700000300', json_encode($rows));
    }

    /**
     * A valid WISA conflict overrides disabled state and exposes only whole-stream choices.
     *
     * @return void
     */
    public function test_valid_wisa_conflict_overrides_status_and_exposes_exactly_two_choices(): void {
        $this->resetAfterTest();
        $this->set_valid_wisa_conflict();
        $registry = source_factory::get_registry_for_component('sissource_wisa');
        $view = new source_stream_admin_view(
            'sissource_wisa',
            $registry,
            new source_stream_state('sissource_wisa'),
            new source_stream_migration_conflict_resolver()
        );

        $row = $this->row($view->get_rows(), 'enrolments', 'enrolments');
        $this->assertFalse($row['enabled']);
        $this->assertSame('requires-admin-resolution', $row['status']);
        $this->assertSame([
            source_stream_migration_conflict_resolver::CHOICE_ENABLE_ENROLMENTS,
            source_stream_migration_conflict_resolver::CHOICE_KEEP_ENROLMENTS_DISABLED,
        ], $view->get_resolution_choices());
    }

    /**
     * A WISA migration conflict remains actionable when another source is active.
     *
     * @return void
     */
    public function test_valid_wisa_conflict_control_is_independent_of_active_source(): void {
        $this->resetAfterTest();
        $this->set_valid_wisa_conflict();

        $this->assertSame([
            source_stream_migration_conflict_resolver::CHOICE_ENABLE_ENROLMENTS,
            source_stream_migration_conflict_resolver::CHOICE_KEEP_ENROLMENTS_DISABLED,
        ], $this->view($this->registry())->get_resolution_choices());
    }

    /**
     * Missing and malformed conflict snapshots must expose no mutation controls.
     *
     * @return void
     */
    public function test_missing_or_malformed_conflict_exposes_no_resolution_control(): void {
        $this->resetAfterTest();
        $registry = source_factory::get_registry_for_component('sissource_wisa');
        set_config('stream_enrolments_enrolments_enabled', 0, 'sissource_wisa');

        foreach ([false, '{invalid'] as $snapshot) {
            if ($snapshot === false) {
                unset_config(source_stream_migrator::CONFLICT_SNAPSHOT, 'sissource_wisa');
            } else {
                set_config(source_stream_migrator::CONFLICT_SNAPSHOT, $snapshot, 'sissource_wisa');
            }
            $view = new source_stream_admin_view(
                'sissource_wisa',
                $registry,
                new source_stream_state('sissource_wisa'),
                new source_stream_migration_conflict_resolver()
            );
            $this->assertSame([], $view->get_resolution_choices());
            $this->assertSame('disabled', $this->row($view->get_rows(), 'enrolments', 'enrolments')['status']);
        }
    }

    /**
     * Build an isolated source-free view.
     *
     * @param array $registry Validated fixture registry.
     * @return source_stream_admin_view
     */
    private function view(array $registry): source_stream_admin_view {
        return new source_stream_admin_view(
            self::COMPONENT,
            source_stream_registry::validate($registry),
            new source_stream_state(self::COMPONENT),
            new source_stream_migration_conflict_resolver()
        );
    }

    /**
     * Return a two-phase descriptor covering delta and full modes.
     *
     * @return array
     */
    private function registry(): array {
        return ['accounts' => [
            'key' => 'accounts',
            'label' => 'stream_accounts',
            'transport' => 'query_accounts',
            'phases' => ['users', 'enrolments'],
            'legacyaliases' => ['users' => [], 'enrolments' => []],
            'defaultenabled' => ['users' => true, 'enrolments' => true],
            'watermarkmode' => ['users' => 'delta', 'enrolments' => 'full'],
            'healthcheckphase' => 'users',
        ], ];
    }

    /**
     * Return one tuple row from a row list.
     *
     * @param array $rows View rows.
     * @param string $stream Stream key.
     * @param string $phase Phase key.
     * @return array
     */
    private function row(array $rows, string $stream, string $phase): array {
        foreach ($rows as $row) {
            if ($row['stream'] === $stream && $row['phase'] === $phase) {
                return $row;
            }
        }
        $this->fail('Tuple row not found.');
    }

    /**
     * Persist a valid unresolved WISA enrolments snapshot.
     *
     * @return void
     */
    private function set_valid_wisa_conflict(): void {
        set_config('stream_enrolments_enrolments_enabled', 1, 'sissource_wisa');
        set_config(source_stream_migrator::CONFLICT_SNAPSHOT, json_encode([[
            'component' => 'sissource_wisa',
            'stream' => 'enrolments',
            'phase' => 'enrolments',
            'status' => 'requires_admin_resolution',
            'aliases' => ['enrol_students' => true, 'enrol_teachers' => false],
            'watermarks' => ['enrol_students' => 1700000000, 'enrol_teachers' => 1700000300],
        ], ]), 'sissource_wisa');
    }
}
