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
 * Credential upgrade tests for sissource_wisa.
 *
 * @package    sissource_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_wisa;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once(__DIR__ . '/../db/upgrade.php');
require_once($CFG->libdir . '/upgradelib.php');

/**
 * Verifies WISA credential migration is safe to persist during plugin upgrade.
 *
 * @package    sissource_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgrade_test extends \advanced_testcase {
    /** @var bool Whether forced settings existed before the test. */
    private $hadforcedsettings;

    /** @var array Original forced settings. */
    private $forcedsettings;

    /**
     * Preserve forced settings because database resets do not restore config.php state.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->hadforcedsettings = property_exists($CFG, 'forced_plugin_settings');
        $this->forcedsettings = $this->hadforcedsettings ? $CFG->forced_plugin_settings : [];
    }

    /**
     * Restore forced settings after each test.
     *
     * @return void
     */
    protected function tearDown(): void {
        global $CFG;

        if ($this->hadforcedsettings) {
            $CFG->forced_plugin_settings = $this->forcedsettings;
        } else {
            unset($CFG->forced_plugin_settings);
        }
        parent::tearDown();
    }

    /**
     * Plaintext WISA credentials are migrated to Sodium ciphertext and can be re-run safely.
     *
     * @return void
     */
    public function test_upgrade_encrypts_plaintext_credentials_and_is_idempotent(): void {
        $this->resetAfterTest();
        set_config('api_user', 'legacy-user', 'sissource_wisa');
        set_config('api_pass', 'legacy-pass', 'sissource_wisa');

        set_config('version', 2026080500, 'sissource_wisa');
        xmldb_sissource_wisa_upgrade(2026080500);
        $firstuser = $this->get_raw_config('api_user');
        $firstpass = $this->get_raw_config('api_pass');

        $this->assertStringStartsWith('sodium:', $firstuser);
        $this->assertStringStartsWith('sodium:', $firstpass);
        $this->assertSame('legacy-user', \core\encryption::decrypt($firstuser));
        $this->assertSame('legacy-pass', \core\encryption::decrypt($firstpass));

        set_config('version', 2026080500, 'sissource_wisa');
        xmldb_sissource_wisa_upgrade(2026080500);

        $this->assertSame($firstuser, $this->get_raw_config('api_user'));
        $this->assertSame($firstpass, $this->get_raw_config('api_pass'));
    }

    /**
     * Forced credentials never replace the raw source values upgraded from storage.
     *
     * @return void
     */
    public function test_upgrade_uses_raw_storage_instead_of_forced_credentials(): void {
        global $CFG;

        $this->resetAfterTest();
        $storeduser = \core\encryption::encrypt('stored-user');
        $storedpass = \core\encryption::encrypt('stored-pass');
        set_config('api_user', $storeduser, 'sissource_wisa');
        set_config('api_pass', $storedpass, 'sissource_wisa');
        $CFG->forced_plugin_settings['sissource_wisa']['api_user'] = 'forced-user';
        $CFG->forced_plugin_settings['sissource_wisa']['api_pass'] = 'forced-pass';

        set_config('version', 2026080500, 'sissource_wisa');
        xmldb_sissource_wisa_upgrade(2026080500);

        $this->assertSame($storeduser, $this->get_raw_config('api_user'));
        $this->assertSame($storedpass, $this->get_raw_config('api_pass'));
        $this->assertSame('forced-user', \local_wisa\credential_resolver::resolve('sissource_wisa', 'api_user'));
        $this->assertSame('forced-pass', \local_wisa\credential_resolver::resolve('sissource_wisa', 'api_pass'));
    }

    /**
     * A malformed WISA ciphertext leaves every candidate unchanged and blocks its savepoint.
     *
     * @return void
     */
    public function test_upgrade_preserves_malformed_candidates_and_blocks_savepoint(): void {
        $this->resetAfterTest();
        set_config('api_user', 'legacy-user', 'sissource_wisa');
        set_config('api_pass', 'sodium:not-valid-base64', 'sissource_wisa');

        set_config('version', 2026080500, 'sissource_wisa');

        $this->resetDebugging();
        $this->assertFalse(xmldb_sissource_wisa_upgrade(2026080500));
        $this->assertDebuggingCalled(
            'local_wisa credential migration failed for sissource_wisa/api_pass.',
            DEBUG_DEVELOPER
        );

        $this->assertSame('legacy-user', $this->get_raw_config('api_user'));
        $this->assertSame('sodium:not-valid-base64', $this->get_raw_config('api_pass'));
        $this->assertSame('2026080500', get_config('sissource_wisa', 'version'));
    }

    /**
     * Runtime WISA code delegates credential resolution and never reads raw secrets itself.
     *
     * @return void
     */
    public function test_wisa_runtime_has_no_direct_raw_credential_reads(): void {
        $apiclient = file_get_contents(dirname(__DIR__) . '/classes/api_client.php');

        $this->assertStringContainsString(
            "credential_resolver::resolve('sissource_wisa', 'api_user')",
            $apiclient
        );
        $this->assertStringContainsString(
            "credential_resolver::resolve('sissource_wisa', 'api_pass')",
            $apiclient
        );
        $this->assertStringNotContainsString("get_config('sissource_wisa', 'api_user')", $apiclient);
        $this->assertStringNotContainsString("get_config('sissource_wisa', 'api_pass')", $apiclient);
    }

    /**
     * Equal legacy WISA enrolment switches converge into one enabled tuple at the raw minimum watermark.
     *
     * @return void
     */
    public function test_upgrade_migrates_equal_wisa_enrolment_lanes_at_raw_minimum_and_is_idempotent(): void {
        $this->resetAfterTest();
        set_config('enable_enrolments', 1, 'local_wisa');
        set_config('enrol_students', 1, 'local_wisa');
        set_config('enrol_teachers', 1, 'local_wisa');
        set_config('wm_enrol_students', 1700000300, 'local_wisa');
        set_config('wm_enrol_teachers', 1700000000, 'local_wisa');
        set_config('version', 2026080600, 'sissource_wisa');

        $this->assertTrue(xmldb_sissource_wisa_upgrade(2026080600));
        $this->assertSame('1', get_config('sissource_wisa', 'stream_enrolments_enrolments_enabled'));
        $this->assertSame('1700000000', get_config('sissource_wisa', 'stream_enrolments_enrolments_watermark'));
        $this->assertSame('1', get_config('sissource_wisa', 'stream_migration_v1_complete'));

        set_config('wm_enrol_students', 1800000000, 'local_wisa');
        set_config('enrol_students', 0, 'local_wisa');
        set_config('version', 2026080600, 'sissource_wisa');
        $this->assertTrue(xmldb_sissource_wisa_upgrade(2026080600));
        $this->assertSame('1', get_config('sissource_wisa', 'stream_enrolments_enrolments_enabled'));
        $this->assertSame('1700000000', get_config('sissource_wisa', 'stream_enrolments_enrolments_watermark'));
    }

    /**
     * Divergent WISA enrolment lanes retain their conservative pre-marker watermark state.
     *
     * @return void
     */
    public function test_upgrade_preserves_divergent_and_incomplete_wisa_enrolment_migration_state(): void {
        $this->resetAfterTest();
        set_config('enable_enrolments', 1, 'local_wisa');
        set_config('enrol_students', 1, 'local_wisa');
        set_config('enrol_teachers', 0, 'local_wisa');
        set_config('wm_enrol_students', 1700000000, 'local_wisa');
        set_config('wm_enrol_teachers', 1700000300, 'local_wisa');
        set_config('stream_enrolments_enrolments_watermark', 1600000000, 'sissource_wisa');
        set_config('rolemap', '{"custom":"manager"}', 'local_wisa');
        set_config('version', 2026080600, 'sissource_wisa');

        $this->assertTrue(xmldb_sissource_wisa_upgrade(2026080600));
        $this->assertSame('0', get_config('sissource_wisa', 'stream_enrolments_enrolments_enabled'));
        $this->assertSame('1600000000', get_config('sissource_wisa', 'stream_enrolments_enrolments_watermark'));
        $this->assertFalse(get_config('sissource_wisa', 'stream_migration_v1_complete'));
        $snapshot = json_decode((string)get_config('sissource_wisa', 'stream_migration_v1_conflict'), true);
        $this->assertSame('requires_admin_resolution', $snapshot[0]['status']);
        $this->assertSame(1700000000, $snapshot[0]['watermarks']['enrol_students']);
        $this->assertSame(1700000300, $snapshot[0]['watermarks']['enrol_teachers']);
        $this->assertSame('{"custom":"manager"}', get_config('local_wisa', 'rolemap'));

        unset_config('stream_migration_v1_conflict', 'sissource_wisa');
        unset_config('wm_enrol_teachers', 'local_wisa');
        set_config('enrol_teachers', 1, 'local_wisa');
        set_config('version', 2026080600, 'sissource_wisa');
        $this->assertTrue(xmldb_sissource_wisa_upgrade(2026080600));
        $this->assertFalse(get_config('sissource_wisa', 'stream_enrolments_enrolments_watermark'));
    }

    /**
     * Return a persisted WISA credential without applying forced configuration.
     *
     * @param string $settingname Source credential setting name.
     * @return string Persisted value.
     */
    private function get_raw_config(string $settingname): string {
        global $DB;

        return (string)$DB->get_field('config_plugins', 'value', [
            'plugin' => 'sissource_wisa',
            'name' => $settingname,
        ]);
    }
}
