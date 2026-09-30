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
 * Force-full synchronization manager test double.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Captures force-full synchronization arguments without source work.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class force_full_sync_manager_test_double extends sync_manager {
    /** @var bool|null Captured force-full argument. */
    public $forcefull;

    /** @var bool|null Captured force-live argument. */
    public $forcelive;

    /** @var bool Fixture synchronization result. */
    public $result = true;

    /** @var string Fixture synchronization status. */
    public $status = 'success';

    /** @var \Throwable|null Fixture failure. */
    public $exception;

    /**
     * Avoid constructing a source adapter for this task seam.
     *
     * @return void
     */
    public function __construct() {
    }

    /**
     * Capture the requested synchronization mode.
     *
     * @param bool $forcefull Whether delta lower bounds are disabled.
     * @param bool $forcelive Whether dry-run is bypassed.
     * @return bool Successful fixture result.
     */
    public function run_full_sync(bool $forcefull = false, bool $forcelive = false): bool {
        $this->forcefull = $forcefull;
        $this->forcelive = $forcelive;
        if ($this->exception !== null) {
            throw $this->exception;
        }
        return $this->result;
    }

    /**
     * Return the status produced by the current fixture run.
     *
     * @return string Current fixture status.
     */
    public function get_run_status(): ?string {
        return $this->status;
    }
}
