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
 * Privacy provider tests for sissource_wisa.
 *
 * @package    sissource_wisa
 * @category   test
 * @copyright  2026 Sebsoft
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_wisa\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\external_location;
use core_privacy\tests\provider_testcase;

/**
 * Tests WISA source privacy metadata.
 *
 * @group sissource_wisa
 * @group local_wisa
 * @covers \sissource_wisa\privacy\provider
 */
final class provider_test extends provider_testcase {
    /**
     * Metadata describes the external WISA location.
     */
    public function test_metadata_describes_wisa_api(): void {
        $collection = provider::get_metadata(new collection('sissource_wisa'));
        $items = $collection->get_collection();

        $externals = array_filter($items, static fn($item) => $item instanceof external_location);
        $external = reset($externals);

        $this->assertInstanceOf(external_location::class, $external);
        $this->assertSame('wisa_api', $external->get_name());
        $this->assertArrayHasKey('username', $external->get_privacy_fields());
        $this->assertArrayHasKey('email', $external->get_privacy_fields());
    }
}
