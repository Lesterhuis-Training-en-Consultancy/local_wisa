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
 * Source-stream parent runtime tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/source_stream_runtime_test_source.php');

/**
 * Verifies generic tuple orchestration through the stream adapter boundary.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_stream_runtime_test extends \advanced_testcase {
    /**
     * One enabled tuple produces one batch and tuple-local state.
     *
     * @return void
     */
    public function test_run_full_sync_fetches_enabled_stream_tuples_once_and_persists_tuple_state(): void {
        $this->resetAfterTest();
        set_config('stream_accounts_users_enabled', 1, 'sissource_fixture');
        set_config('wm_students', 1700000000, 'local_wisa');
        $source = new source_stream_runtime_test_source();

        $success = (new sync_manager($source, null, 'sissource_fixture'))->run_full_sync();

        $this->assertTrue($success);
        $this->assertCount(1, $source->requests);
        $this->assertSame('accounts', $source->requests[0][0]['stream']);
        $this->assertSame('users', $source->requests[0][0]['phase']);
        $this->assertNull($source->requests[0][0]['effective_since']);
        $this->assertGreaterThan(0, (int)get_config('sissource_fixture', 'stream_accounts_users_watermark'));
        $this->assertSame(1700000000, (int)get_config('local_wisa', 'wm_students'));
    }

    /**
     * A run with no enabled tuples cannot approve the initial load.
     *
     * @return void
     */
    public function test_run_full_sync_without_enabled_tuples_keeps_initial_load_gate_closed(): void {
        $this->resetAfterTest();
        set_config('stream_accounts_users_enabled', 0, 'sissource_fixture');
        $source = new source_stream_runtime_test_source();

        $this->assertTrue((new sync_manager($source, null, 'sissource_fixture'))->run_full_sync());

        $this->assertNotSame('1', get_config('local_wisa', 'initial_load_done'));
        $this->assertSame([], $source->requests);
    }
}
