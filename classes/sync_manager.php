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
 * Orchestrates source-stream synchronisation runs.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

use local_wisa\sync\course_sync;
use local_wisa\sync\enrollment_sync;
use local_wisa\sync\unenrollment_sync;
use local_wisa\sync\user_sync;

/**
 * Coordinates source-stream retrieval, tuple processing, and state persistence.
 *
 * @package    local_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_manager {
    /** @var source_interface SIS stream adapter. */
    private $source;

    /** @var string Source component for tuple state. */
    private $sourcecomponent;

    /** @var bool Whether Moodle mutations and tuple writes are disabled. */
    private $dryrun;

    /** @var mixed Optional lock factory test seam. */
    private $lockfactory;

    /** @var source_stream_state Tuple-scoped state store. */
    private $state;

    /** @var string|null Outcome from the current run. */
    private $runstatus;

    /**
     * Construct one stream synchronisation run.
     *
     * @param source_interface|null $source Source adapter or test double.
     * @param mixed $lockfactory Lock factory test seam.
     * @param string|null $sourcecomponent Explicit source component for a pinned run.
     * @return void
     */
    public function __construct(?source_interface $source = null, $lockfactory = null, ?string $sourcecomponent = null) {
        $this->source = $source ?: source_factory::get_active_source();
        $this->sourcecomponent = $sourcecomponent ?? source_factory::get_component_for_source($this->source);
        if (preg_match('/^sissource_[a-z][a-z0-9_]*$/', $this->sourcecomponent) !== 1) {
            throw new \coding_exception('Invalid SIS source component: ' . $this->sourcecomponent);
        }
        $this->lockfactory = $lockfactory;
        $this->dryrun = (bool)get_config('local_wisa', 'dry_run');
        $this->state = new source_stream_state($this->sourcecomponent, $this->dryrun);
    }

    /**
     * Run every enabled source-stream tuple in generic phase and registry order.
     *
     * @param bool $forcefull Whether delta lower bounds are disabled.
     * @param bool $forcelive Whether an approved initial load bypasses dry-run.
     * @return bool Whether every requested tuple completed successfully.
     */
    public function run_full_sync(bool $forcefull = false, bool $forcelive = false): bool {
        $this->runstatus = null;
        if ($forcelive) {
            $this->dryrun = false;
            $this->state = new source_stream_state($this->sourcecomponent, false);
        }
        $factory = $this->lockfactory ?: \core\lock\lock_config::get_lock_factory('local_wisa');
        $lock = $factory->get_lock('full_sync', 0);
        if (!$lock) {
            $this->runstatus = 'failed';
            event_logger::sync_skipped($this->source_name(), 'lock_unavailable');
            return false;
        }

        $window = schoolyear_window::calculate();
        $start = microtime(true);
        $runstart = time();
        $stats = new sync_stats();
        $successful = 0;
        $failed = 0;
        try {
            raise_memory_limit(MEMORY_HUGE);
            if (!$this->dryrun) {
                (new provisioning_service())->reconcile_pending_followups();
            }
            $registry = $this->registry();
            $requests = (new source_stream_request_builder($this->sourcecomponent, $registry, $this->state))->build($forcefull);
            event_logger::sync_started($this->source_name(), event_logger::mode_label($this->dryrun), $forcefull);
            $results = $requests === [] ? [] : source_stream_envelope::validate_results(
                $requests,
                $this->source->fetch_streams($requests)
            );
            foreach ($requests as $request) {
                $tuple = $request['stream'] . ':' . $request['phase'];
                $result = $results[$tuple];
                if ($result['status'] !== 'success') {
                    $this->state->record_failure(
                        $request['stream'],
                        $request['phase'],
                        $result['status'],
                        $result['errorcode'],
                        $runstart
                    );
                    $failed++;
                    continue;
                }
                $this->state->record_processing($request['stream'], $request['phase'], $runstart);
                try {
                    $processing = $this->process_rows($request, $result['rows'], $stats, $window);
                    if ($processing['success']) {
                        $this->state->record_success(
                            $request['stream'],
                            $request['phase'],
                            $this->mode_for($registry[$request['stream']], $request['phase']),
                            $runstart,
                            count($result['rows'])
                        );
                        $successful++;
                    } else {
                        $this->state->record_failure(
                            $request['stream'],
                            $request['phase'],
                            $processing['blocked'] ? 'provisioning-blocked' : 'failed',
                            $processing['blocked'] ? 'provisioning_blocked' : 'processing_failed',
                            $runstart,
                            count($result['rows'])
                        );
                        $failed++;
                    }
                } catch (\Throwable $exception) {
                    $this->state->record_failure(
                        $request['stream'],
                        $request['phase'],
                        'failed',
                        'processing_failed',
                        $runstart,
                        count($result['rows'])
                    );
                    $failed++;
                }
            }
            $status = $failed === 0 ? 'success' : ($successful === 0 ? 'failed' : 'partial');
            $this->runstatus = $status;
            $summary = $this->summary($stats);
            if (!$this->dryrun) {
                set_config('last_run_time', time(), 'local_wisa');
                set_config('last_run_summary', $summary, 'local_wisa');
                set_config('last_run_dryrun', 0, 'local_wisa');
                set_config('last_run_status', $status, 'local_wisa');
            }
            if (!$this->dryrun && $status === 'success' && $requests !== []) {
                set_config('initial_load_done', 1, 'local_wisa');
            }
            event_logger::sync_completed(
                $this->source_name(),
                event_logger::mode_label($this->dryrun),
                $forcefull,
                (int)round(microtime(true) - $start),
                $summary,
                $stats,
                $status
            );
            return $status === 'success';
        } catch (\Throwable $exception) {
            $this->runstatus = 'failed';
            event_logger::sync_failed(
                $this->source_name(),
                event_logger::mode_label($this->dryrun),
                $forcefull,
                (int)round(microtime(true) - $start)
            );
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * Return the outcome produced by the current run.
     *
     * @return string|null Current success, partial or failed outcome.
     */
    public function get_run_status(): ?string {
        return $this->runstatus;
    }

    /**
     * Return source-free metadata for the active stream registry.
     *
     * @return array Source component and validated registry.
     */
    public function preview(): array {
        return [
            'sourcecomponent' => $this->sourcecomponent,
            'streams' => $this->registry(),
        ];
    }

    /**
     * Return the validated static registry for the injected source.
     *
     * @return array Validated source stream registry.
     */
    private function registry(): array {
        $class = get_class($this->source);
        return source_stream_registry::validate($class::get_stream_registry());
    }

    /**
     * Process one successful tuple result through its generic phase service.
     *
     * @param array $request Source-stream request envelope.
     * @param array $rows Source rows.
     * @param sync_stats $stats Shared run statistics.
     * @param array|null $window Configured school-year window.
     * @return array Tuple processing and provisioning-block outcomes.
     */
    private function process_rows(array $request, array $rows, sync_stats $stats, ?array $window): array {
        if ($request['phase'] === 'courses') {
            $sync = new course_sync($this->sourcecomponent, $this->dryrun, $stats, $window);
            $success = $sync->run($rows);
            return ['success' => $success && !$sync->has_blocking_provisions(), 'blocked' => $sync->has_blocking_provisions()];
        }
        if ($request['phase'] === 'users') {
            return ['success' => (new user_sync($this->dryrun, $stats))->run($rows), 'blocked' => false];
        }
        if ($request['phase'] === 'enrolments') {
            $sync = new enrollment_sync($this->sourcecomponent, $this->dryrun, $stats, $window);
            $success = $sync->run($rows);
            return ['success' => $success && !$sync->has_blocking_provisions(), 'blocked' => $sync->has_blocking_provisions()];
        }
        if ($request['phase'] === 'unenrolments') {
            return ['success' => (new unenrollment_sync($this->dryrun, $stats))->run($rows), 'blocked' => false];
        }
        throw new \coding_exception('Unsupported source stream phase.');
    }

    /**
     * Return the descriptor watermark mode for one tuple.
     *
     * @param array $descriptor Validated source stream descriptor.
     * @param string $phase Descriptor phase.
     * @return string Watermark mode.
     */
    private function mode_for(array $descriptor, string $phase): string {
        return $descriptor['watermarkmode'][$phase];
    }

    /**
     * Return the source short name for events.
     *
     * @return string Source short name.
     */
    private function source_name(): string {
        return substr($this->sourcecomponent, strlen('sissource_'));
    }

    /**
     * Build a safe run summary without source data.
     *
     * @param sync_stats $stats Run counters.
     * @return string Safe summary.
     */
    private function summary(sync_stats $stats): string {
        return sprintf(
            '[%s] courses c=%d u=%d f=%d s=%d | users c=%d u=%d f=%d | enrol c=%d u=%d f=%d s=%d | unenrol ok=%d f=%d s=%d',
            event_logger::mode_label($this->dryrun),
            $stats->coursecreate,
            $stats->courseupdate,
            $stats->coursefail,
            $stats->courseskip,
            $stats->usercreate,
            $stats->userupdate,
            $stats->userfail,
            $stats->enrolcreate,
            $stats->enrolupdate,
            $stats->enrolfail,
            $stats->enrolskip,
            $stats->unenrolok,
            $stats->unenrolfail,
            $stats->unenrolskip
        );
    }
}
