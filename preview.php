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
 * Admin page: preview the first WISA load and approve it before the sync runs.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_wisa_preview');
$PAGE->set_url(new moodle_url('/local/wisa/preview.php'));
$PAGE->set_title(get_string('preview_title', 'local_wisa'));
$PAGE->set_heading(get_string('preview_title', 'local_wisa'));

$action = optional_param('action', '', PARAM_ALPHA);

// Confirmation step before the (irreversible-ish) go-live action.
if ($action === 'approve' && confirm_sesskey()) {
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('approve_confirm', 'local_wisa'),
        new moodle_url($PAGE->url, ['action' => 'doapprove', 'sesskey' => sesskey()]),
        $PAGE->url
    );
    echo $OUTPUT->footer();
    exit;
}

// Approve the first full load: switch off test mode and queue it as a background
// (adhoc) task. Running it synchronously here would hit the web-server timeout on a
// large dataset; the cron runner has no such limit. We deliberately do NOT set
// initial_load_done — run_full_sync() opens that gate itself once the background
// load succeeds, so the scheduled delta sync stays paused until the load is done.
if ($action === 'doapprove' && confirm_sesskey()) {
    require_sesskey();
    set_config('dry_run', 0, 'local_wisa');
    set_config('initial_load_queued', 1, 'local_wisa');
    \core\task\manager::queue_adhoc_task(new \local_wisa\task\initial_load_task(), true);
    \core\notification::success(get_string('approve_queued', 'local_wisa'));
    redirect($PAGE->url);
}

// Pause the sync again (re-arm the gate) — handy for re-testing.
if ($action === 'reset' && confirm_sesskey()) {
    require_sesskey();
    set_config('initial_load_done', 0, 'local_wisa');
    \core\notification::info(get_string('reset_done', 'local_wisa'));
    redirect($PAGE->url);
}

// Run a read-only preview count.
$preview = null;
if ($action === 'preview' && confirm_sesskey()) {
    core_php_time_limit::raise();
    $preview = (new \local_wisa\sync_manager())->preview();
}

echo $OUTPUT->header();

$done = get_config('local_wisa', 'initial_load_done') === '1';
$queued = get_config('local_wisa', 'initial_load_queued') === '1';

// Status banner: done (gate open), queued (load running in the background), or pending.
if ($done) {
    echo $OUTPUT->notification(get_string('preview_status_done', 'local_wisa'), 'success');
} else if ($queued) {
    echo $OUTPUT->notification(get_string('preview_status_queued', 'local_wisa'), 'info');
} else {
    echo $OUTPUT->notification(get_string('preview_status_pending', 'local_wisa'), 'warning');
}
echo \html_writer::tag('p', get_string('preview_intro', 'local_wisa'));

// Field mapping: what is read from WISA and where it lands in Moodle.
echo \html_writer::tag('h3', get_string('preview_fieldmap_heading', 'local_wisa'));
$map = new html_table();
$map->head = [
    get_string('fieldmap_part', 'local_wisa'),
    get_string('fieldmap_wisa', 'local_wisa'),
    get_string('fieldmap_moodle', 'local_wisa'),
    get_string('fieldmap_note', 'local_wisa'),
];
$matchkey = get_string('fieldmap_matchkey', 'local_wisa');
$map->data = [
    ['Courses', 'KLAS_ID', 'course.idnumber', $matchkey],
    ['Courses', 'SHORTNAME', 'course.shortname', ''],
    ['Courses', 'FULLNAME', 'course.fullname', ''],
    ['Courses', 'BEGINDATUM', 'course.startdate', ''],
    ['Courses', 'EINDDATUM', 'course.enddate', ''],
    ['Courses', 'CATEGORY', 'course.category', get_string('fieldmap_catnote', 'local_wisa')],
    ['Cursists / teachers', 'IDNUMBER (or USERNAME)', 'user.idnumber', $matchkey],
    ['Cursists / teachers', 'USERNAME', 'user.username', get_string('fieldmap_usernote', 'local_wisa')],
    ['Cursists / teachers', 'FIRSTNAME', 'user.firstname', ''],
    ['Cursists / teachers', 'LASTNAME', 'user.lastname', ''],
    ['Cursists / teachers', 'EMAIL', 'user.email', get_string('fieldmap_emailnote', 'local_wisa')],
    ['Enrolments', 'KLAS_ID', '→ course (by idnumber)', ''],
    ['Enrolments', 'USERNAME', '→ user (by idnumber)', ''],
    ['Enrolments', 'ROL', 'role (student/teacher)', ''],
    ['Enrolments', 'VAN / TOT', get_string('fieldmap_windownote', 'local_wisa'), ''],
    ['Unenrolments', 'KLAS_ID + USERNAME', get_string('fieldmap_suspendnote', 'local_wisa'), ''],
];
echo \html_writer::table($map);

// Preview counts (only after the preview action ran).
echo \html_writer::tag('h3', get_string('preview_counts_heading', 'local_wisa'));
if ($preview !== null) {
    echo \html_writer::tag('p', get_string('preview_window', 'local_wisa', $preview['window']));
    $ct = new html_table();
    $ct->head = [
        get_string('preview_col_part', 'local_wisa'),
        get_string('preview_col_fetched', 'local_wisa'),
        get_string('preview_col_inscope', 'local_wisa'),
        get_string('preview_col_new', 'local_wisa'),
    ];
    foreach ($preview['parts'] as $part => $c) {
        if (!empty($c['error'])) {
            $ct->data[] = [get_string('part_' . $part, 'local_wisa'),
                get_string('preview_fetch_error', 'local_wisa'), '', ''];
            continue;
        }
        $ct->data[] = [
            get_string('part_' . $part, 'local_wisa'),
            $c['fetched'] ?? '',
            $c['in_scope'] ?? '—',
            $c['new'] ?? '—',
        ];
    }
    echo \html_writer::table($ct);
} else {
    echo \html_writer::tag('p', get_string('preview_note', 'local_wisa'));
}

echo $OUTPUT->single_button(
    new moodle_url($PAGE->url, ['action' => 'preview', 'sesskey' => sesskey()]),
    get_string('preview_btn', 'local_wisa'),
    'get'
);

// Action buttons depending on gate state.
echo \html_writer::start_div('mt-3');
if ($done) {
    echo $OUTPUT->single_button(
        new moodle_url($PAGE->url, ['action' => 'reset', 'sesskey' => sesskey()]),
        get_string('reset_btn', 'local_wisa'),
        'get'
    );
} else if ($queued) {
    // Load is running in the background: point to the logs and offer a refresh.
    echo \html_writer::tag('p', get_string('approve_queued_info', 'local_wisa'));
    echo $OUTPUT->single_button(new moodle_url('/local/wisa/logs.php'),
        get_string('log_view', 'local_wisa'), 'get');
    echo $OUTPUT->single_button($PAGE->url, get_string('refresh_btn', 'local_wisa'), 'get');
} else {
    echo \html_writer::tag('p', get_string('approve_intro', 'local_wisa'));
    $btn = new single_button(
        new moodle_url($PAGE->url, ['action' => 'approve', 'sesskey' => sesskey()]),
        get_string('approve_btn', 'local_wisa'),
        'get'
    );
    $btn->add_confirm_action(get_string('approve_confirm', 'local_wisa'));
    echo $OUTPUT->render($btn);
}
echo \html_writer::end_div();

echo $OUTPUT->footer();
