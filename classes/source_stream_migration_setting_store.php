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
 * Source-stream migration setting persistence boundary.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Defines source-scoped setting persistence for source-free migrations.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface source_stream_migration_setting_store {
    /**
     * Read one persisted source-scoped setting.
     *
     * @param string $component Source Frankenstyle component.
     * @param string $setting Setting name.
     * @return string|null Persisted value, or null when absent.
     */
    public function get(string $component, string $setting): ?string;

    /**
     * Persist one source-scoped setting.
     *
     * @param string $component Source Frankenstyle component.
     * @param string $setting Setting name.
     * @param string $value Persisted value.
     * @return bool Whether the write succeeded.
     */
    public function set(string $component, string $setting, string $value): bool;

    /**
     * Remove one source-scoped setting.
     *
     * @param string $component Source Frankenstyle component.
     * @param string $setting Setting name.
     * @return bool Whether the deletion succeeded.
     */
    public function delete(string $component, string $setting): bool;

    /**
     * Begin an atomic migration-state mutation.
     *
     * @return void
     */
    public function begin(): void;

    /**
     * Commit an atomic migration-state mutation.
     *
     * @return bool Whether the commit succeeded.
     */
    public function commit(): bool;

    /**
     * Roll back an atomic migration-state mutation.
     *
     * @return void
     */
    public function rollback(): void;
}
