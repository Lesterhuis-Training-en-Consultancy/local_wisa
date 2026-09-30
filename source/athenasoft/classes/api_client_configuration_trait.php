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
 * AthenaSoft API client configuration and redacted logging.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;

use local_wisa\logger;

/**
 * Supplies API configuration validation and secret-safe logging.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait api_client_configuration_trait {
    /**
     * Mask configured secrets and password field values in log text.
     *
     * @param string $text Text to mask.
     * @return string Masked text.
     */
    protected function mask_secrets(string $text): string {
        $masked = $text;
        foreach ($this->password_field_names() as $fieldname) {
            $fieldpattern = preg_quote($fieldname, '/');
            $jsonmasked = preg_replace(
                '/("' . $fieldpattern . '"\s*:\s*)("(?:\\\\.|[^"\\\\])*(?:"|$)|[^,\}\]\s]*)/i',
                '$1"[MASKED]"',
                $masked
            );
            if ($jsonmasked !== null) {
                $masked = $jsonmasked;
            }

            $keyvaluemasked = preg_replace(
                '/((?<![A-Za-z0-9_])' . $fieldpattern . '(?![A-Za-z0-9_])\s*[:=]\s*)' .
                    '("(?:\\\\.|[^"\\\\])*(?:"|$)|[^,\s&<>\}\]]*)/i',
                '$1[MASKED]',
                $masked
            );
            if ($keyvaluemasked !== null) {
                $masked = $keyvaluemasked;
            }
        }

        foreach ([$this->apikey, $this->authuser, $this->authcred] as $secret) {
            $secret = (string)$secret;
            if ($secret !== '') {
                $masked = str_replace($secret, '[MASKED]', $masked);
            }
        }
        return $masked;
    }

    /**
     * Return static and configured password source field names.
     *
     * @return array Password source field names.
     */
    private function password_field_names(): array {
        $fieldnames = ['password', 'pasword'];
        $fieldmap = json_decode((string)get_config('sissource_athenasoft', 'fieldmap'), true);
        $configured = is_array($fieldmap) ? ($fieldmap['user']['password'] ?? null) : null;
        if (is_string($configured) && trim($configured) !== '') {
            $fieldnames[] = trim($configured);
        }
        return array_values(array_unique($fieldnames));
    }

    /**
     * Load current Moodle configuration into the client.
     *
     * @return void
     */
    private function load_config(): void {
        $this->apiurl = trim((string)get_config('sissource_athenasoft', 'api_url'));
        $this->apikey = trim(\local_wisa\credential_resolver::resolve('sissource_athenasoft', 'api_key'));
        $this->authuser = trim(\local_wisa\credential_resolver::resolve('sissource_athenasoft', 'auth_user'));
        $this->authcred = trim(\local_wisa\credential_resolver::resolve('sissource_athenasoft', 'auth_cred'));
        $this->institution = trim((string)get_config('sissource_athenasoft', 'institution'));
        $this->periode = trim((string)get_config('sissource_athenasoft', 'periode'));
        $this->extraparams = (string)get_config('sissource_athenasoft', 'extra_params');
        $this->debug = (bool)get_config('local_wisa', 'debug_logging');
    }

    /**
     * Check whether all required settings are present.
     *
     * @return bool True when all required config values are usable.
     */
    private function has_required_config(): bool {
        foreach ([$this->apiurl, $this->apikey, $this->authuser, $this->authcred, $this->institution, $this->periode] as $value) {
            if (trim((string)$value) === '') {
                return false;
            }
        }
        return !((is_numeric($this->institution) && (int)$this->institution === 0) ||
            (is_numeric($this->periode) && (int)$this->periode === 0));
    }

    /**
     * Write a masked log row.
     *
     * @param string $action Action name.
     * @param string $objecttype Object type.
     * @param string $objectid Object identifier.
     * @param string $status Log status.
     * @param string $message Log message.
     * @return void
     */
    private function log(string $action, string $objecttype, string $objectid, string $status, string $message): void {
        logger::log(
            $this->mask_secrets($action),
            $this->mask_secrets($objecttype),
            $this->mask_secrets($objectid),
            $this->mask_secrets($status),
            $this->mask_secrets($message)
        );
    }
}
