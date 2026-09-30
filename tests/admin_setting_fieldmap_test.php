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
 * Tests for the shared field mapping admin setting.
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
 * Tests save-time validation for shared adapter field mapping settings.
 *
 * @package    local_wisa
 * @category   test
 * @group      local_wisa
 * @coversNothing
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class admin_setting_fieldmap_test extends \advanced_testcase {
    /**
     * The shared setting must retain textarea rendering behaviour.
     *
     * @return void
     */
    public function test_fieldmap_setting_extends_configtextarea(): void {
        $this->assertInstanceOf(\admin_setting_configtextarea::class, $this->get_fieldmap_setting());
    }

    /**
     * Empty configuration and valid mappings for every record group must be accepted.
     *
     * @dataProvider valid_fieldmap_provider
     * @param string $fieldmap Field mapping JSON.
     * @return void
     */
    public function test_write_setting_accepts_empty_and_valid_fieldmaps(string $fieldmap): void {
        $this->resetAfterTest();

        $this->assertSame('', $this->get_fieldmap_setting()->write_setting($fieldmap));
    }

    /**
     * Invalid field maps must return a localised error rather than be saved.
     *
     * @dataProvider invalid_fieldmap_provider
     * @param string $fieldmap Invalid field mapping JSON.
     * @return void
     */
    public function test_write_setting_rejects_invalid_fieldmaps_with_a_localised_error(string $fieldmap): void {
        $this->resetAfterTest();

        $error = $this->get_fieldmap_setting()->write_setting($fieldmap);

        $this->assertIsString($error);
        $this->assertNotSame('', trim($error));
    }

    /**
     * Invalid input must retain an existing value and valid input must persist.
     *
     * @return void
     */
    public function test_write_setting_preserves_existing_value_after_invalid_input_and_persists_valid_input(): void {
        $this->resetAfterTest();
        $setting = $this->get_fieldmap_setting();
        $existing = '{"user":{"email":"CURRENT_EMAIL"}}';
        $replacement = '{"course":{"templatekey":"TEMPLATE_COLUMN"}}';

        set_config('fieldmap', $existing, 'sissource_fieldmaptest');
        $this->assertNotSame('', $setting->write_setting('{"user":{"email":""}}'));
        $this->assertSame($existing, get_config('sissource_fieldmaptest', 'fieldmap'));

        $this->assertSame('', $setting->write_setting($replacement));
        $this->assertSame($replacement, get_config('sissource_fieldmaptest', 'fieldmap'));
    }

    /**
     * Return valid field map values.
     *
     * @return array[]
     */
    public static function valid_fieldmap_provider(): array {
        return [
            'empty string' => [''],
            'all target groups' => [
                '{"course":{"templatekey":"TEMPLATE"},"user":{"email":"EMAIL",' .
                '"profile_field_student_id":"STUDENT_ID"},"enrolment":{"role":"ROLE"},' .
                '"unenrolment":{"useridnumber":"USER_ID"}}',
            ],
        ];
    }

    /**
     * Return invalid field map values.
     *
     * @return array[]
     */
    public static function invalid_fieldmap_provider(): array {
        return [
            'malformed JSON' => ['{'],
            'list root' => ['[]'],
            'scalar root' => ['"COLUMN"'],
            'null root' => ['null'],
            'unknown record type' => ['{"unknown":{"email":"EMAIL"}}'],
            'unknown target' => ['{"user":{"unknown":"EMAIL"}}'],
            'non-string source column' => ['{"user":{"email":1}}'],
            'empty source column' => ['{"user":{"email":" "}}'],
        ];
    }

    /**
     * Return a shared field mapping setting under an isolated plugin component.
     *
     * @return \local_wisa\admin_setting_fieldmap
     */
    private function get_fieldmap_setting(): \local_wisa\admin_setting_fieldmap {
        return new \local_wisa\admin_setting_fieldmap(
            'sissource_fieldmaptest/fieldmap',
            'Field mapping',
            '',
            '',
            PARAM_RAW
        );
    }
}
