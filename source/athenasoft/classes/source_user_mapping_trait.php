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
 * AthenaSoft user record mapping.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;

/**
 * Maps AthenaSoft student and teacher rows to generic user records.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait source_user_mapping_trait {
    /**
     * Map user rows from one requested transport.
     *
     * @param array $rows Raw rows.
     * @return array Mapped user rows.
     */
    private function map_users(array $rows): array {
        $mapped = [];
        foreach ($rows as $row) {
            $row = (array)$row;
            if (!$this->include_since($row)) {
                continue;
            }
            $mapped[] = $this->map_user($row);
        }

        return $mapped;
    }

    /**
     * Map one user row.
     *
     * @param array $row Raw row.
     * @return array Generic user record.
     */
    private function map_user(array $row): array {
        $mapped = $this->mapper->map_record('user', $row, $this->record_identity($row, 'user'));
        $user = [];
        if (array_key_exists('idnumber', $mapped)) {
            $user['idnumber'] = (string)(int)$mapped['idnumber'];
        }
        if (array_key_exists('username', $mapped)) {
            $user['username'] = \core_text::strtolower(trim((string)$mapped['username']));
        }
        foreach (['firstname', 'lastname', 'email'] as $target) {
            if (array_key_exists($target, $mapped)) {
                $user[$target] = trim((string)$mapped[$target]);
            }
        }
        if (array_key_exists('password', $mapped) && trim((string)$mapped['password']) !== '') {
            $user['password'] = (string)$mapped['password'];
        }

        return $this->add_additional_user_fields($user, $mapped);
    }

    /**
     * Append configured extended user fields without changing core field order.
     *
     * @param array $user Generic user record.
     * @param array $mapped Raw mapped user values.
     * @return array Generic user record with configured extended fields.
     */
    private function add_additional_user_fields(array $user, array $mapped): array {
        foreach ($mapped as $target => $value) {
            if (!array_key_exists($target, $user)) {
                $user[$target] = trim((string)$value);
            }
        }
        return $user;
    }
}
