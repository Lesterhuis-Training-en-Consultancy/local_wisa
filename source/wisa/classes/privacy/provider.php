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
 * Privacy provider for sissource_wisa.
 *
 * @package    sissource_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_wisa\privacy;

use core_privacy\local\metadata\collection;

/**
 * Describes the external WISA/Schoolware API used by this source adapter.
 */
class provider implements \core_privacy\local\metadata\provider, \core_privacy\local\request\plugin\provider {
    /**
     * Describe the external WISA API.
     *
     * @param collection $collection Metadata collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link('wisa_api', [
            'username' => 'privacy:metadata:wisa_api:username',
            'firstname' => 'privacy:metadata:wisa_api:firstname',
            'lastname' => 'privacy:metadata:wisa_api:lastname',
            'email' => 'privacy:metadata:wisa_api:email',
        ], 'privacy:metadata:wisa_api');

        return $collection;
    }

    /**
     * This source stores no local user data, so no contexts are returned.
     *
     * @param int $userid The user id.
     * @return \core_privacy\local\request\contextlist
     */
    public static function get_contexts_for_userid(int $userid): \core_privacy\local\request\contextlist {
        unset($userid);
        return new \core_privacy\local\request\contextlist();
    }

    /**
     * This source stores no local user data outside parent local_wisa logs.
     *
     * @param \core_privacy\local\request\approved_contextlist $contextlist Approved contexts.
     */
    public static function export_user_data(\core_privacy\local\request\approved_contextlist $contextlist) {
        unset($contextlist);
        // No local storage in this subplugin.
    }

    /**
     * Delete all user data in a context.
     *
     * @param \context $context Context.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        unset($context);
        // No local storage in this subplugin.
    }

    /**
     * Delete data for one user.
     *
     * @param \core_privacy\local\request\approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(\core_privacy\local\request\approved_contextlist $contextlist) {
        unset($contextlist);
        // No local storage in this subplugin.
    }
}
