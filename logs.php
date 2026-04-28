<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_wisa_logs');

$PAGE->set_url(new moodle_url('/local/wisa/logs.php'));
$PAGE->set_title(get_string('log_view', 'local_wisa'));
$PAGE->set_heading(get_string('log_view', 'local_wisa'));

if (optional_param('runsync', 0, PARAM_BOOL) && confirm_sesskey()) {
    require_sesskey();
    \core\notification::info(get_string('manual_sync_started', 'local_wisa'));
    $manager = new \local_wisa\sync_manager();
    $manager->run_full_sync();
    redirect($PAGE->url);
}

echo $OUTPUT->header();

$lasttime = (int)get_config('local_wisa', 'last_run_time');
$lastsummary = get_config('local_wisa', 'last_run_summary');
$lastdryrun = (int)get_config('local_wisa', 'last_run_dryrun');

if ($lasttime) {
    $age = format_time(time() - $lasttime);
    $when = userdate($lasttime) . " ($age " . get_string('ago', 'local_wisa') . ')';
    $badge = $lastdryrun
        ? ' <span class="badge badge-warning bg-warning text-dark">DRY-RUN</span>'
        : ' <span class="badge badge-success bg-success">LIVE</span>';
    echo \html_writer::start_div('alert alert-info');
    echo \html_writer::tag('strong', get_string('last_run', 'local_wisa') . ': ');
    echo s($when) . $badge;
    if ($lastsummary) {
        echo '<br>' . \html_writer::tag('code', s($lastsummary));
    }
    echo \html_writer::end_div();
} else {
    echo $OUTPUT->notification(get_string('no_runs_yet', 'local_wisa'), 'info');
}

echo $OUTPUT->single_button(
    new moodle_url('/local/wisa/logs.php', ['runsync' => 1, 'sesskey' => sesskey()]),
    get_string('manual_sync_btn', 'local_wisa')
);

$table = new html_table();
$table->head = [
    get_string('log_time', 'local_wisa'),
    get_string('log_action', 'local_wisa'),
    get_string('log_type', 'local_wisa'),
    get_string('log_objectid', 'local_wisa'),
    get_string('log_status', 'local_wisa'),
    get_string('log_message', 'local_wisa'),
];

$logs = $DB->get_records('local_wisa_log', null, 'timecreated DESC', '*', 0, 200);

foreach ($logs as $log) {
    $table->data[] = [
        userdate($log->timecreated),
        $log->action,
        $log->objecttype,
        $log->objectid,
        $log->status,
        $log->message,
    ];
}

echo html_writer::table($table);

echo $OUTPUT->footer();
