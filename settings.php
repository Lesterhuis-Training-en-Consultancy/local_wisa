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
 * Admin settings for local_wisa.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_wisa', get_string('pluginname', 'local_wisa'));

    $settings->add(new admin_setting_heading('local_wisa_settings', '',
        get_string('settings_heading', 'local_wisa')));

    $settings->add(new admin_setting_configtext('local_wisa/api_url',
        get_string('api_url', 'local_wisa'),
        get_string('api_url_desc', 'local_wisa'),
        'https://testcvoantwerpen.schoolware.be/webwisad/bin/server.fcgi/QUERY/', PARAM_URL));

    $settings->add(new admin_setting_configtext('local_wisa/api_user',
        get_string('api_user', 'local_wisa'),
        get_string('api_user_desc', 'local_wisa'),
        '', PARAM_TEXT));

    $settings->add(new admin_setting_configpasswordunmask('local_wisa/api_pass',
        get_string('api_pass', 'local_wisa'),
        get_string('api_pass_desc', 'local_wisa'),
        ''));

    $settings->add(new admin_setting_configtext('local_wisa/institute_num',
        get_string('institute_num', 'local_wisa'),
        get_string('institute_num_desc', 'local_wisa'),
        '123456', PARAM_INT));

    $testurl = new moodle_url('/local/wisa/test_connection.php');
    $settings->add(new admin_setting_description('local_wisa/test_connection_link',
        get_string('test_connection', 'local_wisa'),
        \html_writer::link($testurl,
            get_string('test_connection_btn', 'local_wisa'),
            ['class' => 'btn btn-secondary'])));

    $roles = role_get_names(null, ROLENAME_SHORT);
    $roleoptions = array();
    foreach ($roles as $role) {
        $roleoptions[$role->id] = $role->localname;
    }

    $settings->add(new admin_setting_configselect('local_wisa/teacher_role',
        get_string('teacher_role', 'local_wisa'),
        get_string('teacher_role_desc', 'local_wisa'),
        3, $roleoptions));

    $settings->add(new admin_setting_configselect('local_wisa/student_role',
        get_string('student_role', 'local_wisa'),
        get_string('student_role_desc', 'local_wisa'),
        5, $roleoptions));

    $settings->add(new admin_setting_configtext('local_wisa/default_category',
        get_string('default_category', 'local_wisa'),
        get_string('default_category_desc', 'local_wisa'),
        1, PARAM_INT));

    $settings->add(new admin_setting_configselect('local_wisa/category_mode',
        get_string('category_mode', 'local_wisa'),
        get_string('category_mode_desc', 'local_wisa'),
        'fixed', [
            'fixed' => get_string('category_mode_fixed', 'local_wisa'),
            'from_feed' => get_string('category_mode_from_feed', 'local_wisa'),
        ]));

    $settings->add(new admin_setting_heading('local_wisa_queries', '',
        get_string('queries_heading', 'local_wisa')));

    $settings->add(new admin_setting_configtext('local_wisa/query_courses',
        get_string('query_courses', 'local_wisa'),
        get_string('query_courses_desc', 'local_wisa'),
        'MCVOD_C', PARAM_ALPHANUMEXT));

    $settings->add(new admin_setting_configtext('local_wisa/query_students',
        get_string('query_students', 'local_wisa'),
        get_string('query_students_desc', 'local_wisa'),
        'MCVOD_STUD', PARAM_ALPHANUMEXT));

    $settings->add(new admin_setting_configtext('local_wisa/query_teachers',
        get_string('query_teachers', 'local_wisa'),
        get_string('query_teachers_desc', 'local_wisa'),
        'MCVOD_LKR', PARAM_ALPHANUMEXT));

    $settings->add(new admin_setting_configtext('local_wisa/query_enrolments',
        get_string('query_enrolments', 'local_wisa'),
        get_string('query_enrolments_desc', 'local_wisa'),
        'MCVOD_INS', PARAM_ALPHANUMEXT));

    $settings->add(new admin_setting_configtext('local_wisa/query_unenrolments',
        get_string('query_unenrolments', 'local_wisa'),
        get_string('query_unenrolments_desc', 'local_wisa'),
        'MCVOD_UIT', PARAM_ALPHANUMEXT));

    $settings->add(new admin_setting_heading('local_wisa_sync_parts', '',
        get_string('sync_parts_heading', 'local_wisa')));

    $settings->add(new admin_setting_configselect('local_wisa/schoolyear_scope',
        get_string('schoolyear_scope', 'local_wisa'),
        get_string('schoolyear_scope_desc', 'local_wisa'),
        'current_next', [
            'off' => get_string('schoolyear_off', 'local_wisa'),
            'current' => get_string('schoolyear_current', 'local_wisa'),
            'current_next' => get_string('schoolyear_current_next', 'local_wisa'),
        ]));

    foreach (['courses', 'students', 'teachers', 'enrolments', 'unenrolments'] as $wisapart) {
        $settings->add(new admin_setting_configcheckbox('local_wisa/enable_' . $wisapart,
            get_string('enable_' . $wisapart, 'local_wisa'),
            get_string('enable_' . $wisapart . '_desc', 'local_wisa'),
            1));
    }

    // Binnen de inschrijvingen (MCVOD_INS): leraar- en cursist-inschrijvingen apart schakelbaar.
    $settings->add(new admin_setting_configcheckbox('local_wisa/enrol_teachers',
        get_string('enrol_teachers', 'local_wisa'),
        get_string('enrol_teachers_desc', 'local_wisa'),
        1));

    $settings->add(new admin_setting_configcheckbox('local_wisa/enrol_students',
        get_string('enrol_students', 'local_wisa'),
        get_string('enrol_students_desc', 'local_wisa'),
        1));

    $settings->add(new admin_setting_configcheckbox('local_wisa/enable_reconcile',
        get_string('enable_reconcile', 'local_wisa'),
        get_string('enable_reconcile_desc', 'local_wisa'),
        0));

    $settings->add(new admin_setting_heading('local_wisa_runtime', '',
        get_string('runtime_heading', 'local_wisa')));

    $settings->add(new admin_setting_configcheckbox('local_wisa/dry_run',
        get_string('dry_run', 'local_wisa'),
        get_string('dry_run_desc', 'local_wisa'),
        0));

    $settings->add(new admin_setting_configcheckbox('local_wisa/debug_logging',
        get_string('debug_logging', 'local_wisa'),
        get_string('debug_logging_desc', 'local_wisa'),
        0));

    $settings->add(new admin_setting_configtext('local_wisa/unenrol_safety_max',
        get_string('unenrol_safety_max', 'local_wisa'),
        get_string('unenrol_safety_max_desc', 'local_wisa'),
        500, PARAM_INT));

    $settings->add(new admin_setting_configtext('local_wisa/unenrol_safety_pct',
        get_string('unenrol_safety_pct', 'local_wisa'),
        get_string('unenrol_safety_pct_desc', 'local_wisa'),
        50, PARAM_INT));

    $settings->add(new admin_setting_configtext('local_wisa/log_retention_days',
        get_string('log_retention', 'local_wisa'),
        get_string('log_retention_desc', 'local_wisa'),
        30, PARAM_INT));

    $ADMIN->add('localplugins', $settings);

    $ADMIN->add('localplugins', new admin_externalpage('local_wisa_preview',
        get_string('preview_title', 'local_wisa'),
        new moodle_url('/local/wisa/preview.php')));

    $ADMIN->add('localplugins', new admin_externalpage('local_wisa_logs',
        get_string('log_view', 'local_wisa'),
        new moodle_url('/local/wisa/logs.php')));

    $ADMIN->add('localplugins', new admin_externalpage('local_wisa_test_connection',
        get_string('test_connection', 'local_wisa'),
        new moodle_url('/local/wisa/test_connection.php')));
}
