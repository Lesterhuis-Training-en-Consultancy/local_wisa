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
 * Database logger for local_wisa.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;


/**
 * Writes local_wisa audit rows to the plugin log table.
 */
class logger {
    /**
     * Insert a log row for a sync or API action.
     *
     * @param string $action Action name.
     * @param string $objecttype Object type.
     * @param string $objectid Object identifier.
     * @param string $status Log status.
     * @param string $message Log message.
     */
    public static function log($action, $objecttype, $objectid, $status, $message = '') {
        global $DB;

        $record = new \stdClass();
        $record->timecreated = time();
        $record->action = substr($action, 0, 20);
        $record->objecttype = substr($objecttype, 0, 20);
        $record->objectid = substr($objectid, 0, 100);
        $record->status = substr($status, 0, 10);
        $record->message = $message;

        try {
            $DB->insert_record('local_wisa_log', $record);
        } catch (\Exception $e) {
            // Fallback logging if the database insert fails.
            debugging('local_wisa logging failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
