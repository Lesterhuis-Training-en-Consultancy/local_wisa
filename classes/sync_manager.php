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
 * Orchestrates a WISA synchronisation run.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

use local_wisa\sync\course_sync;
use local_wisa\sync\user_sync;
use local_wisa\sync\enrollment_sync;
use local_wisa\sync\unenrollment_sync;

class sync_manager {
    private $api;
    private $dryrun;

    public function __construct() {
        $this->api = new api_client();
        $this->dryrun = (bool)get_config('local_wisa', 'dry_run');
    }

    public function run_full_sync($forcefull = false, $forcelive = false) {
        // The approval page / CLI --approve pass $forcelive to run the first real
        // load regardless of the dry-run setting.
        if ($forcelive) {
            $this->dryrun = false;
        }
        $factory = \core\lock\lock_config::get_lock_factory('local_wisa');
        $lock = $factory->get_lock('full_sync', 0);
        if (!$lock) {
            logger::log('sync_skip', 'system', 'lock', 'warn',
                'Another WISA sync is already running, skipped this run.');
            return;
        }

        try {
            // Volledige eerste load (MCVOD_INS/UIT) kan veel geheugen vragen.
            raise_memory_limit(MEMORY_HUGE);
            $stats = new sync_stats();
            $start = microtime(true);
            $runstart = time();
            $mode = $this->dryrun ? 'DRY-RUN' : 'LIVE';
            $window = $this->schoolyear_window();

            logger::log('sync_start', 'system', 'all', 'info',
                "Starting synchronization ($mode), schooljaar=" . $this->window_label($window) . '.');

            // Elke fase apart in/uit te schakelen, met een eigen delta-watermark. De
            // watermark schuift alleen door wanneer run() succes meldt (de fetch is
            // gelukt); zo worden records van een mislukte fase niet permanent gemist.
            if ($this->enabled('courses')) {
                $this->api->set_sinds($this->fase_sinds('courses', $forcefull));
                if ((new course_sync($this->api, $this->dryrun, $stats, $window))->run()) {
                    $this->advance_watermark('courses', $runstart);
                }
            }
            if ($this->enabled('students')) {
                $this->api->set_sinds($this->fase_sinds('students', $forcefull));
                if ((new user_sync($this->api, $this->dryrun, $stats))->run('students')) {
                    $this->advance_watermark('students', $runstart);
                }
            }
            if ($this->enabled('teachers')) {
                $this->api->set_sinds($this->fase_sinds('teachers', $forcefull));
                if ((new user_sync($this->api, $this->dryrun, $stats))->run('teachers')) {
                    $this->advance_watermark('teachers', $runstart);
                }
            }
            if ($this->enabled('enrolments')) {
                $dot = get_config('local_wisa', 'enrol_teachers') !== '0';
                $dos = get_config('local_wisa', 'enrol_students') !== '0';
                if ($dot || $dos) {
                    // Eén feed (MCVOD_INS), maar leraar- en cursist-inschrijvingen hebben elk een
                    // eigen watermark. Haal op vanaf de oudste ingeschakelde watermark; de sync is
                    // idempotent, dus al verwerkte rijen zijn onschadelijk.
                    $this->api->set_sinds($this->enrol_sinds($dot, $dos, $forcefull));
                    if ((new enrollment_sync($this->api, $this->dryrun, $stats, $window, $dot, $dos))->run()) {
                        if ($dot) {
                            $this->advance_watermark('enrol_teachers', $runstart);
                        }
                        if ($dos) {
                            $this->advance_watermark('enrol_students', $runstart);
                        }
                    }
                }
            }
            if ($this->enabled('unenrolments')) {
                $this->api->set_sinds($this->fase_sinds('unenrolments', $forcefull));
                if ((new unenrollment_sync($this->api, $this->dryrun, $stats))->run()) {
                    $this->advance_watermark('unenrolments', $runstart);
                }
            }

            $duration = round(microtime(true) - $start, 1);
            $summary = sprintf(
                '[%s] %ss | sj=%s | courses c=%d u=%d f=%d skip=%d | users c=%d u=%d f=%d '
                . '| enrol c=%d u=%d w=%d f=%d skip=%d | unenrol ok=%d w=%d f=%d skip=%d',
                $mode, $duration, $this->window_label($window),
                $stats->course_create, $stats->course_update, $stats->course_fail, $stats->course_skip,
                $stats->user_create, $stats->user_update, $stats->user_fail,
                $stats->enrol_create, $stats->enrol_update, $stats->enrol_warn, $stats->enrol_fail, $stats->enrol_skip,
                $stats->unenrol_ok, $stats->unenrol_warn, $stats->unenrol_fail, $stats->unenrol_skip
            );

            logger::log('sync_summary', 'system', 'all', 'info', $summary);
            set_config('last_run_time', time(), 'local_wisa');
            set_config('last_run_summary', $summary, 'local_wisa');
            set_config('last_run_dryrun', $this->dryrun ? 1 : 0, 'local_wisa');
            if (!$this->dryrun) {
                // A successful live run accepts the data load; open the gate so the
                // scheduled task may run from now on.
                set_config('initial_load_done', 1, 'local_wisa');
            }
        } finally {
            $lock->release();
        }
    }

