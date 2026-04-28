<?php
defined('MOODLE_INTERNAL') || die();

$tasks = array(
    array(
        'classname' => 'local_wisa\task\sync_task',
        'blocking' => 0,
        'minute' => '*/5',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*'
    ),
    array(
        'classname' => 'local_wisa\task\log_cleanup_task',
        'blocking' => 0,
        'minute' => '15',
        'hour' => '3',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*'
    )
);
