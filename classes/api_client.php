<?php
namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

class api_client {
    private $api_url;
    private $username;
    private $password;
    private $institute_num;

    public function __construct() {
        $this->api_url = get_config('local_wisa', 'api_url');
        $this->username = trim(get_config('local_wisa', 'api_user'));
        $this->password = trim(get_config('local_wisa', 'api_pass'));
        $this->institute_num = get_config('local_wisa', 'institute_num');
    }

    /**
     * Fetch data from a specific WISA query endpoint.
     *
     * @param string $query_name e.g., 'MCVO_C', 'MCVO_LKR', 'MCVO_STUD'
     * @param array $params Optional additional parameters
     * @return array|false Decoded JSON response or false on failure
     */
    public function fetch($query_name, $params = []) {
        if (empty($this->api_url) || empty($this->username) || empty($this->password)) {
            logger::log('api_connect', 'system', 'config', 'fail', 'Missing API configuration.');
            return false;
        }

        // Ensure URL ends with slash if not present, but usually the config has it.
        // The user example shows .../QUERY/MCVO_C
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

        // Log the URL for debugging (masking credentials).
        $debug_url = preg_replace('/_password_=([^&]*)/', '_password_=[MASKED]', $full_url);
        $debug_url = preg_replace('/_username_=([^&]*)/', '_username_=[MASKED]', $debug_url);
        logger::log('api_debug', 'system', 'url', 'info', "Requesting: $debug_url");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $full_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        // Mimic a real browser
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5'
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            logger::log('api_fetch', 'system', $query_name, 'fail', "cURL Error: $error");
            return false;
        }

        if ($http_code !== 200) {
            // Log the response body which might contain error details
            $snippet = substr(strip_tags($response), 0, 200);
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

    public function get_courses($date = null) {
        $params = [];
        if ($date) {
            $params['werkdatum'] = $date;
        }
        return $this->fetch('MCVO_C', $params);
    }

    public function get_teachers($date = null) {
        $params = [];
        if ($date) {
            $params['werkdatum'] = $date;
        }
        return $this->fetch('MCVO_LKR', $params);
    }

    public function get_students($date = null) {
        $params = [];
        if ($date) {
            $params['werkdatum'] = $date;
        }
        return $this->fetch('MCVO_STUD', $params);
    }
}
