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
 * Event logger helper tests for local_wisa.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Event logger helper tests for local_wisa.
 *
 * Expected helper API for implementers:
 * \local_wisa\event_logger::admin_action(string $eventclass, array $other): void.
 * The first argument is the fully-qualified event class to trigger. The helper must create the event with
 * \context_system::instance(), keep only scalar safe other fields, and omit secrets/raw diagnostics.
 *
 * @group local_wisa
 * @group local_wisa_events
 * @covers     \local_wisa\event_logger
 */
final class event_logger_test extends \advanced_testcase {
    /**
     * Test helper: assert a captured event exists and uses system context.
     *
     * @param array $events Captured events.
     * @param string $classname Expected event class.
     * @return \core\event\base
     */
    private function assert_captured_system_event(array $events, string $classname): \core\event\base {
        foreach ($events as $event) {
            if ('\\' . get_class($event) === $classname || get_class($event) === ltrim($classname, '\\')) {
                $this->assertEquals(\context_system::instance(), $event->get_context());
                return $event;
            }
        }

        $this->fail('Expected Moodle event was not captured: ' . $classname);
    }

    /**
     * Return the Moodle event other payload.
     *
     * @param \core\event\base $event Event.
     * @return array
     */
    private function event_other(\core\event\base $event): array {
        $data = $event->get_data();
        return isset($data['other']) && is_array($data['other']) ? $data['other'] : [];
    }

    /**
     * Assert every event other value is scalar and secret-free.
     *
     * @param \core\event\base $event Event.
     */
    private function assert_safe_event_payload(\core\event\base $event): void {
        $haystack = [];
        foreach ($this->event_other($event) as $key => $value) {
            $this->assertTrue(is_scalar($value), 'Event other field must be scalar: ' . $key);
            $haystack[] = (string)$value;
        }
        $haystack[] = $event->get_description();
        $haystack = implode(' ', $haystack);

        foreach ($this->known_secret_values() as $secret) {
            $this->assertStringNotContainsString($secret, $haystack);
        }
    }

    /**
     * Return known secret values used by these tests.
     *
     * @return array
     */
    private function known_secret_values(): array {
        return [
            'SECRET-API-KEY',
            'SECRET-AUTH-USER',
            'SECRET-AUTH-CRED',
            'SECRET-PASWORD',
            'Api-Authorization-Key',
            'auth.user',
            'auth.cred',
            'raw_payload',
        ];
    }

    /**
     * Return unsafe fields that must not be copied into Moodle event other data.
     *
     * @return array
     */
    private function unsafe_other_fields(): array {
        return [
            'api_key' => 'SECRET-API-KEY',
            'auth_user' => 'SECRET-AUTH-USER',
            'auth_cred' => 'SECRET-AUTH-CRED',
            'pasword' => 'SECRET-PASWORD',
            'raw_payload' => '{"Api-Authorization-Key":"SECRET-API-KEY"}',
            'nested' => ['secret' => 'SECRET-API-KEY'],
        ];
    }

    /**
     * Assert unsafe keys are absent from event other data.
     *
     * @param array $other Event other payload.
     */
    private function assert_unsafe_keys_absent(array $other): void {
        foreach (array_keys($this->unsafe_other_fields()) as $key) {
            $this->assertArrayNotHasKey($key, $other);
        }
    }

    /**
     * Admin-action helper emits all requested admin and first-load events.
     */
    public function test_admin_action_helper_emits_safe_admin_and_first_load_events(): void {
        $this->resetAfterTest();
        $cases = [
            [
                'class' => '\\local_wisa\\event\\admin_test_connection_run',
                'other' => [
                    'source' => 'athenasoft',
                    'status' => 'success',
                ],
            ],
            [
                'class' => '\\local_wisa\\event\\admin_preview_run',
                'other' => [
                    'source' => 'wisa',
                    'mode' => 'DRY-RUN',
                    'duration_ms' => 25,
                    'courses_found' => 2,
                    'users_found' => 3,
                ],
            ],
            [
                'class' => '\\local_wisa\\event\\admin_manual_sync_run',
                'other' => [
                    'source' => 'wisa',
                    'mode' => 'LIVE',
                    'forcefull' => true,
                    'duration_seconds' => 4,
                ],
            ],
            [
                'class' => '\\local_wisa\\event\\sync_first_load_approved',
                'other' => ['source' => 'wisa', 'forcefull' => true],
            ],
            [
                'class' => '\\local_wisa\\event\\sync_first_load_reset',
                'other' => ['source' => 'wisa'],
            ],
        ];

        $sink = $this->redirectEvents();
        foreach ($cases as $case) {
            event_logger::admin_action($case['class'], $case['other'] + $this->unsafe_other_fields());
        }
        $events = $sink->get_events();

        foreach ($cases as $case) {
            $event = $this->assert_captured_system_event($events, $case['class']);
            $other = $this->event_other($event);
            foreach ($case['other'] as $key => $value) {
                $this->assertArrayHasKey($key, $other);
                $this->assertSame($value, $other[$key]);
            }
            $this->assert_unsafe_keys_absent($other);
            $this->assert_safe_event_payload($event);
        }
    }

