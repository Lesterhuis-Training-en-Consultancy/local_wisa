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
 * Settings tests for sissource_wisa.
 *
 * @package    sissource_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_wisa;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Tests WISA settings definitions.
 *
 * @package    sissource_wisa
 * @category   test
 * @group      sissource_wisa
 * @group      local_wisa
 * @coversNothing
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class settings_test extends \advanced_testcase {
    /**
     * Credential settings must use Moodle's encrypted password control.
     *
     * @return void
     */
    public function test_credential_settings_use_encrypted_password_controls(): void {
        $source = file_get_contents(__DIR__ . '/../settings.php');
        $settingnames = ['api_user', 'api_pass'];

        $this->assertSame(2, substr_count($source, 'new admin_setting_encryptedpassword('));
        $this->assertStringNotContainsString('admin_setting_configpasswordunmask', $source);
        foreach ($settingnames as $settingname) {
            $pattern = "~new admin_setting_encryptedpassword\\(\\s*'sissource_wisa/{$settingname}'," .
                "\\s*get_string\\('{$settingname}', 'sissource_wisa'\\)," .
                "\\s*get_string\\('{$settingname}_desc', 'sissource_wisa'\\)\\s*\\)~";
            $this->assertMatchesRegularExpression($pattern, $source);
            $this->assertDoesNotMatchRegularExpression(
                "~new admin_setting_configtext\\(\\s*'sissource_wisa/{$settingname}'~",
                $source
            );
        }
    }

    /**
     * Credential settings must encrypt saved values and keep plaintext out of config_log.
     *
     * @return void
     */
    public function test_credential_settings_encrypt_saved_values_without_plaintext_logs(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $settings = $this->get_credential_settings();
        foreach (['api_user', 'api_pass'] as $settingname) {
            unset_config($settingname, 'sissource_wisa');
        }
        $DB->delete_records('config_log', []);

        $submitted = [
            's_sissource_wisa_api_user' => 'submitted-wisa-user',
            's_sissource_wisa_api_pass' => 'submitted-wisa-pass',
        ];
        foreach ($submitted as $fullname => $plaintext) {
            $this->assertInstanceOf(\admin_setting_encryptedpassword::class, $settings[$fullname]);
            $this->assertSame('', $settings[$fullname]->write_setting($plaintext));
            $stored = $settings[$fullname]->get_setting();
            $this->assertStringStartsWith('sodium:', $stored);
            $this->assertSame($plaintext, \core\encryption::decrypt($stored));
        }

        $records = $DB->get_records('config_log', ['plugin' => 'sissource_wisa'], 'id asc');
        $this->assertCount(2, $records);
        $logcontents = json_encode(array_values($records));
        foreach ($submitted as $plaintext) {
            $this->assertStringNotContainsString($plaintext, $logcontents);
        }
    }

    /**
     * Forced credential settings must be read-only through the core setting behavior.
     *
     * @return void
     */
    public function test_forced_credential_settings_are_readonly(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        $settings = $this->get_credential_settings();
        $hadforcedsettings = property_exists($CFG, 'forced_plugin_settings');
        $forcedsettings = $hadforcedsettings ? $CFG->forced_plugin_settings : [];
        try {
            $CFG->forced_plugin_settings['sissource_wisa'] = [
                'api_user' => 'forced-user',
                'api_pass' => 'forced-pass',
            ];

            $this->assertTrue($settings['s_sissource_wisa_api_user']->is_readonly());
            $this->assertTrue($settings['s_sissource_wisa_api_pass']->is_readonly());
        } finally {
            if ($hadforcedsettings) {
                $CFG->forced_plugin_settings = $forcedsettings;
            } else {
                unset($CFG->forced_plugin_settings);
            }
        }
    }

    /**
     * The field mapping configuration must use the shared validating textarea.
     *
     * @return void
     */
    public function test_fieldmap_setting_uses_shared_validating_textarea(): void {
        $settings = $this->get_credential_settings();

        $this->assertArrayHasKey('s_sissource_wisa_fieldmap', $settings);
        $this->assertInstanceOf(\local_wisa\admin_setting_fieldmap::class, $settings['s_sissource_wisa_fieldmap']);
        $this->assertInstanceOf(\admin_setting_configtextarea::class, $settings['s_sissource_wisa_fieldmap']);
    }

    /**
     * Return credential settings from the Moodle admin tree.
     *
     * @return \admin_setting[]
     */
    private function get_credential_settings(): array {
        $adminroot = admin_get_root(false, true);
        $page = $adminroot->locate('sissource_wisa');
        $this->assertInstanceOf(\admin_settingpage::class, $page);

        $settings = [];
        foreach ($page->settings as $setting) {
            $settings[$setting->get_full_name()] = $setting;
        }

        $this->assertArrayHasKey('s_sissource_wisa_api_user', $settings);
        $this->assertArrayHasKey('s_sissource_wisa_api_pass', $settings);
        return $settings;
    }
}
