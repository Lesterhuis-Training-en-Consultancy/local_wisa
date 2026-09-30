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
 * WISA SIS source adapter facade.
 *
 * @package    sissource_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_wisa;

use local_wisa\mapping_provider_interface;
use local_wisa\source_interface;
use local_wisa\source_stream_envelope;

/**
 * Coordinates WISA stream retrieval and generic row mapping.
 *
 * @package    sissource_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class source implements mapping_provider_interface, source_interface {
    /** @var api_client WISA API client. */
    private $api;

    /** @var row_mapper WISA raw-row mapper. */
    private $mapper;

    /**
     * Construct the adapter.
     *
     * @param api_client|null $api Optional API client for tests.
     * @return void
     */
    public function __construct(?api_client $api = null) {
        $this->api = $api ?: new api_client();
        $this->mapper = new row_mapper((string)get_config('sissource_wisa', 'fieldmap'));
    }

    /**
     * Return configured mapping metadata for preview consumers.
     *
     * @return array Effective mappings keyed by record type and generic target.
     */
    public function get_effective_map(): array {
        return $this->mapper->get_effective_map();
    }

    /**
     * Return WISA's source-free static stream registry.
     *
     * @return array WISA source-stream descriptors.
     */
    public static function get_stream_registry(): array {
        return stream_registry::get();
    }

    /**
     * Fetch and map requested WISA source-stream tuples.
     *
     * @param array $requests Parent request envelopes.
     * @return array Result envelopes for requested tuples.
     */
    public function fetch_streams(array $requests): array {
        $registry = self::get_stream_registry();
        $preflight = source_stream_envelope::preflight_requests($requests, $registry);
        $validrequests = $preflight['requests'];
        $bounds = source_stream_envelope::transport_lower_bounds($validrequests);
        $transportrows = [];
        $results = [];
        $emittedtuples = [];
        foreach ($requests as $request) {
            $stream = $request['stream'];
            $phase = $request['phase'];
            $tuple = $stream . ':' . $phase;
            if (isset($preflight['results'][$tuple])) {
                if (!isset($emittedtuples[$tuple])) {
                    $results[] = $preflight['results'][$tuple];
                    $emittedtuples[$tuple] = true;
                }
                continue;
            }
            $transport = $validrequests[$tuple]['transport'];
            if (!array_key_exists($transport, $transportrows)) {
                $transportrows[$transport] = $this->api->fetch_transport($transport, $bounds[$transport]);
            }
            if ($transportrows[$transport] === false) {
                $results[] = source_stream_envelope::build_result(
                    $stream,
                    $phase,
                    $transport,
                    'failed',
                    [],
                    'transport_failed'
                );
                continue;
            }
            try {
                $results[] = source_stream_envelope::build_result(
                    $stream,
                    $phase,
                    $transport,
                    'success',
                    $this->mapper->map_rows($transportrows[$transport], $stream),
                    ''
                );
            } catch (\Throwable $exception) {
                $results[] = source_stream_envelope::build_result(
                    $stream,
                    $phase,
                    $transport,
                    'malformed',
                    [],
                    'mapping_failed'
                );
            }
        }
        return $results;
    }
}
