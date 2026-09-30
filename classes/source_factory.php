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
 * Factory for SIS source adapters.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Discovers and instantiates the active SIS source subplugin.
 */
class source_factory {
    /** Default source for existing installations. */
    public const DEFAULT_SOURCE = 'wisa';

    /**
     * Return a validated static registry for one source component.
     *
     * This lookup deliberately never constructs a source adapter, so callers can
     * safely use it from administration, migration, and request-bound paths.
     *
     * @param string $component Full Frankenstyle SIS source component name.
     * @return array Validated source stream registry.
     * @throws \coding_exception If the component cannot provide a valid registry.
     */
    public static function get_registry_for_component(string $component): array {
        if (!preg_match('/^sissource_[a-z][a-z0-9_]*$/', $component)) {
            throw new \coding_exception('Invalid SIS source component: ' . $component);
        }

        $class = $component . '\\source';
        if (!class_exists($class) || !is_callable([$class, 'get_stream_registry'])) {
            throw new \coding_exception('No static source stream registry is available for ' . $component . '.');
        }

        return source_stream_registry::validate($class::get_stream_registry());
    }

    /**
     * Return the source component selected for a source-free parent operation.
     *
     * @return string Full Frankenstyle SIS source component name.
     */
    public static function get_active_component(): string {
        $name = self::clean_source_name(get_config('local_wisa', 'active_source')) ?: self::DEFAULT_SOURCE;
        $component = 'sissource_' . $name;
        $class = $component . '\source';
        if (class_exists($class)) {
            try {
                self::get_registry_for_component($component);
                return $component;
            } catch (\Throwable $exception) {
                throw new \coding_exception('No usable SIS source adapter is available for local_wisa.');
            }
        }
        if ($name === self::DEFAULT_SOURCE) {
            throw new \coding_exception('No usable SIS source adapter is available for local_wisa.');
        }

        $component = 'sissource_' . self::DEFAULT_SOURCE;
        self::get_registry_for_component($component);
        return $component;
    }

    /**
     * Return the validated registry for the active source component.
     *
     * @return array Validated source stream registry.
     */
    public static function get_active_registry(): array {
        return self::get_registry_for_component(self::get_active_component());
    }

    /**
     * Return the active SIS source adapter.
     *
     * @return source_interface
     */
    public static function get_active_source(): source_interface {
        $name = get_config('local_wisa', 'active_source') ?: self::DEFAULT_SOURCE;
        $name = self::clean_source_name($name) ?: self::DEFAULT_SOURCE;

        $class = 'sissource_' . $name . '\source';
        if (class_exists($class) && !self::has_valid_registry('sissource_' . $name)) {
            throw new \coding_exception('No usable SIS source adapter is available for local_wisa.');
        }

        $source = self::create_source($name);
        if ($source !== null) {
            return $source;
        }

        if ($name !== self::DEFAULT_SOURCE) {
            $source = self::create_source(self::DEFAULT_SOURCE);
            if ($source !== null) {
                return $source;
            }
        }

        throw new \coding_exception('No usable SIS source adapter is available for local_wisa.');
    }

    /**
     * Return a source adapter for an explicit SIS source component.
     *
     * @param string $component Full Frankenstyle SIS source component name.
     * @return source_interface
     * @throws \coding_exception If the component is invalid or unavailable.
     */
    public static function get_source_for_component(string $component): source_interface {
        if (!preg_match('/^sissource_[a-z][a-z0-9_]*$/', $component)) {
            throw new \coding_exception('Invalid SIS source component: ' . $component);
        }

        $class = $component . '\source';
        if (!class_exists($class)) {
            throw new \coding_exception('No usable SIS source adapter is available for ' . $component . '.');
        }

        $source = new $class();
        if (!$source instanceof source_interface) {
            throw new \coding_exception($class . ' must implement ' . source_interface::class);
        }

        return $source;
    }

    /**
     * Return the Frankenstyle component for a source adapter.
     *
     * @param source_interface $source Source adapter.
     * @return string Full Frankenstyle source component name.
     */
    public static function get_component_for_source(source_interface $source): string {
        $classname = ltrim(get_class($source), '\\');
        if (preg_match('/^(sissource_[a-z][a-z0-9_]*)\\\\/', $classname, $matches)) {
            $component = $matches[1];
            if (preg_match('/^sissource_[a-z][a-z0-9_]*$/', $component)) {
                return $component;
            }
        }

        $name = self::clean_source_name(get_config('local_wisa', 'active_source'));
        return 'sissource_' . ($name ?: self::DEFAULT_SOURCE);
    }

    /**
     * Build the options list for the active-source admin setting.
     *
     * @return array Source name => display name.
     */
    public static function get_source_options(): array {
        $options = [];
        if (class_exists('\core_component')) {
            foreach (\core_component::get_plugin_list('sissource') as $name => $dir) {
                $source = self::clean_source_name($name);
                if ($source === null) {
                    continue;
                }
                $component = 'sissource_' . $source;
                if (!self::has_valid_registry($component)) {
                    continue;
                }
                $options[$source] = get_string('pluginname', $component);
            }
        }

        // During early install/upgrade the subplugin cache may not yet be ready.
        if (!isset($options[self::DEFAULT_SOURCE])) {
            $component = 'sissource_' . self::DEFAULT_SOURCE;
            $class = $component . '\source';
            if (!class_exists($class) || self::has_valid_registry($component)) {
                $options[self::DEFAULT_SOURCE] = get_string('source_wisa', 'local_wisa');
            }
        }
        if (!isset($options['athenasoft'])) {
            $component = 'sissource_athenasoft';
            $class = $component . '\source';
            if (!class_exists($class) || self::has_valid_registry($component)) {
                $options['athenasoft'] = get_string('source_athenasoft', 'local_wisa');
            }
        }

        return $options;
    }

    /**
     * Instantiate one named source if available.
     *
     * @param string $name Source short name.
     * @return source_interface|null
     */
    private static function create_source(string $name): ?source_interface {
        $name = self::clean_source_name($name);
        if ($name === null) {
            return null;
        }

        $class = 'sissource_' . $name . '\source';
        if (!class_exists($class)) {
            return null;
        }

        $source = new $class();
        if (!$source instanceof source_interface) {
            throw new \coding_exception($class . ' must implement ' . source_interface::class);
        }
        return $source;
    }

    /**
     * Return whether a component exposes a valid static source registry.
     *
     * @param string $component Full Frankenstyle SIS source component name.
     * @return bool Whether the component registry is valid.
     */
    private static function has_valid_registry(string $component): bool {
        try {
            self::get_registry_for_component($component);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Validate and normalise a source short name.
     *
     * @param mixed $name Raw setting value.
     * @return string|null
     */
    private static function clean_source_name($name): ?string {
        $name = strtolower(trim((string)$name));
        return preg_match('/^[a-z][a-z0-9_]*$/', $name) ? $name : null;
    }
}
