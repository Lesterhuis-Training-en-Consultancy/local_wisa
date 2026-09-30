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
 * Tests Moodle source-stream migration setting persistence.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Verifies Moodle-backed migration setting transactions.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class moodle_source_stream_migration_setting_store_test extends \advanced_testcase {
    /**
     * Transactional reads do not retain rolled-back values and committed values remain visible.
     *
     * @return void
     */
    public function test_transaction_reads_do_not_cache_rolled_back_values_and_commits_remain_visible(): void {
        // The store needs a real top-level rollback, not a delegated rollback inside PHPUnit's transaction.
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $component = 'local_wisa';
        $setting = 'source_stream_migration_setting_store_test';
        set_config($setting, 'initial', $component);
        $store = new moodle_source_stream_migration_setting_store();

        $store->begin();
        $this->assertTrue($store->set($component, $setting, 'rolled-back'));
        $this->assertSame('rolled-back', $store->get($component, $setting));
        $store->rollback();
        $this->assertSame('initial', $store->get($component, $setting));

        $store->begin();
        $this->assertTrue($store->set($component, $setting, 'committed'));
        $this->assertSame('committed', $store->get($component, $setting));
        $this->assertTrue($store->commit());
        $this->assertSame('committed', get_config($component, $setting));
    }
}
