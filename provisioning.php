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
 * Admin page for course provisioning status, retries and deleted-course recovery.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_wisa_provisioning');
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/wisa/provisioning.php'));
$PAGE->set_title(get_string('provisioning_title', 'local_wisa'));
$PAGE->set_heading(get_string('provisioning_title', 'local_wisa'));

$repository = new \local_wisa\provisioning_repository();
$service = new \local_wisa\provisioning_service();
$bulkqueue = new \local_wisa\provisioning_bulk_retry_queue();
$statuses = [
    \local_wisa\provisioning_repository::STATUS_PENDING,
    \local_wisa\provisioning_repository::STATUS_RUNNING,
    \local_wisa\provisioning_repository::STATUS_READY,
    \local_wisa\provisioning_repository::STATUS_FALLBACK_READY,
    \local_wisa\provisioning_repository::STATUS_FAILED,
];
$statusparam = optional_param('status', '', PARAM_ALPHAEXT);
$status = in_array($statusparam, $statuses, true) ? $statusparam : null;
$page = max(0, optional_param('page', 0, PARAM_INT));
$perpage = 50;
$retry = optional_param('retry', 0, PARAM_INT);
if ($retry > 0 && data_submitted()) {
    require_sesskey();
    try {
        $service->retry($retry, (int)$USER->id);
        \core\notification::success(get_string('provisioning_retry_success', 'local_wisa'));
    } catch (\Throwable $exception) {
        \core\notification::error(get_string('provisioning_retry_failed', 'local_wisa'));
    }
    redirect(new moodle_url($PAGE->url, ['status' => $statusparam]));
}

$recover = optional_param('recover', 0, PARAM_INT);
if ($recover > 0 && data_submitted()) {
    require_sesskey();
    try {
        $service->recover($recover, (int)$USER->id);
        \core\notification::success(get_string('provisioning_recover_success', 'local_wisa'));
    } catch (\Throwable $exception) {
        \core\notification::error(get_string('provisioning_recover_failed', 'local_wisa'));
    }
    redirect(new moodle_url($PAGE->url, ['status' => $statusparam, 'page' => $page]));
}

$bulkaction = optional_param('bulkaction', '', PARAM_ALPHAEXT);
if (in_array($bulkaction, ['selected', 'filtered'], true) && data_submitted()) {
    require_sesskey();
    if ($bulkaction === 'filtered' && $status === \local_wisa\provisioning_repository::STATUS_FAILED) {
        $queued = $bulkqueue->queue_filtered_failed((int)$USER->id);
    } else if ($bulkaction === 'selected') {
        $queued = $bulkqueue->queue_selected(
            optional_param_array('provisionids', [], PARAM_INT),
            (int)$USER->id
        );
    } else {
        $queued = false;
    }
    $notification = $queued ? 'provisioning_bulk_queued' : 'provisioning_bulk_already_queued';
    \core\notification::info(get_string($notification, 'local_wisa'));
    redirect(new moodle_url($PAGE->url, ['status' => $statusparam]));
}

$queuedretrystate = $bulkqueue->get_queued_retry_state();
$bulkcontrolsdisabled = $queuedretrystate['mode'] !== '';
echo $OUTPUT->header();

$bulkresult = $bulkqueue->consume_result_for_user((int)$USER->id);
if ($bulkresult !== null) {
    $bulkcounts = $bulkresult['counts'];
    echo $OUTPUT->notification(get_string('provisioning_bulk_result', 'local_wisa', (object)[
        'queued' => $bulkcounts['queued'] ?? 0,
        'alreadyqueued' => $bulkcounts['already_queued'] ?? 0,
        'ineligible' => $bulkcounts['ineligible'] ?? 0,
        'rejected' => $bulkcounts['rejected'] ?? 0,
    ]), $bulkresult['status'] === 'success' ? 'success' : 'error');
}

