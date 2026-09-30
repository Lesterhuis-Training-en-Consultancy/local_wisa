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
 * Source-free source-stream administration view model.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Builds redacted administration rows from static descriptors and persisted state.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_stream_admin_view {
    /** Public tuple states read from persisted source-stream state. */
    private const PERSISTED_STATUSES = [
        'never-run',
        'successful',
        'failed',
        'malformed',
        'processing',
        'provisioning-blocked',
    ];

    /** @var string Active source Frankenstyle component. */
    private string $component;

    /** @var array Validated static source-stream registry. */
    private array $registry;

    /** @var source_stream_state Source-scoped persisted state reader. */
    private source_stream_state $state;

    /** @var bool Whether the valid WISA enrolments conflict is unresolved. */
    private bool $unresolvedwisa;

    /**
     * Construct a source-free administration view model.
     *
     * @param string $component Active source Frankenstyle component.
     * @param array $registry Validated static source-stream registry.
     * @param source_stream_state $state Source-scoped state reader.
     * @param source_stream_migration_conflict_resolver $resolver WISA conflict reader.
     * @return void
     */
    public function __construct(
        string $component,
        array $registry,
        source_stream_state $state,
        source_stream_migration_conflict_resolver $resolver
    ) {
        if (preg_match('/^sissource_[a-z][a-z0-9_]*$/', $component) !== 1) {
            throw new \coding_exception('Invalid source stream component.');
        }
        $this->component = $component;
        $this->registry = source_stream_registry::validate($registry);
        $this->state = $state;
        $this->unresolvedwisa = $resolver->has_unresolved_wisa_enrolments();
    }

    /**
     * Return one redacted administration row per descriptor tuple.
     *
     * @return array Tuple rows in descriptor and phase declaration order.
     */
    public function get_rows(): array {
        $rows = [];
        foreach ($this->registry as $descriptor) {
            foreach ($descriptor['phases'] as $phase) {
                $tuple = $this->state->get_tuple($descriptor['key'], $phase);
                $enabled = $this->is_enabled($descriptor, $phase);
                $rows[] = [
                    'sourcecomponent' => $this->component,
                    'stream' => $descriptor['key'],
                    'label' => $descriptor['label'],
                    'phase' => $phase,
                    'transport' => $descriptor['transport'],
                    'watermarkmode' => $descriptor['watermarkmode'][$phase],
                    'enabled' => $enabled,
                    'haspriorwatermark' => $descriptor['watermarkmode'][$phase] === 'delta' &&
                        $tuple['watermark'] !== null,
                    'status' => $this->status($descriptor['key'], $phase, $enabled, $tuple['status']),
                ];
            }
        }
        return $rows;
    }

    /**
     * Return the permitted whole-stream choices when WISA resolution is required.
     *
     * @return array Resolution choice identifiers.
     */
    public function get_resolution_choices(): array {
        if (!$this->unresolvedwisa) {
            return [];
        }
        return [
            source_stream_migration_conflict_resolver::CHOICE_ENABLE_ENROLMENTS,
            source_stream_migration_conflict_resolver::CHOICE_KEEP_ENROLMENTS_DISABLED,
        ];
    }

    /**
     * Return tuple enablement from the canonical setting or descriptor default.
     *
     * @param array $descriptor Source stream descriptor.
     * @param string $phase Generic phase.
     * @return bool Whether the tuple is enabled.
     */
    private function is_enabled(array $descriptor, string $phase): bool {
        if (
            $this->component === 'sissource_wisa' && $this->unresolvedwisa &&
                $descriptor['key'] === 'enrolments' && $phase === 'enrolments'
        ) {
            return false;
        }
        $setting = 'stream_' . $descriptor['key'] . '_' . $phase . '_enabled';
        $configured = get_config($this->component, $setting);
        if ($configured === false) {
            return $descriptor['defaultenabled'][$phase];
        }
        return (string)$configured !== '0';
    }

    /**
     * Derive the public status without exposing persisted error details.
     *
     * @param string $stream Stream key.
     * @param string $phase Generic phase.
     * @param bool $enabled Whether the tuple is enabled.
     * @param string $persistedstatus Persisted tuple status.
     * @return string Public status.
     */
    private function status(string $stream, string $phase, bool $enabled, string $persistedstatus): string {
        if (
            $this->component === 'sissource_wisa' && $this->unresolvedwisa &&
                $stream === 'enrolments' && $phase === 'enrolments'
        ) {
            return 'requires-admin-resolution';
        }
        if (!$enabled) {
            return 'disabled';
        }
        return in_array($persistedstatus, self::PERSISTED_STATUSES, true) ? $persistedstatus : 'malformed';
    }
}
