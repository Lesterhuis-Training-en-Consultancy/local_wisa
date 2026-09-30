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
 * In-memory store for source-stream migration conflict tests.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\tests;

/**
 * Simulates atomic source-stream migration setting persistence.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_stream_migration_conflict_setting_store implements \local_wisa\source_stream_migration_setting_store {
    /** @var array Stored values indexed by component and setting. */
    private array $values;

    /** @var array Initial values used to simulate failed deletion verification. */
    private array $initialvalues;

    /** @var string Simulated persistence failure mode. */
    private string $failuremode;

    /** @var array Ordered write and delete operations. */
    public array $operations = [];

    /** @var int Number of started transactions. */
    public int $begincount = 0;

    /** @var int Number of committed transactions. */
    public int $commitcount = 0;

    /** @var int Number of rolled back transactions. */
    public int $rollbackcount = 0;

    /** @var array|null Rollback checkpoint. */
    private ?array $checkpoint = null;

    /**
     * Construct a test setting store.
     *
     * @param array $values Initial values.
     * @param string $failuremode Simulated persistence failure mode.
     */
    public function __construct(array $values, string $failuremode) {
        $this->values = $values;
        $this->initialvalues = $values;
        $this->failuremode = $failuremode;
    }

    /**
     * Read one persisted setting.
     *
     * @param string $component Component name.
     * @param string $setting Setting name.
     * @return string|null Persisted value.
     */
    public function get(string $component, string $setting): ?string {
        if (
            $this->failuremode === 'verify-enabled' &&
            $component === 'sissource_wisa' &&
            $setting === 'stream_enrolments_enrolments_enabled' &&
            ($this->values[$component . '/' . $setting] ?? null) === '1'
        ) {
            return '0';
        }
        if (
            $this->failuremode === 'verify-watermark-write' &&
            $this->checkpoint !== null &&
            $component === 'sissource_wisa' &&
            $setting === 'stream_enrolments_enrolments_watermark' &&
            isset($this->values[$component . '/' . $setting])
        ) {
            return '0';
        }
        if (
            $this->failuremode === 'verify-watermark-delete' &&
            $component === 'sissource_wisa' &&
            $setting === 'stream_enrolments_enrolments_watermark' &&
            !isset($this->values[$component . '/' . $setting])
        ) {
            return $this->initialvalues[$component . '/' . $setting] ?? null;
        }
        if (
            $this->failuremode === 'verify-completion-marker' &&
            $component === 'sissource_wisa' &&
            $setting === \local_wisa\source_stream_migrator::COMPLETION_MARKER &&
            ($this->values[$component . '/' . $setting] ?? null) === '1'
        ) {
            return '0';
        }
        return $this->values[$component . '/' . $setting] ?? null;
    }

    /**
     * Write one persisted setting.
     *
     * @param string $component Component name.
     * @param string $setting Setting name.
     * @param string $value Persisted value.
     * @return bool Whether the write succeeded.
     */
    public function set(string $component, string $setting, string $value): bool {
        $this->operations[] = 'write:' . $component . ':' . $setting . ':' . $value;
        if ($this->failuremode === 'write-enabled' && $value === '1') {
            return false;
        }
        $this->values[$component . '/' . $setting] = $value;
        return true;
    }

    /**
     * Begin an atomic persistence sequence.
     *
     * @return void
     */
    public function begin(): void {
        $this->begincount++;
        $this->checkpoint = $this->values;
    }

    /**
     * Commit an atomic persistence sequence.
     *
     * @return bool Whether the commit succeeded.
     */
    public function commit(): bool {
        $this->commitcount++;
        $this->checkpoint = null;
        return true;
    }

    /**
     * Roll back an atomic persistence sequence.
     *
     * @return void
     */
    public function rollback(): void {
        $this->rollbackcount++;
        $this->values = $this->checkpoint ?? $this->values;
        $this->checkpoint = null;
    }

    /**
     * Delete one persisted setting.
     *
     * @param string $component Component name.
     * @param string $setting Setting name.
     * @return bool Whether the deletion succeeded.
     */
    public function delete(string $component, string $setting): bool {
        $this->operations[] = 'delete:' . $component . ':' . $setting;
        if (
            $this->failuremode === 'delete-conflict' &&
            $setting === \local_wisa\source_stream_migrator::CONFLICT_SNAPSHOT
        ) {
            return false;
        }
        if (
            $this->failuremode === 'delete-legacy' &&
            $component === 'local_wisa' && $setting === 'enable_enrolments'
        ) {
            return false;
        }
        unset($this->values[$component . '/' . $setting]);
        return true;
    }
}
