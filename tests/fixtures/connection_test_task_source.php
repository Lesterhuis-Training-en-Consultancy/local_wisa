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
 * Source fixture for connection-test adhoc task tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_connectiontestfixture;

defined('MOODLE_INTERNAL') || die();

/**
 * Source fixture that records construction and exact request batches.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source implements \local_wisa\source_interface {
    /** @var int Number of source constructions. */
    public static int $constructions = 0;

    /** @var array Request batches received by the source. */
    public static array $requests = [];

    /**
     * Record source construction.
     *
     * @return void
     */
    public function __construct() {
        self::$constructions++;
    }

    /**
     * Return two static health-check descriptors.
     *
     * @return array
     */
    public static function get_stream_registry(): array {
        return [
            'accounts' => self::descriptor('accounts', 'stream_student_accounts', 'query_accounts', 'users'),
            'courses' => self::descriptor('courses', 'stream_courses', 'query_courses', 'courses'),
        ];
    }

    /**
     * Return a successful two-row result for the one requested tuple.
     *
     * @param array $requests Request envelopes.
     * @return array
     */
    public function fetch_streams(array $requests): array {
        self::$requests[] = $requests;
        $request = $requests[0];
        return [\local_wisa\source_stream_envelope::build_result(
            $request['stream'],
            $request['phase'],
            $request['transport'],
            'success',
            [['id' => 1], ['id' => 2]],
            ''
        ), ];
    }

    /**
     * Build one fixture descriptor.
     *
     * @param string $key Stream key.
     * @param string $label Language string key.
     * @param string $transport Transport key.
     * @param string $phase Health-check phase.
     * @return array
     */
    private static function descriptor(string $key, string $label, string $transport, string $phase): array {
        return [
            'key' => $key,
            'label' => $label,
            'transport' => $transport,
            'phases' => [$phase],
            'legacyaliases' => [$phase => []],
            'defaultenabled' => [$phase => true],
            'watermarkmode' => [$phase => 'delta'],
            'healthcheckphase' => $phase,
        ];
    }
}
