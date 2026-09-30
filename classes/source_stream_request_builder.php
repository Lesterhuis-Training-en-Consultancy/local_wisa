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
 * Builds enabled source-stream request envelopes.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Builds source-stream requests consistently for sync and preview runs.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_stream_request_builder {
    /** @var string Source component for enabled tuple settings. */
    private string $sourcecomponent;

    /** @var array Validated source stream registry. */
    private array $registry;

    /** @var source_stream_state Tuple state reader. */
    private source_stream_state $state;

    /** @var source_stream_migration_conflict_resolver WISA conflict reader. */
    private source_stream_migration_conflict_resolver $resolver;

    /**
     * Construct a request builder for one source component.
     *
     * @param string $sourcecomponent Source component.
     * @param array $registry Source stream registry.
     * @param source_stream_state $state Tuple state reader.
     * @param source_stream_migration_conflict_resolver|null $resolver WISA conflict reader.
     * @return void
     */
    public function __construct(
        string $sourcecomponent,
        array $registry,
        source_stream_state $state,
        ?source_stream_migration_conflict_resolver $resolver = null
    ) {
        if (preg_match('/^sissource_[a-z][a-z0-9_]*$/', $sourcecomponent) !== 1) {
            throw new \coding_exception('Invalid SIS source component: ' . $sourcecomponent);
        }
        $this->sourcecomponent = $sourcecomponent;
        $this->registry = source_stream_registry::validate($registry);
        $this->state = $state;
        $this->resolver = $resolver ?: new source_stream_migration_conflict_resolver();
    }

    /**
     * Build every enabled request in generic phase and registry order.
     *
     * @param bool $forcefull Whether delta lower bounds are disabled.
     * @return array Request envelopes.
     */
    public function build(bool $forcefull): array {
        $requests = [];
        foreach (source_stream_registry::PHASES as $phase) {
            foreach ($this->registry as $descriptor) {
                if (!in_array($phase, $descriptor['phases'], true) || !$this->is_enabled($descriptor, $phase)) {
                    continue;
                }
                $requests[] = source_stream_envelope::build_request(
                    $descriptor,
                    $phase,
                    $this->state->get_watermark($descriptor['key'], $phase),
                    $forcefull
                );
            }
        }
        return $requests;
    }

    /**
     * Return whether one descriptor phase is enabled for this source component.
     *
     * @param array $descriptor Validated source stream descriptor.
     * @param string $phase Descriptor phase.
     * @return bool Whether the tuple is enabled.
     */
    private function is_enabled(array $descriptor, string $phase): bool {
        if (
            $this->sourcecomponent === 'sissource_wisa' && $descriptor['key'] === 'enrolments' &&
                $phase === 'enrolments' && $this->resolver->has_unresolved_wisa_enrolments()
        ) {
            return false;
        }
        $setting = 'stream_' . $descriptor['key'] . '_' . $phase . '_enabled';
        $configured = get_config($this->sourcecomponent, $setting);
        if ($configured === false) {
            return $descriptor['defaultenabled'][$phase];
        }
        return (string)$configured !== '0';
    }
}
