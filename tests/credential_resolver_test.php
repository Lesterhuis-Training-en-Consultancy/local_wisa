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
 * Credential resolver and migration tests for local_wisa.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Verifies source-neutral credential resolution and migration.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class credential_resolver_test extends \advanced_testcase {
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
     * A forced plaintext setting wins without decrypting or changing the stored value.
     *
     * @return void
     */
    public function test_forced_plaintext_takes_precedence_over_stored_ciphertext(): void {
        global $CFG;

        $this->resetAfterTest();
        $component = 'sissource_wisa';
        $settingname = 'api_pass';
        $storedvalue = \core\encryption::encrypt('stored-value');
        set_config($settingname, $storedvalue, $component);
        $CFG->forced_plugin_settings[$component][$settingname] = 'forced-value';

        $this->assertTrue(class_exists(credential_resolver::class));
        $this->assertSame('forced-value', credential_resolver::resolve($component, $settingname));
        $this->assertSame($storedvalue, $this->get_raw_config($component, $settingname));
    }

    /**
     * Stored Sodium ciphertext is decrypted when no forced setting exists.
     *
     * @return void
     */
    public function test_stored_sodium_ciphertext_is_decrypted(): void {
        $this->resetAfterTest();
        $component = 'sissource_wisa';
        $settingname = 'api_user';
        $storedvalue = \core\encryption::encrypt('stored-value');
        set_config($settingname, $storedvalue, $component);

        $this->assertStringStartsWith('sodium:', $storedvalue);
        $this->assertSame('stored-value', credential_resolver::resolve($component, $settingname));
    }

    /**
     * Absent and empty credentials remain empty without a migration write.
     *
     * @return void
     */
    public function test_empty_credentials_resolve_and_migrate_without_change(): void {
        $this->resetAfterTest();
        $component = 'sissource_wisa';
        unset_config('api_user', $component);
        set_config('api_pass', '', $component);

        $this->assertSame('', credential_resolver::resolve($component, 'api_user'));
        $this->assertSame('', credential_resolver::resolve($component, 'api_pass'));
        $this->assertTrue((new credential_migrator())->migrate([$component => ['api_user', 'api_pass']]));
        $this->assertNull($this->get_raw_config($component, 'api_user'));
        $this->assertSame('', $this->get_raw_config($component, 'api_pass'));
    }

    /**
     * Malformed ciphertext resolves empty and emits only a setting identity.
     *
     * @return void
     */
    public function test_malformed_ciphertext_fails_closed_with_value_free_log(): void {
        $this->resetAfterTest();
        $component = 'sissource_wisa';
        $settingname = 'api_pass';
        set_config($settingname, 'sodium:not-valid-base64', $component);

        $this->resetDebugging();
        $this->assertSame('', credential_resolver::resolve($component, $settingname));
        $this->assertDebuggingCalled(
            'local_wisa credential decryption failed for sissource_wisa/api_pass.',
            DEBUG_DEVELOPER
        );
    }

    /**
     * Migration encrypts raw plaintext despite a forced override and preserves ciphertext on rerun.
     *
     * @return void
     */
    public function test_migration_uses_raw_persisted_values_and_is_idempotent(): void {
        global $CFG;

        $this->resetAfterTest();
        $component = 'sissource_wisa';
        $settingname = 'api_pass';
        set_config($settingname, 'legacy-value', $component);
        $CFG->forced_plugin_settings[$component][$settingname] = 'forced-value';
        $migrator = new credential_migrator();

        $this->assertTrue($migrator->migrate([$component => [$settingname]]));
        $firstvalue = $this->get_raw_config($component, $settingname);
        $this->assertStringStartsWith('sodium:', $firstvalue);
        $this->assertNotSame('legacy-value', $firstvalue);
        $this->assertSame('legacy-value', \core\encryption::decrypt($firstvalue));
        $this->assertSame('forced-value', credential_resolver::resolve($component, $settingname));

        $this->assertTrue($migrator->migrate([$component => [$settingname]]));
        $this->assertSame($firstvalue, $this->get_raw_config($component, $settingname));
    }

    /**
     * A malformed candidate prevents all plaintext writes during preflight.
     *
     * @return void
     */
    public function test_malformed_ciphertext_prevents_all_migration_mutation(): void {
        $this->resetAfterTest();
        $component = 'sissource_wisa';
        set_config('api_user', 'legacy-user', $component);
        set_config('api_pass', 'sodium:not-valid-base64', $component);

        $this->resetDebugging();
        $this->assertFalse((new credential_migrator())->migrate([$component => ['api_user', 'api_pass']]));
        $this->assertDebuggingCalled(
            'local_wisa credential migration failed for sissource_wisa/api_pass.',
            DEBUG_DEVELOPER
        );
        $this->assertSame('legacy-user', $this->get_raw_config($component, 'api_user'));
        $this->assertSame('sodium:not-valid-base64', $this->get_raw_config($component, 'api_pass'));
    }

    /**
     * Missing Sodium blocks upgrade savepoint eligibility without changing persisted plaintext.
     *
     * @return void
     */
    public function test_missing_sodium_prevents_migration_and_returns_false(): void {
        $this->resetAfterTest();
        $component = 'sissource_wisa';
        $settingname = 'api_pass';
        set_config($settingname, 'legacy-value', $component);
        $migrator = new credential_migrator(static function (): bool {
            return false;
        });

        $this->resetDebugging();
        $this->assertFalse($migrator->migrate([$component => [$settingname]]));
        $this->assertDebuggingCalled(
            'local_wisa credential migration requires Sodium for sissource_wisa/api_pass.',
            DEBUG_DEVELOPER
        );
        $this->assertSame('legacy-value', $this->get_raw_config($component, $settingname));
    }

    /**
     * A failed persisted write rolls back every credential and makes the migration ineligible.
     *
     * @return void
     */
    public function test_failed_persistence_rolls_back_every_credential_and_returns_false(): void {
        $this->resetAfterTest();
        $component = 'sissource_wisa';
        set_config('api_user', 'legacy-user', $component);
        set_config('api_pass', 'legacy-password', $component);
        $writecount = 0;
        $migrator = new credential_migrator(null, static function (
            string $settingname,
            string $value,
            string $writercomponent
        ) use (&$writecount): bool {
            $writecount++;
            if ($writecount === 2) {
                return false;
            }
            return set_config($settingname, $value, $writercomponent);
        });

        $this->resetDebugging();
        $this->assertFalse($migrator->migrate([$component => ['api_user', 'api_pass']]));
        $this->assertdebuggingcalledcount(2, [
            'local_wisa credential migration failed for sissource_wisa/api_pass.',
            'local_wisa credential migration rolled back for sissource_wisa/api_pass.',
        ], [
            DEBUG_DEVELOPER,
            DEBUG_DEVELOPER,
        ]);
        $this->assertSame('legacy-user', $this->get_raw_config($component, 'api_user'));
        $this->assertSame('legacy-password', $this->get_raw_config($component, 'api_pass'));
    }

    /**
     * Return the persisted value without applying forced-plugin configuration.
     *
     * @param string $component Plugin component.
     * @param string $settingname Setting name.
     * @return string|null Persisted value, or null when it is absent.
     */
    private function get_raw_config(string $component, string $settingname): ?string {
        global $DB;

        $value = $DB->get_field('config_plugins', 'value', [
            'plugin' => $component,
            'name' => $settingname,
        ]);
        return $value === false ? null : (string)$value;
    }
}
