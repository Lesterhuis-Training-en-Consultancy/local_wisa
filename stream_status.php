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
 * Source-stream administration and status page.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_wisa_stream_status');
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/wisa/stream_status.php'));
$PAGE->set_title(get_string('stream_status_title', 'local_wisa'));
$PAGE->set_heading(get_string('stream_status_title', 'local_wisa'));

$resolver = new \local_wisa\source_stream_migration_conflict_resolver();
if (data_submitted()) {
    require_sesskey();
    $choice = optional_param('choice', '', PARAM_ALPHANUMEXT);
    try {
        $resolved = $resolver->resolve_wisa_enrolments($choice);
    } catch (\Throwable $exception) {
        $resolved = false;
    }
    $notification = $resolved ? 'stream_resolution_success' : 'stream_resolution_failed';
    if ($resolved) {
        \core\notification::success(get_string($notification, 'local_wisa'));
    } else {
        \core\notification::error(get_string($notification, 'local_wisa'));
    }
    redirect($PAGE->url);
}

$sourcecomponent = \local_wisa\source_factory::get_active_component();
$registry = \local_wisa\source_factory::get_registry_for_component($sourcecomponent);
$state = new \local_wisa\source_stream_state($sourcecomponent);
$view = new \local_wisa\source_stream_admin_view($sourcecomponent, $registry, $state, $resolver);

echo $OUTPUT->header();
echo html_writer::tag('p', get_string('stream_status_intro', 'local_wisa'));

$table = new html_table();
$table->head = [
    get_string('stream_status_col_source', 'local_wisa'),
    get_string('stream_status_col_descriptor', 'local_wisa'),
    get_string('stream_status_col_phase', 'local_wisa'),
    get_string('stream_status_col_transport', 'local_wisa'),
    get_string('stream_status_col_enabled', 'local_wisa'),
    get_string('stream_status_col_watermark', 'local_wisa'),
    get_string('stream_status_col_status', 'local_wisa'),
];

foreach ($view->get_rows() as $row) {
    $descriptorparams = (object)[
        'stream' => s($row['stream']),
        'watermarkmode' => s($row['watermarkmode']),
    ];
    $labelcomponent = get_string_manager()->string_exists($row['label'], 'local_wisa')
        ? 'local_wisa' : $row['sourcecomponent'];
    $descriptor = html_writer::tag('strong', get_string($row['label'], $labelcomponent));
    $descriptor .= html_writer::tag(
        'div',
        get_string('stream_status_descriptor_help', 'local_wisa', $descriptorparams)
    );
    $statuskey = 'stream_status_' . str_replace('-', '_', $row['status']);
    $table->data[] = [
        s($row['sourcecomponent']),
        $descriptor,
        get_string('part_' . $row['phase'], 'local_wisa'),
        s($row['transport']),
        get_string($row['enabled'] ? 'stream_status_yes' : 'stream_status_no', 'local_wisa'),
        get_string($row['haspriorwatermark'] ? 'stream_status_yes' : 'stream_status_no', 'local_wisa'),
        get_string($statuskey, 'local_wisa'),
    ];
}
echo html_writer::table($table);

$choices = $view->get_resolution_choices();
if ($choices !== []) {
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $PAGE->url->out(false),
        'class' => 'mt-3',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::start_tag('fieldset');
    echo html_writer::tag('legend', get_string('stream_resolution_heading', 'local_wisa'));
    echo html_writer::tag('p', get_string('stream_resolution_help', 'local_wisa'));
    foreach ($choices as $choice) {
        $id = 'stream-resolution-' . $choice;
        $labelkey = $choice === \local_wisa\source_stream_migration_conflict_resolver::CHOICE_ENABLE_ENROLMENTS
            ? 'stream_resolution_choice_enable' : 'stream_resolution_choice_disable';
        $radio = html_writer::empty_tag('input', [
            'type' => 'radio',
            'name' => 'choice',
            'id' => $id,
            'value' => $choice,
            'required' => 'required',
            'class' => 'form-check-input',
        ]);
        $label = html_writer::tag('label', get_string($labelkey, 'local_wisa'), [
            'for' => $id,
            'class' => 'form-check-label',
        ]);
        echo html_writer::div($radio . $label, 'form-check');
    }
    echo html_writer::end_tag('fieldset');
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary mt-3',
        'value' => get_string('stream_resolution_submit', 'local_wisa'),
    ]);
    echo html_writer::end_tag('form');
}

echo $OUTPUT->footer();
