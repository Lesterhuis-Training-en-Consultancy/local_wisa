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
 * Per-run counters for the SIS synchronisation.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Value object that stores counters for one synchronisation run.
 */
class sync_stats {
    /** @var int Created courses. */
    public $coursecreate = 0;

    /** @var int Updated courses. */
    public $courseupdate = 0;

    /** @var int Failed course rows. */
    public $coursefail = 0;

    /** @var int Skipped course rows. */
    public $courseskip = 0;

    /** @var int Created users. */
    public $usercreate = 0;

    /** @var int Updated users. */
    public $userupdate = 0;

    /** @var int Failed user rows. */
    public $userfail = 0;

    /** @var int Created enrolments. */
    public $enrolcreate = 0;

    /** @var int Updated enrolments. */
    public $enrolupdate = 0;

    /** @var int Enrolment warnings. */
    public $enrolwarn = 0;

    /** @var int Failed enrolment rows. */
    public $enrolfail = 0;

    /** @var int Skipped enrolment rows. */
    public $enrolskip = 0;

    /** @var int Suspended enrolments. */
    public $unenrolok = 0;

    /** @var int Unenrolment warnings. */
    public $unenrolwarn = 0;

    /** @var int Failed unenrolment rows. */
    public $unenrolfail = 0;

    /** @var int Skipped unenrolment rows. */
    public $unenrolskip = 0;
}
