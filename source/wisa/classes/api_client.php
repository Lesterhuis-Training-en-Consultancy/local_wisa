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
 * WISA REST API client.
 *
 * @package    sissource_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_wisa;

use local_wisa\logger;

/**
 * Client for the current WISA/Schoolware query endpoints.
 */
class api_client {
    /** @var string Base WISA API URL. */
    private $apiurl;

    /** @var string WISA API username. */
    private $username;

    /** @var string WISA API password. */
    private $password;

    /** @var string WISA institute number. */
    private $institutenum;

    /** @var array Configurable query codes, keyed by stream transport. */
    private $queries;

    /** @var bool Log a redacted request marker for each API call. */
    private $debug = false;

    /**
     * Construct the API client from plugin configuration.
     */
    public function __construct() {
        $this->apiurl = get_config('sissource_wisa', 'api_url');
        $this->username = trim(\local_wisa\credential_resolver::resolve('sissource_wisa', 'api_user'));
        $this->password = trim(\local_wisa\credential_resolver::resolve('sissource_wisa', 'api_pass'));
        $this->institutenum = get_config('sissource_wisa', 'institute_num');
        $this->debug = (bool)get_config('local_wisa', 'debug_logging');
        $this->queries = [
            'query_courses'      => get_config('sissource_wisa', 'query_courses') ?: 'MCVOD_C',
            'query_students'     => get_config('sissource_wisa', 'query_students') ?: 'MCVOD_STUD',
            'query_teachers'     => get_config('sissource_wisa', 'query_teachers') ?: 'MCVOD_LKR',
            'query_enrolments'   => get_config('sissource_wisa', 'query_enrolments') ?: 'MCVOD_INS',
            'query_unenrolments' => get_config('sissource_wisa', 'query_unenrolments') ?: 'MCVOD_UIT',
        ];
    }

    /**
     * Fetch one configured WISA transport at its effective lower bound.
     *
     * @param string $transport Declared source-stream transport identifier.
     * @param int|null $effectivesince Nullable effective delta lower bound.
     * @return array|false Decoded response rows or false on transport failure.
     */
    public function fetch_transport(string $transport, ?int $effectivesince): array|false {
        if (!array_key_exists($transport, $this->queries)) {
            throw new \coding_exception('Unknown WISA source-stream transport: ' . $transport);
        }
        $sinds = $effectivesince === null ? '1900-01-01 00:00:00' : date('Y-m-d H:i:s', $effectivesince);
        return $this->fetch($this->queries[$transport], ['sinds' => $sinds]);
    }

    /**
     * Fetch data from a specific WISA query endpoint.
     *
     * @param string $queryname e.g. 'MCVOD_C', 'MCVOD_LKR', 'MCVOD_STUD'
     * @param array $params Optional additional parameters
     * @return array|false Decoded JSON response or false on failure
     */
    public function fetch($queryname, $params = []) {
        if (empty($this->apiurl) || empty($this->username) || empty($this->password)) {
            logger::log('api_connect', 'system', 'redacted', 'fail', 'WISA_API_CONFIG_MISSING');
            return false;
        }

        $fullurl = $this->build_query_url($queryname, $params);

        if ($this->debug) {
            logger::log('api_debug', 'system', 'redacted', 'info', 'WISA_API_REQUEST');
        }

        [$response, $httpcode, $error] = $this->execute_request($fullurl);

        if ($response === false) {
            logger::log('api_fetch', 'system', 'redacted', 'fail', 'WISA_API_TRANSPORT_FAILED');
            return false;
        }

        if ($httpcode !== 200) {
            logger::log('api_fetch', 'system', 'redacted', 'fail', 'WISA_API_HTTP_FAILED');
            return false;
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            logger::log('api_fetch', 'system', 'redacted', 'fail', 'WISA_API_RESPONSE_INVALID_JSON');
            return false;
        }
        if (!is_array($data)) {
            logger::log('api_fetch', 'system', 'redacted', 'fail', 'WISA_API_RESPONSE_INVALID_SHAPE');
            return false;
        }

        return $data;
    }

    /**
     * Build a WISA query URL using the currently configured credentials and delta
     * parameters. Kept separate from cURL so PHPUnit can characterize URL construction
     * without contacting a real WISA endpoint.
     *
     * @param string $queryname e.g. 'MCVOD_C', 'MCVOD_LKR', 'MCVOD_STUD'
     * @param array $params Optional additional parameters
     * @return string
     */
    protected function build_query_url($queryname, $params = []) {
        // Ensure URL ends with slash if not present, but usually the config has it.
        // Example: .../QUERY/MCVOD_C.
        $url = rtrim($this->apiurl, '/') . '/' . $queryname;

        $defaultparams = [
            'werkdatum' => date('Y-m-d'), // Default to today, can be overridden.
            'instellingsnummer' => $this->institutenum,
            'format' => 'json',
            '_username_' => $this->username,
            '_password_' => $this->password,
        ];

        $queryparams = array_merge($defaultparams, $params);

        // Build query string. Force "&" as separator - PHP's arg_separator.output
        // is "&amp;" under Moodle, which would corrupt every param after the first.
        $querystring = http_build_query($queryparams, '', '&');

        return $url . '?' . $querystring;
    }

    /**
     * Execute a WISA HTTP request.
     *
     * @param string $fullurl
     * @return array [$response, $httpcode, $error]
     */
    protected function execute_request($fullurl) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullurl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        // Grote (eerste) loads van MCVOD_INS/MCVOD_UIT kunnen >30s duren; ruim genomen.
        curl_setopt($ch, CURLOPT_TIMEOUT, 180);
        // Mimic a real browser.
        $useragent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36';
        curl_setopt($ch, CURLOPT_USERAGENT, $useragent);
        // Geen redirects volgen: de credentials staan in de query-string en mogen bij een
        // (kwaadaardige) redirect niet mee-verhuizen naar een andere host.
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [$response, $httpcode, $error];
    }

    /**
     * Mask the WISA credentials in any string before it is logged. The API takes
     * _username_/_password_ in the query string, so URLs, cURL error texts and
     * HTTP error bodies can all leak the cleartext password into the log table.
     *
     * @param string $text
     * @return string
     */
    protected function mask_secrets($text) {
        $text = preg_replace('/_password_=([^&\s]*)/', '_password_=[MASKED]', (string)$text);
        $text = preg_replace('/_username_=([^&\s]*)/', '_username_=[MASKED]', $text);
        return $text;
    }
}
