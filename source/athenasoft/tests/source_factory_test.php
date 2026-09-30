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
 * AthenaSoft source factory tests.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace sissource_athenasoft;
defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/fixtures/source_test_case.php');
/**
 * Tests AthenaSoft source selection.
 *
 * @package    sissource_athenasoft
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_factory_test extends source_test_case {
    public function test_factory_returns_athenasoft_adapter_when_selected(): void {
        $this->resetAfterTest();
        set_config('active_source', 'athenasoft', 'local_wisa');
        $this->assertInstanceOf(
            \sissource_athenasoft\source::class,
            \local_wisa\source_factory::get_active_source()
        );
    }

    public function test_factory_default_remains_wisa(): void {
        $this->resetAfterTest();
        unset_config('active_source', 'local_wisa');
        $this->assertInstanceOf(\sissource_wisa\source::class, \local_wisa\source_factory::get_active_source());
    }
}
