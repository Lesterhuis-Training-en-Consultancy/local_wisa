<?php
namespace local_wisa\task;

defined('MOODLE_INTERNAL') || die();

use local_wisa\sync_manager;

class sync_task extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('task_sync', 'local_wisa');
    }

    public function execute() {
        $manager = new sync_manager();
        $manager->run_full_sync();
    }
}
