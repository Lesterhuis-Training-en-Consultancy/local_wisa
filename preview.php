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
 * Admin page: preview the first SIS load and approve it before the sync runs.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_wisa_preview');
$context = context_system::instance();
require_capability('moodle/site:config', $context);
$PAGE->set_url(new moodle_url('/local/wisa/preview.php'));
$PAGE->set_title(get_string('preview_title', 'local_wisa'));
$PAGE->set_heading(get_string('preview_title', 'local_wisa'));

$action = optional_param('action', '', PARAM_ALPHA);

\local_wisa\explicit_action_queue::reconcile_initial_load();

// Confirmation step before the (irreversible-ish) go-live action.
if ($action === 'approve') {
    require_sesskey();
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('approve_confirm', 'local_wisa'),
        new moodle_url($PAGE->url, ['action' => 'doapprove', 'sesskey' => sesskey()]),
        $PAGE->url
    );
    echo $OUTPUT->footer();
    exit;
}

if ($action === 'doapprove') {
    require_sesskey();
    $queueresult = \local_wisa\explicit_action_queue::queue_initial_load((int)$USER->id);
    if ($queueresult['queued']) {
        \local_wisa\event_logger::admin_action(\local_wisa\event\sync_first_load_approved::class, [
            'source' => \local_wisa\event_logger::active_source(),
            'forcefull' => true,
        ]);
        \core\notification::success(get_string('approve_queued', 'local_wisa'));
    } else {
        \core\notification::info(get_string('approve_already_queued', 'local_wisa'));
    }
    redirect($PAGE->url);
}

// Pause the sync again (re-arm the gate) - handy for re-testing.
if ($action === 'reset') {
    require_sesskey();
    set_config('initial_load_done', 0, 'local_wisa');
    \local_wisa\event_logger::admin_action(\local_wisa\event\sync_first_load_reset::class, [
        'source' => \local_wisa\event_logger::active_source(),
    ]);
    \core\notification::info(get_string('reset_done', 'local_wisa'));
    redirect($PAGE->url);
}

if ($action === 'preview') {
    require_sesskey();
    $queuedpreview = \local_wisa\explicit_action_queue::queue_preview((int)$USER->id);
    $notification = $queuedpreview ? 'preview_queued' : 'preview_already_queued';
    \core\notification::info(get_string($notification, 'local_wisa'));
    redirect($PAGE->url);
}

echo $OUTPUT->header();

$done = get_config('local_wisa', 'initial_load_done') === '1';
$queued = get_config('local_wisa', 'initial_load_queued') === '1';
$previewstatus = (string)get_config('local_wisa', 'last_preview_status');
if (!in_array($previewstatus, ['queued', 'success', 'failed'], true)) {
    $previewstatus = '';
}
$previewcounts = json_decode((string)get_config('local_wisa', 'last_preview_counts'), true);
if (!is_array($previewcounts)) {
    $previewcounts = [];
}
$previewwindow = (string)get_config('local_wisa', 'last_preview_window');
$previewtime = (int)get_config('local_wisa', 'last_preview_time');

// Status banner: done (gate open), queued (load running in the background), or pending.
if ($done) {
    echo $OUTPUT->notification(get_string('preview_status_done', 'local_wisa'), 'success');
} else if ($queued) {
    echo $OUTPUT->notification(get_string('preview_status_queued', 'local_wisa'), 'info');
} else {
    echo $OUTPUT->notification(get_string('preview_status_pending', 'local_wisa'), 'warning');
}
echo \html_writer::tag('p', get_string('preview_intro', 'local_wisa'));

if ($previewstatus !== '') {
    $messagetype = $previewstatus === 'success' ? 'success' : ($previewstatus === 'failed' ? 'error' : 'info');
    echo $OUTPUT->notification(get_string('preview_result_' . $previewstatus, 'local_wisa'), $messagetype);
    if ($previewtime > 0) {
        echo \html_writer::tag('p', get_string('action_result_time', 'local_wisa', s(userdate($previewtime))));
    }
}

$sourcecomponent = \local_wisa\source_factory::get_active_component();
$registry = \local_wisa\source_factory::get_registry_for_component($sourcecomponent);
$state = new \local_wisa\source_stream_state($sourcecomponent);
$resolver = new \local_wisa\source_stream_migration_conflict_resolver();
$view = new \local_wisa\source_stream_admin_view($sourcecomponent, $registry, $state, $resolver);

