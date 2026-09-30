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
 * Follow-up task seam for provisioning service tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Follow-up task seam for source-lock release coverage.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provision_followup_task_test_double extends \local_wisa\task\provision_followup_task {
    /** @var callable|null Lock-release checkpoint supplied by a test. */
    private $beforeruncallback;

    /**
     * Set the callback that runs after provision locks are released.
     *
     * @param callable|null $callback Lock-release callback.
     * @return void
     */
    public function set_before_run_callback(?callable $callback): void {
        $this->beforeruncallback = $callback;
    }

    /**
     * Run the lock-release checkpoint before source work.
     *
     * @param \stdClass $record Validated terminal provision record.
     * @return void
     */
    protected function before_followup_sync(\stdClass $record): void {
        if ($this->beforeruncallback !== null) {
            call_user_func($this->beforeruncallback, $record);
        }
    }
}
