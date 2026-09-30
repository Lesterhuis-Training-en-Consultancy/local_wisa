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
 * Manual sync adhoc task outcome tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa {
    defined('MOODLE_INTERNAL') || die();

    global $CFG;

    require_once(__DIR__ . '/../db/upgrade.php');
    require_once($CFG->libdir . '/upgradelib.php');
    require_once(__DIR__ . '/fixtures/sync_testcase.php');
    require_once(__DIR__ . '/fixtures/sync_manager_test_case.php');
    require_once(__DIR__ . '/fixtures/manual_sync_task_source.php');

    /**
     * Verifies manual task completion reflects aggregate sync outcomes.
     *
     * @package    local_wisa
     * @category   test
     * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
     * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
     * @covers     \local_wisa\task\manual_sync_task
     */
    final class manual_sync_task_test extends sync_manager_test_case {
        /**
         * Reset the fixture source outcome controls.
         *
         * @return void
         */
        protected function setUp(): void {
            parent::setUp();
            \sissource_manualsyncfixture\source::$outcomes = [];
            \sissource_manualsyncfixture\source::$throwexception = false;
        }

        /**
         * Verify mixed sync outcomes persist and emit partial manual status.
         *
         * @return void
         */
        public function test_mixed_run_persists_and_emits_partial_manual_status(): void {
            $this->resetAfterTest();
            $this->configure_fixture_source(['students', 'teachers']);
            \sissource_manualsyncfixture\source::$outcomes = ['teachers' => 'failed'];
            $sink = $this->redirectEvents();

            (new \local_wisa\task\manual_sync_task())->execute();

            $this->assertSame('partial', get_config('local_wisa', 'last_manual_sync_status'));
            $this->assertSame('partial', $this->manual_event_status($sink->get_events()));
        }

        /**
         * Verify an all-failed run persists and emits failed manual status.
         *
         * @return void
         */
        public function test_all_failed_run_persists_and_emits_failed_manual_status(): void {
            $this->resetAfterTest();
            $this->configure_fixture_source(['students']);
            \sissource_manualsyncfixture\source::$outcomes = ['students' => 'failed'];
            $sink = $this->redirectEvents();

            (new \local_wisa\task\manual_sync_task())->execute();

            $this->assertSame('failed', get_config('local_wisa', 'last_manual_sync_status'));
            $this->assertSame('failed', $this->manual_event_status($sink->get_events()));
        }

        /**
         * Verify unexpected exceptions persist failed status and are rethrown.
         *
         * @return void
         */
        public function test_unexpected_exception_persists_failed_manual_status_and_rethrows(): void {
            $this->resetAfterTest();
            $this->configure_fixture_source(['students']);
            \sissource_manualsyncfixture\source::$throwexception = true;
            $sink = $this->redirectEvents();

            try {
                (new \local_wisa\task\manual_sync_task())->execute();
                $this->fail('Unexpected manual sync exception was not rethrown.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Unexpected manual sync fixture failure.', $exception->getMessage());
            }

            $this->assertSame('failed', get_config('local_wisa', 'last_manual_sync_status'));
            $this->assertSame('failed', $this->manual_event_status($sink->get_events()));
        }

        /**
         * Configure enabled fixture tuples for a manual sync task run.
         *
         * @param array $enabled Enabled source stream keys.
         * @return void
         */
        private function configure_fixture_source(array $enabled): void {
            $this->configure_wisa_defaults();
            set_config('active_source', 'manualsyncfixture', 'local_wisa');
            foreach (\sissource_manualsyncfixture\source::get_stream_registry() as $descriptor) {
                set_config(
                    'stream_' . $descriptor['key'] . '_users_enabled',
                    in_array($descriptor['key'], $enabled, true) ? 1 : 0,
                    'sissource_manualsyncfixture'
                );
            }
        }

        /**
         * Return the emitted manual sync event status.
         *
         * @param array $events Captured Moodle events.
         * @return string
         */
        private function manual_event_status(array $events): string {
            foreach ($events as $event) {
                if ($event instanceof \local_wisa\event\admin_manual_sync_run) {
                    return $event->other['status'];
                }
            }
            $this->fail('Manual sync event was not captured.');
            return '';
        }
    }
}