    /** Of een sync-onderdeel is ingeschakeld (default aan). */
    private function enabled($part) {
        return get_config('local_wisa', 'enable_' . $part) !== '0';
    }

    /** Bepaal de sinds-parameter voor één fase uit diens eigen watermark. */
    private function fase_sinds($part, $forcefull) {
        $wm = (int)get_config('local_wisa', 'wm_' . $part);
        if ($forcefull || $wm <= 0) {
            return '1900-01-01 00:00:00';
        }
        return date('Y-m-d H:i:s', $wm - 300); // 5 min overlap; sync is idempotent
    }

    /** Verzet de watermark van een fase, alleen na een geslaagde live (niet-dry-run) run. */
    private function advance_watermark($part, $runstart) {
        if (!$this->dryrun) {
            set_config('wm_' . $part, $runstart, 'local_wisa');
        }
    }

    /**
     * Sinds-parameter voor de inschrijvingsfeed: de oudste watermark van de ingeschakelde
     * rollen (leraren/cursisten). Zo komen, wanneer een rol na een uit-periode weer aangaat,
     * de intussen gemiste inschrijvingen alsnog mee.
     */
    private function enrol_sinds($dot, $dos, $forcefull) {
        $candidates = [];
        if ($dot) {
            $candidates[] = $this->fase_sinds('enrol_teachers', $forcefull);
        }
        if ($dos) {
            $candidates[] = $this->fase_sinds('enrol_students', $forcefull);
        }
        return $candidates ? min($candidates) : '1900-01-01 00:00:00';
    }

    /**
     * Schooljaarvenster [van_ts, tot_ts] of null (filter uit). Een schooljaar loopt
     * van 1 september tot 31 augustus. 'current' = lopend schooljaar, 'current_next'
     * = lopend + volgend.
     */
    private function schoolyear_window() {
        $scope = get_config('local_wisa', 'schoolyear_scope') ?: 'current_next';
        if ($scope === 'off') {
            return null;
        }
        $y = (int)date('Y');
        $m = (int)date('n');
        $startyear = ($m >= 9) ? $y : $y - 1;     // 1 september van het lopende schooljaar
        $extra = ($scope === 'current') ? 1 : 2;  // aantal schooljaren in scope
        $van = make_timestamp($startyear, 9, 1, 0, 0, 0);
        $tot = make_timestamp($startyear + $extra, 8, 31, 23, 59, 59);
        return [$van, $tot];
    }

    private function window_label($window) {
        return $window === null ? 'off' : (date('Y-m-d', $window[0]) . '..' . date('Y-m-d', $window[1]));
    }

