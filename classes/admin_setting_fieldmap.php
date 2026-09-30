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
 * Validated field mapping admin setting.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Rejects invalid field mapping JSON before Moodle persists it.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_fieldmap extends \admin_setting_configtextarea {
    /** @var array Adapter-specific targets keyed by record type. */
    private $additionaltargets = [];

    /**
     * Allow adapter-specific field mapping targets.
     *
     * @param array $additionaltargets Targets keyed by record type.
     * @return void
     */
    public function set_additional_targets(array $additionaltargets): void {
        $this->additionaltargets = $additionaltargets;
    }

    /**
     * Validate field mapping JSON before storage.
     *
     * @param string $data Submitted setting value.
     * @return bool|string True when valid, otherwise a localized error.
     */
    public function validate($data): bool|string {
        $parentresult = parent::validate($data);
        if ($parentresult !== true) {
            return $parentresult;
        }
        if (!source_field_mapper::is_valid_configuration((string)$data, $this->additionaltargets)) {
            return get_string('fieldmap_invalid', 'local_wisa');
        }
        return true;
    }
}
