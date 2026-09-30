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
 * Static source-stream registry validation.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Validates source-declared stream descriptors without constructing a source.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_stream_registry {
    /** Supported generic stream phases in execution order. */
    public const PHASES = ['courses', 'users', 'enrolments', 'unenrolments'];

    /** Required descriptor keys. */
    private const DESCRIPTOR_KEYS = [
        'key', 'label', 'transport', 'phases', 'legacyaliases', 'defaultenabled', 'watermarkmode', 'healthcheckphase',
    ];

    /**
     * Validate and return static source-stream descriptors unchanged.
     *
     * @param array $registry Descriptor registry keyed by stream key.
     * @return array Validated registry.
     * @throws \coding_exception If the descriptor schema is invalid.
     */
    public static function validate(array $registry): array {
        $aliases = [];
        foreach ($registry as $registrykey => $descriptor) {
            if (!is_string($registrykey) || !is_array($descriptor)) {
                self::invalid('Each source stream descriptor must use a string key and array value.');
            }
            self::validate_descriptor($registrykey, $descriptor, $aliases);
        }
        return $registry;
    }

    /**
     * Validate one descriptor and register its migration alias names.
     *
     * @param string $registrykey Associative registry key.
     * @param array $descriptor Source-provided descriptor.
     * @param array $aliases Registered aliases by phase and alias name.
     * @return void
     */
    private static function validate_descriptor(string $registrykey, array $descriptor, array &$aliases): void {
        if (!self::has_exact_keys($descriptor, self::DESCRIPTOR_KEYS)) {
            self::invalid('A source stream descriptor must contain exactly the required keys.');
        }
        if (
            !self::is_identifier($registrykey) || !is_string($descriptor['key']) ||
                $descriptor['key'] !== $registrykey || !self::is_identifier($descriptor['key'])
        ) {
            self::invalid('A source stream descriptor key is invalid.');
        }
        if (!is_string($descriptor['label']) || trim($descriptor['label']) === '') {
            self::invalid('A source stream descriptor label is invalid.');
        }
        if (!is_string($descriptor['transport']) || preg_match('/^[\x21-\x7e]+$/', $descriptor['transport']) !== 1) {
            self::invalid('A source stream descriptor transport is invalid.');
        }
        self::validate_phases($descriptor['phases']);
        self::validate_aliases($descriptor['legacyaliases'], $descriptor['phases'], $aliases);
        self::validate_phase_boolean_map($descriptor['defaultenabled'], $descriptor['phases'], 'defaultenabled');
        self::validate_watermark_modes($descriptor['watermarkmode'], $descriptor['phases']);
        if (
            !is_string($descriptor['healthcheckphase']) ||
                !in_array($descriptor['healthcheckphase'], $descriptor['phases'], true)
        ) {
            self::invalid('A source stream health-check phase is invalid.');
        }
    }

    /**
     * Validate an ordered source-stream phase list.
     *
     * @param mixed $phases Candidate phase list.
     * @return void
     */
    private static function validate_phases($phases): void {
        if (!is_array($phases) || !self::is_list($phases) || $phases === []) {
            self::invalid('Source stream phases must be a non-empty ordered list.');
        }
        foreach ($phases as $phase) {
            if (
                !is_string($phase) || !in_array($phase, self::PHASES, true) ||
                    count(array_keys($phases, $phase, true)) !== 1
            ) {
                self::invalid('A source stream phase is invalid or duplicated.');
            }
        }
    }

    /**
     * Validate descriptor aliases and reject colliding aliases per phase.
     *
     * @param mixed $aliases Candidate aliases by phase.
     * @param array $phases Declared descriptor phases.
     * @param array $registeredaliases Registered aliases by phase and name.
     * @return void
     */
    private static function validate_aliases($aliases, array $phases, array &$registeredaliases): void {
        if (!is_array($aliases) || !self::has_exact_keys($aliases, $phases)) {
            self::invalid('Source stream aliases must contain one list for every declared phase.');
        }
        foreach ($aliases as $phase => $entries) {
            if (!is_string($phase) || !in_array($phase, $phases, true) || !is_array($entries) || !self::is_list($entries)) {
                self::invalid('Source stream aliases must be phase-keyed lists.');
            }
            foreach ($entries as $entry) {
                self::validate_alias_entry($entry);
                $name = $entry['name'];
                if (isset($registeredaliases[$phase][$name])) {
                    self::invalid('A source stream legacy alias collides with another descriptor.');
                }
                $registeredaliases[$phase][$name] = true;
            }
        }
    }

    /**
     * Validate a single legacy alias entry.
     *
     * @param mixed $entry Candidate alias entry.
     * @return void
     */
    private static function validate_alias_entry($entry): void {
        if (
            !is_array($entry) || !isset($entry['name']) || !is_string($entry['name']) || trim($entry['name']) === '' ||
                !array_key_exists('enabledby', $entry) || !is_array($entry['enabledby']) ||
                !self::is_list($entry['enabledby']) || $entry['enabledby'] === []
        ) {
            self::invalid('A source stream legacy alias is invalid.');
        }
        foreach ($entry['enabledby'] as $condition) {
            self::validate_enabled_condition($condition);
        }
        if (isset($entry['watermark'])) {
            self::validate_watermark_alias($entry['watermark']);
        }
        foreach ($entry as $key => $value) {
            if (($key !== 'name' && $key !== 'enabledby' && $key !== 'watermark') || $value === null) {
                self::invalid('A source stream legacy alias contains unsupported data.');
            }
        }
    }

    /**
     * Validate an enabled-by conjunction condition.
     *
     * @param mixed $condition Candidate condition.
     * @return void
     */
    private static function validate_enabled_condition($condition): void {
        if (
            !is_array($condition) || !self::has_exact_keys($condition, ['component', 'key', 'absentdefault']) ||
                !self::is_nonempty_string($condition['component']) || !self::is_nonempty_string($condition['key']) ||
                !is_bool($condition['absentdefault'])
        ) {
            self::invalid('A source stream enabled-by condition is invalid.');
        }
    }

    /**
     * Validate one optional legacy watermark location.
     *
     * @param mixed $watermark Candidate watermark location.
     * @return void
     */
    private static function validate_watermark_alias($watermark): void {
        if (
            !is_array($watermark) || !self::has_exact_keys($watermark, ['component', 'key']) ||
                !self::is_nonempty_string($watermark['component']) || !self::is_nonempty_string($watermark['key'])
        ) {
            self::invalid('A source stream watermark alias is invalid.');
        }
    }

    /**
     * Validate a boolean map with exactly one entry per descriptor phase.
     *
     * @param mixed $map Candidate phase map.
     * @param array $phases Declared descriptor phases.
     * @param string $name Map name for failure context.
     * @return void
     */
    private static function validate_phase_boolean_map($map, array $phases, string $name): void {
        if (!is_array($map) || !self::has_exact_phase_keys($map, $phases)) {
            self::invalid('The source stream ' . $name . ' map is invalid.');
        }
        foreach ($map as $value) {
            if (!is_bool($value)) {
                self::invalid('The source stream ' . $name . ' map must contain booleans.');
            }
        }
    }

    /**
     * Validate a watermark-mode map with exactly one valid mode per phase.
     *
     * @param mixed $modes Candidate phase map.
     * @param array $phases Declared descriptor phases.
     * @return void
     */
    private static function validate_watermark_modes($modes, array $phases): void {
        if (!is_array($modes) || !self::has_exact_phase_keys($modes, $phases)) {
            self::invalid('The source stream watermark-mode map is invalid.');
        }
        foreach ($modes as $mode) {
            if ($mode !== 'delta' && $mode !== 'full') {
                self::invalid('A source stream watermark mode is invalid.');
            }
        }
    }

    /**
     * Check whether a map contains exactly the declared phase keys.
     *
     * @param array $map Candidate map.
     * @param array $phases Declared phases.
     * @return bool Whether the map keys match the phases.
     */
    private static function has_exact_phase_keys(array $map, array $phases): bool {
        return self::has_exact_keys($map, $phases);
    }

    /**
     * Check whether an array contains exactly the expected keys in any order.
     *
     * @param array $values Candidate array.
     * @param array $expected Expected keys.
     * @return bool Whether the keys match.
     */
    private static function has_exact_keys(array $values, array $expected): bool {
        $keys = array_keys($values);
        sort($keys);
        sort($expected);
        return $keys === $expected;
    }

    /**
     * Check whether a candidate array is list-shaped on PHP 8.0.
     *
     * @param array $values Candidate array.
     * @return bool Whether numeric keys are sequential from zero.
     */
    private static function is_list(array $values): bool {
        $index = 0;
        foreach ($values as $key => $value) {
            if ($key !== $index++) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check a stream identifier.
     *
     * @param string $value Candidate identifier.
     * @return bool Whether the identifier is valid.
     */
    private static function is_identifier(string $value): bool {
        return preg_match('/^[a-z][a-z0-9_]*$/', $value) === 1;
    }

    /**
     * Check a non-empty string value.
     *
     * @param mixed $value Candidate value.
     * @return bool Whether the value is a non-empty string.
     */
    private static function is_nonempty_string($value): bool {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * Raise a generic safe descriptor validation failure.
     *
     * @param string $message Safe failure message.
     * @return void
     * @throws \coding_exception Always.
     */
    private static function invalid(string $message): void {
        throw new \coding_exception($message);
    }
}
