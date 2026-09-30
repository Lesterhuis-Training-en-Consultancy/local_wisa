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
 * Upgrade steps for sissource_wisa.
 *
 * @package    sissource_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(dirname(__DIR__, 3) . '/classes/credential_migrator.php');
require_once(dirname(__DIR__, 3) . '/classes/source_stream_migrator.php');
require_once(dirname(__DIR__) . '/classes/source.php');

/**
 * Run the sissource_wisa upgrade steps.
 *
 * @param int $oldversion Version being upgraded from.
 * @return bool True when the upgrade completes.
 */
function xmldb_sissource_wisa_upgrade($oldversion) {
    if ($oldversion < 2026080501) {
        $persisted = (new \local_wisa\credential_migrator())->migrate([
            'sissource_wisa' => [
                'api_user',
                'api_pass',
            ],
        ]);
        if (!$persisted) {
            return false;
        }
        upgrade_plugin_savepoint(true, 2026080501, 'sissource', 'wisa');
    }

    if ($oldversion < 2026080700) {
        $migrated = \local_wisa\source_stream_migrator::migrate_component(
            'sissource_wisa',
            \sissource_wisa\source::get_stream_registry()
        );
        if ($migrated) {
            upgrade_plugin_savepoint(true, 2026080700, 'sissource', 'wisa');
        }
    }

    return true;
}
