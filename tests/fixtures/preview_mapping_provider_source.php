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
 * Mapping provider fixture for preview mapping tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/preview_mapping_source.php');

/**
 * Source double exposing validated effective mapping metadata.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preview_mapping_provider_source extends preview_mapping_source implements mapping_provider_interface {
    /**
     * Return representative default, override, and dynamic profile mappings.
     *
     * @return array Effective mapping metadata.
     */
    public function get_effective_map(): array {
        return [
            'course' => [
                'idnumber' => [
                    'source' => 'KLAS_ID',
                    'defaultsource' => 'KLAS_ID',
                    'overridden' => false,
                ],
            ],
            'user' => [
                'email' => [
                    'source' => 'ALT_EMAIL',
                    'defaultsource' => 'EMAIL',
                    'overridden' => true,
                ],
                'profile_field_number_id' => [
                    'source' => 'NUMBER_ID',
                    'defaultsource' => null,
                    'overridden' => true,
                ],
            ],
        ];
    }
}
