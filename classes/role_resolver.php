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
 * Resolves configured SIS role tokens to Moodle role IDs.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Resolves source role tokens through the parent JSON role map.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class role_resolver {
    /** @var array Source token to Moodle role shortname mapping. */
    private $rolemap;

    /** @var array Moodle role ID cache keyed by shortname. */
    private $roleids = [];

    /**
     * Construct a resolver using the current parent role-map configuration.
     *
     * @return void
     */
    public function __construct() {
        $this->rolemap = $this->configured_rolemap();
    }

    /**
     * Resolve one source token to an existing Moodle role ID.
     *
     * @param string $token Source role token.
     * @return int|null Moodle role ID or null when no safe mapping exists.
     */
    public function resolve(string $token): ?int {
        global $DB;

        $token = $this->normalise_token($token);
        if (!isset($this->rolemap[$token])) {
            logger::log('sync_enrol', 'enrollment', $token, 'warn', 'ROLE_TOKEN_UNKNOWN');
            return null;
        }

        $shortname = $this->rolemap[$token];
        if (!array_key_exists($shortname, $this->roleids)) {
            $this->roleids[$shortname] = (int)$DB->get_field('role', 'id', ['shortname' => $shortname]);
        }
        if ($this->roleids[$shortname] <= 0) {
            logger::log(
                'sync_enrol',
                'enrollment',
                'redacted',
                'warn',
                'ROLE_MAPPING_UNAVAILABLE'
            );
            return null;
        }

        return $this->roleids[$shortname];
    }

    /**
     * Return the semantic default token map.
     *
     * @return array Source token to Moodle role shortname map.
     */
    public static function default_rolemap(): array {
        return [
            'student' => 'student',
            'teacher' => 'editingteacher',
            'cursist' => 'student',
            'leerkracht' => 'editingteacher',
        ];
    }

    /**
     * Parse a safe non-empty JSON object mapping tokens to role shortnames.
     *
     * @param string $json Raw JSON role map.
     * @return array|null Parsed map or null when the JSON is not a safe map.
     */
    public static function parse_rolemap(string $json): ?array {
        $decoded = json_decode(trim($json), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || $decoded === []) {
            return null;
        }
        if (array_keys($decoded) === range(0, count($decoded) - 1)) {
            return null;
        }

        $rolemap = [];
        foreach ($decoded as $token => $shortname) {
            if (!is_string($token) || !is_string($shortname)) {
                return null;
            }
            $token = self::normalise_token($token);
            $shortname = trim($shortname);
            if ($token === '' || $shortname === '') {
                return null;
            }
            $rolemap[$token] = $shortname;
        }

        return $rolemap;
    }

    /**
     * Read configured role-map overrides over the semantic defaults.
     *
     * @return array Source token to Moodle role shortname map.
     */
    private function configured_rolemap(): array {
        $configured = get_config('local_wisa', 'rolemap');
        if ($configured === false || trim((string)$configured) === '') {
            return self::default_rolemap();
        }

        $rolemap = self::parse_rolemap((string)$configured);
        if ($rolemap === null) {
            logger::log(
                'api_config',
                'mapping',
                'redacted',
                'warning',
                'Invalid rolemap JSON for local_wisa; semantic defaults remain active.'
            );
            return self::default_rolemap();
        }

        return array_replace(self::default_rolemap(), $rolemap);
    }

    /**
     * Normalise source role tokens consistently.
     *
     * @param string $token Source role token.
     * @return string Normalised token.
     */
    private static function normalise_token(string $token): string {
        return \core_text::strtolower(trim($token));
    }
}
