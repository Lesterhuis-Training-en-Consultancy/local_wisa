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
 * Plugin information for local_wisa SIS source subplugins.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\plugininfo;

use core\plugininfo\base;

/**
 * Describes the sissource subplugin type owned by local_wisa.
 */
class sissource extends base {
    /**
     * SIS source subplugins are independent adapters and can be uninstalled.
     *
     * @return bool
     */
    public function is_uninstall_allowed() {
        return true;
    }

    /**
     * Return the settings section name for a source subplugin.
     *
     * @return string
     */
    public function get_settings_section_name() {
        return $this->component;
    }

    /**
     * Load subplugin settings into the admin tree when a source owns settings.
     *
     * @param \part_of_admin_tree $adminroot Admin tree root.
     * @param string $parentnodename Parent admin node name.
     * @param bool $hassiteconfig Whether the user has site config capability.
     */
    public function load_settings(\part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig) {
        global $CFG, $USER, $DB, $OUTPUT, $PAGE; // Variables commonly expected by settings.php files.

        if (!$this->is_installed_and_upgraded()) {
            return;
        }

        $settingsfile = $this->full_path('settings.php');
        if (!$hassiteconfig || !file_exists($settingsfile)) {
            return;
        }

        $ADMIN = $adminroot;
        $plugininfo = $this;
        include($settingsfile);
    }
}
