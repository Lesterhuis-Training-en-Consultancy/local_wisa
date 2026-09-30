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
 * Contract for SIS source adapters.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Source adapter contract used by the generic synchronisation layer.
 *
 * Implementations declare their static source streams without construction and
 * receive a batch of validated tuple request envelopes at runtime.
 */
interface source_interface {
    /**
     * Return the static source-stream registry.
     *
     * @return array Source stream descriptors.
     */
    public static function get_stream_registry(): array;

    /**
     * Fetch every requested source-stream tuple.
     *
     * @param array $requests Validated source-stream request envelopes.
     * @return array Source-stream result envelopes.
     */
    public function fetch_streams(array $requests): array;
}
