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
 * Upgrade tests for AthenaSoft legacy role-map migration.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once(__DIR__ . '/../db/upgrade.php');
require_once(__DIR__ . '/../../wisa/db/upgrade.php');
require_once($CFG->libdir . '/upgradelib.php');

/**
 * Verifies AthenaSoft role-map migration merges rather than overwrites.
 *
 * @package    sissource_athenasoft
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
     * Legacy generic tokens resolve through the default parent role map.
     *
     * @return void
     */
    public function test_athena_rolemap_migration_resolves_legacy_generic_tokens(): void {
        $this->resetAfterTest();
        unset_config('rolemap', 'local_wisa');
        set_config('rolemap_cursist', 'student', 'sissource_athenasoft');
        set_config('rolemap_leerkracht', 'teacher', 'sissource_athenasoft');

        set_config('version', 2026080499, 'sissource_athenasoft');
        xmldb_sissource_athenasoft_upgrade(2026080499);
        $rolemap = json_decode((string)get_config('local_wisa', 'rolemap'), true);

        $this->assertSame('student', $rolemap['cursist']);
        $this->assertSame('editingteacher', $rolemap['leerkracht']);
        $this->assertSame('student', $rolemap['student']);
        $this->assertSame('editingteacher', $rolemap['teacher']);
        $this->assertFalse(get_config('sissource_athenasoft', 'rolemap_cursist'));
        $this->assertFalse(get_config('sissource_athenasoft', 'rolemap_leerkracht'));
    }

    /**
     * Unresolvable legacy values retain source settings and block the savepoint.
     *
     * @return void
     */
    public function test_athena_rolemap_migration_keeps_unresolvable_legacy_settings(): void {
        $this->resetAfterTest();
        unset_config('rolemap', 'local_wisa');
        set_config('rolemap_cursist', 'student', 'sissource_athenasoft');
        set_config('rolemap_leerkracht', 'not_a_role_or_token', 'sissource_athenasoft');

        set_config('version', 2026080499, 'sissource_athenasoft');
        $this->assertFalse(xmldb_sissource_athenasoft_upgrade(2026080499));

        $this->assertSame('2026080499', get_config('sissource_athenasoft', 'version'));
        $this->assertFalse(get_config('local_wisa', 'rolemap'));
        $this->assertSame('student', get_config('sissource_athenasoft', 'rolemap_cursist'));
        $this->assertSame('not_a_role_or_token', get_config('sissource_athenasoft', 'rolemap_leerkracht'));
    }

    /**
     * Athena legacy role tokens merge into missing parent tokens and can be rerun.
     *
     * @return void
     */
    public function test_athena_rolemap_migration_is_idempotent_and_preserves_existing_tokens(): void {
        $this->resetAfterTest();
        set_config('rolemap', '{"cursist":"manager","advisor":"student"}', 'local_wisa');
        set_config('rolemap_cursist', 'student', 'sissource_athenasoft');
        set_config('rolemap_leerkracht', 'editingteacher', 'sissource_athenasoft');

        set_config('version', 2026080499, 'sissource_athenasoft');
        xmldb_sissource_athenasoft_upgrade(2026080499);
        $firstmap = json_decode((string)get_config('local_wisa', 'rolemap'), true);

        $this->assertSame('manager', $firstmap['cursist']);
        $this->assertSame('editingteacher', $firstmap['leerkracht']);
        $this->assertSame('student', $firstmap['advisor']);
        $this->assertFalse(get_config('sissource_athenasoft', 'rolemap_cursist'));
        $this->assertFalse(get_config('sissource_athenasoft', 'rolemap_leerkracht'));

        set_config('version', 2026080499, 'sissource_athenasoft');
        xmldb_sissource_athenasoft_upgrade(2026080499);

        $this->assertSame($firstmap, json_decode((string)get_config('local_wisa', 'rolemap'), true));
    }

    /**
     * Athena plaintext credentials are migrated to Sodium and remain unchanged on rerun.
     *
     * @return void
     */
    public function test_athena_credential_migration_encrypts_plaintext_and_is_idempotent(): void {
        $this->resetAfterTest();
        set_config('api_key', 'legacy-api-key', 'sissource_athenasoft');
        set_config('auth_user', 'legacy-auth-user', 'sissource_athenasoft');
        set_config('auth_cred', 'legacy-auth-cred', 'sissource_athenasoft');

        set_config('version', 2026080500, 'sissource_athenasoft');
        xmldb_sissource_athenasoft_upgrade(2026080500);

        $firstapikey = $this->get_raw_config('api_key');
        $firstauthuser = $this->get_raw_config('auth_user');
        $firstauthcred = $this->get_raw_config('auth_cred');
        $this->assertStringStartsWith('sodium:', $firstapikey);
        $this->assertStringStartsWith('sodium:', $firstauthuser);
        $this->assertStringStartsWith('sodium:', $firstauthcred);
        $this->assertSame('legacy-api-key', \core\encryption::decrypt($firstapikey));
        $this->assertSame('legacy-auth-user', \core\encryption::decrypt($firstauthuser));
        $this->assertSame('legacy-auth-cred', \core\encryption::decrypt($firstauthcred));

        set_config('version', 2026080500, 'sissource_athenasoft');
        xmldb_sissource_athenasoft_upgrade(2026080500);

        $this->assertSame($firstapikey, $this->get_raw_config('api_key'));
        $this->assertSame($firstauthuser, $this->get_raw_config('auth_user'));
        $this->assertSame($firstauthcred, $this->get_raw_config('auth_cred'));
    }

    /**
     * Forced values do not mask raw plaintext from the source-owned migration.
     *
     * @return void
     */
    public function test_athena_credential_migration_uses_raw_values_despite_forced_overrides(): void {
        global $CFG;

        $this->resetAfterTest();
        set_config('api_key', 'legacy-api-key', 'sissource_athenasoft');
        set_config('auth_user', 'legacy-auth-user', 'sissource_athenasoft');
        set_config('auth_cred', 'legacy-auth-cred', 'sissource_athenasoft');
        $CFG->forced_plugin_settings['sissource_athenasoft'] = [
            'api_key' => 'forced-api-key',
            'auth_user' => 'forced-auth-user',
            'auth_cred' => 'forced-auth-cred',
        ];

        set_config('version', 2026080500, 'sissource_athenasoft');
        xmldb_sissource_athenasoft_upgrade(2026080500);

        $storedapikey = $this->get_raw_config('api_key');
        $storedauthuser = $this->get_raw_config('auth_user');
        $storedauthcred = $this->get_raw_config('auth_cred');
        $this->assertStringStartsWith('sodium:', $storedapikey);
        $this->assertStringStartsWith('sodium:', $storedauthuser);
        $this->assertStringStartsWith('sodium:', $storedauthcred);
        $this->assertSame('legacy-api-key', \core\encryption::decrypt($storedapikey));
        $this->assertSame('legacy-auth-user', \core\encryption::decrypt($storedauthuser));
        $this->assertSame('legacy-auth-cred', \core\encryption::decrypt($storedauthcred));
    }

    /**
     * Empty, absent, and decryptable source credentials are left unchanged.
     *
     * @return void
     */
    public function test_athena_credential_migration_preserves_empty_absent_and_decryptable_values(): void {
        $this->resetAfterTest();
        $storedvalue = \core\encryption::encrypt('stored-auth-user');
        set_config('api_key', '', 'sissource_athenasoft');
        set_config('auth_user', $storedvalue, 'sissource_athenasoft');
        unset_config('auth_cred', 'sissource_athenasoft');

        set_config('version', 2026080500, 'sissource_athenasoft');
        xmldb_sissource_athenasoft_upgrade(2026080500);

        $this->assertSame('', $this->get_raw_config('api_key'));
        $this->assertSame($storedvalue, $this->get_raw_config('auth_user'));
        $this->assertNull($this->get_raw_config('auth_cred'));
    }

    /**
     * A malformed ciphertext returns false without advancing the savepoint or exposing a value in debugging.
     *
     * @return void
     */
    public function test_athena_credential_migration_preserves_malformed_candidate_and_returns_false(): void {
        $this->resetAfterTest();
        set_config('version', 2026080500, 'sissource_athenasoft');
        set_config('api_key', 'legacy-api-key', 'sissource_athenasoft');
        set_config('auth_user', 'sodium:not-valid-base64', 'sissource_athenasoft');

        $this->resetDebugging();
        $this->assertFalse(xmldb_sissource_athenasoft_upgrade(2026080500));
        $this->assertDebuggingCalled(
            'local_wisa credential migration failed for sissource_athenasoft/auth_user.',
            DEBUG_DEVELOPER
        );

        $this->assertSame('legacy-api-key', $this->get_raw_config('api_key'));
        $this->assertSame('sodium:not-valid-base64', $this->get_raw_config('auth_user'));
        $this->assertSame(2026080500, (int)get_config('sissource_athenasoft', 'version'));
    }

    /**
     * The runtime client delegates every AthenaSoft credential read to the resolver.
     *
     * @return void
     */
    public function test_athena_runtime_has_no_direct_credential_config_reads(): void {
        $clientpath = __DIR__ . '/../classes/api_client.php';
        $clientcontents = file_get_contents($clientpath);

        $this->assertNotFalse($clientcontents);
        foreach (['api_key', 'auth_user', 'auth_cred'] as $settingname) {
            $this->assertDoesNotMatchRegularExpression(
                "/get_config\\(\\s*'sissource_athenasoft'\\s*,\\s*'" . $settingname . "'\\s*\\)/",
                $clientcontents
            );
        }
    }

    /**
     * Stream-state migration is source-free, idempotent, and records its marker after state transfer.
     *
     * @return void
     */
    public function test_stream_migration_transfers_legacy_state_before_marking_completion(): void {
        $this->resetAfterTest();
        set_config('version', 2026080600, 'sissource_athenasoft');
        set_config('wm_students', 1700000000, 'sissource_athenasoft');
        set_config('enable_students', 1, 'local_wisa');

        $this->assertTrue(xmldb_sissource_athenasoft_upgrade(2026080600));
        $this->assertSame(1700000000, (int)get_config(
            'sissource_athenasoft',
            'stream_placements_users_watermark'
        ));
        $this->assertSame('1', get_config(
            'sissource_athenasoft',
            'stream_placements_users_enabled'
        ));
        $this->assertSame('1', get_config('sissource_athenasoft', 'stream_migration_v1_complete'));
        $this->assertFalse(get_config('sissource_athenasoft', 'stream_migration_v1_conflict'));

        $this->assertTrue(\local_wisa\source_stream_migrator::migrate_component(
            'sissource_athenasoft',
            source::get_stream_registry()
        ));
        $this->assertSame(1700000000, (int)get_config(
            'sissource_athenasoft',
            'stream_placements_users_watermark'
        ));
    }

    /**
     * No-conflict migration cleans shared keys only after every installed source completes.
     *
     * @return void
     */
    public function test_no_conflict_migrations_defer_shared_cleanup_until_all_markers_complete_and_remain_idempotent(): void {
        $this->resetAfterTest();
        set_config('enable_enrolments', 1, 'local_wisa');
        set_config('enrol_students', 1, 'local_wisa');
        set_config('enrol_teachers', 1, 'local_wisa');
        set_config('wm_enrol_students', 1700000000, 'local_wisa');
        set_config('wm_enrol_teachers', 1700000300, 'local_wisa');
        set_config('wm_students', 1700000000, 'sissource_athenasoft');
        set_config('wm_enrol_students', 1700000000, 'sissource_athenasoft');
        set_config('wm_enrol_teachers', 1700000300, 'sissource_athenasoft');
        set_config('rolemap', '{"custom":"manager"}', 'local_wisa');

        set_config('version', 2026080600, 'sissource_wisa');
        $this->assertTrue(xmldb_sissource_wisa_upgrade(2026080600));
        $this->assertSame('1', get_config('sissource_wisa', 'stream_migration_v1_complete'));
        $this->assertSame('1', get_config('local_wisa', 'enable_enrolments'));
        $this->assertSame('{"custom":"manager"}', get_config('local_wisa', 'rolemap'));

        set_config('version', 2026080600, 'sissource_athenasoft');
        $this->assertTrue(xmldb_sissource_athenasoft_upgrade(2026080600));
        $this->assertSame('1', get_config('sissource_athenasoft', 'stream_migration_v1_complete'));
        $this->assertFalse(get_config('local_wisa', 'enable_enrolments'));
        $this->assertFalse(get_config('local_wisa', 'wm_enrol_students'));
        $this->assertFalse(get_config('sissource_athenasoft', 'wm_enrol_teachers'));
        $this->assertSame('{"custom":"manager"}', get_config('local_wisa', 'rolemap'));

        set_config('version', 2026080600, 'sissource_athenasoft');
        $this->assertTrue(xmldb_sissource_athenasoft_upgrade(2026080600));
        $this->assertFalse(get_config('local_wisa', 'enable_enrolments'));
        $this->assertFalse(get_config('local_wisa', 'wm_enrol_students'));
        $this->assertFalse(get_config('sissource_athenasoft', 'wm_enrol_teachers'));
        $this->assertSame('{"custom":"manager"}', get_config('local_wisa', 'rolemap'));
    }

    /**
     * Return persisted AthenaSoft configuration without applying forced settings.
     *
     * @param string $settingname Setting name.
     * @return string|null Persisted value, or null when it is absent.
     */
    private function get_raw_config(string $settingname): ?string {
        global $DB;

        $value = $DB->get_field('config_plugins', 'value', [
            'plugin' => 'sissource_athenasoft',
            'name' => $settingname,
        ]);
        return $value === false ? null : (string)$value;
    }
}
