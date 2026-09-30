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
 * Generic source-stream fixture for local_wisa tests.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\tests;

use local_wisa\source_interface;
use local_wisa\source_stream_envelope;

/**
 * Supplies declared source-stream tuples to parent runtime tests.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fake_api_client implements source_interface {
    /** @var array Requested stream-phase tuple keys. */
    public $calls = [];

    /** @var array Request envelopes received by the fixture. */
    public $requests = [];

    /** @var array Rows keyed by stream key. */
    private $streams;

    /** @var array Boolean failure map keyed by stream key. */
    private $failures;

    /**
     * Construct a source-stream fixture.
     *
     * @param array $streams Rows keyed by stream key.
     * @param array $failures Boolean failure map keyed by stream key.
     */
    public function __construct(array $streams = [], array $failures = []) {
        $this->streams = array_merge([
            'courses' => [],
            'student_accounts' => [],
            'teacher_accounts' => [],
            'enrolments' => [],
            'unenrolments' => [],
        ], $streams);
        $this->failures = $failures;
    }

    /**
     * Return the declared generic fixture streams.
     *
     * @return array Source stream descriptors.
     */
    public static function get_stream_registry(): array {
        return [
            'courses' => self::descriptor('courses', 'query_courses', ['courses']),
            'student_accounts' => self::descriptor('student_accounts', 'query_students', ['users']),
            'teacher_accounts' => self::descriptor('teacher_accounts', 'query_teachers', ['users']),
            'enrolments' => self::descriptor('enrolments', 'query_enrolments', ['enrolments']),
            'unenrolments' => self::descriptor('unenrolments', 'query_unenrolments', ['unenrolments']),
        ];
    }

    /**
     * Return one result for every requested tuple.
     *
     * @param array $requests Source-stream request envelopes.
     * @return array Source-stream result envelopes.
     */
    public function fetch_streams(array $requests): array {
        $this->requests[] = $requests;
        $results = [];
        foreach ($requests as $request) {
            $stream = $request['stream'];
            $this->calls[] = $stream . ':' . $request['phase'];
            if (!empty($this->failures[$stream])) {
                $results[] = source_stream_envelope::build_result(
                    $stream,
                    $request['phase'],
                    $request['transport'],
                    'failed',
                    [],
                    'fixture_failed'
                );
                continue;
            }
            $results[] = source_stream_envelope::build_result(
                $stream,
                $request['phase'],
                $request['transport'],
                'success',
                $this->streams[$stream],
                ''
            );
        }
        return $results;
    }

    /**
     * Build one delta fixture descriptor.
     *
     * @param string $key Stream key.
     * @param string $transport Stream transport.
     * @param array $phases Supported phases.
     * @return array Source stream descriptor.
     */
    private static function descriptor(string $key, string $transport, array $phases): array {
        $defaultenabled = [];
        $watermarkmode = [];
        $aliases = [];
        foreach ($phases as $phase) {
            $defaultenabled[$phase] = true;
            $watermarkmode[$phase] = 'delta';
            $aliases[$phase] = [];
        }
        return [
            'key' => $key,
            'label' => 'stream_' . $key,
            'transport' => $transport,
            'phases' => $phases,
            'legacyaliases' => $aliases,
            'defaultenabled' => $defaultenabled,
            'watermarkmode' => $watermarkmode,
            'healthcheckphase' => $phases[0],
        ];
    }
}
