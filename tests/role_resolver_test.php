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
 * Role resolver tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Verifies configured source-role tokens resolve safely at runtime.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class role_resolver_test extends \advanced_testcase {
    /**
     * Arbitrary configured Moodle role shortnames resolve to their role IDs.
     *
     * @return void
     */
    public function test_resolves_arbitrary_configured_role_shortname(): void {
        $this->resetAfterTest();
        $roleid = self::getDataGenerator()->create_role([
            'name' => 'WISA mentor',
            'shortname' => 'wisa_mentor',
        ]);
        set_config('rolemap', '{"mentor":"wisa_mentor"}', 'local_wisa');

        $resolver = new role_resolver();

        $this->assertSame($roleid, $resolver->resolve('mentor'));
    }

    /**
     * Invalid configuration retains the semantic default mappings safely.
     *
     * @return void
     */
    public function test_invalid_rolemap_falls_back_to_semantic_defaults(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('rolemap', '{not-json}', 'local_wisa');
        $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);

        $this->assertSame($studentroleid, (new role_resolver())->resolve('student'));
        $this->assertTrue($DB->record_exists('local_wisa_log', [
            'action' => 'api_config',
            'status' => 'warning',
        ]));
    }

    /**
     * Unknown source tokens identify their normalised token, while unavailable mapped roles remain redacted.
     *
     * @return void
     */
    public function test_unknown_token_and_missing_moodle_role_are_rejected_with_warnings(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('rolemap', '{"missing":"not_a_moodle_role"}', 'local_wisa');
        $resolver = new role_resolver();

        $this->assertNull($resolver->resolve('  UNKNOWN  '));
        $this->assertNull($resolver->resolve('missing'));
        $this->assertSame(2, $DB->count_records('local_wisa_log', [
            'action' => 'sync_enrol',
            'status' => 'warn',
        ]));
        $warnings = $DB->get_records('local_wisa_log', [
            'action' => 'sync_enrol',
            'status' => 'warn',
        ], 'id ASC');
        $this->assertSame(['unknown', 'redacted'], array_column($warnings, 'objectid'));
        $this->assertSame(['ROLE_TOKEN_UNKNOWN', 'ROLE_MAPPING_UNAVAILABLE'], array_column($warnings, 'message'));
        foreach ($warnings as $warning) {
            $this->assertStringNotContainsString('unknown', $warning->message);
            $this->assertStringNotContainsString('missing', $warning->message);
            $this->assertStringNotContainsString('not_a_moodle_role', $warning->message);
        }
    }
}
