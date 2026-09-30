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
 * Admin page: SIS source connection test.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_wisa_test_connection');
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/wisa/test_connection.php'));
$PAGE->set_title(get_string('test_connection', 'local_wisa'));
$PAGE->set_heading(get_string('test_connection', 'local_wisa'));

$sourcecomponent = \local_wisa\source_factory::get_active_component();
$registry = \local_wisa\source_factory::get_registry_for_component($sourcecomponent);

if (data_submitted()) {
    require_sesskey();
    $stream = required_param('stream', PARAM_ALPHANUMEXT);
    try {
        $queued = \local_wisa\explicit_action_queue::queue_connection_test((int)$USER->id, $sourcecomponent, $stream);
        $notification = $queued ? 'test_connection_queued' : 'test_connection_already_queued';
        \core\notification::info(get_string($notification, 'local_wisa'));
    } catch (\coding_exception $exception) {
        \core\notification::error(get_string('test_connection_invalid_selection', 'local_wisa'));
    }
    redirect($PAGE->url);
}

echo $OUTPUT->header();
echo html_writer::tag('p', get_string('test_connection_intro', 'local_wisa'));

$teststatus = (string)get_config('local_wisa', 'last_connection_test_status');
if (!in_array($teststatus, ['queued', 'success', 'failed'], true)) {
    $teststatus = '';
}
$testcount = max(0, (int)get_config('local_wisa', 'last_connection_test_count'));
$testtime = (int)get_config('local_wisa', 'last_connection_test_time');
$testsource = (string)get_config('local_wisa', 'last_connection_test_source');
$teststream = (string)get_config('local_wisa', 'last_connection_test_stream');
$testphase = (string)get_config('local_wisa', 'last_connection_test_phase');
$testtransport = (string)get_config('local_wisa', 'last_connection_test_transport');

if ($teststatus === 'queued') {
    echo $OUTPUT->notification(get_string('test_connection_result_queued', 'local_wisa'), 'info');
} else if ($teststatus === 'failed') {
    echo $OUTPUT->notification(get_string('test_connection_result_failed', 'local_wisa'), 'error');
    echo \html_writer::tag('p', get_string('test_failed_help', 'local_wisa'));
    echo \html_writer::tag('p', \html_writer::link(new moodle_url('/local/wisa/logs.php'), get_string('log_view', 'local_wisa')));
} else if ($teststatus === 'success') {
    echo $OUTPUT->notification(get_string('test_connection_result_success', 'local_wisa', $testcount), 'success');
}
if ($teststatus !== '' && $testtime > 0) {
    echo \html_writer::tag('p', get_string('action_result_time', 'local_wisa', s(userdate($testtime))));
}
if ($testsource !== '' && $teststream !== '' && $testphase !== '' && $testtransport !== '') {
    echo \html_writer::tag('h3', get_string('test_connection_last_descriptor', 'local_wisa'));
    echo \html_writer::alist([
        get_string('test_connection_last_source', 'local_wisa', s($testsource)),
        get_string('test_connection_last_stream', 'local_wisa', s($teststream)),
        get_string('test_connection_last_phase', 'local_wisa', s($testphase)),
        get_string('test_connection_last_transport', 'local_wisa', s($testtransport)),
    ]);
}

$options = [];
foreach ($registry as $descriptor) {
    $labelcomponent = get_string_manager()->string_exists($descriptor['label'], 'local_wisa')
        ? 'local_wisa' : $sourcecomponent;
    $phase = $descriptor['healthcheckphase'];
    $options[$descriptor['key']] = get_string('test_connection_option', 'local_wisa', (object)[
        'label' => get_string($descriptor['label'], $labelcomponent),
        'phase' => get_string('part_' . $phase, 'local_wisa'),
        'transport' => $descriptor['transport'],
    ]);
}

echo \html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
echo \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo \html_writer::start_div('form-group');
echo \html_writer::tag('label', get_string('test_connection_select', 'local_wisa'), [
    'for' => 'connection-test-stream',
]);
echo \html_writer::select(
    $options,
    'stream',
    '',
    ['' => get_string('test_connection_select_prompt', 'local_wisa')],
    ['id' => 'connection-test-stream', 'required' => 'required', 'class' => 'form-control']
);
echo \html_writer::end_div();
echo \html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary',
    'value' => get_string($teststatus === '' ? 'test_connection_run' : 'test_again', 'local_wisa'),
]);
echo \html_writer::end_tag('form');

echo $OUTPUT->footer();
