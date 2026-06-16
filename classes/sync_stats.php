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
 * Per-run counters for the WISA synchronisation.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

class sync_stats {
    public $course_create = 0;
    public $course_update = 0;
    public $course_fail = 0;
    public $course_skip = 0;
    public $user_create = 0;
    public $user_update = 0;
    public $user_fail = 0;
    public $enrol_create = 0;
    public $enrol_update = 0;
    public $enrol_warn = 0;
    public $enrol_fail = 0;
    public $enrol_skip = 0;
    public $unenrol_ok = 0;
    public $unenrol_warn = 0;
    public $unenrol_fail = 0;
    public $unenrol_skip = 0;
}
