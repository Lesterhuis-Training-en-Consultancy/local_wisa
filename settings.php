<?php
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

    $settings->add(new admin_setting_heading('local_wisa_runtime', '',
        get_string('runtime_heading', 'local_wisa')));

    $settings->add(new admin_setting_configcheckbox('local_wisa/dry_run',
        get_string('dry_run', 'local_wisa'),
        get_string('dry_run_desc', 'local_wisa'),
        0));

    $settings->add(new admin_setting_configtext('local_wisa/log_retention_days',
        get_string('log_retention', 'local_wisa'),
        get_string('log_retention_desc', 'local_wisa'),
        30, PARAM_INT));

    $ADMIN->add('localplugins', $settings);

    $ADMIN->add('localplugins', new admin_externalpage('local_wisa_logs',
        get_string('log_view', 'local_wisa'),
        new moodle_url('/local/wisa/logs.php')));

    $ADMIN->add('localplugins', new admin_externalpage('local_wisa_test_connection',
        get_string('test_connection', 'local_wisa'),
        new moodle_url('/local/wisa/test_connection.php')));
}
