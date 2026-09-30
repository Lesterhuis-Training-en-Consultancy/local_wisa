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
 * Course category ID and path resolution.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\sync;

use local_wisa\logger;

/**
 * Provides category resolution behavior for course synchronisation.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait course_category_trait {
    /**
     * Resolve the target category id for a course.
     *
     * @param array $record Generic course record.
     * @return int
     */
    private function resolve_category_id($record) {
        if ($this->categorymode !== 'from_feed') {
            return $this->defaultcategoryid;
        }
        $path = trim($record['category'] ?? '');
        if ($path === '') {
            return $this->defaultcategoryid;
        }
        if ($this->dryrun) {
            logger::log(
                'sync_category',
                'category',
                $path,
                'dryrun',
                "Would resolve/create category path '$path'."
            );
            return $this->defaultcategoryid;
        }
        try {
            return $this->resolve_category_path($path);
        } catch (\Throwable $e) {
            logger::log(
                'sync_category',
                'category',
                'redacted',
                'warn',
                'CATEGORY_RESOLUTION_FAILED'
            );
            return $this->defaultcategoryid;
        }
    }

    /**
     * Walk a category path, creating missing levels, and return the deepest id.
     *
     * @param string $path Category path separated by slashes.
     * @return int
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
                logger::log(
                    'sync_category',
                    'category',
                    $name,
                    'create',
                    "Created category '$name' (id {$parentid})."
                );
            }
        }
        return $parentid ?: $this->defaultcategoryid;
    }
}
