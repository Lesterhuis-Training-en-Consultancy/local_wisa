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
 * AthenaSoft API client masking tests.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/api_client_test_case.php');

use sissource_athenasoft\tests\capturing_api_client;

/**
 * Tests AthenaSoft API masking contracts.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @group      sissource_athenasoft
 * @group      local_wisa
 * @covers     \sissource_athenasoft\api_client
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class api_client_masking_test extends api_client_test_case {
    /**
     * Assert api_key values are absent from log rows.
     *
     * @return void
     */
    public function test_masking_removes_api_key_from_log(): void {
        $this->assert_error_log_masks_config_value('api-key-secret');
    }

    /**
     * Assert auth_cred values are absent from log rows.
     *
     * @return void
     */
    public function test_masking_removes_auth_cred_from_log(): void {
        $this->assert_error_log_masks_config_value('authcredsecret');
    }

    /**
     * Assert auth_user values are absent from log rows.
     *
     * @return void
     */
    public function test_masking_removes_auth_user_from_log(): void {
        $this->assert_error_log_masks_config_value('apiuser');
    }

    /**
     * Assert pasword values are absent from log rows.
     *
     * @return void
     */
    public function test_masking_removes_pasword_from_log(): void {
        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();
        $api = new capturing_api_client();
        $api->set_response(5, '{"pasword":"secret-pasword-value"}', 500);

        $this->assertFalse($api->fetch(5));

        $this->assert_log_masks_secret('secret-pasword-value');
    }

    /**
     * Assert unquoted pasword values are absent from log rows.
     *
     * @return void
     */
    public function test_masking_removes_unquoted_pasword_value_from_log(): void {
        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();
        $api = new capturing_api_client();
        $api->set_response(5, '{"pasword":123456789}', 500);

        $this->assertFalse($api->fetch(5));

        $this->assert_log_masks_secret('123456789');
    }

    /**
     * Assert truncated quoted pasword values are absent from log rows.
     *
     * @return void
     */
    public function test_masking_removes_truncated_pasword_value_from_log(): void {
        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();
        $api = new capturing_api_client();
        $api->set_response(5, '{"pasword":"secret-truncated-value', 500);

        $this->assertFalse($api->fetch(5));

        $this->assert_log_masks_secret('secret-truncated-value');
    }

    /**
     * Assert interpolated pasword values are absent from log rows.
     *
     * @return void
     */
    public function test_masking_removes_interpolated_pasword_value_from_log(): void {
        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();
        $api = new capturing_api_client();
        $api->set_response(5, 'Failure includes pasword=secret-interpolated-value', 500);

        $this->assertFalse($api->fetch(5));

        $this->assert_log_masks_secret('secret-interpolated-value');
    }

    /**
     * Static and dynamically configured password fields are masked in diagnostic fragments.
     *
     * @return void
     */
    public function test_masking_uses_effective_password_mapping_for_json_and_key_value_fragments(): void {
        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();
        set_config('fieldmap', '{"user":{"password":"wachtwoord"}}', 'sissource_athenasoft');
        $api = new capturing_api_client();
        $fragments = [
            ['{"password":"default-secret"}', 'default-secret'],
            ['password=default-key-value-secret', 'default-key-value-secret'],
            ['{"pasword":"legacy-secret"}', 'legacy-secret'],
            ['pasword: legacy-key-value-secret', 'legacy-key-value-secret'],
            ['{"wachtwoord":"mapped-secret"}', 'mapped-secret'],
            ['wachtwoord=mapped-key-value-secret', 'mapped-key-value-secret'],
        ];

        foreach ($fragments as [$fragment, $secret]) {
            $masked = $api->reveal_mask_secrets($fragment);
            $this->assertStringNotContainsString($secret, $masked);
            $this->assertStringContainsString('[MASKED]', $masked);
        }
    }

    /**
     * Password fields are masked before overlapping configured secret values.
     *
     * @return void
     */
    public function test_masking_precedes_overlapping_configured_secret_replacement(): void {
        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();
        set_config('auth_user', \core\encryption::encrypt('pass'), 'sissource_athenasoft');
        $api = new api_client();
        $method = new \ReflectionMethod($api, 'mask_secrets');
        $method->setAccessible(true);

        $masked = $method->invoke($api, 'password=overlap-secret');

        $this->assertStringNotContainsString('overlap-secret', $masked);
        $this->assertStringNotContainsString('pass', $masked);
        $this->assertStringContainsString('[MASKED]', $masked);
    }

    /**
     * Escaped quotes do not expose a suffix in key/value password fragments.
     *
     * @return void
     */
    public function test_masking_handles_escaped_quotes_in_key_value_fragments(): void {
        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();
        set_config('fieldmap', '{"user":{"password":"wachtwoord"}}', 'sissource_athenasoft');
        $api = new capturing_api_client();

        $masked = $api->reveal_mask_secrets('wachtwoord="alpha\\"omega"');

        $this->assertSame('wachtwoord=[MASKED]', $masked);
        $this->assertStringNotContainsString('alpha', $masked);
        $this->assertStringNotContainsString('omega', $masked);
    }

    /**
     * The logging wrapper masks password values in every persisted log field.
     *
     * @return void
     */
    public function test_logging_wrapper_masks_password_values_in_all_fields(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();
        set_config('fieldmap', '{"user":{"password":"wachtwoord"}}', 'sissource_athenasoft');
        $api = new api_client();
        $method = new \ReflectionMethod($api, 'log');
        $method->setAccessible(true);
        $secrets = ['ACTION_SECRET', 'TYPE_SECRET', 'OBJECT_SECRET', 'STATUS_SECRET', 'MESSAGE_SECRET'];

        $method->invoke(
            $api,
            'password=' . $secrets[0],
            'pasword=' . $secrets[1],
            'wachtwoord=' . $secrets[2],
            '{"password":"' . $secrets[3] . '"}',
            'wachtwoord="' . $secrets[4] . '"'
        );

        $log = $DB->get_record('local_wisa_log', [], '*', MUST_EXIST);
        $persisted = implode(' ', [$log->action, $log->objecttype, $log->objectid, $log->status, $log->message]);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $persisted);
        }
        $this->assertStringContainsString('[MASKED]', $persisted);
    }

    /**
     * Assert debug logging records only a stable redacted request code.
     *
     * @return void
     */
    public function test_debug_logging_logs_stable_request_code(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_athenasoft_defaults();
        set_config('debug_logging', 1, 'local_wisa');
        $api = new capturing_api_client();
        $api->set_response(5, [['ok' => true]]);

        $this->assertSame([['ok' => true]], $api->fetch(5));

        $this->assertTrue($DB->record_exists('local_wisa_log', ['action' => 'api_debug']));
        $messages = $this->combined_log_messages();
        $this->assertStringContainsString('ATHENASOFT_API_REQUEST', $messages);
        $this->assertStringNotContainsString('https://api-test.example.be/script', $messages);
        $this->assertStringNotContainsString('script ID 5', $messages);
        $this->assertStringNotContainsString('api-key-secret', $messages);
    }
}
