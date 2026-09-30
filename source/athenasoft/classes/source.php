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
 * AthenaSoft SIS source adapter facade.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;

use local_wisa\mapping_provider_interface;
use local_wisa\source_field_mapper;
use local_wisa\source_interface;
use local_wisa\source_stream_envelope;
use local_wisa\source_stream_registry;

/**
 * Fetches and maps requested AthenaSoft source stream tuples.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class source implements mapping_provider_interface, source_interface {
    use source_stream_registry_trait;
    use source_course_mapping_trait;
    use source_user_mapping_trait;
    use source_enrolment_mapping_trait;
    use source_record_identity_trait;
    use source_row_filter_trait;

    /** @var array Default AthenaSoft raw source columns. */
    private const DEFAULT_FIELDS = [
        'course' => [
            'idnumber' => 'NummerIdCursus',
            'shortname' => 'commercieleNaam',
            'fullname' => 'commercieleNaam',
            'startdate' => 'aanvangsdatum',
            'enddate' => 'dateEindMoodle',
            'category' => 'naam',
            'templatekey' => 'opleidingsvariantId',
        ],
        'user' => [
            'idnumber' => 'userNummerId',
            'username' => 'userNummerId',
            'firstname' => 'voornaam',
            'lastname' => 'familienaam',
            'email' => 'email',
            'password' => 'password',
        ],
        'enrolment' => [
            'courseidnumber' => 'NummerIdCursus',
            'useridnumber' => 'userNummerId',
        ],
        'unenrolment' => [
            'courseidnumber' => 'NummerIdCursus',
            'useridnumber' => 'userNummerId',
        ],
    ];

    /** @var api_client AthenaSoft API client. */
    private $api;

    /** @var string|null Active tuple-local filtering lower bound. */
    private $effectivesince = null;

    /** @var int Script ID for course rows. */
    private $scriptcourses;

    /** @var int Script ID for placement rows. */
    private $scriptplacements;

    /** @var int Script ID for teacher rows. */
    private $scriptteachers;

    /** @var source_field_mapper AthenaSoft raw-field mapper. */
    private $mapper;

    /**
     * Construct the adapter.
     *
     * @param api_client|null $api Optional API client for tests.
     * @return void
     */
    public function __construct(?api_client $api = null) {
        $this->api = $api ?: new api_client();
        $this->load_config();
    }

    /**
     * Return the configured mapping metadata for preview consumers.
     *
     * @return array Effective mappings keyed by record type and generic target.
     */
    public function get_effective_map(): array {
        return $this->mapper->get_effective_map();
    }

    /**
     * Fetch and map the requested AthenaSoft stream tuples.
     *
     * @param array $requests Source-stream request envelopes.
     * @return array Source-stream result envelopes.
     */
    public function fetch_streams(array $requests): array {
        $registry = source_stream_registry::validate(self::get_stream_registry());
        $preflight = source_stream_envelope::preflight_requests($requests, $registry);
        $validrequests = $preflight['requests'];
        foreach ($validrequests as $request) {
            $this->validate_stream_request($request, $registry);
        }
        $transports = source_stream_envelope::transport_lower_bounds($validrequests);

        $rawresponses = [];
        foreach (array_keys($transports) as $transport) {
            $rawresponses[$transport] = $this->api->fetch($this->script_id($transport));
        }

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
            $rows = $rawresponses[$transport];
            if ($rows === false) {
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
                    $this->map_stream_rows($validrequests[$tuple], $rows),
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

    /**
     * Validate a request against the source's static stream registry.
     *
     * @param array $request Valid source-stream request envelope.
     * @param array $registry Validated source stream registry.
     * @return void
     */
    private function validate_stream_request(array $request, array $registry): void {
        $stream = $request['stream'];
        if (
            !isset($registry[$stream]) || !in_array($request['phase'], $registry[$stream]['phases'], true) ||
                $request['transport'] !== $registry[$stream]['transport']
        ) {
            throw new \coding_exception('Invalid AthenaSoft source stream request.');
        }
        $mode = $registry[$stream]['watermarkmode'][$request['phase']];
        if ($mode === 'full' && ($request['watermark'] !== null || $request['effective_since'] !== null)) {
            throw new \coding_exception('Full AthenaSoft source streams cannot have a watermark.');
        }
    }

    /**
     * Return a configured AthenaSoft script ID for a static transport.
     *
     * @param string $transport AthenaSoft transport identifier.
     * @return int Script ID.
     */
    private function script_id(string $transport): int {
        if ($transport === 'script_courses') {
            return $this->scriptcourses;
        }
        if ($transport === 'script_placements') {
            return $this->scriptplacements;
        }
        if ($transport === 'script_teachers') {
            return $this->scriptteachers;
        }
        throw new \coding_exception('Unknown AthenaSoft source transport.');
    }

    /**
     * Map one successful stream response for the requested tuple.
     *
     * @param array $request Source-stream request envelope.
     * @param array $rows Raw AthenaSoft response rows.
     * @return array Mapped generic rows.
     */
    private function map_stream_rows(array $request, array $rows): array {
        $this->effectivesince = $request['effective_since'] === null ? null : gmdate('Y-m-d H:i:s', $request['effective_since']);
        if ($request['stream'] === 'courses') {
            return $this->map_courses($rows);
        }
        if ($request['phase'] === 'users') {
            return $this->map_users($rows);
        }
        if ($request['phase'] === 'enrolments') {
            return $this->map_enrolment_rows($rows);
        }
        return $this->map_unenrolment_rows($rows);
    }

    /**
     * Load adapter settings.
     *
     * @return void
     */
    private function load_config(): void {
        $this->scriptcourses = (int)(get_config('sissource_athenasoft', 'script_courses') ?: 5);
        $this->scriptplacements = (int)(get_config('sissource_athenasoft', 'script_placements') ?: 6);
        $this->scriptteachers = (int)(get_config('sissource_athenasoft', 'script_teachers') ?: 7);
        $this->mapper = new source_field_mapper(
            'sissource_athenasoft',
            self::DEFAULT_FIELDS,
            (string)get_config('sissource_athenasoft', 'fieldmap'),
            ['user' => ['password']]
        );
    }
}
