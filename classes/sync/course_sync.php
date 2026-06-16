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
 * Course synchronisation from WISA.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GNU GPL v3 or later
 */

namespace local_wisa\sync;

defined('MOODLE_INTERNAL') || die();

use local_wisa\api_client;
use local_wisa\logger;
use local_wisa\sync_stats;

class course_sync {
    private $api;
    private $default_category_id;
    private $category_mode;
    private $dryrun;
    private $stats;
    /** @var array|null [van_ts, tot_ts] schooljaarvenster, of null = geen filter. */
    private $window;

    public function __construct(api_client $api, bool $dryrun = false, ?sync_stats $stats = null, ?array $window = null) {
        $this->api = $api;
        $this->dryrun = $dryrun;
        $this->stats = $stats ?: new sync_stats();
        $this->default_category_id = get_config('local_wisa', 'default_category');
        $this->category_mode = get_config('local_wisa', 'category_mode') ?: 'fixed';
        $this->window = $window;
    }

    /** @return bool False when the WISA fetch failed (so the watermark must not advance). */
    public function run() {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $courses = $this->api->get_courses();
        if ($courses === false || !is_array($courses)) {
            logger::log('sync_courses', 'system', 'api', 'fail', 'Failed to fetch courses from WISA.');
            return false;
        }

        logger::log('sync_courses', 'system', 'count', 'info', 'Fetched ' . count($courses) . ' courses from WISA.');

        foreach ($courses as $wisa_course) {
            try {
                $this->process_course((array)$wisa_course);
            } catch (\Throwable $e) {
                $klas = $wisa_course['KLAS_ID'] ?? 'unknown';
                logger::log('sync_course', 'course', $klas, 'fail', "Process error: " . $e->getMessage());
                $this->stats->course_fail++;
            }
        }
        return true;
    }

    private function process_course($wisa_course) {
        global $DB;

        $klas_id = trim($wisa_course['KLAS_ID'] ?? '');
        $shortname = trim($wisa_course['SHORTNAME'] ?? '');
        $fullname = trim($wisa_course['FULLNAME'] ?? '');
        $startdate = $this->to_ts($wisa_course['BEGINDATUM'] ?? '');
        $enddate = $this->to_ts($wisa_course['EINDDATUM'] ?? '');

        // Een onvolledige feed-rij (ontbrekende sleutelvelden) overslaan i.p.v. een
        // kapotte cursus aan te maken.
        if ($klas_id === '' || $shortname === '' || $fullname === '') {
            logger::log('sync_course', 'course', $klas_id ?: 'unknown', 'fail',
                'Missing KLAS_ID, SHORTNAME or FULLNAME in feed row; skipped.');
            $this->stats->course_fail++;
            return;
        }

        // Schooljaarvenster: sla cursussen over die volledig buiten het venster vallen.
        if ($this->window !== null) {
            if (($enddate && $enddate < $this->window[0]) || ($startdate && $startdate > $this->window[1])) {
                $this->stats->course_skip++;
                return;
            }
        }

        $existing_course = $DB->get_record('course', ['idnumber' => $klas_id]);

        if ($existing_course) {
            $update = new \stdClass();
            $update->id = $existing_course->id;
            $changed = false;

            if ($existing_course->fullname !== $fullname) {
                $update->fullname = $fullname;
                $changed = true;
            }
            if ($existing_course->shortname !== $shortname) {
                if (!$DB->record_exists('course', ['shortname' => $shortname])) {
                    $update->shortname = $shortname;
                    $changed = true;
                } else {
                    logger::log('sync_course', 'course', $klas_id, 'warn', "Duplicate shortname $shortname skipped.");
                }
            }
            // Moodle requires startdate when enddate is set; pass both if either differs.
            if ($existing_course->startdate != $startdate || $existing_course->enddate != $enddate) {
                $update->startdate = $startdate ?: $existing_course->startdate;
                $update->enddate = $enddate;
                $changed = true;
            }

            if ($changed) {
                if ($this->dryrun) {
                    logger::log('sync_course', 'course', $klas_id, 'dryrun', "Would update course $fullname.");
                } else {
                    update_course($update);
                    logger::log('sync_course', 'course', $klas_id, 'update', "Updated course $fullname.");
                }
                $this->stats->course_update++;
            }

        } else {
            $new_course = new \stdClass();
            $new_course->fullname = $fullname;
            $new_course->shortname = $shortname;
            $new_course->idnumber = $klas_id;
            $new_course->startdate = $startdate;
            $new_course->enddate = $enddate;
            $new_course->category = $this->resolve_category_id($wisa_course);
            $new_course->visible = 1;

            if ($DB->record_exists('course', ['shortname' => $shortname])) {
                $new_course->shortname = $shortname . '_' . $klas_id;
                logger::log('sync_course', 'course', $klas_id, 'warn', "Duplicate shortname $shortname. Appended ID.");
            }

            try {
                if ($this->dryrun) {
                    logger::log('sync_course', 'course', $klas_id, 'dryrun', "Would create course $fullname.");
                } else {
                    create_course($new_course);
                    logger::log('sync_course', 'course', $klas_id, 'create', "Created course $fullname.");
                }
                $this->stats->course_create++;
            } catch (\Exception $e) {
                logger::log('sync_course', 'course', $klas_id, 'fail', "Failed to create course: " . $e->getMessage());
                $this->stats->course_fail++;
            }
        }
    }

    /** Parse a WISA date to a unix timestamp; '' or an unparseable value yields 0. */
    private function to_ts($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return 0;
        }
        $ts = strtotime($value);
        return $ts !== false ? $ts : 0;
    }

    /**
     * Resolve the target category id for a course.
     *
     * In 'fixed' mode (default) every course goes to the configured default
     * category. In 'from_feed' mode the WISA CATEGORY field (a name, or a path
     * like "Languages / NT2") is looked up and created if needed. Falls back to
     * the default category when the field is empty, in dry-run, or on error.
     */
    private function resolve_category_id($wisa_course) {
        if ($this->category_mode !== 'from_feed') {
            return $this->default_category_id;
        }
        $path = trim($wisa_course['CATEGORY'] ?? '');
        if ($path === '') {
            return $this->default_category_id;
        }
        if ($this->dryrun) {
            logger::log('sync_category', 'category', $path, 'dryrun',
                "Would resolve/create category path '$path'.");
            return $this->default_category_id;
        }
        try {
            return $this->resolve_category_path($path);
        } catch (\Throwable $e) {
            logger::log('sync_category', 'category', $path, 'warn',
                "Could not resolve category '$path' (" . $e->getMessage() . "); used default category.");
            return $this->default_category_id;
        }
    }

    /**
     * Walk a "/"-separated category path, creating missing levels, and return the
     * id of the deepest category.
     */
    private function resolve_category_path($path) {
        global $DB;
        $parentid = 0;
        foreach (explode('/', $path) as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $existing = $DB->get_record('course_categories', ['name' => $name, 'parent' => $parentid]);
            if ($existing) {
                $parentid = (int)$existing->id;
            } else {
                $cat = \core_course_category::create((object)['name' => $name, 'parent' => $parentid]);
                $parentid = (int)$cat->id;
                logger::log('sync_category', 'category', $name, 'create',
                    "Created category '$name' (id {$parentid}).");
            }
        }
        return $parentid ?: $this->default_category_id;
    }
}
