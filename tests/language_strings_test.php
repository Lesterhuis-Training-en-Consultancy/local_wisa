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
 * Parent language string tests for local_wisa.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests parent language strings used by SIS source administration.
 *
 * @package    local_wisa
 * @category   test
 * @group      local_wisa
 * @coversNothing
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class language_strings_test extends \advanced_testcase {
    /**
     * Connection-test selection and tuple status strings must exist in English and Dutch.
     *
     * @return void
     */
    public function test_connection_test_descriptor_strings_exist_in_english_and_dutch(): void {
        $requiredkeys = [
            'test_connection_select',
            'test_connection_select_prompt',
            'test_connection_option',
            'test_connection_last_descriptor',
            'test_connection_last_source',
            'test_connection_last_stream',
            'test_connection_last_phase',
            'test_connection_last_transport',
            'test_connection_invalid_selection',
        ];

        foreach (['en', 'nl'] as $language) {
            $string = [];
            require(__DIR__ . '/../lang/' . $language . '/local_wisa.php');
            foreach ($requiredkeys as $key) {
                $this->assertArrayHasKey($key, $string, $language . ': ' . $key);
                $this->assertNotSame('', trim($string[$key]), $language . ': ' . $key);
            }
        }
    }

    /**
     * Stream status labels, help, statuses, controls, and feedback must exist in EN and NL.
     *
     * @return void
     */
    public function test_stream_status_strings_exist_in_english_and_dutch(): void {
        $requiredkeys = [
            'stream_status_title', 'stream_status_intro', 'stream_status_col_source',
            'stream_status_col_descriptor', 'stream_status_descriptor_help', 'stream_status_col_phase',
            'stream_status_col_transport', 'stream_status_col_enabled', 'stream_status_col_watermark',
            'stream_status_col_status', 'stream_status_yes', 'stream_status_no',
            'stream_status_disabled', 'stream_status_never_run', 'stream_status_successful',
            'stream_status_failed', 'stream_status_malformed', 'stream_status_processing',
            'stream_status_provisioning_blocked', 'stream_status_requires_admin_resolution',
            'stream_resolution_heading', 'stream_resolution_help', 'stream_resolution_choice_enable',
            'stream_resolution_choice_disable', 'stream_resolution_submit',
            'stream_resolution_success', 'stream_resolution_failed', 'stream_setting_resolution_required', 'stream_placements',
            'stream_linked_teachers',
        ];

        foreach (['en', 'nl'] as $language) {
            $string = [];
            require(__DIR__ . '/../lang/' . $language . '/local_wisa.php');
            foreach ($requiredkeys as $key) {
                $this->assertArrayHasKey($key, $string, $language . ': ' . $key);
                $this->assertNotSame('', trim($string[$key]), $language . ': ' . $key);
            }
        }
    }

    /**
     * Preview tuple metadata labels must exist in English and Dutch.
     *
     * @return void
     */
    public function test_preview_tuple_metadata_strings_exist_in_english_and_dutch(): void {
        $requiredkeys = [
            'preview_source_metadata_heading',
            'stream_status_col_stream',
            'stream_status_col_watermark_mode',
        ];

        foreach (['en', 'nl'] as $language) {
            $string = [];
            require(__DIR__ . '/../lang/' . $language . '/local_wisa.php');
            foreach ($requiredkeys as $key) {
                $this->assertArrayHasKey($key, $string, $language . ': ' . $key);
                $this->assertNotSame('', trim($string[$key]), $language . ': ' . $key);
            }
        }
    }

    /**
     * Provisioning settings and status page strings must exist in English and Dutch.
     *
     * @return void
     */
    public function test_provisioning_strings_exist_in_english_and_dutch(): void {
        $requiredkeys = [
            'enable_course_provisioning',
            'enable_course_provisioning_desc',
            'templatemap',
            'templatemap_desc',
            'provisioning_title',
            'provisioning_retry',
            'provisioning_retry_success',
            'provisioning_retry_failed',
            'provisioning_empty',
            'provisioning_no_action',
            'provisioning_col_source',
            'provisioning_col_courseid',
            'provisioning_col_desiredcourse',
            'provisioning_col_status',
            'provisioning_col_attempts',
            'provisioning_col_lasterror',
            'provisioning_col_updated',
            'provisioning_col_action',
        ];

        foreach (['en', 'nl'] as $language) {
            $string = [];
            require(__DIR__ . '/../lang/' . $language . '/local_wisa.php');
            foreach ($requiredkeys as $key) {
                $this->assertArrayHasKey($key, $string, $language . ': ' . $key);
                $this->assertNotSame('', trim($string[$key]), $language . ': ' . $key);
            }
        }
    }

    /**
     * Template map help must explain its neutral positive-ID contract and fallback.
     *
     * @return void
     */
    public function test_templatemap_help_explains_positive_ids_and_unmapped_fallback(): void {
        $expected = [
            'en' => ['Source-neutral', 'positive Moodle course ID', 'empty course fallback'],
            'nl' => ['Bronneutraal', 'positief Moodle-cursus-ID', 'lege terugvalcursus'],
        ];

        foreach ($expected as $language => $phrases) {
            $string = [];
            require(__DIR__ . '/../lang/' . $language . '/local_wisa.php');
            foreach ($phrases as $phrase) {
                $this->assertStringContainsString($phrase, $string['templatemap_desc']);
            }
        }
    }

    /**
     * Role mapping help must describe token mapping without stream-specific classifications.
     *
     * @return void
     */
    public function test_rolemap_help_is_stream_neutral_in_english_and_dutch(): void {
        $expected = [
            'en' => ['Role mapping', 'arbitrary', 'role shortnames'],
            'nl' => ['Rolmapping', 'willekeurige', 'rolshortnames'],
        ];
        $forbidden = [
            'en' => ['enrol_students', 'enrol_teachers', 'feed', 'classification'],
            'nl' => ['enrol_students', 'enrol_teachers', 'feed', 'classificatie'],
        ];

        foreach ($expected as $language => [$label, $arbitrary, $shortnames]) {
            $string = [];
            require(__DIR__ . '/../lang/' . $language . '/local_wisa.php');

            $this->assertSame($label, $string['rolemap']);
            $this->assertStringContainsString($arbitrary, $string['rolemap_desc']);
            $this->assertStringContainsString($shortnames, $string['rolemap_desc']);
            foreach ($forbidden[$language] as $term) {
                $this->assertStringNotContainsString(
                    $term,
                    \core_text::strtolower($string['rolemap_desc']),
                    $language . ': ' . $term
                );
            }
            $this->assertArrayNotHasKey('teacher_role', $string);
            $this->assertArrayNotHasKey('student_role', $string);
        }
    }

    /**
     * Preview introduction copy must not describe mutable workflow state.
     *
     * @return void
     */
    public function test_preview_intro_is_state_neutral_in_english_and_dutch(): void {
        $forbidden = [
            'en' => ['paus', 'approv'],
            'nl' => ['pauz', 'goedkeur'],
        ];

        foreach ($forbidden as $language => $terms) {
            $string = [];
            require(__DIR__ . '/../lang/' . $language . '/local_wisa.php');
            $intro = \core_text::strtolower($string['preview_intro']);

            foreach ($terms as $term) {
                $this->assertStringNotContainsString($term, $intro, $language . ': ' . $term);
            }
        }
    }

    /**
     * Required singular and plural SIS source labels must resolve exactly.
     *
     * @return void
     */
    public function test_required_sissource_labels_resolve_exactly(): void {
        $expected = [
            'en' => ['SIS source', 'SIS sources'],
            'nl' => ['SIS-source', 'SIS-sources'],
        ];

        foreach ($expected as $language => [$singular, $plural]) {
            $string = [];
            require(__DIR__ . '/../lang/' . $language . '/local_wisa.php');
            $this->assertSame($singular, $string['subplugintype_sissource']);
            $this->assertSame($plural, $string['subplugintype_sissource_plural']);
        }
    }
}
