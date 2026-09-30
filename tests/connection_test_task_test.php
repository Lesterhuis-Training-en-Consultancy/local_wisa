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
 * Connection-test adhoc task tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa {
    require_once(__DIR__ . '/fixtures/connection_test_task_source.php');

    /**
     * Verifies exact descriptor execution without canonical tuple mutations.
     *
     * @package    local_wisa
     * @category   test
     * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
     * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
     * @covers     \local_wisa\task\connection_test_task
     */
    final class connection_test_task_test extends \advanced_testcase {
        /**
         * Reset the call-counting source fixture.
         *
         * @return void
         */
        protected function setUp(): void {
            parent::setUp();
            \sissource_connectiontestfixture\source::$constructions = 0;
            \sissource_connectiontestfixture\source::$requests = [];
        }

        /**
         * The task must execute exactly the selected health-check request and preserve all tuple state.
         *
         * @return void
         */
        public function test_executes_selected_healthcheck_without_changing_tuple_state(): void {
            $this->resetAfterTest();
            set_config('stream_accounts_users_watermark', 1700000000, 'sissource_connectiontestfixture');
            set_config('stream_accounts_users_status', 'successful', 'sissource_connectiontestfixture');
            set_config('stream_accounts_users_errorcode', '', 'sissource_connectiontestfixture');
            set_config('stream_accounts_users_lastsuccess', 1700000100, 'sissource_connectiontestfixture');
            set_config('stream_accounts_users_lastattempt', 1700000200, 'sissource_connectiontestfixture');
            set_config('stream_accounts_users_rowcount', 7, 'sissource_connectiontestfixture');
            $state = new source_stream_state('sissource_connectiontestfixture');
            $before = $state->get_tuple('accounts', 'users');
            $eventsink = $this->redirectEvents();

            $task = new \local_wisa\task\connection_test_task();
            $task->set_custom_data([
                'component' => 'sissource_connectiontestfixture',
                'stream' => 'accounts',
                'phase' => 'users',
            ]);
            $task->execute();

            $this->assertSame(1, \sissource_connectiontestfixture\source::$constructions);
            $this->assertCount(1, \sissource_connectiontestfixture\source::$requests);
            $this->assertCount(1, \sissource_connectiontestfixture\source::$requests[0]);
            $this->assertSame([
                'stream' => 'accounts',
                'phase' => 'users',
                'transport' => 'query_accounts',
                'watermark' => null,
                'effective_since' => null,
            ], \sissource_connectiontestfixture\source::$requests[0][0]);
            $this->assertSame($before, $state->get_tuple('accounts', 'users'));
            $this->assertSame('success', get_config('local_wisa', 'last_connection_test_status'));
            $this->assertSame('2', get_config('local_wisa', 'last_connection_test_count'));
            $this->assertGreaterThan(0, (int)get_config('local_wisa', 'last_connection_test_time'));
            $this->assertSame('sissource_connectiontestfixture', get_config('local_wisa', 'last_connection_test_source'));
            $this->assertSame('accounts', get_config('local_wisa', 'last_connection_test_stream'));
            $this->assertSame('users', get_config('local_wisa', 'last_connection_test_phase'));
            $this->assertSame('query_accounts', get_config('local_wisa', 'last_connection_test_transport'));

            $events = $eventsink->get_events();
            $this->assertCount(1, $events);
            $this->assertSame('sissource_connectiontestfixture', $events[0]->other['component']);
            $this->assertSame('accounts', $events[0]->other['stream']);
            $this->assertSame('users', $events[0]->other['phase']);
            $this->assertSame('query_accounts', $events[0]->other['transport']);
            $this->assertSame('success', $events[0]->other['status']);
            $this->assertSame(2, $events[0]->other['count']);
        }

        /**
         * A task whose fixed phase no longer matches the descriptor must fail before source construction.
         *
         * @return void
         */
        public function test_rejects_mismatched_phase_before_constructing_source(): void {
            $this->resetAfterTest();
            $task = new \local_wisa\task\connection_test_task();
            $task->set_custom_data([
                'component' => 'sissource_connectiontestfixture',
                'stream' => 'accounts',
                'phase' => 'courses',
            ]);

            try {
                $task->execute();
                $this->fail('Mismatched connection-test task data was accepted.');
            } catch (\coding_exception $exception) {
                $this->assertSame(0, \sissource_connectiontestfixture\source::$constructions);
                $this->assertSame([], \sissource_connectiontestfixture\source::$requests);
                $this->assertSame('failed', get_config('local_wisa', 'last_connection_test_status'));
            }
        }
    }
}
