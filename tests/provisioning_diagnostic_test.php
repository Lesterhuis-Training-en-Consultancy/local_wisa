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
 * Provisioning diagnostic test coverage.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/provisioning_service_test_case.php');

/**
 * Verifies stable, safe provisioning diagnostics.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_wisa
 * @covers     \local_wisa\provisioning_diagnostic
 */
final class provisioning_diagnostic_test extends provisioning_service_test_case {
    /**
     * Every operational category has a bounded rendering contract.
     *
     * @return void
     */
    public function test_format_supports_all_stable_operational_diagnostic_categories(): void {
        // Given.
        $this->resetAfterTest();
        $codes = [
            provisioning_diagnostic::CODE_LOCK_CONCURRENCY,
            provisioning_diagnostic::CODE_SHORTNAME_COLLISION,
            provisioning_diagnostic::CODE_INVALID_SOURCE_DATA,
            provisioning_diagnostic::CODE_MAPPING_VALIDATION,
            provisioning_diagnostic::CODE_MOODLE_COURSE_CREATE_RESTORE,
            provisioning_diagnostic::CODE_SOURCE_API,
            provisioning_diagnostic::CODE_UNKNOWN,
        ];

        // When.
        $formatted = array_map([provisioning_diagnostic::class, 'format'], $codes);

        // Then.
        $this->assertSame(7, count(array_unique($codes)));
        foreach ($formatted as $value) {
            $this->assertNotSame('', $value);
            $this->assertLessThanOrEqual(255, \core_text::strlen($value));
        }
    }

    /**
     * Unknown failures are categorised without retaining exception contents.
     *
     * @return void
     */
    public function test_unknown_exception_diagnostic_excludes_sensitive_and_personal_values(): void {
        // Given.
        $this->resetAfterTest();
        $sensitive = 'password=secret endpoint=https://sis.example.test payload={"token":"abc"} '
            . 'user=Ada-Lovelace course=Personal-course';

        // When.
        $code = provisioning_diagnostic::from_exception(new \Exception($sensitive));
        $formatted = provisioning_diagnostic::format($code);

        // Then.
        $this->assertSame(provisioning_diagnostic::CODE_UNKNOWN, $code);
        $this->assertLessThanOrEqual(255, \core_text::strlen($formatted));
        $this->assertStringNotContainsString('secret', $formatted);
        $this->assertStringNotContainsString('https://sis.example.test', $formatted);
        $this->assertStringNotContainsString('payload', $formatted);
        $this->assertStringNotContainsString('Ada-Lovelace', $formatted);
        $this->assertStringNotContainsString('Personal-course', $formatted);
    }

    /**
     * Known exception families map to distinct safe diagnostic categories.
     *
     * @return void
     */
    public function test_known_exception_families_map_to_operational_categories(): void {
        $this->resetAfterTest();

        $this->assertSame(
            provisioning_diagnostic::CODE_LOCK_CONCURRENCY,
            provisioning_diagnostic::from_exception(new \coding_exception('Provisioning state is already being changed.'))
        );
        $this->assertSame(
            provisioning_diagnostic::CODE_INVALID_SOURCE_DATA,
            provisioning_diagnostic::from_exception(new \UnexpectedValueException('Invalid source row.'))
        );
        $this->assertSame(
            provisioning_diagnostic::CODE_MAPPING_VALIDATION,
            provisioning_diagnostic::from_exception(new \JsonException('Invalid mapping JSON.'))
        );
        $this->assertSame(
            provisioning_diagnostic::CODE_MOODLE_COURSE_CREATE_RESTORE,
            provisioning_diagnostic::from_exception(new \moodle_exception('error'))
        );
        $this->assertSame(
            provisioning_diagnostic::CODE_SOURCE_API,
            provisioning_diagnostic::from_exception(new \RuntimeException('Source API failed.'))
        );
    }

    /**
     * An unproven restore failure persists the unknown diagnostic rather than generic text.
     *
     * @return void
     */
    public function test_unproven_restore_failure_persists_course_restore_diagnostic(): void {
        // Given.
        $this->resetAfterTest();
        $context = $this->create_context();
        set_config('templatemap', json_encode(['template-one' => (int)$context['template']->id]), 'local_wisa');
        $record = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            'template-one',
            'C6-DIAGNOSTIC-RESTORE'
        );
        $service = new provisioning_service_test_double(function (\stdClass $running): array {
            throw new \moodle_exception('simulatedrestorefailure');
        });

        // When.
        $service->execute_provision((int)$record->id, $record->jobid, $context['userid']);
        $finished = (new provisioning_repository())->get((int)$record->id);

        // Then.
        $this->assertSame(provisioning_repository::STATUS_FAILED, $finished->status);
        $this->assertNotSame('Provisioning failed.', $finished->lasterror);
        $this->assertSame(provisioning_diagnostic::CODE_MOODLE_COURSE_CREATE_RESTORE, $finished->lasterror);
    }
}
