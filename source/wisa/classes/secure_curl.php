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
 * Moodle HTTP client with credential-safe URL security checks.
 *
 * @package    sissource_wisa
 * @copyright  2026 Sebsoft.nl <sebastian@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_wisa;

/**
 * Keep core's blocked-host/port and DNS protections without logging WISA credentials.
 *
 * Moodle's core curl emits a url_blocked event with its checked URL when a
 * security rule rejects it. WISA authenticates using query parameters, so
 * check an equivalent URL with only the credential values redacted instead.
 * The actual request still uses the original URL and core's DNS pinning.
 */
class secure_curl extends \curl {
    /**
     * Redact credential values before Moodle's blocked-URL event is emitted.
     *
     * @param string $url Original request URL.
     * @return string Redacted URL with the same host and port.
     */
    public static function redact_url(string $url): string {
        $safeurl = preg_replace('/([?&](?:_username_|_password_)=)[^&#]*/i', '$1redacted', $url);
        if ($safeurl === null) {
            throw new \coding_exception('Unable to validate WISA endpoint');
        }
        return $safeurl;
    }

    /**
     * Run Moodle's URL security check without exposing credential values.
     *
     * @param string $url Original request URL.
     * @return string|null Core's blocked URL reason, if any.
     */
    protected function check_securityhelper_blocklist(string $url): ?string {
        return parent::check_securityhelper_blocklist(self::redact_url($url));
    }
}
