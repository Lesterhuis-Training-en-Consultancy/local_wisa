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
 * Version metadata tests for local_wisa and its source plugins.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests version metadata without executing version.php files.
 *
 * @package    local_wisa
 * @category   test
 * @group      local_wisa
 * @coversNothing
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class version_test extends \advanced_testcase {
    /**
     * Current metadata must exceed every completed upgrade savepoint.
     *
     * @return void
     */
    public function test_versions_exceed_savepoints_and_dependencies_align(): void {
        $paths = [
            'parent' => __DIR__ . '/../version.php',
            'wisa' => __DIR__ . '/../source/wisa/version.php',
            'athenasoft' => __DIR__ . '/../source/athenasoft/version.php',
        ];
        $versions = [];
        $dependencies = [];

        foreach ($paths as $name => $path) {
            $source = file_get_contents($path);
            preg_match('/\$plugin->version\s*=\s*(\d+)/', $source, $versionmatch);
            $versions[$name] = (int)$versionmatch[1];
            preg_match("/'local_wisa'\s*=>\s*(\d+)/", $source, $dependencymatch);
            $dependencies[$name] = isset($dependencymatch[1]) ? (int)$dependencymatch[1] : null;
        }

        $this->assertGreaterThan(2026082800, $versions['parent']);
        $this->assertGreaterThan(2026080700, $versions['wisa']);
        $this->assertGreaterThan(2026080702, $versions['athenasoft']);
        $this->assertLessThanOrEqual($versions['parent'], $versions['wisa']);
        $this->assertLessThanOrEqual($versions['parent'], $versions['athenasoft']);
        $this->assertLessThanOrEqual($versions['parent'], $dependencies['wisa']);
        $this->assertLessThanOrEqual($versions['parent'], $dependencies['athenasoft']);
    }
}
