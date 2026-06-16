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
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

class api_client {
    private $api_url;
    private $username;
    private $password;
    private $institute_num;
    /** @var array Configurable query codes, keyed by logical role. */
    private $queries;
    /** @var string|null Delta watermark passed as the :sinds query parameter. */
    private $sinds = null;
    /** @var bool Log every API URL — debugging only; off by default to avoid log bloat. */
    private $debug = false;

    public function __construct() {
        $this->api_url = get_config('local_wisa', 'api_url');
        $this->username = trim(get_config('local_wisa', 'api_user'));
        $this->password = trim(get_config('local_wisa', 'api_pass'));
        $this->institute_num = get_config('local_wisa', 'institute_num');
        $this->debug = (bool)get_config('local_wisa', 'debug_logging');
        $this->queries = [
            'courses'      => get_config('local_wisa', 'query_courses') ?: 'MCVOD_C',
            'students'     => get_config('local_wisa', 'query_students') ?: 'MCVOD_STUD',
            'teachers'     => get_config('local_wisa', 'query_teachers') ?: 'MCVOD_LKR',
            'enrolments'   => get_config('local_wisa', 'query_enrolments') ?: 'MCVOD_INS',
            'unenrolments' => get_config('local_wisa', 'query_unenrolments') ?: 'MCVOD_UIT',
        ];
    }

    /** Set the delta watermark used by the get_* helpers. */
    public function set_sinds($sinds) {
        $this->sinds = $sinds;
    }

    /**
     * Fetch data from a specific WISA query endpoint.
     *
     * @param string $query_name e.g. 'MCVOD_C', 'MCVOD_LKR', 'MCVOD_STUD'
     * @param array $params Optional additional parameters
     * @return array|false Decoded JSON response or false on failure
     */
    public function fetch($query_name, $params = []) {
        if (empty($this->api_url) || empty($this->username) || empty($this->password)) {
            logger::log('api_connect', 'system', 'config', 'fail', 'Missing API configuration.');
            return false;
        }

        // Ensure URL ends with slash if not present, but usually the config has it.
        // Example: .../QUERY/MCVOD_C
        $url = rtrim($this->api_url, '/') . '/' . $query_name;

        $default_params = [
            'werkdatum' => date('Y-m-d'), // Default to today, can be overridden
            'instellingsnummer' => $this->institute_num,
            'format' => 'json',
            '_username_' => $this->username,
            '_password_' => $this->password,
        ];

        $query_params = array_merge($default_params, $params);
        
        // Build query string. Force "&" as separator - PHP's arg_separator.output
        // is "&amp;" under Moodle, which would corrupt every param after the first.
        $query_string = http_build_query($query_params, '', '&');
        
        $full_url = $url . '?' . $query_string;

        // Log de URL (creds gemaskeerd) — alleen als debug-logging aanstaat, anders log-bloat.
        if ($this->debug) {
            logger::log('api_debug', 'system', 'url', 'info', 'Requesting: ' . $this->mask_secrets($full_url));
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $full_url);
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
            'Accept-Language: en-US,en;q=0.5'
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            logger::log('api_fetch', 'system', $query_name, 'fail',
                'cURL Error: ' . $this->mask_secrets($error));
            return false;
        }

        if ($http_code !== 200) {
            // Log the response body which might contain error details (creds masked).
            $snippet = $this->mask_secrets(substr(strip_tags($response), 0, 200));
            logger::log('api_fetch', 'system', $query_name, 'fail', "HTTP Error: $http_code. Response: $snippet");
            return false;
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            logger::log('api_fetch', 'system', $query_name, 'fail', "JSON Decode Error: " . json_last_error_msg());
            return false;
        }

        return $data;
    }

    /**
     * Mask the WISA credentials in any string before it is logged. The API takes
     * _username_/_password_ in the query string, so URLs, cURL error texts and
     * HTTP error bodies can all leak the cleartext password into the log table.
     *
     * @param string $text
     * @return string
     */
    private function mask_secrets($text) {
        $text = preg_replace('/_password_=([^&\s]*)/', '_password_=[MASKED]', (string)$text);
        $text = preg_replace('/_username_=([^&\s]*)/', '_username_=[MASKED]', $text);
        return $text;
    }

    /**
     * Build the delta parameter for the MCVOD queries. An empty watermark
     * means a full load (everything changed since 1900).
     */
    private function delta_params() {
        return ['sinds' => $this->sinds ?: '1900-01-01 00:00:00'];
    }

    public function get_courses() {
        return $this->fetch($this->queries['courses'], $this->delta_params());
    }

    public function get_teachers() {
        return $this->fetch($this->queries['teachers'], $this->delta_params());
    }

    public function get_students() {
        return $this->fetch($this->queries['students'], $this->delta_params());
    }

    public function get_enrolments() {
        return $this->fetch($this->queries['enrolments'], $this->delta_params());
    }

    public function get_unenrolments() {
        return $this->fetch($this->queries['unenrolments'], $this->delta_params());
    }
}
