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
 * Admin settings for sissource_wisa.
 *
 * @package    sissource_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('sissource_wisa', get_string('pluginname', 'sissource_wisa'));

    $settings->add(new admin_setting_heading(
        'sissource_wisa_settings',
        '',
        get_string('settings_heading', 'sissource_wisa')
    ));

    $settings->add(new admin_setting_configtext(
        'sissource_wisa/api_url',
        get_string('api_url', 'sissource_wisa'),
        get_string('api_url_desc', 'sissource_wisa'),
        'https://testcvoantwerpen.schoolware.be/webwisad/bin/server.fcgi/QUERY/',
        PARAM_URL
    ));

    $settings->add(new admin_setting_encryptedpassword(
        'sissource_wisa/api_user',
        get_string('api_user', 'sissource_wisa'),
        get_string('api_user_desc', 'sissource_wisa')
    ));

    $settings->add(new admin_setting_encryptedpassword(
        'sissource_wisa/api_pass',
        get_string('api_pass', 'sissource_wisa'),
        get_string('api_pass_desc', 'sissource_wisa')
    ));

    $settings->add(new admin_setting_configtext(
        'sissource_wisa/institute_num',
        get_string('institute_num', 'sissource_wisa'),
        get_string('institute_num_desc', 'sissource_wisa'),
        '123456',
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'sissource_wisa_queries',
        '',
        get_string('queries_heading', 'sissource_wisa')
    ));

    $settings->add(new admin_setting_configtext(
        'sissource_wisa/query_courses',
        get_string('query_courses', 'sissource_wisa'),
        get_string('query_courses_desc', 'sissource_wisa'),
        'MCVOD_C',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'sissource_wisa/query_students',
        get_string('query_students', 'sissource_wisa'),
        get_string('query_students_desc', 'sissource_wisa'),
        'MCVOD_STUD',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'sissource_wisa/query_teachers',
        get_string('query_teachers', 'sissource_wisa'),
        get_string('query_teachers_desc', 'sissource_wisa'),
        'MCVOD_LKR',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'sissource_wisa/query_enrolments',
        get_string('query_enrolments', 'sissource_wisa'),
        get_string('query_enrolments_desc', 'sissource_wisa'),
        'MCVOD_INS',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'sissource_wisa/query_unenrolments',
        get_string('query_unenrolments', 'sissource_wisa'),
        get_string('query_unenrolments_desc', 'sissource_wisa'),
        'MCVOD_UIT',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new \local_wisa\admin_setting_fieldmap(
        'sissource_wisa/fieldmap',
        get_string('fieldmap', 'sissource_wisa'),
        get_string('fieldmap_desc', 'sissource_wisa'),
        '',
        PARAM_RAW
    ));

    $testurl = new moodle_url('/local/wisa/test_connection.php');
    $settings->add(new admin_setting_description(
        'sissource_wisa/test_connection_link',
        get_string('test_connection_link', 'sissource_wisa'),
        html_writer::link(
            $testurl,
            get_string('test_connection_link_desc', 'sissource_wisa'),
            ['class' => 'btn btn-secondary']
        )
    ));

    $ADMIN->add('localplugins', $settings);
}
