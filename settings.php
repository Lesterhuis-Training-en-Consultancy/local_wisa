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
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_wisa', get_string('pluginname', 'local_wisa'));

    $settings->add(new admin_setting_heading(
        'local_wisa_settings',
        '',
        get_string('settings_heading', 'local_wisa')
    ));

    $settings->add(new admin_setting_configselect(
        'local_wisa/active_source',
        get_string('active_source', 'local_wisa'),
        get_string('active_source_desc', 'local_wisa'),
        \local_wisa\source_factory::DEFAULT_SOURCE,
        \local_wisa\source_factory::get_source_options()
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_wisa/rolemap',
        get_string('rolemap', 'local_wisa'),
        get_string('rolemap_desc', 'local_wisa'),
        '{"student":"student","teacher":"editingteacher","cursist":"student","leerkracht":"editingteacher"}',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_wisa/force_password_change',
        get_string('force_password_change', 'local_wisa'),
        get_string('force_password_change_desc', 'local_wisa'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_wisa/default_category',
        get_string('default_category', 'local_wisa'),
        get_string('default_category_desc', 'local_wisa'),
        1,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'local_wisa/category_mode',
        get_string('category_mode', 'local_wisa'),
        get_string('category_mode_desc', 'local_wisa'),
        'fixed',
        [
            'fixed' => get_string('category_mode_fixed', 'local_wisa'),
            'from_feed' => get_string('category_mode_from_feed', 'local_wisa'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_wisa/enable_course_provisioning',
        get_string('enable_course_provisioning', 'local_wisa'),
        get_string('enable_course_provisioning_desc', 'local_wisa'),
        0
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_wisa/templatemap',
        get_string('templatemap', 'local_wisa'),
        get_string('templatemap_desc', 'local_wisa'),
        '{}',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_heading(
        'local_wisa_sync_parts',
        '',
        get_string('sync_parts_heading', 'local_wisa')
    ));

    $settings->add(new admin_setting_configselect(
        'local_wisa/schoolyear_scope',
        get_string('schoolyear_scope', 'local_wisa'),
        get_string('schoolyear_scope_desc', 'local_wisa'),
        'current_next',
        [
            'off' => get_string('schoolyear_off', 'local_wisa'),
            'current' => get_string('schoolyear_current', 'local_wisa'),
            'current_next' => get_string('schoolyear_current_next', 'local_wisa'),
        ]
    ));

    $sourcecomponent = \local_wisa\source_factory::get_active_component();
    $unresolvedwisaenrolments = $sourcecomponent === 'sissource_wisa' &&
        (new \local_wisa\source_stream_migration_conflict_resolver())->has_unresolved_wisa_enrolments();
    foreach (\local_wisa\source_factory::get_registry_for_component($sourcecomponent) as $descriptor) {
        foreach ($descriptor['phases'] as $phase) {
            $name = get_string($descriptor['label'], $sourcecomponent) . ' - '
                . get_string('part_' . $phase, 'local_wisa');
            $aliases = implode(', ', array_column($descriptor['legacyaliases'][$phase], 'name')) ?: '-';
            $description = get_string('stream_setting_desc', 'local_wisa', (object)[
                'transport' => \html_writer::span(s($descriptor['transport']), 'd-block text-break'),
                'aliases' => \html_writer::span(s($aliases), 'd-block text-break'),
            ]);
            if ($unresolvedwisaenrolments && $descriptor['key'] === 'enrolments' && $phase === 'enrolments') {
                $settings->add(new admin_setting_description(
                    $sourcecomponent . '/stream_' . $descriptor['key'] . '_' . $phase . '_resolution_required',
                    $name,
                    $description . ' ' . get_string('stream_setting_resolution_required', 'local_wisa')
                ));
                continue;
            }
            $settings->add(new admin_setting_configcheckbox(
                $sourcecomponent . '/stream_' . $descriptor['key'] . '_' . $phase . '_enabled',
                $name,
                $description,
                $descriptor['defaultenabled'][$phase] ? 1 : 0
            ));
        }
    }

    $settings->add(new admin_setting_configcheckbox(
        'local_wisa/enable_reconcile',
        get_string('enable_reconcile', 'local_wisa'),
        get_string('enable_reconcile_desc', 'local_wisa'),
        0
    ));

    $settings->add(new admin_setting_heading(
        'local_wisa_runtime',
        '',
        get_string('runtime_heading', 'local_wisa')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_wisa/dry_run',
        get_string('dry_run', 'local_wisa'),
        get_string('dry_run_desc', 'local_wisa'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_wisa/debug_logging',
        get_string('debug_logging', 'local_wisa'),
        get_string('debug_logging_desc', 'local_wisa'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_wisa/unenrol_safety_max',
        get_string('unenrol_safety_max', 'local_wisa'),
        get_string('unenrol_safety_max_desc', 'local_wisa'),
        500,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_wisa/unenrol_safety_pct',
        get_string('unenrol_safety_pct', 'local_wisa'),
        get_string('unenrol_safety_pct_desc', 'local_wisa'),
        50,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_wisa/log_retention_days',
        get_string('log_retention', 'local_wisa'),
        get_string('log_retention_desc', 'local_wisa'),
        30,
        PARAM_INT
    ));

    $ADMIN->add('localplugins', $settings);

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_wisa_stream_status',
        get_string('stream_status_title', 'local_wisa'),
        new moodle_url('/local/wisa/stream_status.php')
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_wisa_preview',
        get_string('preview_title', 'local_wisa'),
        new moodle_url('/local/wisa/preview.php')
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_wisa_logs',
        get_string('log_view', 'local_wisa'),
        new moodle_url('/local/wisa/logs.php')
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_wisa_provisioning',
        get_string('provisioning_title', 'local_wisa'),
        new moodle_url('/local/wisa/provisioning.php')
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_wisa_test_connection',
        get_string('test_connection', 'local_wisa'),
        new moodle_url('/local/wisa/test_connection.php')
    ));

    foreach (\core_plugin_manager::instance()->get_plugins_of_type('sissource') as $plugin) {
        $plugin->load_settings($ADMIN, 'localplugins', $hassiteconfig);
    }
}
