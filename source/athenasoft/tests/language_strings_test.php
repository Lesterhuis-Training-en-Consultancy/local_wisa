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
 * Language string tests for sissource_athenasoft.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_athenasoft;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests AthenaSoft language string browser-rendering contracts.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @group      sissource_athenasoft
 * @group      local_wisa
 * @coversNothing
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class language_strings_test extends \advanced_testcase {
    /**
     * Credential help must describe encrypted storage, forced overrides, and no reveal UI.
     *
     * @dataProvider credential_help_provider
     * @param string $lang Language pack to inspect.
     * @param string $storagefragment Localised encrypted storage text.
     * @param string $revealingfragment Localised no-reveal text.
     * @return void
     */
    public function test_credential_help_documents_secure_storage_and_forced_overrides(
        string $lang,
        string $storagefragment,
        string $revealingfragment
    ): void {
        $string = [];
        require(__DIR__ . '/../lang/' . $lang . '/sissource_athenasoft.php');

        foreach (['api_key', 'auth_user', 'auth_cred'] as $settingname) {
            $description = $string[$settingname . '_desc'];
            $this->assertStringContainsString($storagefragment, $description);
            $this->assertStringContainsString('config.php', $description);
            $this->assertStringContainsString(
                "\$CFG-&gt;forced_plugin_settings['sissource_athenasoft']['{$settingname}']",
                $description
            );
            $this->assertStringContainsString($revealingfragment, $description);
        }
    }

    /**
     * Migration-only role mapping labels must not remain in the language packs.
     *
     * @dataProvider api_url_desc_provider
     * @param string $lang Language pack to inspect.
     * @return void
     */
    public function test_legacy_rolemap_labels_are_absent(string $lang): void {
        $string = [];
        require(__DIR__ . '/../lang/' . $lang . '/sissource_athenasoft.php');

        $this->assertArrayNotHasKey('rolemap_cursist', $string);
        $this->assertArrayNotHasKey('rolemap_cursist_desc', $string);
        $this->assertArrayNotHasKey('rolemap_leerkracht', $string);
        $this->assertArrayNotHasKey('rolemap_leerkracht_desc', $string);
    }

    /**
     * Assert field mapping help documents every expanded target group.
     *
     * @dataProvider api_url_desc_provider
     * @param string $lang Language pack to inspect.
     * @return void
     */
    public function test_fieldmap_help_documents_expanded_targets(string $lang): void {
        $string = [];
        require(__DIR__ . '/../lang/' . $lang . '/sissource_athenasoft.php');

        foreach (
            [
                'course', 'user', 'enrolment', 'unenrolment', 'templatekey',
                'profile_field_&lt;shortname&gt;', 'address', 'password', 'role', 'opleidingsvariantId',
            ] as $target
        ) {
            $this->assertStringContainsString($target, $string['fieldmap_desc']);
        }
        $this->assertStringContainsString('user (idnumber, username,', $string['fieldmap_desc']);
    }

    /**
     * Identity mapping help must explain stable columns and existing-user behavior.
     *
     * @dataProvider identity_help_provider
     * @param string $lang Language pack to inspect.
     * @param string $identityfragment Localised identity-target text.
     * @param string $stablefragment Localised stable-source-column text.
     * @param string $renamefragment Localised existing-user behavior text.
     * @param string $collisionfragment Localised collision-skip text.
     * @param string $duplicatefragment Localised duplicate-account text.
     * @return void
     */
    public function test_fieldmap_help_documents_identity_field_safety(
        string $lang,
        string $identityfragment,
        string $stablefragment,
        string $renamefragment,
        string $collisionfragment,
        string $duplicatefragment
    ): void {
        $string = [];
        require(__DIR__ . '/../lang/' . $lang . '/sissource_athenasoft.php');

        $this->assertStringContainsString($identityfragment, $string['fieldmap_desc']);
        $this->assertStringContainsString($stablefragment, $string['fieldmap_desc']);
        $this->assertStringContainsString($renamefragment, $string['fieldmap_desc']);
        $this->assertStringContainsString($collisionfragment, $string['fieldmap_desc']);
        $this->assertStringContainsString($duplicatefragment, $string['fieldmap_desc']);
    }

    /**
     * Assert API URL help escapes the endpoint placeholder for browser rendering.
     *
     * @dataProvider api_url_desc_provider
     * @param string $lang Language pack to inspect.
     * @return void
     */
    public function test_api_url_desc_escapes_endpoint_placeholder_for_browser_rendering(string $lang): void {
        $string = [];
        require(__DIR__ . '/../lang/' . $lang . '/sissource_athenasoft.php');

        $this->assertArrayHasKey('api_url_desc', $string);
        $this->assertStringNotContainsString('<DOMEINCENTRUM>', $string['api_url_desc']);
        $this->assertStringContainsString('&lt;DOMEINCENTRUM&gt;', $string['api_url_desc']);
    }

    /**
     * Return language packs that document AthenaSoft endpoint placeholders.
     *
     * @return array[]
     */
    public static function api_url_desc_provider(): array {
        return [
            'English' => ['en'],
            'Dutch' => ['nl'],
        ];
    }

    /**
     * Return identity mapping help expectations for each supported language.
     *
     * @return array[]
     */
    public static function identity_help_provider(): array {
        return [
            'English' => [
                'en',
                'Identity targets idnumber and username',
                'stable, unique source columns',
                'does not rename existing users',
                'collision skips',
                'duplicate accounts',
            ],
            'Dutch' => [
                'nl',
                'identiteitsdoelen idnumber en username',
                'stabiele, unieke bronkolommen',
                'hernoemt bestaande gebruikers niet',
                'overgeslagen conflicten',
                'dubbele accounts',
            ],
        ];
    }

    /**
     * Return credential help expectations for each supported language.
     *
     * @return array[]
     */
    public static function credential_help_provider(): array {
        return [
            'English' => ['en', 'encrypted in the database', 'no reveal control'],
            'Dutch' => ['nl', 'versleuteld in de database', 'geen bediening om de waarde zichtbaar te maken'],
        ];
    }
}