$statusoptions = ['' => get_string('provisioning_all_statuses', 'local_wisa')];
foreach ($statuses as $statusoption) {
    $statusoptions[$statusoption] = get_string('provisioning_status_' . $statusoption, 'local_wisa');
}
$statusselect = $OUTPUT->single_select(
    $PAGE->url,
    'status',
    $statusoptions,
    $statusparam,
    null,
    'provisioning-status-filter',
    ['label' => get_string('provisioning_filter_status', 'local_wisa')]
);
echo \html_writer::div($statusselect, 'mb-3');

$total = $repository->count_admin_rows($status);
echo \html_writer::tag('p', get_string('provisioning_total', 'local_wisa', $total));
$pagingbar = new paging_bar(
    $total,
    $page,
    $perpage,
    new moodle_url($PAGE->url, ['status' => $statusparam]),
    'page'
);
echo $OUTPUT->render($pagingbar);

$records = $repository->get_admin_page($status, $page * $perpage, $perpage);
if (!$records) {
    echo $OUTPUT->notification(get_string('provisioning_empty', 'local_wisa'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('provisioning_col_select', 'local_wisa'),
        get_string('provisioning_col_source', 'local_wisa'),
        get_string('provisioning_col_courseid', 'local_wisa'),
        get_string('provisioning_col_desiredcourse', 'local_wisa'),
        get_string('provisioning_col_status', 'local_wisa'),
        get_string('provisioning_col_attempts', 'local_wisa'),
        get_string('provisioning_col_lasterror', 'local_wisa'),
        get_string('provisioning_col_updated', 'local_wisa'),
        get_string('provisioning_col_action', 'local_wisa'),
    ];
    $mobilecards = [];

    foreach ($records as $record) {
        $retryavailable = $service->is_retry_available($record);
        $recoveryavailable = $service->is_recovery_available($record);
        $retryqueued = $record->status === \local_wisa\provisioning_repository::STATUS_FAILED && (
            $queuedretrystate['mode'] === 'filtered_failed'
                || ($queuedretrystate['mode'] === 'selected'
                    && in_array((int)$record->id, $queuedretrystate['ids'], true))
        );
        $displaystatus = s($record->status);
        if ($retryqueued) {
            $displaystatus .= ' ' . \html_writer::tag(
                'span',
                s(get_string('provisioning_retry_queued', 'local_wisa')),
                ['class' => 'badge badge-warning bg-warning text-dark']
            );
        }
        if ($record->status === \local_wisa\provisioning_repository::STATUS_FAILED && $retryavailable) {
            $select = \html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'name' => 'provisionids[]',
                'value' => (int)$record->id,
                'form' => 'provisioning-bulk-form',
                'checked' => $retryqueued ? 'checked' : null,
                'disabled' => $bulkcontrolsdisabled ? 'disabled' : null,
                'aria-label' => get_string('provisioning_select_row', 'local_wisa', s($record->courseidnumber)),
            ]);
        } else {
            $select = '';
        }
        if ($retryqueued) {
            $desktopaction = get_string('provisioning_retry_queued', 'local_wisa');
            $mobileaction = $desktopaction;
        } else if ($retryavailable || $recoveryavailable) {
            $action = $recoveryavailable ? 'recover' : 'retry';
            $actionurl = new moodle_url($PAGE->url, [
                $action => (int)$record->id,
                'status' => $statusparam,
                'page' => $page,
                'sesskey' => sesskey(),
            ]);
            $actionlabel = get_string('provisioning_' . $action, 'local_wisa');
            $desktopaction = $OUTPUT->single_button($actionurl, $actionlabel, 'post');
            $mobileaction = $OUTPUT->single_button($actionurl, $actionlabel, 'post');
        } else {
            $desktopaction = get_string('provisioning_no_action', 'local_wisa');
            $mobileaction = $desktopaction;
        }

        $diagnostic = $record->lasterror === null
            ? ''
            : s(\local_wisa\provisioning_diagnostic::format((string)$record->lasterror));

        $table->data[] = [
            $select,
            s($record->sourcecomponent),
            s($record->courseidnumber),
            s($record->desiredfullname),
            $displaystatus,
            s((string)$record->attempts),
            $diagnostic,
            s(userdate((int)$record->timemodified)),
            $desktopaction,
        ];

        $details = '';
        foreach (
            [
                get_string('provisioning_col_source', 'local_wisa') => s($record->sourcecomponent),
                get_string('provisioning_col_courseid', 'local_wisa') => s($record->courseidnumber),
                get_string('provisioning_col_status', 'local_wisa') => $displaystatus,
                get_string('provisioning_col_attempts', 'local_wisa') => s((string)$record->attempts),
                get_string('provisioning_col_lasterror', 'local_wisa') => $diagnostic,
                get_string('provisioning_col_updated', 'local_wisa') => s(userdate((int)$record->timemodified)),
            ] as $label => $value
        ) {
            $details .= \html_writer::tag('dt', $label, ['class' => 'col-5 col-sm-4']) .
                \html_writer::tag('dd', $value === '' ? '-' : $value, ['class' => 'col-7 col-sm-8 text-break']);
        }
        $selectlabel = $select === '' ? '' : \html_writer::tag(
            'label',
            $select . ' ' . get_string('provisioning_col_select', 'local_wisa'),
            ['class' => 'mb-0']
        );
        $mobilecards[] = \html_writer::div(
            \html_writer::tag('h3', s($record->desiredfullname), ['class' => 'h5 card-title']) .
            \html_writer::tag('dl', $details, ['class' => 'row mb-3']) .
            \html_writer::div($selectlabel . $mobileaction, 'd-flex flex-wrap align-items-center gap-3'),
            'card-body'
        );
    }

    echo \html_writer::div(html_writer::table($table), 'table-responsive d-none d-lg-block');
    echo \html_writer::div(
        implode('', array_map(function (string $body): string {
            return \html_writer::div($body, 'card mb-3');
        }, $mobilecards)),
        'local-wisa-provisioning-cards d-lg-none'
    );
    echo \html_writer::start_tag('form', [
        'id' => 'provisioning-bulk-form',
        'action' => $PAGE->url->out(false),
        'method' => 'post',
    ]);
    echo \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'status', 'value' => $statusparam]);
    if ($bulkcontrolsdisabled) {
        echo \html_writer::div(
            s(get_string('provisioning_bulk_wait', 'local_wisa')),
            'alert alert-info mt-3 mb-2'
        );
    } else {
        echo \html_writer::div(
            s(get_string('provisioning_bulk_single_flight', 'local_wisa')),
            'text-muted mt-3 mb-2'
        );
    }
    echo \html_writer::start_div('d-flex flex-wrap gap-2 mt-3');
    echo \html_writer::tag('button', get_string('provisioning_bulk_retry_selected', 'local_wisa'), [
        'class' => 'btn btn-secondary',
        'type' => 'submit',
        'name' => 'bulkaction',
        'value' => 'selected',
        'disabled' => $bulkcontrolsdisabled ? 'disabled' : null,
    ]);
    if ($status === \local_wisa\provisioning_repository::STATUS_FAILED) {
        echo \html_writer::tag('button', get_string('provisioning_bulk_retry_filtered', 'local_wisa'), [
            'class' => $bulkcontrolsdisabled ? 'btn btn-secondary' : 'btn btn-primary',
            'type' => 'submit',
            'name' => 'bulkaction',
            'value' => 'filtered',
            'disabled' => $bulkcontrolsdisabled ? 'disabled' : null,
        ]);
    }
    echo \html_writer::end_div();
    echo \html_writer::end_tag('form');
}

echo $OUTPUT->render($pagingbar);

echo $OUTPUT->footer();
