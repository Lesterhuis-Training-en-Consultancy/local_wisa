<?php
namespace local_wisa\task;

defined('MOODLE_INTERNAL') || die();

class log_cleanup_task extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('task_log_cleanup', 'local_wisa');
    }

    public function execute() {
        global $DB;
        $days = (int)get_config('local_wisa', 'log_retention_days');
        if ($days < 1) {
            $days = 30;
        }
        $cutoff = time() - ($days * DAYSECS);
        $count = $DB->count_records_select('local_wisa_log', 'timecreated < ?', [$cutoff]);
        if ($count > 0) {
            $DB->delete_records_select('local_wisa_log', 'timecreated < ?', [$cutoff]);
            mtrace("local_wisa: deleted $count log records older than $days days.");
        } else {
            mtrace("local_wisa: no log records older than $days days.");
        }
    }
}
