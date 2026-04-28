<?php
namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

use local_wisa\sync\course_sync;
use local_wisa\sync\user_sync;
use local_wisa\sync\enrollment_sync;

class sync_manager {
    private $api;
    private $dryrun;

    public function __construct() {
        $this->api = new api_client();
        $this->dryrun = (bool)get_config('local_wisa', 'dry_run');
    }

    public function run_full_sync() {
        $factory = \core\lock\lock_config::get_lock_factory('local_wisa');
        $lock = $factory->get_lock('full_sync', 0);
        if (!$lock) {
            logger::log('sync_skip', 'system', 'lock', 'warn',
                'Another WISA sync is already running, skipped this run.');
            return;
        }

        try {
            $stats = new sync_stats();
            $start = microtime(true);
            $mode = $this->dryrun ? 'DRY-RUN' : 'LIVE';

            logger::log('sync_start', 'system', 'all', 'info',
                "Starting full synchronization ($mode).");

            (new course_sync($this->api, $this->dryrun, $stats))->run();
            (new user_sync($this->api, $this->dryrun, $stats))->run();
            (new enrollment_sync($this->api, $this->dryrun, $stats))->run();

            $duration = round(microtime(true) - $start, 1);
            $summary = sprintf(
                '[%s] %ss | courses c=%d u=%d f=%d | users c=%d u=%d f=%d | enrol c=%d w=%d f=%d',
                $mode, $duration,
                $stats->course_create, $stats->course_update, $stats->course_fail,
                $stats->user_create, $stats->user_update, $stats->user_fail,
                $stats->enrol_create, $stats->enrol_warn, $stats->enrol_fail
            );

            logger::log('sync_summary', 'system', 'all', 'info', $summary);
            set_config('last_run_time', time(), 'local_wisa');
            set_config('last_run_summary', $summary, 'local_wisa');
            set_config('last_run_dryrun', $this->dryrun ? 1 : 0, 'local_wisa');
        } finally {
            $lock->release();
        }
    }
}
