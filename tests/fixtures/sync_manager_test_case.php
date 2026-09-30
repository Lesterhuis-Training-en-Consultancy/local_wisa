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
 * Shared fixture for sync manager tests.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared sync manager test fixture.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class sync_manager_test_case extends sync_testcase {
    /**
     * Set up the shared sync manager test fixture.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG, $DB;

        parent::setUp();
        try {
            $table = new \xmldb_table('local_wisa_course_provision');
            if (!$DB->get_manager()->table_exists($table)) {
                $previousversion = get_config('local_wisa', 'version');
                try {
                    set_config('version', 2026080502, 'local_wisa');
                    xmldb_local_wisa_upgrade(2026080502);
                } finally {
                    if ($previousversion === false) {
                        unset_config('version', 'local_wisa');
                    } else {
                        set_config('version', $previousversion, 'local_wisa');
                    }
                }
            }
        } finally {
            unset($CFG->upgraderunning);
            unset_config('upgraderunning');
        }
    }
    /**
     * Create a provision record with the requested status.
     *
     * @param string $status Provisioning status.
     * @param string $courseidnumber Course ID number.
     * @param string $sourcecomponent Source component.
     * @param int|null $executionuserid Executing user ID.
     * @return \stdClass Provision record.
     */
    protected function create_provision_record(
        string $status,
        string $courseidnumber = 'WISA-COURSE-001',
        string $sourcecomponent = 'sissource_wisa',
        ?int $executionuserid = null
    ): \stdClass {
        global $USER;

        self::setAdminUser();
        $repository = new provisioning_repository();
        $record = $repository->create_pending(
            $sourcecomponent,
            $courseidnumber,
            'Provision ' . $courseidnumber,
            'Provisioned ' . $courseidnumber,
            (int)get_config('local_wisa', 'default_category'),
            null,
            null,
            0,
            0,
            $executionuserid ?? (int)$USER->id
        );
        if ($status === provisioning_repository::STATUS_PENDING) {
            return $record;
        }

        $record = $repository->mark_running((int)$record->id);
        if ($status === provisioning_repository::STATUS_RUNNING) {
            return $record;
        }

        return $repository->mark_terminal((int)$record->id, $status);
    }
    /**
     * Assert that a system event was captured.
     *
     * @param array $events Captured events.
     * @param string $classname Event class name.
     * @return \core\event\base Captured event.
     */
    protected function assert_captured_system_event(array $events, string $classname): \core\event\base {
        foreach ($events as $event) {
            if ('\\' . get_class($event) === $classname || get_class($event) === ltrim($classname, '\\')) {
                $this->assertEquals(\context_system::instance(), $event->get_context());
                return $event;
            }
        }

        $this->fail('Expected Moodle event was not captured: ' . $classname);
    }
    /**
     * Assert that an event class was not captured.
     *
     * @param array $events Captured events.
     * @param string $classname Event class name.
     * @return void
     */
    protected function assert_event_not_captured(array $events, string $classname): void {
        foreach ($events as $event) {
            $this->assertNotSame($classname, get_class($event));
        }
    }
    /**
     * Return an event's other data.
     *
     * @param \core\event\base $event Event.
     * @return array Event other data.
     */
    protected function event_other(\core\event\base $event): array {
        $data = $event->get_data();
        return isset($data['other']) && is_array($data['other']) ? $data['other'] : [];
    }
    /**
     * Assert that event other data contains scalar values.
     *
     * @param \core\event\base $event Event.
     * @return void
     */
    protected function assert_event_other_is_scalar(\core\event\base $event): void {
        foreach ($this->event_other($event) as $key => $value) {
            $this->assertTrue(is_scalar($value), 'Event other field must be scalar: ' . $key);
        }
    }
    /**
     * Configure known secret values for event safety tests.
     *
     * @return void
     */
    protected function configure_known_secret_values(): void {
        set_config('api_url', 'https://school.example.test/?Api-Authorization-Key=SECRET-API-KEY', 'sissource_wisa');
        set_config('api_user', 'SECRET-AUTH-USER', 'sissource_wisa');
        set_config('api_pass', 'SECRET-AUTH-CRED', 'sissource_wisa');
        set_config('api_key', 'SECRET-API-KEY', 'sissource_athenasoft');
        set_config('auth_user', 'SECRET-AUTH-USER', 'sissource_athenasoft');
        set_config('auth_cred', 'SECRET-AUTH-CRED', 'sissource_athenasoft');
        set_config('pasword', 'SECRET-PASWORD', 'sissource_athenasoft');
    }
    /**
     * Assert that an event contains no configured secrets.
     *
     * @param \core\event\base $event Event.
     * @return void
     */
    protected function assert_event_has_no_secret_values(\core\event\base $event): void {
        $haystack = [];
        foreach ($this->event_other($event) as $value) {
            if (is_scalar($value)) {
                $haystack[] = (string)$value;
            }
        }
        $haystack[] = $event->get_description();
        $haystack = implode(' ', $haystack);

        foreach ($this->known_secret_values() as $secret) {
            $this->assertStringNotContainsString($secret, $haystack);
        }
    }
    /**
     * Return secret values that events must not expose.
     *
     * @return array Secret values.
     */
    protected function known_secret_values(): array {
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
     * Enable one sync part and disable the others.
     *
     * @param string $part Sync part.
     * @return void
     */
    protected function enable_only_sync_part(string $part): void {
        foreach (['courses', 'students', 'teachers', 'enrolments', 'unenrolments'] as $candidate) {
            set_config('enable_' . $candidate, $candidate === $part ? 1 : 0, 'local_wisa');
        }
    }
}