echo \html_writer::tag('h3', get_string('preview_source_metadata_heading', 'local_wisa'));
$tupletable = new html_table();
$tupletable->responsive = true;
$tupletable->head = [
    get_string('stream_status_col_source', 'local_wisa'),
    get_string('stream_status_col_descriptor', 'local_wisa'),
    get_string('stream_status_col_stream', 'local_wisa'),
    get_string('stream_status_col_phase', 'local_wisa'),
    get_string('stream_status_col_transport', 'local_wisa'),
    get_string('stream_status_col_enabled', 'local_wisa'),
    get_string('stream_status_col_watermark_mode', 'local_wisa'),
    get_string('stream_status_col_watermark', 'local_wisa'),
    get_string('stream_status_col_status', 'local_wisa'),
];
foreach ($view->get_rows() as $row) {
    $labelcomponent = get_string_manager()->string_exists($row['label'], 'local_wisa')
        ? 'local_wisa' : $row['sourcecomponent'];
    $statuskey = 'stream_status_' . str_replace('-', '_', $row['status']);
    $tupletable->data[] = [
        s($row['sourcecomponent']),
        get_string($row['label'], $labelcomponent),
        s($row['stream']),
        get_string('part_' . $row['phase'], 'local_wisa'),
        s($row['transport']),
        get_string($row['enabled'] ? 'stream_status_yes' : 'stream_status_no', 'local_wisa'),
        s($row['watermarkmode']),
        get_string($row['haspriorwatermark'] ? 'stream_status_yes' : 'stream_status_no', 'local_wisa'),
        get_string($statuskey, 'local_wisa'),
    ];
}
echo \html_writer::table($tupletable);

// Field mapping: configured source columns and their generic Moodle targets.
echo \html_writer::tag('h3', get_string('preview_fieldmap_heading', 'local_wisa'));
$map = new html_table();
$map->head = [
    get_string('fieldmap_part', 'local_wisa'),
    get_string('fieldmap_wisa', 'local_wisa'),
    get_string('fieldmap_moodle', 'local_wisa'),
    get_string('fieldmap_origin', 'local_wisa'),
];
$partstrings = [
    'course' => 'part_courses',
    'user' => 'part_users',
    'enrolment' => 'part_enrolments',
    'unenrolment' => 'part_unenrolments',
];
$source = \local_wisa\source_factory::get_active_source();
$map->data = [];
foreach (\local_wisa\preview_mapping::get_rows($source) as $row) {
    $part = isset($partstrings[$row['recordtype']])
        ? get_string($partstrings[$row['recordtype']], 'local_wisa')
        : s($row['recordtype']);
    if ($row['fallback']) {
        $origin = get_string('fieldmap_origin_fallback', 'local_wisa');
    } else if (!$row['overridden']) {
        $origin = get_string('fieldmap_origin_default', 'local_wisa');
    } else if ($row['defaultsource'] === null) {
        $origin = get_string('fieldmap_origin_override', 'local_wisa');
    } else {
        $origin = get_string('fieldmap_origin_override_default', 'local_wisa', s($row['defaultsource']));
    }
    $map->data[] = [
        $part,
        s($row['source']),
        s($row['recordtype']) . '.' . s($row['target']),
        $origin,
    ];
}
echo \html_writer::table($map);

echo \html_writer::tag('h3', get_string('preview_counts_heading', 'local_wisa'));
if ($previewstatus === 'success') {
    if ($previewwindow !== '') {
        echo \html_writer::tag('p', get_string('preview_window', 'local_wisa', s($previewwindow)));
    }
    $ct = new html_table();
    $ct->head = [
        get_string('preview_col_part', 'local_wisa'),
        get_string('preview_col_fetched', 'local_wisa'),
    ];
    foreach (['courses', 'users', 'enrolments', 'unenrolments'] as $part) {
        $ct->data[] = [
            get_string('part_' . $part, 'local_wisa'),
            s((string)max(0, (int)($previewcounts[$part] ?? 0))),
        ];
    }
    echo \html_writer::table($ct);
} else {
    echo \html_writer::tag('p', get_string('preview_note', 'local_wisa'));
}

echo $OUTPUT->single_button(
    new moodle_url($PAGE->url, ['action' => 'preview', 'sesskey' => sesskey()]),
    get_string('preview_btn', 'local_wisa'),
    'post'
);

// Action buttons depending on gate state.
echo \html_writer::start_div('mt-3');
if ($done) {
    echo $OUTPUT->single_button(
        new moodle_url($PAGE->url, ['action' => 'reset', 'sesskey' => sesskey()]),
        get_string('reset_btn', 'local_wisa'),
        'post'
    );
} else if ($queued) {
    // Load is running in the background: point to the logs and offer a refresh.
    echo \html_writer::tag('p', get_string('approve_queued_info', 'local_wisa'));
    echo $OUTPUT->single_button(
        new moodle_url('/local/wisa/logs.php'),
        get_string('log_view', 'local_wisa'),
        'get'
    );
    echo $OUTPUT->single_button($PAGE->url, get_string('refresh_btn', 'local_wisa'), 'get');
} else {
    echo \html_writer::tag('p', get_string('approve_intro', 'local_wisa'));
    $btn = new single_button(
        new moodle_url($PAGE->url, ['action' => 'approve', 'sesskey' => sesskey()]),
        get_string('approve_btn', 'local_wisa'),
        'post'
    );
    $btn->add_confirm_action(get_string('approve_confirm', 'local_wisa'));
    echo $OUTPUT->render($btn);
}
echo \html_writer::end_div();

echo $OUTPUT->footer();
