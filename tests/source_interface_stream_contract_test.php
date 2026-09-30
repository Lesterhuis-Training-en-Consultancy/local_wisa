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
 * Source-stream adapter interface contract tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Verifies that the generic adapter contract exposes streams only.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_interface_stream_contract_test extends \advanced_testcase {
    /**
     * The fixed generic feed methods are not an alternate runtime contract.
     *
     * @return void
     */
    public function test_interface_declares_only_static_registry_and_batched_fetch_methods(): void {
        $reflection = new \ReflectionClass(source_interface::class);
        $methods = $reflection->getMethods();
        $names = array_map(function (\ReflectionMethod $method): string {
            return $method->getName();
        }, $methods);
        sort($names);

        $this->assertSame(['fetch_streams', 'get_stream_registry'], $names);
        $this->assertTrue($reflection->getMethod('get_stream_registry')->isStatic());
        $this->assertFalse($reflection->getMethod('fetch_streams')->isStatic());
    }
}
