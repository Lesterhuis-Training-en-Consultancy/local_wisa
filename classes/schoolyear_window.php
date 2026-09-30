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
 * Calculates configured school-year stream windows.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Calculates the configured September-August school-year window.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schoolyear_window {
    /**
     * Return the configured school-year window, or null when filtering is off.
     *
     * @return array|null [Start timestamp, end timestamp], or null.
     */
    public static function calculate(): ?array {
        $scope = (string)get_config('local_wisa', 'schoolyear_scope');
        if ($scope === 'off') {
            return null;
        }

        $year = (int)date('Y');
        if ((int)date('n') < 9) {
            $year--;
        }
        $start = make_timestamp($year, 9, 1);
        $endyear = $scope === 'current_next' ? $year + 2 : $year + 1;

        return [$start, make_timestamp($endyear, 8, 31, 23, 59, 59)];
    }

    /**
     * Return a safe display label for the configured school-year window.
     *
     * @return string Empty when filtering is off, otherwise an ISO date range.
     */
    public static function label(): string {
        $window = self::calculate();
        if ($window === null) {
            return '';
        }
        return date('Y-m-d', $window[0]) . ' to ' . date('Y-m-d', $window[1]);
    }
}
