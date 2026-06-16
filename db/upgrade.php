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
 * Upgrade steps for local_wisa.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Run the local_wisa upgrade steps.
 *
 * The only database object is local_wisa_log, created by install.xml and unchanged
 * since the initial release, so there are no steps yet. Add future schema changes
 * here, each guarded by an $oldversion check and closed with upgrade_plugin_savepoint().
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool
 */
function xmldb_local_wisa_upgrade($oldversion) {
    // No upgrade steps required yet.
    return true;
}
