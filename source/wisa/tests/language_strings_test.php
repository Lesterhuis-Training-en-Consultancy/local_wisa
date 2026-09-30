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
 * Language string tests for sissource_wisa.
 *
 * @package    sissource_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests WISA field mapping help in supported languages.
 *
 * @package    sissource_wisa
 * @category   test
 * @group      sissource_wisa
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
        require(__DIR__ . '/../lang/' . $lang . '/sissource_wisa.php');

        foreach (['api_user', 'api_pass'] as $settingname) {
            $description = $string[$settingname . '_desc'];
            $this->assertStringContainsString($storagefragment, $description);
            $this->assertStringContainsString('config.php', $description);
            $this->assertStringContainsString(
                "\$CFG-&gt;forced_plugin_settings['sissource_wisa']['{$settingname}']",
                $description
            );
            $this->assertStringContainsString($revealingfragment, $description);
        }
    }

    /**
     * Field mapping help must document every expanded target group.
     *
     * @dataProvider language_provider
     * @param string $lang Language pack to inspect.
     * @return void
     */
    public function test_fieldmap_help_documents_expanded_targets(string $lang): void {
        $string = [];
        require(__DIR__ . '/../lang/' . $lang . '/sissource_wisa.php');

        $this->assertArrayHasKey('fieldmap', $string);
        $this->assertArrayHasKey('fieldmap_desc', $string);
        foreach (['course', 'user', 'enrolment', 'unenrolment', 'templatekey', 'profile_field_&lt;shortname&gt;', 'address', 'role'] as $target) {
            $this->assertStringContainsString($target, $string['fieldmap_desc']);
        }
        $this->assertStringContainsString('user (idnumber, username,', $string['fieldmap_desc']);
    }

    /**
     * Template keys must require an explicit source column because WISA has no default.
     *
     * @dataProvider templatekey_help_provider
     * @param string $lang Language pack to inspect.
     * @param string $nodefault Localised no-default term.
     * @param string $explicitsource Localised explicit-source-column term.
     * @return void
     */
    public function test_fieldmap_templatekey_help_requires_an_explicit_source_without_a_default(
        string $lang,
        string $nodefault,
        string $explicitsource
    ): void {
        $string = [];
        require(__DIR__ . '/../lang/' . $lang . '/sissource_wisa.php');

        foreach (['templatekey', $nodefault, $explicitsource] as $term) {
            $this->assertStringContainsString($term, $string['fieldmap_desc']);
        }
        $tokens = preg_split('/\s+/', strip_tags($string['fieldmap_desc']));
        foreach ($tokens as $token) {
            $this->assertLessThanOrEqual(64, \core_text::strlen($token));
        }
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
        require(__DIR__ . '/../lang/' . $lang . '/sissource_wisa.php');

        $this->assertStringContainsString($identityfragment, $string['fieldmap_desc']);
        $this->assertStringContainsString($stablefragment, $string['fieldmap_desc']);
        $this->assertStringContainsString($renamefragment, $string['fieldmap_desc']);
        $this->assertStringContainsString($collisionfragment, $string['fieldmap_desc']);
        $this->assertStringContainsString($duplicatefragment, $string['fieldmap_desc']);
    }

    /**
     * Return supported field mapping help languages.
     *
     * @return array[]
     */
    public static function language_provider(): array {
        return [
            'English' => ['en'],
            'Dutch' => ['nl'],
        ];
    }

    /**
     * Return template key help expectations for each supported language.
     *
     * @return array[]
     */
    public static function templatekey_help_provider(): array {
        return [
            'English' => ['en', 'no default', 'explicit source column'],
            'Dutch' => ['nl', 'geen standaard', 'expliciete bronkolom'],
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
