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
 * Moodle source-stream migration setting persistence.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Persists source-stream migration settings through Moodle configuration APIs.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class moodle_source_stream_migration_setting_store implements source_stream_migration_setting_store {
    /** @var \moodle_transaction|null Active Moodle delegated transaction. */
    private ?\moodle_transaction $transaction = null;

    /** @var array Components whose configuration cache must be invalidated when the transaction ends. */
    private array $touchedcomponents = [];

    /**
     * Read one persisted source-scoped setting.
     *
     * @param string $component Source Frankenstyle component.
     * @param string $setting Setting name.
     * @return string|null Persisted value, or null when absent.
     */
    public function get(string $component, string $setting): ?string {
        if ($this->transaction !== null) {
            global $DB;

            $value = $DB->get_field('config_plugins', 'value', [
                'plugin' => $component,
                'name' => $setting,
            ]);
            return $value === false ? null : (string)$value;
        }
        $value = get_config($component, $setting);
        return $value === false ? null : (string)$value;
    }

    /**
     * Persist one source-scoped setting.
     *
     * @param string $component Source Frankenstyle component.
     * @param string $setting Setting name.
     * @param string $value Persisted value.
     * @return bool Whether the write succeeded.
     */
    public function set(string $component, string $setting, string $value): bool {
        $success = set_config($setting, $value, $component);
        if ($success && $this->transaction !== null) {
            $this->touchedcomponents[$component] = true;
        }
        return $success;
    }

    /**
     * Remove one source-scoped setting.
     *
     * @param string $component Source Frankenstyle component.
     * @param string $setting Setting name.
     * @return bool Whether the deletion succeeded.
     */
    public function delete(string $component, string $setting): bool {
        $success = unset_config($setting, $component);
        if ($success && $this->transaction !== null) {
            $this->touchedcomponents[$component] = true;
        }
        return $success;
    }

    /**
     * Begin an atomic migration-state mutation.
     *
     * @return void
     */
    public function begin(): void {
        global $DB;

        $this->transaction = $DB->start_delegated_transaction();
        $this->touchedcomponents = [];
    }

    /**
     * Commit an atomic migration-state mutation.
     *
     * @return bool Whether the commit succeeded.
     */
    public function commit(): bool {
        if ($this->transaction === null) {
            return false;
        }
        try {
            $this->transaction->allow_commit();
            return true;
        } finally {
            $this->finish_transaction();
        }
    }

    /**
     * Roll back an atomic migration-state mutation.
     *
     * @return void
     */
    public function rollback(): void {
        if ($this->transaction === null) {
            return;
        }
        try {
            $this->transaction->rollback(new \coding_exception('Source stream migration resolution failed.'));
        } catch (\coding_exception $exception) {
            // Moodle rethrows the supplied exception after rolling back.
            unset($exception);
        } finally {
            $this->finish_transaction();
        }
    }

    /**
     * Invalidate configuration changed by the completed transaction and clear its state.
     *
     * @return void
     */
    private function finish_transaction(): void {
        foreach (array_keys($this->touchedcomponents) as $component) {
            \cache_helper::invalidate_by_definition('core', 'config', [], $component);
        }
        $this->transaction = null;
        $this->touchedcomponents = [];
    }
}
