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
 * Resolves source credentials from forced or encrypted plugin configuration.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Resolves a source credential without exposing persisted ciphertext to callers.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class credential_resolver {
    /**
     * Return a forced plaintext override or decrypt the stored configuration value.
     *
     * @param string $component Source component name.
     * @param string $settingname Credential setting name.
     * @return string Resolved credential, or an empty string when no valid value exists.
     */
    public static function resolve(string $component, string $settingname): string {
        global $CFG;

        if (
            isset($CFG->forced_plugin_settings[$component])
                && array_key_exists($settingname, $CFG->forced_plugin_settings[$component])
        ) {
            return (string)$CFG->forced_plugin_settings[$component][$settingname];
        }

        $storedvalue = get_config($component, $settingname);
        if ($storedvalue === false || $storedvalue === '') {
            return '';
        }

        try {
            return \core\encryption::decrypt((string)$storedvalue);
        } catch (\Throwable $exception) {
            self::log_decryption_failure($component, $settingname);
            return '';
        }
    }

    /**
     * Log a decryption failure without including credential data or exception details.
     *
     * @param string $component Source component name.
     * @param string $settingname Credential setting name.
     * @return void
     */
    private static function log_decryption_failure(string $component, string $settingname): void {
        debugging(
            'local_wisa credential decryption failed for ' . $component . '/' . $settingname . '.',
            DEBUG_DEVELOPER
        );
    }
}
