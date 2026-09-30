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
 * Tuple-local source-stream state persistence.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Stores source-scoped stream-phase watermarks and outcome state.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_stream_state {
    /** @var string Source Frankenstyle component. */
    private $component;

    /** @var bool Whether persistence is disabled. */
    private $dryrun;

    /** @var source_stream_migration_setting_store Source-scoped state persistence. */
    private source_stream_migration_setting_store $store;

    /**
     * Construct tuple-local state for a source component.
     *
     * @param string $component Source Frankenstyle component.
     * @param bool $dryrun Whether writes are disabled.
     * @param source_stream_migration_setting_store|null $store Source-scoped state persistence.
     * @return void
     */
    public function __construct(
        string $component,
        bool $dryrun = false,
        ?source_stream_migration_setting_store $store = null
    ) {
        if (!preg_match('/^sissource_[a-z][a-z0-9_]*$/', $component)) {
            throw new \coding_exception('Invalid source stream component.');
        }
        $this->component = $component;
        $this->dryrun = $dryrun;
        $this->store = $store ?: new moodle_source_stream_migration_setting_store();
    }

    /**
     * Return a nullable positive delta watermark for one tuple.
     *
     * @param string $stream Stream key.
     * @param string $phase Generic phase.
     * @return int|null Watermark or null when absent or invalid.
     */
    public function get_watermark(string $stream, string $phase): ?int {
        $this->validate_tuple($stream, $phase);
        $watermark = (int)$this->store->get($this->component, $this->setting_name($stream, $phase, 'watermark'));
        return $watermark > 0 ? $watermark : null;
    }

    /**
     * Return all persisted state for one source stream tuple.
     *
     * @param string $stream Stream key.
     * @param string $phase Generic phase.
     * @return array Watermark and outcome state.
     */
    public function get_tuple(string $stream, string $phase): array {
        $this->validate_tuple($stream, $phase);
        $status = $this->store->get($this->component, $this->setting_name($stream, $phase, 'status'));
        $errorcode = $this->store->get($this->component, $this->setting_name($stream, $phase, 'errorcode'));
        $lastsuccess = (int)$this->store->get($this->component, $this->setting_name($stream, $phase, 'lastsuccess'));
        return [
            'watermark' => $this->get_watermark($stream, $phase),
            'status' => is_string($status) && $status !== '' ? $status : 'never-run',
            'errorcode' => is_string($errorcode) ? $errorcode : '',
            'lastsuccess' => $lastsuccess > 0 ? $lastsuccess : null,
            'lastattempt' => $this->get_positive_setting($stream, $phase, 'lastattempt'),
            'rowcount' => (int)$this->store->get($this->component, $this->setting_name($stream, $phase, 'rowcount')),
        ];
    }

    /**
     * Persist a successful delta watermark or full-mode status.
     *
     * @param string $stream Stream key.
     * @param string $phase Generic phase.
     * @param string $mode Descriptor watermark mode.
     * @param int $runstart Run-start candidate timestamp.
     * @param int $rowcount Processed row count.
     * @return void
     */
    public function record_success(string $stream, string $phase, string $mode, int $runstart, int $rowcount = 0): void {
        $this->validate_tuple($stream, $phase);
        $this->validate_mode($mode);
        $this->validate_attempt($runstart, $rowcount);
        if ($this->dryrun) {
            return;
        }
        $this->persist_state($stream, $phase, [
            'status' => 'successful',
            'errorcode' => '',
            'lastsuccess' => (string)$runstart,
            'lastattempt' => (string)$runstart,
            'rowcount' => (string)$rowcount,
        ], true, $mode === 'delta' ? $runstart : null);
    }

    /**
     * Record processing for a tuple without advancing its watermark.
     *
     * @param string $stream Stream key.
     * @param string $phase Generic phase.
     * @param int $runstart Run-start candidate timestamp.
     * @return void
     */
    public function record_processing(string $stream, string $phase, int $runstart): void {
        $this->validate_tuple($stream, $phase);
        $this->validate_attempt($runstart, 0);
        if ($this->dryrun) {
            return;
        }
        $this->persist_state($stream, $phase, [
            'status' => 'processing',
            'errorcode' => '',
            'lastattempt' => (string)$runstart,
        ]);
    }

    /**
     * Persist a tuple-local failed outcome without advancing state.
     *
     * @param string $stream Stream key.
     * @param string $phase Generic phase.
     * @param string $status Failure status.
     * @param string $errorcode Stable redacted error code.
     * @param int|null $runstart Run-start candidate timestamp when available.
     * @param int $rowcount Processed row count.
     * @return void
     */
    public function record_failure(
        string $stream,
        string $phase,
        string $status,
        string $errorcode,
        ?int $runstart = null,
        int $rowcount = 0
    ): void {
        $this->validate_tuple($stream, $phase);
        if (
            ($status !== 'failed' && $status !== 'malformed' && $status !== 'provisioning-blocked') ||
                preg_match('/^[a-z][a-z0-9_]*$/', $errorcode) !== 1
        ) {
            throw new \coding_exception('A source stream failure outcome is invalid.');
        }
        if ($runstart !== null) {
            $this->validate_attempt($runstart, $rowcount);
        } else if ($rowcount < 0) {
            throw new \coding_exception('A source stream row count cannot be negative.');
        }
        if ($this->dryrun) {
            return;
        }
        $settings = [
            'status' => $status,
            'errorcode' => $errorcode,
        ];
        if ($runstart !== null) {
            $settings['lastattempt'] = (string)$runstart;
            $settings['rowcount'] = (string)$rowcount;
        }
        $this->persist_state($stream, $phase, $settings);
    }

    /**
     * Validate a stream and phase tuple.
     *
     * @param string $stream Stream key.
     * @param string $phase Generic phase.
     * @return void
     */
    private function validate_tuple(string $stream, string $phase): void {
        if (
            preg_match('/^[a-z][a-z0-9_]*$/', $stream) !== 1 ||
                !in_array($phase, source_stream_registry::PHASES, true)
        ) {
            throw new \coding_exception('Invalid source stream tuple.');
        }
    }

    /**
     * Validate a descriptor watermark mode.
     *
     * @param string $mode Descriptor watermark mode.
     * @return void
     */
    private function validate_mode(string $mode): void {
        if ($mode !== 'delta' && $mode !== 'full') {
            throw new \coding_exception('Invalid source stream watermark mode.');
        }
    }

    /**
     * Validate one run attempt timestamp and row count.
     *
     * @param int $runstart Run-start candidate timestamp.
     * @param int $rowcount Processed row count.
     * @return void
     */
    private function validate_attempt(int $runstart, int $rowcount): void {
        if ($runstart <= 0 || $rowcount < 0) {
            throw new \coding_exception('A source stream attempt is invalid.');
        }
    }

    /**
     * Return a nullable positive tuple setting.
     *
     * @param string $stream Stream key.
     * @param string $phase Generic phase.
     * @param string $suffix State field suffix.
     * @return int|null Positive setting value or null.
     */
    private function get_positive_setting(string $stream, string $phase, string $suffix): ?int {
        $value = (int)$this->store->get($this->component, $this->setting_name($stream, $phase, $suffix));
        return $value > 0 ? $value : null;
    }

    /**
     * Atomically persist verified tuple state and optionally update its watermark last.
     *
     * @param string $stream Stream key.
     * @param string $phase Generic phase.
     * @param array $settings State values indexed by suffix.
     * @param bool $updatewatermark Whether to mutate the tuple watermark.
     * @param int|null $watermark Watermark value, or null to remove it.
     * @return void
     */
    private function persist_state(
        string $stream,
        string $phase,
        array $settings,
        bool $updatewatermark = false,
        ?int $watermark = null
    ): void {
        $this->store->begin();
        try {
            foreach ($settings as $suffix => $value) {
                if (!$this->write_setting($stream, $phase, $suffix, $value)) {
                    throw new \coding_exception('Unable to persist source stream state.');
                }
            }
            if ($updatewatermark && !$this->persist_watermark($stream, $phase, $watermark)) {
                throw new \coding_exception('Unable to persist source stream state.');
            }
            if (!$this->store->commit()) {
                throw new \coding_exception('Unable to persist source stream state.');
            }
        } catch (\Throwable $exception) {
            $this->store->rollback();
            throw new \coding_exception('Unable to persist source stream state.');
        }
    }

    /**
     * Persist and verify one tuple setting.
     *
     * @param string $stream Stream key.
     * @param string $phase Generic phase.
     * @param string $suffix State field suffix.
     * @param string $value State value.
     * @return bool Whether the value persisted and verified.
     */
    private function write_setting(string $stream, string $phase, string $suffix, string $value): bool {
        $setting = $this->setting_name($stream, $phase, $suffix);
        return $this->store->set($this->component, $setting, $value) &&
            $this->store->get($this->component, $setting) === $value;
    }

    /**
     * Persist and verify one tuple watermark after all other state fields.
     *
     * @param string $stream Stream key.
     * @param string $phase Generic phase.
     * @param int|null $watermark Watermark value, or null to remove it.
     * @return bool Whether the watermark mutation persisted and verified.
     */
    private function persist_watermark(string $stream, string $phase, ?int $watermark): bool {
        $setting = $this->setting_name($stream, $phase, 'watermark');
        if ($watermark !== null) {
            return $this->write_setting($stream, $phase, 'watermark', (string)$watermark);
        }
        return $this->store->delete($this->component, $setting) &&
            $this->store->get($this->component, $setting) === null;
    }

    /**
     * Build one source-scoped tuple setting name.
     *
     * @param string $stream Stream key.
     * @param string $phase Generic phase.
     * @param string $suffix State field suffix.
     * @return string Moodle configuration key.
     */
    private function setting_name(string $stream, string $phase, string $suffix): string {
        return 'stream_' . $stream . '_' . $phase . '_' . $suffix;
    }
}