    /**
     * Sync helper methods emit expected Moodle events and safe payloads.
     */
    public function test_sync_helpers_emit_expected_events_with_safe_payloads(): void {
        $this->resetAfterTest();

        $stats = new sync_stats();
        $stats->coursecreate = 1;
        $stats->courseupdate = 2;
        $stats->coursefail = 3;
        $stats->courseskip = 4;
        $stats->usercreate = 5;
        $stats->userupdate = 6;
        $stats->userfail = 7;
        $stats->enrolcreate = 8;
        $stats->enrolupdate = 9;
        $stats->enrolwarn = 10;
        $stats->enrolfail = 11;
        $stats->enrolskip = 12;
        $stats->unenrolok = 13;
        $stats->unenrolwarn = 14;
        $stats->unenrolfail = 15;
        $stats->unenrolskip = 16;

        $cases = [
            [
                'class' => '\\local_wisa\\event\\sync_started',
                'other' => [
                    'source' => 'wisa',
                    'mode' => 'LIVE',
                    'forcefull' => true,
                ],
            ],
            [
                'class' => '\\local_wisa\\event\\sync_skipped',
                'other' => [
                    'source' => 'athenasoft',
                    'reason' => 'disabled',
                    'status' => 'skipped',
                ],
            ],
            [
                'class' => '\\local_wisa\\event\\sync_failed',
                'other' => [
                    'source' => 'wisa',
                    'mode' => 'DRY-RUN',
                    'forcefull' => false,
                    'status' => 'failed',
                    'duration_seconds' => 7,
                ],
            ],
            [
                'class' => '\\local_wisa\\event\\sync_completed',
                'other' => [
                    'source' => 'athenasoft',
                    'mode' => 'LIVE',
                    'forcefull' => true,
                    'status' => 'success',
                    'duration_seconds' => 13,
                    'courses_created' => 1,
                    'courses_updated' => 2,
                    'courses_failed' => 3,
                    'courses_skipped' => 4,
                    'users_created' => 5,
                    'users_updated' => 6,
                    'users_failed' => 7,
                    'enrolments_created' => 8,
                    'enrolments_updated' => 9,
                    'enrolments_warned' => 10,
                    'enrolments_failed' => 11,
                    'enrolments_skipped' => 12,
                    'unenrolments_completed' => 13,
                    'unenrolments_warned' => 14,
                    'unenrolments_failed' => 15,
                    'unenrolments_skipped' => 16,
                    'summary' => 'Completed summary',
                ],
            ],
        ];

        $sink = $this->redirectEvents();
        event_logger::sync_started('wisa', 'LIVE', true);
        event_logger::sync_skipped('athenasoft', 'disabled');
        event_logger::sync_failed('wisa', 'DRY-RUN', false, 7);
        event_logger::sync_completed('athenasoft', 'LIVE', true, 13, 'Completed summary', $stats);
        $events = $sink->get_events();

        $this->assertCount(count($cases), $events);
        foreach ($cases as $case) {
            $event = $this->assert_captured_system_event($events, $case['class']);
            $other = $this->event_other($event);
            $this->assertEqualsCanonicalizing(array_keys($case['other']), array_keys($other));
            foreach ($case['other'] as $key => $value) {
                $this->assertSame($value, $other[$key]);
            }
            $this->assert_safe_event_payload($event);
        }
    }

    /**
     * Active source helper returns default and configured source values.
     */
    public function test_active_source_returns_default_and_configured_values(): void {
        $this->resetAfterTest();

        unset_config('active_source', 'local_wisa');
        $this->assertSame('wisa', event_logger::active_source());

        set_config('active_source', 'athenasoft', 'local_wisa');
        $this->assertSame('athenasoft', event_logger::active_source());
    }

    /**
     * Mode label helper returns explicit and configured mode labels.
     */
    public function test_mode_label_returns_explicit_and_configured_values(): void {
        $this->resetAfterTest();

        $this->assertSame('LIVE', event_logger::mode_label(false));
        $this->assertSame('DRY-RUN', event_logger::mode_label(true));

        set_config('dry_run', 0, 'local_wisa');
        $this->assertSame('LIVE', event_logger::mode_label());

        set_config('dry_run', 1, 'local_wisa');
        $this->assertSame('DRY-RUN', event_logger::mode_label());
    }

    /**
     * Admin-action helper rejects invalid non-event classes.
     */
    public function test_admin_action_rejects_invalid_non_event_class(): void {
        $this->resetAfterTest();
        $this->expectException(\coding_exception::class);

        event_logger::admin_action(\stdClass::class, []);
    }
}
