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
 * Preview mapping tests for local_wisa.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/preview_mapping_source.php');
require_once(__DIR__ . '/fixtures/preview_mapping_provider_source.php');

/**
 * Tests safe mapping metadata collection for the preview page.
 *
 * @package    local_wisa
 * @category   test
 * @group      local_wisa
 * @covers     \local_wisa\preview_mapping
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preview_mapping_test extends \advanced_testcase {
    /**
     * Provider metadata must preserve defaults, overrides, and dynamic profile targets.
     *
     * @return void
     */
    public function test_provider_metadata_includes_defaults_overrides_and_dynamic_targets_without_fetching(): void {
        $rows = preview_mapping::get_rows(new preview_mapping_provider_source());

        $this->assertSame([
            [
                'recordtype' => 'course',
                'target' => 'idnumber',
                'source' => 'KLAS_ID',
                'defaultsource' => 'KLAS_ID',
                'overridden' => false,
                'fallback' => false,
            ],
            [
                'recordtype' => 'user',
                'target' => 'email',
                'source' => 'ALT_EMAIL',
                'defaultsource' => 'EMAIL',
                'overridden' => true,
                'fallback' => false,
            ],
            [
                'recordtype' => 'user',
                'target' => 'profile_field_number_id',
                'source' => 'NUMBER_ID',
                'defaultsource' => null,
                'overridden' => true,
                'fallback' => false,
            ],
        ], $rows);
    }

    /**
     * Sources without mapping metadata must receive the generic contract fallback.
     *
     * @return void
     */
    public function test_source_without_provider_capability_uses_generic_contract_without_fetching(): void {
        $rows = preview_mapping::get_rows(new preview_mapping_source());

        $this->assertCount(28, $rows);
        $this->assertSame([
            'recordtype' => 'course',
            'target' => 'idnumber',
            'source' => 'idnumber',
            'defaultsource' => null,
            'overridden' => false,
            'fallback' => true,
        ], $rows[0]);
        $this->assertContains([
            'recordtype' => 'course',
            'target' => 'templatekey',
            'source' => 'templatekey',
            'defaultsource' => null,
            'overridden' => false,
            'fallback' => true,
        ], $rows);
        $this->assertContains([
            'recordtype' => 'user',
            'target' => 'address',
            'source' => 'address',
            'defaultsource' => null,
            'overridden' => false,
            'fallback' => true,
        ], $rows);
    }

    /**
     * The web entrypoint must instantiate only the active source and escape mapping metadata.
     *
     * @return void
     */
    public function test_preview_entrypoint_uses_safe_mapping_metadata_only(): void {
        $source = file_get_contents(__DIR__ . '/../preview.php');

        $this->assertStringContainsString('source_factory::get_active_source()', $source);
        $this->assertStringContainsString('preview_mapping::get_rows($source)', $source);
        $this->assertStringContainsString("s(\$row['source'])", $source);
        $this->assertStringContainsString("s(\$row['target'])", $source);
        $this->assertStringContainsString("s(\$row['defaultsource'])", $source);

        foreach (['get_courses', 'get_students', 'get_teachers', 'get_enrolments', 'get_unenrolments'] as $method) {
            $this->assertStringNotContainsString('->' . $method . '(', $source);
        }
    }

    /**
     * Preview must render the approved tuple metadata before its existing field mapping.
     *
     * @return void
     */
    public function test_preview_renders_approved_tuple_metadata_before_field_mapping(): void {
        $source = file_get_contents(__DIR__ . '/../preview.php');
        $headingposition = strpos($source, "get_string('preview_source_metadata_heading', 'local_wisa')");
        $mappingposition = strpos($source, "get_string('preview_fieldmap_heading', 'local_wisa')");

        $this->assertNotFalse($headingposition);
        $this->assertNotFalse($mappingposition);
        $this->assertLessThan($mappingposition, $headingposition);

        foreach (
            ['sourcecomponent', 'label', 'stream', 'phase', 'transport', 'enabled', 'watermarkmode',
                'haspriorwatermark', 'status', ] as $field
        ) {
            $this->assertStringContainsString("\$row['$field']", $source, $field);
        }
        $this->assertStringContainsString("get_string(\$row['label'], \$labelcomponent)", $source);
        $this->assertStringContainsString("get_string('part_' . \$row['phase'], 'local_wisa')", $source);
        $this->assertStringContainsString("'stream_status_' . str_replace('-', '_', \$row['status'])", $source);
        $this->assertStringContainsString("s(\$row['sourcecomponent'])", $source);
        $this->assertStringContainsString("s(\$row['stream'])", $source);
        $this->assertStringContainsString("s(\$row['transport'])", $source);
        $this->assertStringContainsString("s(\$row['watermarkmode'])", $source);
        $this->assertStringContainsString("\$row['enabled'] ? 'stream_status_yes' : 'stream_status_no'", $source);
        $this->assertStringContainsString(
            "\$row['haspriorwatermark'] ? 'stream_status_yes' : 'stream_status_no'",
            $source
        );
    }
}
