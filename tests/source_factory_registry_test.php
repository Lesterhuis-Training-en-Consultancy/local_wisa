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
 * Source-free source-stream registry discovery tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa {
    require_once(__DIR__ . '/fixtures/source_factory_registry_source.php');
    require_once(__DIR__ . '/fixtures/invalid_sis_source/classes/source.php');

    /**
     * Verifies static registry discovery without source construction.
     *
     * @package    local_wisa
     * @category   test
     * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
     * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
     */
    final class source_factory_registry_test extends \advanced_testcase {
        /**
         * Discovery validates an adapter registry without constructing its source.
         *
         * @return void
         */
        public function test_get_registry_for_component_is_source_free(): void {
            \sissource_fixture\source::$constructions = 0;

            $registry = source_factory::get_registry_for_component('sissource_fixture');

            $this->assertArrayHasKey('accounts', $registry);
            $this->assertSame(0, \sissource_fixture\source::$constructions);
        }

        /**
         * An installed source with an invalid static registry is never selectable.
         *
         * @runInSeparateProcess
         * @return void
         */
        public function test_invalid_static_registry_is_excluded_from_deterministic_source_free_options(): void {
            global $CFG;

            $this->resetAfterTest(true);
            $this->add_mocked_plugin(
                'sissource',
                'invalid_registry',
                $CFG->dirroot . '/local/wisa/tests/fixtures/invalid_sis_source'
            );
            \sissource_invalid_registry\source::reset();

            $firstoptions = source_factory::get_source_options();
            $secondoptions = source_factory::get_source_options();

            $this->assertSame($firstoptions, $secondoptions);
            $this->assertArrayNotHasKey('invalid_registry', $firstoptions);
            $this->assertSame(2, \sissource_invalid_registry\source::$registrycalls);
            $this->assertSame(0, \sissource_invalid_registry\source::$constructions);
            $this->assertSame(0, \sissource_invalid_registry\source::$transportcalls);
        }
    }
}
