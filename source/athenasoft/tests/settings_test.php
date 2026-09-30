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
 * Settings tests for sissource_athenasoft.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Tests AthenaSoft settings definitions and config logging.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @group      sissource_athenasoft
 * @group      local_wisa
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
        $settingnames = ['api_key', 'auth_user', 'auth_cred'];

        $this->assertSame(3, substr_count($source, 'new admin_setting_encryptedpassword('));
        $this->assertStringNotContainsString('admin_setting_configpasswordunmask', $source);
        foreach ($settingnames as $settingname) {
            $pattern = "~new admin_setting_encryptedpassword\\(\\s*'sissource_athenasoft/{$settingname}'," .
                "\\s*get_string\\('{$settingname}', 'sissource_athenasoft'\\)," .
                "\\s*get_string\\('{$settingname}_desc', 'sissource_athenasoft'\\)\\s*\\)~";
            $this->assertMatchesRegularExpression($pattern, $source);
            $this->assertDoesNotMatchRegularExpression(
                "~new admin_setting_configtext\\(\\s*'sissource_athenasoft/{$settingname}'~",
                $source
            );
        }
    }

    /**
     * Legacy role mapping migration inputs must not be exposed as settings.
     *
     * @return void
     */
    public function test_legacy_rolemap_settings_are_not_exposed(): void {
        $source = file_get_contents(__DIR__ . '/../settings.php');

        $this->assertStringNotContainsString("'sissource_athenasoft/rolemap_cursist'", $source);
        $this->assertStringNotContainsString("'sissource_athenasoft/rolemap_leerkracht'", $source);
        $this->assertStringNotContainsString("get_string('rolemap_cursist', 'sissource_athenasoft')", $source);
        $this->assertStringNotContainsString("get_string('rolemap_leerkracht', 'sissource_athenasoft')", $source);
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
        foreach (['api_key', 'auth_user', 'auth_cred'] as $settingname) {
            unset_config($settingname, 'sissource_athenasoft');
        }
        $DB->delete_records('config_log', []);

        $submitted = [
            's_sissource_athenasoft_api_key' => 'submitted-athena-api-key',
            's_sissource_athenasoft_auth_user' => 'submitted-athena-auth-user',
            's_sissource_athenasoft_auth_cred' => 'submitted-athena-auth-cred',
        ];
        foreach ($submitted as $fullname => $plaintext) {
            $this->assertInstanceOf(\admin_setting_encryptedpassword::class, $settings[$fullname]);
            $this->assertSame('', $settings[$fullname]->write_setting($plaintext));
            $stored = $settings[$fullname]->get_setting();
            $this->assertStringStartsWith('sodium:', $stored);
            $this->assertSame($plaintext, \core\encryption::decrypt($stored));
        }

        $records = $DB->get_records('config_log', ['plugin' => 'sissource_athenasoft'], 'id asc');
        $this->assertCount(3, $records);
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
            $CFG->forced_plugin_settings['sissource_athenasoft'] = [
                'api_key' => 'forced-api-key',
                'auth_user' => 'forced-auth-user',
                'auth_cred' => 'forced-auth-cred',
            ];

            foreach (['api_key', 'auth_user', 'auth_cred'] as $settingname) {
                $this->assertTrue($settings['s_sissource_athenasoft_' . $settingname]->is_readonly());
            }
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
        $this->resetAfterTest();
        $this->setAdminUser();
        $settings = $this->get_credential_settings();

        $this->assertArrayHasKey('s_sissource_athenasoft_fieldmap', $settings);
        $this->assertInstanceOf(\local_wisa\admin_setting_fieldmap::class, $settings['s_sissource_athenasoft_fieldmap']);
        $this->assertInstanceOf(\admin_setting_configtextarea::class, $settings['s_sissource_athenasoft_fieldmap']);
    }

    /**
     * The field mapping setting accepts a password target.
     *
     * @return void
     */
    public function test_fieldmap_setting_accepts_password_target(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $setting = $this->get_credential_settings()['s_sissource_athenasoft_fieldmap'];
        $valid = '{"user":{"password":"wachtwoord"}}';

        $this->assertSame('', $setting->write_setting($valid));
        $this->assertSame($valid, get_config('sissource_athenasoft', 'fieldmap'));
    }

    /**
     * Return credential settings from the Moodle admin tree.
     *
     * @return \admin_setting[]
     */
    private function get_credential_settings(): array {
        $adminroot = admin_get_root(false, true);
        $page = $adminroot->locate('sissource_athenasoft');
        $this->assertInstanceOf(\admin_settingpage::class, $page);

        $settings = [];
        foreach ($page->settings as $setting) {
            $settings[$setting->get_full_name()] = $setting;
        }

        foreach (['api_key', 'auth_user', 'auth_cred'] as $settingname) {
            $this->assertArrayHasKey('s_sissource_athenasoft_' . $settingname, $settings);
        }
        return $settings;
    }
}
