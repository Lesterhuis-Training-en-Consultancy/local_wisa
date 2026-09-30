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
 * Source-free migration of legacy source settings to stream tuples.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Migrates source-declared aliases without constructing a source adapter.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_stream_migrator {
    /** Completion marker setting name. */
    public const COMPLETION_MARKER = 'stream_migration_v1_complete';

    /** Conflict snapshot setting name. */
    public const CONFLICT_SNAPSHOT = 'stream_migration_v1_conflict';

    /**
     * Migrate a static source registry to source-scoped tuple settings.
     *
     * A conflicting set of aliases is preserved as a source-local resolution
     * snapshot and leaves the completion marker absent.
     *
     * @param string $component Source Frankenstyle component.
     * @param array $registry Static source stream registry.
     * @param source_stream_migration_setting_store|null $store Source-scoped setting persistence boundary.
     * @return bool Whether migration state was persisted and upgrade may continue.
     */
    public static function migrate_component(
        string $component,
        array $registry,
        ?source_stream_migration_setting_store $store = null
    ): bool {
        self::validate_component($component);
        $registry = source_stream_registry::validate($registry);
        $store = $store ?? new moodle_source_stream_migration_setting_store();
        if ($store->get($component, self::COMPLETION_MARKER) === '1') {
            return (new source_stream_migration_conflict_resolver($store))->cleanup_completed_migrations();
        }

        try {
            $store->begin();
            $conflicts = [];
            foreach ($registry as $descriptor) {
                foreach ($descriptor['phases'] as $phase) {
                    $aliases = $descriptor['legacyaliases'][$phase] ?? [];
                    $outcome = self::migrate_tuple($store, $component, $descriptor, $phase, $aliases);
                    if ($outcome !== null) {
                        $conflicts[] = $outcome;
                    }
                }
            }

            if ($conflicts !== []) {
                self::write($store, $component, self::CONFLICT_SNAPSHOT, json_encode($conflicts));
                self::clear($store, $component, self::COMPLETION_MARKER);
                if (!$store->commit()) {
                    throw new \coding_exception('Unable to persist source stream migration state.');
                }
                return true;
            }

            self::clear($store, $component, self::CONFLICT_SNAPSHOT);
            self::write($store, $component, self::COMPLETION_MARKER, '1');
            if (!$store->commit()) {
                throw new \coding_exception('Unable to persist source stream migration state.');
            }
        } catch (\Throwable $exception) {
            $store->rollback();
            throw new \coding_exception('Unable to persist source stream migration state.');
        }

        return (new source_stream_migration_conflict_resolver($store))->cleanup_completed_migrations();
    }

    /**
     * Migrate one source stream tuple and return a conflict snapshot when needed.
     *
     * @param source_stream_migration_setting_store $store Source-scoped setting persistence boundary.
     * @param string $component Source Frankenstyle component.
     * @param array $descriptor Validated source stream descriptor.
     * @param string $phase Descriptor phase.
     * @param array $aliases Legacy aliases for the tuple.
     * @return array|null Conflict snapshot or null.
     */
    private static function migrate_tuple(
        source_stream_migration_setting_store $store,
        string $component,
        array $descriptor,
        string $phase,
        array $aliases
    ): ?array {
        $stream = $descriptor['key'];
        $enabledname = self::tuple_setting($stream, $phase, 'enabled');
        $watermarkname = self::tuple_setting($stream, $phase, 'watermark');
        if ($aliases === []) {
            self::write($store, $component, $enabledname, '0');
            self::clear($store, $component, $watermarkname);
            return null;
        }

        $enabledvalues = [];
        foreach ($aliases as $alias) {
            $enabledvalues[$alias['name']] = self::alias_enabled($store, $alias['enabledby']);
        }
        if (count(array_unique($enabledvalues, SORT_REGULAR)) > 1) {
            $watermarks = [];
            foreach ($aliases as $alias) {
                $watermarks[$alias['name']] = self::raw_watermark_state($store, $alias);
            }
            $canonicalwatermark = self::positive_timestamp($store->get($component, $watermarkname));
            self::write($store, $component, $enabledname, '0');
            self::migrate_conflict_watermark(
                $store,
                $component,
                $watermarkname,
                $descriptor['watermarkmode'][$phase],
                $canonicalwatermark,
                $watermarks
            );
            return [
                'component' => $component,
                'stream' => $stream,
                'phase' => $phase,
                'status' => 'requires_admin_resolution',
                'aliases' => $enabledvalues,
                'watermarks' => $watermarks,
            ];
        }

        self::write($store, $component, $enabledname, reset($enabledvalues) ? '1' : '0');
        self::migrate_watermark($store, $component, $watermarkname, $descriptor['watermarkmode'][$phase], $aliases);
        return null;
    }

    /**
     * Return whether every legacy condition for an alias is enabled.
     *
     * @param source_stream_migration_setting_store $store Source-scoped setting persistence boundary.
     * @param array $conditions Legacy setting conditions.
     * @return bool Whether the alias is enabled.
     */
    private static function alias_enabled(source_stream_migration_setting_store $store, array $conditions): bool {
        foreach ($conditions as $condition) {
            $value = $store->get($condition['component'], $condition['key']);
            if ($value === null) {
                if (!$condition['absentdefault']) {
                    return false;
                }
            } else if ((string)$value === '0') {
                return false;
            }
        }
        return true;
    }

    /**
     * Persist the conservative raw watermark for a delta tuple.
     *
     * @param source_stream_migration_setting_store $store Source-scoped setting persistence boundary.
     * @param string $component Source Frankenstyle component.
     * @param string $setting Canonical tuple watermark setting.
     * @param string $mode Descriptor watermark mode.
     * @param array $aliases Legacy aliases for the tuple.
     * @return void
     */
    private static function migrate_watermark(
        source_stream_migration_setting_store $store,
        string $component,
        string $setting,
        string $mode,
        array $aliases
    ): void {
        if ($mode === 'full') {
            self::clear($store, $component, $setting);
            return;
        }

        $watermarks = [self::positive_timestamp($store->get($component, $setting))];
        foreach ($aliases as $alias) {
            if (!isset($alias['watermark'])) {
                continue;
            }
            $watermark = self::positive_timestamp($store->get(
                $alias['watermark']['component'],
                $alias['watermark']['key']
            ));
            if (count($aliases) > 1 && $watermark === null) {
                self::clear($store, $component, $setting);
                return;
            }
            $watermarks[] = $watermark;
        }
        $watermarks = array_filter($watermarks, function ($watermark): bool {
            return $watermark !== null;
        });
        if ($watermarks === []) {
            self::clear($store, $component, $setting);
            return;
        }
        self::write($store, $component, $setting, (string)min($watermarks));
    }

    /**
     * Persist the conservative canonical watermark captured for a conflicting tuple.
     *
     * @param source_stream_migration_setting_store $store Source-scoped setting persistence boundary.
     * @param string $component Source Frankenstyle component.
     * @param string $setting Canonical tuple watermark setting.
     * @param string $mode Descriptor watermark mode.
     * @param int|null $canonicalwatermark Pre-marker canonical watermark.
     * @param array $watermarks Raw alias watermark states from the conflict snapshot.
     * @return void
     */
    private static function migrate_conflict_watermark(
        source_stream_migration_setting_store $store,
        string $component,
        string $setting,
        string $mode,
        ?int $canonicalwatermark,
        array $watermarks
    ): void {
        if ($mode === 'full' || in_array(null, $watermarks, true)) {
            self::clear($store, $component, $setting);
            return;
        }
        $watermarks[] = $canonicalwatermark;
        $watermarks = array_filter($watermarks, function ($watermark): bool {
            return $watermark !== null;
        });
        if ($watermarks === []) {
            self::clear($store, $component, $setting);
            return;
        }
        self::write($store, $component, $setting, (string)min($watermarks));
    }

    /**
     * Return a positive timestamp or null for an invalid setting.
     *
     * @param mixed $value Candidate timestamp.
     * @return int|null Positive timestamp or null.
     */
    private static function positive_timestamp($value): ?int {
        $timestamp = (int)$value;
        return $timestamp > 0 ? $timestamp : null;
    }

    /**
     * Return one legacy alias watermark's raw presence and validity state.
     *
     * @param source_stream_migration_setting_store $store Source-scoped setting persistence boundary.
     * @param array $alias Legacy alias descriptor.
     * @return array Raw watermark state.
     */
    private static function raw_watermark_state(source_stream_migration_setting_store $store, array $alias): ?int {
        if (!isset($alias['watermark'])) {
            return null;
        }
        $watermark = $alias['watermark'];
        $value = $store->get($watermark['component'], $watermark['key']);
        $timestamp = self::positive_timestamp($value);
        return $timestamp;
    }

    /**
     * Persist and verify one setting.
     *
     * @param source_stream_migration_setting_store $store Source-scoped setting persistence boundary.
     * @param string $component Moodle component name.
     * @param string $setting Setting name.
     * @param string $value Setting value.
     * @return void
     */
    private static function write(
        source_stream_migration_setting_store $store,
        string $component,
        string $setting,
        string $value
    ): void {
        if (!$store->set($component, $setting, $value) || $store->get($component, $setting) !== $value) {
            throw new \coding_exception('Unable to persist source stream migration state.');
        }
    }

    /**
     * Clear and verify one source-scoped migration setting.
     *
     * @param source_stream_migration_setting_store $store Source-scoped setting persistence boundary.
     * @param string $component Moodle component name.
     * @param string $setting Setting name.
     * @return void
     */
    private static function clear(
        source_stream_migration_setting_store $store,
        string $component,
        string $setting
    ): void {
        if (!$store->delete($component, $setting) || $store->get($component, $setting) !== null) {
            throw new \coding_exception('Unable to clear source stream migration state.');
        }
    }

    /**
     * Return one canonical tuple setting name.
     *
     * @param string $stream Stream key.
     * @param string $phase Stream phase.
     * @param string $suffix Setting suffix.
     * @return string Setting name.
     */
    public static function tuple_setting(string $stream, string $phase, string $suffix): string {
        return 'stream_' . $stream . '_' . $phase . '_' . $suffix;
    }

    /**
     * Validate a source Frankenstyle component name.
     *
     * @param string $component Source Frankenstyle component.
     * @return void
     */
    private static function validate_component(string $component): void {
        if (preg_match('/^sissource_[a-z][a-z0-9_]*$/', $component) !== 1) {
            throw new \coding_exception('Invalid source stream component.');
        }
    }
}
