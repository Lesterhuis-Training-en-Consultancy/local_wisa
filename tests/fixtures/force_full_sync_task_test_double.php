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
 * Force-full task test double.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Injects a capturing synchronization manager into the force-full task.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class force_full_sync_task_test_double extends \local_wisa\task\force_full_sync_task {
    /** @var force_full_sync_manager_test_double Capturing manager. */
    private $manager;

    /**
     * Store the capturing manager.
     *
     * @param force_full_sync_manager_test_double $manager Capturing manager.
     * @return void
     */
    public function __construct(force_full_sync_manager_test_double $manager) {
        $this->manager = $manager;
    }

    /**
     * Return the capturing synchronization manager.
     *
     * @return sync_manager Capturing manager.
     */
    protected function create_sync_manager(): sync_manager {
        return $this->manager;
    }
}
