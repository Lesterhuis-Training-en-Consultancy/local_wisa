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
 * Sync manager preview contract test coverage.
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
require_once(__DIR__ . '/fixtures/preview_task_source.php');

use local_wisa\tests\fake_api_client;

/**
 * Sync manager preview contract tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sync_manager_preview_test extends sync_manager_test_case {
    /**
     * Preview exposes source-free validated registry metadata.
     *
     * @return void
     */
    public function test_preview_returns_source_component_and_stream_registry_without_fetching(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        $api = new fake_api_client();

        $preview = (new sync_manager($api))->preview();

        $this->assertSame('sissource_wisa', $preview['sourcecomponent']);
        $this->assertSame(fake_api_client::get_stream_registry(), $preview['streams']);
        $this->assertSame([], $api->calls);
    }

    /**
     * Queued previews fetch enabled tuples once without changing tuple state.
     *
     * @return void
     */
    public function test_preview_task_fetches_enabled_tuples_and_persists_safe_counts_without_tuple_writes(): void {
        $this->resetAfterTest();
        $this->configure_wisa_defaults();
        \sissource_previewtaskfixture\source::$requests = [];
        set_config('active_source', 'previewtaskfixture', 'local_wisa');
        set_config('schoolyear_scope', 'current', 'local_wisa');
        foreach (\sissource_previewtaskfixture\source::get_stream_registry() as $descriptor) {
            set_config(
                'stream_' . $descriptor['key'] . '_' . $descriptor['phases'][0] . '_enabled',
                1,
                'sissource_previewtaskfixture'
            );
        }
        set_config('stream_accounts_users_watermark', 1700000000, 'sissource_previewtaskfixture');
        set_config('stream_accounts_users_status', 'successful', 'sissource_previewtaskfixture');
        $state = new source_stream_state('sissource_previewtaskfixture');
        $before = $state->get_tuple('accounts', 'users');

        (new \local_wisa\task\preview_task())->execute();

        $this->assertCount(1, \sissource_previewtaskfixture\source::$requests);
        $this->assertSame([
            'courses:courses',
            'accounts:users',
            'enrolments:enrolments',
            'unenrolments:unenrolments',
        ], array_map(static function (array $request): string {
            return $request['stream'] . ':' . $request['phase'];
        }, \sissource_previewtaskfixture\source::$requests[0]));
        foreach (\sissource_previewtaskfixture\source::$requests[0] as $request) {
            $this->assertNull($request['effective_since']);
        }
        $this->assertSame(1700000000, \sissource_previewtaskfixture\source::$requests[0][1]['watermark']);
        $this->assertSame([
            'courses' => 2,
            'users' => 3,
            'enrolments' => 4,
            'unenrolments' => 5,
        ], json_decode((string)get_config('local_wisa', 'last_preview_counts'), true));
        $this->assertSame('success', get_config('local_wisa', 'last_preview_status'));
        $this->assertSame(schoolyear_window::label(), get_config('local_wisa', 'last_preview_window'));
        $this->assertSame($before, $state->get_tuple('accounts', 'users'));
    }
}
