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
 * WISA source-stream migration conflict resolution domain service.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Resolves the source-free WISA enrolments migration conflict as one tuple.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_stream_migration_conflict_resolver {
    /** Enable the complete WISA enrolments tuple. */
    public const CHOICE_ENABLE_ENROLMENTS = 'enable_enrolments';

    /** Keep the complete WISA enrolments tuple disabled. */
    public const CHOICE_KEEP_ENROLMENTS_DISABLED = 'keep_enrolments_disabled';

    /** WISA source Frankenstyle component. */
    private const WISA_COMPONENT = 'sissource_wisa';

    /** WISA enrolments stream key. */
    private const WISA_ENROLMENTS_STREAM = 'enrolments';

    /** WISA enrolments phase key. */
    private const WISA_ENROLMENTS_PHASE = 'enrolments';

    /** @var source_stream_migration_setting_store Source-free persistence boundary. */
    private source_stream_migration_setting_store $store;

    /** @var array Installed source Frankenstyle components. */
    private array $sourcecomponents;

    /**
     * Construct a source-free WISA conflict resolver.
     *
     * @param source_stream_migration_setting_store|null $store Persistence boundary.
     * @param array|null $sourcecomponents Installed source components.
     */
    public function __construct(?source_stream_migration_setting_store $store = null, ?array $sourcecomponents = null) {
        $this->store = $store ?: new moodle_source_stream_migration_setting_store();
        $this->sourcecomponents = $sourcecomponents ?? self::installed_source_components();
    }

    /**
     * Return whether WISA's enrolments tuple requires source-free resolution.
     *
     * @return bool Whether a valid unresolved WISA conflict exists.
     */
    public function has_unresolved_wisa_enrolments(): bool {
        if ($this->store->get(self::WISA_COMPONENT, source_stream_migrator::COMPLETION_MARKER) === '1') {
            return false;
        }
        return $this->wisa_enrolments_snapshot() !== null;
    }

    /**
     * Resolve WISA's enrolments conflict using one permitted whole-tuple choice.
     *
     * @param string $choice Whole-tuple resolution choice.
     * @return bool Whether the resolution or already-complete cleanup succeeded.
     */
    public function resolve_wisa_enrolments(string $choice): bool {
        if (!in_array($choice, [self::CHOICE_ENABLE_ENROLMENTS, self::CHOICE_KEEP_ENROLMENTS_DISABLED], true)) {
            return false;
        }
        if ($this->store->get(self::WISA_COMPONENT, source_stream_migrator::COMPLETION_MARKER) === '1') {
            return $this->cleanup_completed_migrations();
        }
        $snapshot = $this->wisa_enrolments_snapshot();
        if ($snapshot === null) {
            return false;
        }
        if (!$this->write_setting(self::canonical_enabled_setting(), '0')) {
            return false;
        }

        return $this->run_resolution($choice === self::CHOICE_ENABLE_ENROLMENTS ? '1' : '0', $snapshot);
    }

    /**
     * Run deferred shared legacy cleanup for completed source migrations.
     *
     * @return bool Whether cleanup committed or remains deferred.
     */
    public function cleanup_completed_migrations(): bool {
        return $this->run_cleanup();
    }

    /**
     * Return the current snapshot when it is precisely the WISA enrolments conflict.
     *
     * @return array|null Persisted conflict snapshot, or null when invalid.
     */
    private function wisa_enrolments_snapshot(): ?array {
        $rawsnapshot = $this->store->get(self::WISA_COMPONENT, source_stream_migrator::CONFLICT_SNAPSHOT);
        if ($rawsnapshot === null) {
            return null;
        }
        $snapshot = json_decode($rawsnapshot, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($snapshot) || count($snapshot) !== 1) {
            return null;
        }
        $conflict = reset($snapshot);
        if (!is_array($conflict)) {
            return null;
        }
        if (
            !isset($conflict['aliases'], $conflict['watermarks']) ||
            !is_array($conflict['aliases']) || !is_array($conflict['watermarks'])
        ) {
            return null;
        }
        if (
            ($conflict['component'] ?? null) !== self::WISA_COMPONENT ||
            ($conflict['stream'] ?? null) !== self::WISA_ENROLMENTS_STREAM ||
            ($conflict['phase'] ?? null) !== self::WISA_ENROLMENTS_PHASE ||
            ($conflict['status'] ?? null) !== 'requires_admin_resolution' ||
            !self::valid_conflict_values($conflict['aliases'], true) ||
            !self::valid_conflict_values($conflict['watermarks'], false)
        ) {
            return null;
        }
        return $conflict;
    }

    /**
     * Persist and verify one WISA source-scoped setting.
     *
     * @param string $setting Setting name.
     * @param string $value Persisted value.
     * @return bool Whether the value persisted and verified.
     */
    private function write_setting(string $setting, string $value): bool {
        return $this->store->set(self::WISA_COMPONENT, $setting, $value) &&
            $this->store->get(self::WISA_COMPONENT, $setting) === $value;
    }

    /**
     * Atomically persist a verified WISA whole-tuple resolution.
     *
     * @param string $enabled Canonical enabled setting value.
     * @param array $snapshot Verified WISA enrolments conflict snapshot.
     * @return bool Whether all migration-state changes committed.
     */
    private function run_resolution(string $enabled, array $snapshot): bool {
        $this->store->begin();
        if (
            ($enabled === '1' && !$this->write_setting(self::canonical_enabled_setting(), '1')) ||
            !$this->persist_canonical_watermark($snapshot['watermarks']) ||
            !$this->write_setting(source_stream_migrator::COMPLETION_MARKER, '1') ||
            !$this->delete_setting(self::WISA_COMPONENT, source_stream_migrator::CONFLICT_SNAPSHOT) ||
            !$this->cleanup_legacy_settings() || !$this->store->commit()
        ) {
            $this->store->rollback();
            return false;
        }
        return true;
    }

    /**
     * Persist and verify the canonical conflict-resolution watermark.
     *
     * @param array $watermarks Raw alias watermark states from the conflict snapshot.
     * @return bool Whether the required canonical state persisted and verified.
     */
    private function persist_canonical_watermark(array $watermarks): bool {
        $setting = self::canonical_watermark_setting();
        if (in_array(null, $watermarks, true)) {
            return $this->delete_setting(self::WISA_COMPONENT, $setting);
        }
        $watermarks[] = self::positive_timestamp($this->store->get(self::WISA_COMPONENT, $setting));
        $watermarks = array_filter($watermarks, function ($watermark): bool {
            return $watermark !== null;
        });
        if ($watermarks === []) {
            return $this->delete_setting(self::WISA_COMPONENT, $setting);
        }
        return $this->write_setting($setting, (string)min($watermarks));
    }

    /**
     * Atomically run deferred cleanup for an already-complete migration.
     *
     * @return bool Whether cleanup committed or remains deferred.
     */
    private function run_cleanup(): bool {
        $this->store->begin();
        if (!$this->cleanup_legacy_settings() || !$this->store->commit()) {
            $this->store->rollback();
            return false;
        }
        return true;
    }

    /**
     * Delete and verify one source-scoped setting.
     *
     * @param string $component Source Frankenstyle component.
     * @param string $setting Setting name.
     * @return bool Whether deletion succeeded and verified.
     */
    private function delete_setting(string $component, string $setting): bool {
        return $this->store->delete($component, $setting) && $this->store->get($component, $setting) === null;
    }

    /**
     * Remove fixed shared legacy settings when all installed adapters complete.
     *
     * @return bool Whether cleanup succeeded or remains deferred.
     */
    private function cleanup_legacy_settings(): bool {
        foreach ($this->sourcecomponents as $component) {
            if ($this->store->get($component, source_stream_migrator::COMPLETION_MARKER) !== '1') {
                return true;
            }
        }
        foreach (self::legacy_settings() as $component => $settings) {
            foreach ($settings as $setting) {
                if (!$this->delete_setting($component, $setting)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Validate the required WISA alias or watermark values in a snapshot.
     *
     * @param array $values Snapshot values indexed by alias.
     * @param bool $boolean Whether every value must be boolean.
     * @return bool Whether the required values are valid.
     */
    private static function valid_conflict_values(array $values, bool $boolean): bool {
        foreach (['enrol_students', 'enrol_teachers'] as $alias) {
            if (!array_key_exists($alias, $values)) {
                return false;
            }
            if ($boolean && !is_bool($values[$alias])) {
                return false;
            }
            if (
                !$boolean && $values[$alias] !== null &&
                (!is_int($values[$alias]) || $values[$alias] <= 0)
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Return the canonical WISA enrolments enablement setting name.
     *
     * @return string Setting name.
     */
    private static function canonical_enabled_setting(): string {
        return source_stream_migrator::tuple_setting(
            self::WISA_ENROLMENTS_STREAM,
            self::WISA_ENROLMENTS_PHASE,
            'enabled'
        );
    }

    /**
     * Return the canonical WISA enrolments watermark setting name.
     *
     * @return string Setting name.
     */
    private static function canonical_watermark_setting(): string {
        return source_stream_migrator::tuple_setting(
            self::WISA_ENROLMENTS_STREAM,
            self::WISA_ENROLMENTS_PHASE,
            'watermark'
        );
    }

    /**
     * Return a positive timestamp or null for an invalid setting.
     *
     * @param string|null $value Candidate timestamp.
     * @return int|null Positive timestamp or null.
     */
    private static function positive_timestamp(?string $value): ?int {
        $timestamp = (int)$value;
        return $timestamp > 0 ? $timestamp : null;
    }

    /**
     * Return approved fixed shared legacy settings eligible for delayed cleanup.
     *
     * @return array Component names mapped to setting names.
     */
    private static function legacy_settings(): array {
        return [
            'local_wisa' => [
                'enable_courses', 'enable_students', 'enable_teachers', 'enable_enrolments',
                'enrol_students', 'enrol_teachers', 'enable_unenrolments',
                'wm_courses', 'wm_students', 'wm_teachers', 'wm_enrol_students',
                'wm_enrol_teachers', 'wm_unenrolments',
            ],
            'sissource_athenasoft' => [
                'wm_courses', 'wm_students', 'wm_enrol_students', 'wm_enrol_teachers',
                'wm_unenrolments', 'wm_teachers',
            ],
        ];
    }

    /**
     * Return installed sissource components without constructing adapters.
     *
     * @return array Source Frankenstyle component names.
     */
    private static function installed_source_components(): array {
        $components = [];
        foreach (\core_component::get_plugin_list('sissource') as $name => $directory) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $name) === 1) {
                $components[] = 'sissource_' . $name;
            }
        }
        return $components;
    }
}
