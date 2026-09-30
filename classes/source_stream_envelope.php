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
 * Source-stream request and result envelope helpers.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Builds and validates source-stream request and result envelopes.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_stream_envelope {
    /**
     * Build one request envelope from a validated descriptor phase.
     *
     * @param array $descriptor Validated source-stream descriptor.
     * @param string $phase Requested descriptor phase.
     * @param int|null $watermark Persisted delta watermark.
     * @param bool $forcefull Whether this run ignores a delta lower bound.
     * @return array Request envelope.
     * @throws \coding_exception If the descriptor or watermark is invalid.
     */
    public static function build_request(array $descriptor, string $phase, ?int $watermark, bool $forcefull): array {
        if (
            !isset($descriptor['key'], $descriptor['transport'], $descriptor['phases'], $descriptor['watermarkmode']) ||
                !is_string($descriptor['key']) || !is_string($descriptor['transport']) ||
                !in_array($phase, $descriptor['phases'], true) || !isset($descriptor['watermarkmode'][$phase])
        ) {
            throw new \coding_exception('The source stream request descriptor is invalid.');
        }
        $mode = $descriptor['watermarkmode'][$phase];
        if ($mode !== 'delta' && $mode !== 'full') {
            throw new \coding_exception('The source stream request watermark mode is invalid.');
        }
        if ($watermark !== null && $watermark <= 0) {
            throw new \coding_exception('A source stream watermark must be a positive Unix timestamp.');
        }
        if ($mode === 'full') {
            return [
                'stream' => $descriptor['key'],
                'phase' => $phase,
                'transport' => $descriptor['transport'],
                'watermark' => null,
                'effective_since' => null,
            ];
        }
        return [
            'stream' => $descriptor['key'],
            'phase' => $phase,
            'transport' => $descriptor['transport'],
            'watermark' => $watermark,
            'effective_since' => $forcefull || $watermark === null ? null : $watermark - 300,
        ];
    }

    /**
     * Return one lower bound per transport for a batch of request envelopes.
     *
     * A null request bound means that its transport must retrieve the full dataset.
     *
     * @param array $requests Request envelopes.
     * @return array Transport lower bounds keyed by transport identifier.
     * @throws \coding_exception If a request envelope is malformed.
     */
    public static function transport_lower_bounds(array $requests): array {
        $bounds = [];
        foreach ($requests as $request) {
            self::validate_request($request);
            $transport = $request['transport'];
            $effective = $request['effective_since'];
            if (!array_key_exists($transport, $bounds)) {
                $bounds[$transport] = $effective;
            } else if ($bounds[$transport] !== null && ($effective === null || $effective < $bounds[$transport])) {
                $bounds[$transport] = $effective;
            }
        }
        return $bounds;
    }

    /**
     * Validate and classify a full source-stream request batch before retrieval.
     *
     * Structural errors abort the batch. Duplicate tuples collapse to one malformed
     * result, while registry-invalid tuples remain tuple-local malformed results.
     *
     * @param array $requests Request envelopes.
     * @param array $registry Source stream descriptors keyed by stream.
     * @return array Valid requests and malformed results keyed by stream-phase tuple.
     * @throws \coding_exception If a request envelope is malformed.
     */
    public static function preflight_requests(array $requests, array $registry): array {
        $tuples = [];
        foreach ($requests as $request) {
            self::validate_request($request);
            $tuple = self::tuple_key($request['stream'], $request['phase']);
            $tuples[$tuple][] = $request;
        }

        $validrequests = [];
        $malformedresults = [];
        foreach ($tuples as $tuple => $tuplerequests) {
            $request = $tuplerequests[0];
            if (count($tuplerequests) > 1) {
                $malformedresults[$tuple] = self::malformed_result($request, 'duplicate_request');
                continue;
            }
            $descriptor = $registry[$request['stream']] ?? null;
            if (
                !is_array($descriptor) || !isset($descriptor['transport'], $descriptor['phases']) ||
                    !in_array($request['phase'], $descriptor['phases'], true) ||
                    $request['transport'] !== $descriptor['transport']
            ) {
                $malformedresults[$tuple] = self::malformed_result($request, 'invalid_request');
                continue;
            }
            $validrequests[$tuple] = $request;
        }
        return ['requests' => $validrequests, 'results' => $malformedresults];
    }

    /**
     * Build a result envelope with the approved success or failure shape.
     *
     * @param string $stream Stream key.
     * @param string $phase Generic phase.
     * @param string $transport Transport identifier.
     * @param string $status Result status.
     * @param array $rows Result rows.
     * @param string $errorcode Stable redacted error code.
     * @return array Result envelope.
     * @throws \coding_exception If the result shape is invalid.
     */
    public static function build_result(
        string $stream,
        string $phase,
        string $transport,
        string $status,
        array $rows,
        string $errorcode
    ): array {
        $result = [
            'stream' => $stream,
            'phase' => $phase,
            'transport' => $transport,
            'status' => $status,
            'rows' => $rows,
            'errorcode' => $errorcode,
        ];
        self::validate_result($result);
        return $result;
    }

    /**
     * Validate adapter results against the requested tuples.
     *
     * Missing, duplicate, and malformed responses become tuple-local malformed results.
     * Unrequested responses are rejected and never returned to processing.
     *
     * @param array $requests Requested tuple envelopes.
     * @param array $results Adapter result envelopes.
     * @return array Results keyed by stream and phase tuple.
     */
    public static function validate_results(array $requests, array $results): array {
        $expected = [];
        $duplicaterequests = [];
        foreach ($requests as $request) {
            self::validate_request($request);
            $tuple = self::tuple_key($request['stream'], $request['phase']);
            if (array_key_exists($tuple, $expected)) {
                $expected[$tuple] = self::malformed_result($request, 'duplicate_request');
                $duplicaterequests[$tuple] = true;
                continue;
            }
            $expected[$tuple] = null;
        }

        foreach ($results as $result) {
            if (
                !is_array($result) || !isset($result['stream'], $result['phase']) ||
                    !self::is_identifier($result['stream']) ||
                    !in_array($result['phase'], source_stream_registry::PHASES, true)
            ) {
                continue;
            }
            $tuple = self::tuple_key($result['stream'], $result['phase']);
            if (isset($duplicaterequests[$tuple])) {
                continue;
            }
            $request = self::request_for_tuple($requests, $tuple);
            if (!array_key_exists($tuple, $expected) || $expected[$tuple] !== null) {
                if (array_key_exists($tuple, $expected) && $expected[$tuple] !== null) {
                    $expected[$tuple] = self::malformed_result($request, 'duplicate_result');
                }
                continue;
            }
            try {
                self::validate_result($result);
                if ($request === null || $result['transport'] !== $request['transport']) {
                    $expected[$tuple] = self::malformed_result($request, 'invalid_result');
                } else {
                    $expected[$tuple] = $result;
                }
            } catch (\coding_exception $exception) {
                $expected[$tuple] = self::malformed_result($request, 'invalid_result');
            }
        }

        foreach ($expected as $tuple => $result) {
            if ($result === null) {
                $request = self::request_for_tuple($requests, $tuple);
                $expected[$tuple] = self::malformed_result($request, 'missing_result');
            }
        }
        return $expected;
    }

    /**
     * Validate a request envelope.
     *
     * @param mixed $request Candidate request envelope.
     * @return void
     * @throws \coding_exception If the request is malformed.
     */
    private static function validate_request($request): void {
        if (
            !is_array($request) || !self::has_exact_keys(
                $request,
                ['stream', 'phase', 'transport', 'watermark', 'effective_since']
            ) ||
                !self::is_identifier($request['stream']) || !in_array($request['phase'], source_stream_registry::PHASES, true) ||
                !self::is_nonempty_string($request['transport']) ||
                ($request['watermark'] !== null && (!is_int($request['watermark']) || $request['watermark'] <= 0)) ||
                ($request['effective_since'] !== null && !is_int($request['effective_since']))
        ) {
            throw new \coding_exception('A source stream request envelope is invalid.');
        }
    }

    /**
     * Validate a result envelope.
     *
     * @param mixed $result Candidate result envelope.
     * @return void
     * @throws \coding_exception If the result is malformed.
     */
    private static function validate_result($result): void {
        if (
            !is_array($result) || !self::has_exact_keys(
                $result,
                ['stream', 'phase', 'transport', 'status', 'rows', 'errorcode']
            ) ||
                !self::is_identifier($result['stream']) || !in_array($result['phase'], source_stream_registry::PHASES, true) ||
                !self::is_nonempty_string($result['transport']) ||
                !in_array($result['status'], ['success', 'failed', 'malformed'], true) || !is_array($result['rows']) ||
                !is_string($result['errorcode'])
        ) {
            throw new \coding_exception('A source stream result envelope is invalid.');
        }
        if (
            ($result['status'] === 'success' && $result['errorcode'] !== '') ||
                ($result['status'] !== 'success' && ($result['rows'] !== [] || !self::is_error_code($result['errorcode'])))
        ) {
            throw new \coding_exception('A source stream result status is invalid.');
        }
    }

    /**
     * Build a tuple-local malformed result from a request.
     *
     * @param array|null $request Request envelope.
     * @param string $errorcode Stable redacted error code.
     * @return array Result envelope.
     */
    private static function malformed_result(?array $request, string $errorcode): array {
        if ($request === null) {
            throw new \coding_exception('Missing source stream request for malformed result.');
        }
        return [
            'stream' => $request['stream'],
            'phase' => $request['phase'],
            'transport' => $request['transport'],
            'status' => 'malformed',
            'rows' => [],
            'errorcode' => $errorcode,
        ];
    }

    /**
     * Find a request by tuple key.
     *
     * @param array $requests Request envelopes.
     * @param string $tuple Tuple key.
     * @return array|null Request envelope or null.
     */
    private static function request_for_tuple(array $requests, string $tuple): ?array {
        foreach ($requests as $request) {
            if (self::tuple_key($request['stream'], $request['phase']) === $tuple) {
                return $request;
            }
        }
        return null;
    }

    /**
     * Return a collision-free stream-phase tuple key.
     *
     * @param string $stream Stream key.
     * @param string $phase Generic phase.
     * @return string Tuple key.
     */
    private static function tuple_key(string $stream, string $phase): string {
        return $stream . ':' . $phase;
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
     * Check a stream identifier.
     *
     * @param mixed $value Candidate identifier.
     * @return bool Whether the identifier is valid.
     */
    private static function is_identifier($value): bool {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_]*$/', $value) === 1;
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
     * Check a stable redacted error code.
     *
     * @param string $errorcode Candidate error code.
     * @return bool Whether the code is valid.
     */
    private static function is_error_code(string $errorcode): bool {
        return preg_match('/^[a-z][a-z0-9_]*$/', $errorcode) === 1;
    }
}
