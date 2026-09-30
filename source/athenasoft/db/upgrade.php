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
 * Upgrade steps for sissource_athenasoft.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(dirname(__DIR__, 3) . '/classes/role_resolver.php');
require_once(dirname(__DIR__, 3) . '/classes/credential_migrator.php');
require_once(dirname(__DIR__, 3) . '/classes/source_stream_migrator.php');

/**
 * Run the sissource_athenasoft upgrade steps.
 *
 * @param int $oldversion Version being upgraded from.
 * @return bool True when the upgrade completes, otherwise false.
 */
function xmldb_sissource_athenasoft_upgrade($oldversion) {
    if ($oldversion < 2026080500) {
        global $DB;

        $configured = get_config('local_wisa', 'rolemap');
        $rolemap = $configured === false ? null : \local_wisa\role_resolver::parse_rolemap((string)$configured);
        $existingmapisvalid = $rolemap !== null;
        $effectiverolemap = \local_wisa\role_resolver::default_rolemap();
        if (!$existingmapisvalid) {
            $rolemap = \local_wisa\role_resolver::default_rolemap();
        } else {
            $effectiverolemap = array_replace($effectiverolemap, $rolemap);
        }

        $canpersist = true;
        $changed = !$existingmapisvalid;
        foreach (['cursist' => 'rolemap_cursist', 'leerkracht' => 'rolemap_leerkracht'] as $token => $setting) {
            $legacyvalue = get_config('sissource_athenasoft', $setting);
            if ($legacyvalue === false || trim((string)$legacyvalue) === '') {
                continue;
            }
            $legacyvalue = trim((string)$legacyvalue);
            $legacytoken = \core_text::strtolower($legacyvalue);
            if (isset($effectiverolemap[$legacytoken])) {
                $shortname = $effectiverolemap[$legacytoken];
            } else if ($DB->record_exists('role', ['shortname' => $legacyvalue])) {
                $shortname = $legacyvalue;
            } else {
                $canpersist = false;
                break;
            }

            if ($existingmapisvalid && isset($rolemap[$token])) {
                continue;
            }
            $rolemap[$token] = $shortname;
            $changed = true;
        }

        $persisted = $canpersist;
        if ($persisted && $changed) {
            $persisted = set_config('rolemap', json_encode($rolemap), 'local_wisa');
        }
        if ($persisted) {
            unset_config('rolemap_cursist', 'sissource_athenasoft');
            unset_config('rolemap_leerkracht', 'sissource_athenasoft');
        }

        if (!$persisted) {
            return false;
        }

        upgrade_plugin_savepoint(true, 2026080500, 'sissource', 'athenasoft');
    }

    if ($oldversion < 2026080501) {
        $persisted = (new \local_wisa\credential_migrator())->migrate([
            'sissource_athenasoft' => ['api_key', 'auth_user', 'auth_cred'],
        ]);
        if (!$persisted) {
            return false;
        }

        upgrade_plugin_savepoint(true, 2026080501, 'sissource', 'athenasoft');
    }

    if ($oldversion < 2026080702) {
        if (
            !\local_wisa\source_stream_migrator::migrate_component(
                'sissource_athenasoft',
                \sissource_athenasoft\source::get_stream_registry()
            )
        ) {
            return false;
        }
        upgrade_plugin_savepoint(true, 2026080702, 'sissource', 'athenasoft');
    }

    return true;
}