    /**
     * Read-only preview of the next full load: per enabled part, how many records
     * would be fetched, how many fall inside the school-year window, and how many
     * are new. Writes nothing and logs nothing — used by the approval page so an
     * admin can review before the first load runs.
     *
     * @return array ['window' => string, 'parts' => array]
     */
    public function preview() {
        raise_memory_limit(MEMORY_HUGE);
        $window = $this->schoolyear_window();
        $this->api->set_sinds('1900-01-01 00:00:00'); // Full load.
        $result = ['window' => $this->window_label($window), 'parts' => []];

        if ($this->enabled('courses')) {
            $result['parts']['courses'] = $this->count_courses($this->api->get_courses(), $window);
        }
        if ($this->enabled('students')) {
            $result['parts']['students'] = $this->count_users($this->api->get_students());
        }
        if ($this->enabled('teachers')) {
            $result['parts']['teachers'] = $this->count_users($this->api->get_teachers());
        }
        if ($this->enabled('enrolments')) {
            $dot = get_config('local_wisa', 'enrol_teachers') !== '0';
            $dos = get_config('local_wisa', 'enrol_students') !== '0';
            $result['parts']['enrolments'] = $this->count_enrol($this->api->get_enrolments(), $window, $dot, $dos);
        }
        if ($this->enabled('unenrolments')) {
            $result['parts']['unenrolments'] = $this->count_simple($this->api->get_unenrolments());
        }
        return $result;
    }

    /** Count courses: fetched, inside the window, and new (not yet in Moodle). */
    private function count_courses($rows, $window) {
        global $DB;
        if (!is_array($rows)) {
            return ['error' => true];
        }
        $inscope = 0;
        $new = 0;
        foreach ($rows as $r) {
            $r = (array)$r;
            $klas = trim($r['KLAS_ID'] ?? '');
            if ($klas === '') {
                continue;
            }
            $sd = !empty($r['BEGINDATUM']) ? strtotime($r['BEGINDATUM']) : 0;
            $ed = !empty($r['EINDDATUM']) ? strtotime($r['EINDDATUM']) : 0;
            if ($window !== null && (($ed && $ed < $window[0]) || ($sd && $sd > $window[1]))) {
                continue;
            }
            $inscope++;
            if (!$DB->record_exists('course', ['idnumber' => $klas])) {
                $new++;
            }
        }
        return ['fetched' => count($rows), 'in_scope' => $inscope, 'new' => $new, 'existing' => $inscope - $new];
    }

    /** Count users: fetched and new (no account with this idnumber yet). */
    private function count_users($rows) {
        global $DB;
        if (!is_array($rows)) {
            return ['error' => true];
        }
        $new = 0;
        foreach ($rows as $r) {
            $r = (array)$r;
            $idnumber = trim($r['IDNUMBER'] ?? $r['USERNAME'] ?? '');
            if ($idnumber === '') {
                continue;
            }
            if (!$DB->record_exists('user', ['idnumber' => $idnumber, 'deleted' => 0])) {
                $new++;
            }
        }
        return ['fetched' => count($rows), 'new' => $new, 'existing' => count($rows) - $new];
    }

    /** Count enrolment rows: fetched and inside the window, honouring the role toggles. */
    private function count_enrol($rows, $window, $dot = true, $dos = true) {
        if (!is_array($rows)) {
            return ['error' => true];
        }
        $inscope = 0;
        foreach ($rows as $r) {
            $r = (array)$r;
            $rol = strtolower(trim($r['ROL'] ?? 'student'));
            $isteacher = ($rol === 'teacher' || $rol === 'leraar' || $rol === 'lkr');
            if (($isteacher && !$dot) || (!$isteacher && !$dos)) {
                continue;
            }
            if ($window === null) {
                $inscope++;
                continue;
            }
            $van = !empty($r['VAN']) ? strtotime($r['VAN']) : 0;
            $tot = !empty($r['TOT']) ? strtotime($r['TOT']) : 0;
            if (($tot && $tot < $window[0]) || ($van && $van > $window[1])) {
                continue;
            }
            $inscope++;
        }
        return ['fetched' => count($rows), 'in_scope' => $inscope];
    }

    /** Count rows with no further breakdown. */
    private function count_simple($rows) {
        if (!is_array($rows)) {
            return ['error' => true];
        }
        return ['fetched' => count($rows)];
    }
}
