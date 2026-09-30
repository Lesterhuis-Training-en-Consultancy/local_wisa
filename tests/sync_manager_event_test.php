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
 * Sync manager event test coverage.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once(__DIR__ . '/../db/upgrade.php');
require_once($CFG->libdir . '/upgradelib.php');
require_once(__DIR__ . '/fixtures/sync_testcase.php');
require_once(__DIR__ . '/fixtures/sync_manager_test_case.php');
require_once(__DIR__ . '/fixtures/throwing_course_source.php');

use local_wisa\tests\fake_api_client;
use local_wisa\tests\sis_fixtures;


/**
 * Sync manager event tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sync_manager_event_test extends sync_manager_test_case {
    /**
     * Test emitted started and completed events.
     *
     * @return void
     */
    public function test_run_full_sync_emits_started_and_completed_events_with_safe_summary(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->configure_known_secret_values();
        $api = new fake_api_client([
            'courses' => [sis_fixtures::course()],
            'student_accounts' => [sis_fixtures::student()],
            'enrolments' => [sis_fixtures::enrolment()],
        ]);

        $sink = $this->redirectEvents();
        (new sync_manager($api))->run_full_sync();
        $events = $sink->get_events();

        $started = $this->assert_captured_system_event($events, '\\local_wisa\\event\\sync_started');
        $startedother = $this->event_other($started);
        $this->assertSame('wisa', $startedother['source']);
        $this->assertSame('LIVE', $startedother['mode']);
        $this->assertArrayHasKey('forcefull', $startedother);
        $this->assert_event_other_is_scalar($started);
        $this->assert_event_has_no_secret_values($started);

        $completed = $this->assert_captured_system_event($events, '\\local_wisa\\event\\sync_completed');
        $completedother = $this->event_other($completed);
        $this->assertSame('wisa', $completedother['source']);
        $this->assertSame('LIVE', $completedother['mode']);
        $this->assertArrayHasKey('forcefull', $completedother);
        $this->assertSame('success', $completedother['status']);
        $this->assertSame(1, $completedother['courses_created']);
        $this->assertSame(1, $completedother['users_created']);
        $this->assertSame(1, $completedother['enrolments_created']);
        $this->assertArrayHasKey('summary', $completedother);
        $this->assertNotSame('', (string)$completedother['summary']);
        $this->assert_event_other_is_scalar($completed);
        $this->assert_event_has_no_secret_values($completed);
    }

    /**
     * Test that mixed tuple outcomes emit a partial completion event.
     *
     * @return void
     */
    public function test_mixed_tuple_outcomes_emit_partial_completed_event(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->disable_except(['student_accounts:users', 'teacher_accounts:users']);
        $sink = $this->redirectEvents();

        $this->assertFalse((new sync_manager(new fake_api_client([], ['teacher_accounts' => true])))->run_full_sync());

        $completed = $this->assert_captured_system_event($sink->get_events(), '\\local_wisa\\event\\sync_completed');
        $this->assertSame('partial', $this->event_other($completed)['status']);
    }

    /**
     * Test that an all-failed request set emits a failed completion event.
     *
     * @return void
     */
    public function test_all_failed_tuple_outcomes_emit_failed_completed_event(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->disable_except(['teacher_accounts:users']);
        $sink = $this->redirectEvents();

        $this->assertFalse((new sync_manager(new fake_api_client([], ['teacher_accounts' => true])))->run_full_sync());

        $completed = $this->assert_captured_system_event($sink->get_events(), '\\local_wisa\\event\\sync_completed');
        $this->assertSame('failed', $this->event_other($completed)['status']);
    }

    /**
     * Test that the sync lock prevents overlapping runs.
     *
     * @return void
     */
    public function test_sync_lock_prevents_overlapping_runs(): void {
        global $DB;

        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $api = new fake_api_client(['courses' => [sis_fixtures::course()]]);
        $lockfactory = new class {
            /**
             * Simulate an unavailable lock.
             *
             * @param string $resource Lock resource.
             * @param int $timeout Timeout.
             * @return false
             */
            public function get_lock($resource, $timeout) {
                unset($resource, $timeout);
                return false;
            }
        };

        $this->assertSame([], $api->calls);
        $this->assertFalse((new sync_manager($api, $lockfactory))->run_full_sync());
    }

    /**
     * Test that lock contention emits a skipped event without calling the source.
     *
     * @return void
     */
    public function test_sync_lock_contention_emits_skipped_event_without_calling_source(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->configure_known_secret_values();
        $api = new fake_api_client(['courses' => [sis_fixtures::course()]]);
        $lockfactory = new class {
            /**
             * Simulate an unavailable lock.
             *
             * @param string $resource Lock resource.
             * @param int $timeout Timeout.
             * @return false
             */
            public function get_lock($resource, $timeout) {
                unset($resource, $timeout);
                return false;
            }
        };

        $sink = $this->redirectEvents();
        (new sync_manager($api, $lockfactory))->run_full_sync();
        $events = $sink->get_events();

        $this->assertSame([], $api->calls);
        $skipped = $this->assert_captured_system_event($events, '\\local_wisa\\event\\sync_skipped');
        $skippedother = $this->event_other($skipped);
        $this->assertSame('wisa', $skippedother['source']);
        $this->assertArrayHasKey('reason', $skippedother);
        $this->assertNotSame('', (string)$skippedother['reason']);
        $this->assert_event_other_is_scalar($skipped);
        $this->assert_event_has_no_secret_values($skipped);
        $this->assert_event_not_captured($events, '\\local_wisa\\event\\sync_started');
        $this->assert_event_not_captured($events, '\\local_wisa\\event\\sync_completed');
    }

    /**
     * Test that source failure emits a failed event without raw diagnostics.
     *
     * @return void
     */
    public function test_source_failure_emits_failed_event_without_raw_diagnostics(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $this->configure_known_secret_values();
        $sink = $this->redirectEvents();
        try {
            (new sync_manager(new throwing_course_source()))->run_full_sync();
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('SECRET-API-KEY', $exception->getMessage());
        }
        $events = $sink->get_events();

        $failed = $this->assert_captured_system_event($events, '\\local_wisa\\event\\sync_failed');
        $failedother = $this->event_other($failed);
        $this->assertSame('wisa', $failedother['source']);
        $this->assertSame('LIVE', $failedother['mode']);
        $this->assertArrayHasKey('forcefull', $failedother);
        $this->assertArrayHasKey('duration_seconds', $failedother);
        foreach (['exception', 'message', 'payload', 'diagnostic', 'trace', 'response', 'raw'] as $forbiddenkey) {
            $this->assertArrayNotHasKey($forbiddenkey, $failedother);
        }
        $this->assert_event_other_is_scalar($failed);
        $this->assert_event_has_no_secret_values($failed);
    }

    /**
     * Enable only the requested fixture tuples.
     *
     * @param array $enabled Tuple keys in stream:phase form.
     * @return void
     */
    private function disable_except(array $enabled): void {
        foreach (fake_api_client::get_stream_registry() as $descriptor) {
            foreach ($descriptor['phases'] as $phase) {
                $tuple = $descriptor['key'] . ':' . $phase;
                set_config(
                    'stream_' . $descriptor['key'] . '_' . $phase . '_enabled',
                    in_array($tuple, $enabled, true) ? 1 : 0,
                    'sissource_wisa'
                );
            }
        }
    }
}
