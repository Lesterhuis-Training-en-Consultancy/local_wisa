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
 * Fetches safe aggregate counts for queued source-stream previews.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Executes a read-only, force-full source preview without tuple mutations.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sync_preview {
    /** @var source_interface Active source adapter. */
    private source_interface $source;

    /** @var string Active source component. */
    private string $sourcecomponent;

    /** @var array Validated source stream registry. */
    private array $registry;

    /** @var source_stream_state Read-only tuple state access. */
    private source_stream_state $state;

    /**
     * Construct a queued source preview service.
     *
     * @param source_interface $source Active source adapter.
     * @param string $sourcecomponent Active source component.
     * @return void
     */
    public function __construct(source_interface $source, string $sourcecomponent) {
        $this->source = $source;
        $this->sourcecomponent = $sourcecomponent;
        $this->registry = source_factory::get_registry_for_component($sourcecomponent);
        $this->state = new source_stream_state($sourcecomponent);
    }

    /**
     * Fetch every enabled tuple once and return a redacted aggregate summary.
     *
     * @return array Preview status, generic phase counts, and safe window label.
     */
    public function preview(): array {
        $requests = (new source_stream_request_builder($this->sourcecomponent, $this->registry, $this->state))->build(true);
        $results = $requests === [] ? [] : source_stream_envelope::validate_results(
            $requests,
            $this->source->fetch_streams($requests)
        );
        $counts = [
            'courses' => 0,
            'users' => 0,
            'enrolments' => 0,
            'unenrolments' => 0,
        ];
        $success = true;
        foreach ($requests as $request) {
            $result = $results[$request['stream'] . ':' . $request['phase']];
            if ($result['status'] !== 'success') {
                $success = false;
                continue;
            }
            $counts[$request['phase']] += count($result['rows']);
        }
        return [
            'status' => $success ? 'success' : 'failed',
            'counts' => $counts,
            'window' => schoolyear_window::label(),
        ];
    }
}
