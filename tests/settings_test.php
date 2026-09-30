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
 * Settings tests for local_wisa.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Tests parent settings definitions.
 *
 * @package    local_wisa
 * @category   test
 * @group      local_wisa
 * @coversNothing
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class settings_test extends \advanced_testcase {
    /**
     * Provisioning settings must be source-neutral and disabled by default.
     *
     * @return void
     */
    public function test_course_provisioning_settings_and_status_page_are_registered(): void {
        $settings = $this->get_parent_settings();
        $this->assertInstanceOf(\admin_setting_configcheckbox::class, $settings['s_local_wisa_enable_course_provisioning']);
        $this->assertSame(0, $settings['s_local_wisa_enable_course_provisioning']->get_defaultsetting());
        $this->assertInstanceOf(\admin_setting_configtextarea::class, $settings['s_local_wisa_templatemap']);
        $this->assertSame('{}', $settings['s_local_wisa_templatemap']->get_defaultsetting());

        $adminroot = admin_get_root(true, true);
        $page = $adminroot->locate('local_wisa_provisioning');
        $this->assertInstanceOf(\admin_externalpage::class, $page);
        $streampage = $adminroot->locate('local_wisa_stream_status');
        $this->assertInstanceOf(\admin_externalpage::class, $streampage);
    }

    /**
     * The JSON role map must replace the legacy role selects.
     *
     * @return void
     */
    public function test_rolemap_setting_replaces_legacy_role_selects(): void {
        $settings = $this->get_parent_settings();
        $this->assertInstanceOf(\admin_setting_configtextarea::class, $settings['s_local_wisa_rolemap']);
        $this->assertSame(
            '{"student":"student","teacher":"editingteacher","cursist":"student","leerkracht":"editingteacher"}',
            $settings['s_local_wisa_rolemap']->get_defaultsetting()
        );
        $this->assertArrayNotHasKey('s_local_wisa_teacher_role', $settings);
        $this->assertArrayNotHasKey('s_local_wisa_student_role', $settings);
    }

    /**
     * The global new-user password policy must be enabled by default.
     *
     * @return void
     */
    public function test_force_password_change_setting_is_registered_and_enabled_by_default(): void {
        $settings = $this->get_parent_settings();

        $this->assertInstanceOf(
            \admin_setting_configcheckbox::class,
            $settings['s_local_wisa_force_password_change']
        );
        $this->assertSame(1, $settings['s_local_wisa_force_password_change']->get_defaultsetting());
    }

    /**
     * Dynamic stream metadata must use Moodle breakable block classes.
     *
     * @return void
     */
    public function test_stream_setting_transport_and_alias_metadata_is_breakable(): void {
        $settings = $this->get_parent_settings(true);
        $sourcecomponent = source_factory::get_active_component();
        $registry = source_factory::get_registry_for_component($sourcecomponent);
        $breakabletag = '<[^>]+class="(?=[^"]*\bd-block\b)(?=[^"]*\btext-break\b)[^"]*"[^>]*>';
        $this->assertNotEmpty($registry);

        foreach ($registry as $descriptor) {
            foreach ($descriptor['phases'] as $phase) {
                $settingname = 's_' . $sourcecomponent . '_stream_' . $descriptor['key'] . '_' . $phase . '_enabled';
                $resolutionname = null;
                if (
                    $sourcecomponent === 'sissource_wisa' && $descriptor['key'] === 'enrolments' &&
                        $phase === 'enrolments' &&
                        (new source_stream_migration_conflict_resolver())->has_unresolved_wisa_enrolments()
                ) {
                    $resolutionname = 's_' . $sourcecomponent . '_stream_' . $descriptor['key'] . '_' . $phase .
                        '_resolution_required';
                    $this->assertArrayNotHasKey($settingname, $settings);
                    $this->assertInstanceOf(\admin_setting_description::class, $settings[$resolutionname]);
                    $this->assertStringContainsString(
                        get_string('stream_setting_resolution_required', 'local_wisa'),
                        (string)$settings[$resolutionname]->description
                    );
                    $settingname = $resolutionname;
                }
                if ($resolutionname === null) {
                    $this->assertArrayHasKey($settingname, $settings);
                }
                $description = (string)$settings[$settingname]->description;
                $aliases = implode(', ', array_column($descriptor['legacyaliases'][$phase], 'name')) ?: '-';

                $this->assertMatchesRegularExpression(
                    '~' . $breakabletag . preg_quote(s($descriptor['transport']), '~') . '</[^>]+>~',
                    $description,
                    $settingname . ': transport'
                );
                $this->assertMatchesRegularExpression(
                    '~' . $breakabletag . preg_quote(s($aliases), '~') . '</[^>]+>~',
                    $description,
                    $settingname . ': aliases'
                );
            }
        }
    }

    /**
     * Return parent settings from the Moodle admin tree.
     *
     * @param bool $unresolvedwisaenrolments Whether to seed an unresolved WISA enrolment conflict.
     * @return \admin_setting[]
     */
    private function get_parent_settings(bool $unresolvedwisaenrolments = false): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        if ($unresolvedwisaenrolments) {
            set_config('stream_enrolments_enrolments_enabled', 1, 'sissource_wisa');
            set_config(source_stream_migrator::CONFLICT_SNAPSHOT, json_encode([[
                'component' => 'sissource_wisa',
                'stream' => 'enrolments',
                'phase' => 'enrolments',
                'status' => 'requires_admin_resolution',
                'aliases' => ['enrol_students' => true, 'enrol_teachers' => false],
                'watermarks' => ['enrol_students' => 1700000000, 'enrol_teachers' => 1700000300],
            ], ]), 'sissource_wisa');
        }
        $adminroot = admin_get_root(true, true);
        $page = $adminroot->locate('local_wisa');
        $this->assertInstanceOf(\admin_settingpage::class, $page);

        $settings = [];
        foreach ($page->settings as $setting) {
            $settings[$setting->get_full_name()] = $setting;
        }

        $this->assertArrayHasKey('s_local_wisa_enable_course_provisioning', $settings);
        $this->assertArrayHasKey('s_local_wisa_templatemap', $settings);
        $this->assertArrayHasKey('s_local_wisa_rolemap', $settings);
        return $settings;
    }
}
