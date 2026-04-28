<?php
namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

class sync_stats {
    public $course_create = 0;
    public $course_update = 0;
    public $course_fail = 0;
    public $user_create = 0;
    public $user_update = 0;
    public $user_fail = 0;
    public $enrol_create = 0;
    public $enrol_warn = 0;
    public $enrol_fail = 0;
}
