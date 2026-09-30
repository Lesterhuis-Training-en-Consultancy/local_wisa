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
 * Admin settings for sissource_athenasoft.
 *
 * @package    sissource_athenasoft
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('sissource_athenasoft', get_string('pluginname', 'sissource_athenasoft'));

    $settings->add(new admin_setting_heading(
        'sissource_athenasoft_settings',
        '',
        get_string('settings_heading', 'sissource_athenasoft')
    ));

    $settings->add(new admin_setting_configtext(
        'sissource_athenasoft/api_url',
        get_string('api_url', 'sissource_athenasoft'),
        get_string('api_url_desc', 'sissource_athenasoft'),
        'https://api-test.example.invalid/script',
        PARAM_URL
    ));

    $settings->add(new admin_setting_encryptedpassword(
        'sissource_athenasoft/api_key',
        get_string('api_key', 'sissource_athenasoft'),
        get_string('api_key_desc', 'sissource_athenasoft')
    ));

    $settings->add(new admin_setting_encryptedpassword(
        'sissource_athenasoft/auth_user',
        get_string('auth_user', 'sissource_athenasoft'),
        get_string('auth_user_desc', 'sissource_athenasoft')
    ));

    $settings->add(new admin_setting_encryptedpassword(
        'sissource_athenasoft/auth_cred',
        get_string('auth_cred', 'sissource_athenasoft'),
        get_string('auth_cred_desc', 'sissource_athenasoft')
    ));

    $settings->add(new admin_setting_configtext(
        'sissource_athenasoft/institution',
        get_string('institution', 'sissource_athenasoft'),
        get_string('institution_desc', 'sissource_athenasoft'),
        '0',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'sissource_athenasoft/periode',
        get_string('periode', 'sissource_athenasoft'),
        get_string('periode_desc', 'sissource_athenasoft'),
        '0',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'sissource_athenasoft/script_courses',
        get_string('script_courses', 'sissource_athenasoft'),
        get_string('script_courses_desc', 'sissource_athenasoft'),
        '5',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'sissource_athenasoft/script_placements',
        get_string('script_placements', 'sissource_athenasoft'),
        get_string('script_placements_desc', 'sissource_athenasoft'),
        '6',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'sissource_athenasoft/script_teachers',
        get_string('script_teachers', 'sissource_athenasoft'),
        get_string('script_teachers_desc', 'sissource_athenasoft'),
        '7',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtextarea(
        'sissource_athenasoft/extra_params',
        get_string('extra_params', 'sissource_athenasoft'),
        get_string('extra_params_desc', 'sissource_athenasoft'),
        '',
        PARAM_RAW
    ));

    $fieldmapsetting = new \local_wisa\admin_setting_fieldmap(
        'sissource_athenasoft/fieldmap',
        get_string('fieldmap', 'sissource_athenasoft'),
        get_string('fieldmap_desc', 'sissource_athenasoft'),
        '',
        PARAM_RAW
    );
    $fieldmapsetting->set_additional_targets(['user' => ['password']]);
    $settings->add($fieldmapsetting);

    $ADMIN->add('localplugins', $settings);
}
