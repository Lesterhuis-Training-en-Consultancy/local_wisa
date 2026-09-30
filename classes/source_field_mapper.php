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
 * Source-neutral raw-field mapping helper.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Validates adapter mapping configuration and maps raw source values safely.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class source_field_mapper {
    /** @var array Generic targets supported by each record type. */
    private const RECORD_TARGETS = [
        'course' => ['idnumber', 'shortname', 'fullname', 'startdate', 'enddate', 'category', 'templatekey'],
        'user' => [
            'idnumber', 'username', 'firstname', 'lastname', 'email', 'city', 'country', 'lang', 'description',
            'institution', 'department', 'phone1', 'phone2', 'address',
        ],
        'enrolment' => ['courseidnumber', 'useridnumber', 'role', 'startdate', 'enddate'],
        'unenrolment' => ['courseidnumber', 'useridnumber'],
    ];

    /** @var string Adapter component name. */
    private $component;

    /** @var array Adapter default raw source fields. */
    private $defaults = [];

    /** @var array Valid configured raw source field overrides. */
    private $overrides = [];

    /** @var array Safe effective mapping metadata. */
    private $effective = [];

    /** @var array Adapter-specific targets keyed by record type. */
    private $additionaltargets = [];

    /**
     * Construct a mapper for one source adapter.
     *
     * @param string $component Adapter component name.
     * @param array $defaults Default raw source fields by record type and target.
     * @param string $fieldmapjson Raw JSON field mapping configuration.
     * @param array $additionaltargets Adapter-specific targets keyed by record type.
     * @return void
     */
    public function __construct(
        string $component,
        array $defaults,
        string $fieldmapjson,
        array $additionaltargets = []
    ) {
        $this->component = $component;
        $this->additionaltargets = $additionaltargets;
        $this->load_defaults($defaults);
        $this->load_overrides($fieldmapjson);
        $this->build_effective_map();
    }

    /**
     * Map one raw source row to configured generic targets.
     *
     * An effective source column that is absent from a row is warned about and omitted.
     *
     * @param string $recordtype Generic record type.
     * @param array $row Raw source row.
     * @param string $recordidentity Source-record identity, which is not persisted.
     * @return array Raw values keyed by generic target.
     */
    public function map_record(string $recordtype, array $row, string $recordidentity): array {
        if (!isset(self::RECORD_TARGETS[$recordtype])) {
            $this->log_mapping_warning('Ignoring unknown mapping record type for adapter ' . $this->component . ': ' . $recordtype);
            return [];
        }

        $mapped = [];
        foreach ($this->effective[$recordtype] ?? [] as $target => $mapping) {
            $sourcefield = $mapping['source'];
            if (!array_key_exists($sourcefield, $row)) {
                $this->log_mapping_warning(
                    'Adapter ' . $this->component . ' omitted ' . $recordtype . '.' . $target .
                    ': configured source column ' . $sourcefield . ' is absent.'
                );
                continue;
            }
            $mapped[$target] = $row[$sourcefield] ?? '';
        }

        return $mapped;
    }

    /**
     * Return safe effective mapping metadata for preview consumers.
     *
     * @return array Effective mappings keyed by record type and generic target.
     */
    public function get_effective_map(): array {
        return $this->effective;
    }

    /**
     * Check whether a generic target has a configured source override.
     *
     * @param string $recordtype Generic record type.
     * @param string $target Generic target.
     * @return bool True when configuration overrides the adapter default.
     */
    public function is_override_configured(string $recordtype, string $target): bool {
        return isset($this->overrides[$recordtype][$target]);
    }

    /**
     * Validate a complete field mapping configuration at the settings boundary.
     *
     * @param string $fieldmapjson Raw JSON field mapping configuration.
     * @param array $additionaltargets Adapter-specific targets keyed by record type.
     * @return bool Whether the configuration has the supported object shape.
     */
    public static function is_valid_configuration(string $fieldmapjson, array $additionaltargets = []): bool {
        $fieldmapjson = trim($fieldmapjson);
        if ($fieldmapjson === '') {
            return true;
        }

        $object = json_decode($fieldmapjson);
        $decoded = json_decode($fieldmapjson, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_object($object) || !is_array($decoded)) {
            return false;
        }

        foreach ($decoded as $recordtype => $targets) {
            if (!isset(self::RECORD_TARGETS[$recordtype]) || !is_array($targets)) {
                return false;
            }
            foreach ($targets as $target => $sourcefield) {
                if (
                    !self::is_allowed_target($recordtype, (string)$target, $additionaltargets)
                    || !is_string($sourcefield)
                    || trim($sourcefield) === ''
                ) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Store valid adapter defaults.
     *
     * @param array $defaults Default raw source fields by record type and target.
     * @return void
     */
    private function load_defaults(array $defaults): void {
        foreach ($defaults as $recordtype => $targets) {
            if (!isset(self::RECORD_TARGETS[$recordtype]) || !is_array($targets)) {
                continue;
            }
            foreach ($targets as $target => $sourcefield) {
                if (
                    !self::is_allowed_target($recordtype, (string)$target, $this->additionaltargets) ||
                    !is_string($sourcefield)
                ) {
                    continue;
                }
                $sourcefield = trim($sourcefield);
                if ($sourcefield !== '') {
                    $this->defaults[$recordtype][$target] = $sourcefield;
                }
            }
        }
    }

    /**
     * Parse and validate configured source-column overrides.
     *
     * @param string $fieldmapjson Raw JSON field mapping configuration.
     * @return void
     */
    private function load_overrides(string $fieldmapjson): void {
        $fieldmapjson = trim($fieldmapjson);
        if ($fieldmapjson === '') {
            return;
        }

        $decoded = json_decode($fieldmapjson, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            logger::log(
                'api_config',
                'mapping',
                $this->component,
                'warning',
                'Invalid fieldmap JSON for adapter ' . $this->component . '; defaults remain active.'
            );
            return;
        }

        foreach ($decoded as $recordtype => $targets) {
            if (!isset(self::RECORD_TARGETS[$recordtype]) || !is_array($targets)) {
                $this->log_mapping_warning(
                    'Ignoring unknown fieldmap record type for adapter ' . $this->component . ': ' . $recordtype
                );
                continue;
            }
            foreach ($targets as $target => $sourcefield) {
                if (!self::is_allowed_target($recordtype, (string)$target, $this->additionaltargets)) {
                    $this->log_mapping_warning(
                        'Ignoring unknown fieldmap target for adapter ' . $this->component . ': ' .
                        $recordtype . '.' . $target
                    );
                    continue;
                }
                if (!is_string($sourcefield) || trim($sourcefield) === '') {
                    $this->log_mapping_warning(
                        'Ignoring empty fieldmap source column for adapter ' . $this->component . ': ' .
                        $recordtype . '.' . $target
                    );
                    continue;
                }
                $this->overrides[$recordtype][$target] = trim($sourcefield);
            }
        }
    }

    /**
     * Build preview-safe mappings from defaults and configured overrides.
     *
     * @return void
     */
    private function build_effective_map(): void {
        foreach ($this->defaults as $recordtype => $targets) {
            foreach ($targets as $target => $sourcefield) {
                $override = $this->overrides[$recordtype][$target] ?? null;
                $this->effective[$recordtype][$target] = [
                    'source' => $override ?? $sourcefield,
                    'defaultsource' => $sourcefield,
                    'overridden' => $override !== null,
                ];
            }
        }
        foreach ($this->overrides as $recordtype => $targets) {
            foreach ($targets as $target => $sourcefield) {
                if (isset($this->effective[$recordtype][$target])) {
                    continue;
                }
                $this->effective[$recordtype][$target] = [
                    'source' => $sourcefield,
                    'defaultsource' => null,
                    'overridden' => true,
                ];
            }
        }
    }

    /**
     * Determine whether a target is valid for a generic record type.
     *
     * @param string $recordtype Generic record type.
     * @param string $target Generic target.
     * @param array $additionaltargets Adapter-specific targets keyed by record type.
     * @return bool True when the target is supported.
     */
    private static function is_allowed_target(string $recordtype, string $target, array $additionaltargets = []): bool {
        if (in_array($target, self::RECORD_TARGETS[$recordtype], true)) {
            return true;
        }
        if (in_array($target, $additionaltargets[$recordtype] ?? [], true)) {
            return true;
        }
        return $recordtype === 'user' && preg_match('/^profile_field_[A-Za-z0-9_]+$/', $target) === 1;
    }

    /**
     * Persist a mapping warning without including raw row values.
     *
     * @param string $message Safe warning message.
     * @return void
     */
    private function log_mapping_warning(string $message): void {
        logger::log('source_map', 'mapping', $this->component, 'warning', $message);
    }
}
