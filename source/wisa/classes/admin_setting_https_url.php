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
 * WISA API endpoint setting.
 *
 * @package    sissource_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_wisa;

/**
 * Prevent credentials from being sent to an insecure or malformed endpoint.
 */
class admin_setting_https_url extends \admin_setting_configtext {
    /**
     * Check the endpoint before saving it.
     *
     * @param string $data Submitted endpoint.
     * @return bool|string True for valid values, otherwise an error message.
     */
    public function validate($data): bool|string {
        $valid = parent::validate($data);
        if ($valid !== true) {
            return $valid;
        }
        if (trim((string)$data) !== '' && !api_client::is_valid_api_url((string)$data)) {
            return get_string('api_url_https_required', 'sissource_wisa');
        }
        return true;
    }
}