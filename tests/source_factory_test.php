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
 * Source factory tests for local_wisa.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/invalid_sis_source/classes/source.php');
require_once(__DIR__ . '/fixtures/source_factory_test_double.php');

/**
 * Source factory tests for local_wisa.
 *
 * @group local_wisa
 * @covers     \local_wisa\source_factory
 */
final class source_factory_test extends \advanced_testcase {
    public function test_default_source_is_wisa(): void {
        $this->resetAfterTest();
        unset_config('active_source', 'local_wisa');

        $source = source_factory::get_active_source();

        $this->assertInstanceOf(source_interface::class, $source);
        $this->assertInstanceOf(\sissource_wisa\source::class, $source);
    }

    public function test_missing_configured_source_falls_back_to_wisa(): void {
        $this->resetAfterTest();
        set_config('active_source', 'missing_source', 'local_wisa');

        $source = source_factory::get_active_source();

        $this->assertInstanceOf(\sissource_wisa\source::class, $source);
    }

    /**
     * An installed source with an invalid static registry fails closed before adapter runtime access.
     *
     * @runInSeparateProcess
     * @return void
     */
    public function test_invalid_configured_source_fails_closed_without_secret_or_runtime_access(): void {
        global $CFG;

        $this->resetAfterTest(true);
        $this->add_mocked_plugin(
            'sissource',
            'invalid_registry',
            $CFG->dirroot . '/local/wisa/tests/fixtures/invalid_sis_source'
        );
        \sissource_invalid_registry\source::reset();
        set_config('active_source', 'invalid_registry', 'local_wisa');

        foreach (['get_active_component', 'get_active_source'] as $method) {
            try {
                source_factory::$method();
                $this->fail('An installed source with an invalid static registry must not fall back to WISA.');
            } catch (\coding_exception $exception) {
                $this->assertStringContainsString(
                    'No usable SIS source adapter is available for local_wisa.',
                    $exception->getMessage()
                );
                $this->assertStringNotContainsString('SECRET-INVALID-REGISTRY-TRANSPORT', $exception->getMessage());
            }
        }

        $this->assertSame(2, \sissource_invalid_registry\source::$registrycalls);
        $this->assertSame(0, \sissource_invalid_registry\source::$constructions);
        $this->assertSame(0, \sissource_invalid_registry\source::$transportcalls);
    }

    /**
     * Strict component resolution must ignore the configured active source.
     *
     * @return void
     */
    public function test_get_source_for_component_returns_requested_adapter(): void {
        $this->resetAfterTest();
        set_config('active_source', 'wisa', 'local_wisa');

        $source = source_factory::get_source_for_component('sissource_athenasoft');

        $this->assertInstanceOf(source_interface::class, $source);
        $this->assertInstanceOf(\sissource_athenasoft\source::class, $source);
    }

    /**
     * Strict component resolution must reject invalid Frankenstyle names.
     *
     * @dataProvider invalid_source_component_provider
     * @param string $component Invalid source component.
     * @return void
     */
    public function test_get_source_for_component_rejects_invalid_component(string $component): void {
        $this->resetAfterTest();
        set_config('active_source', 'wisa', 'local_wisa');

        $this->expectException(\coding_exception::class);
        source_factory::get_source_for_component($component);
    }

    /**
     * Strict component resolution must reject unavailable adapters instead of falling back.
     *
     * @return void
     */
    public function test_get_source_for_component_rejects_unavailable_component(): void {
        $this->resetAfterTest();
        set_config('active_source', 'wisa', 'local_wisa');

        $this->expectException(\coding_exception::class);
        source_factory::get_source_for_component('sissource_missing_source');
    }

    /**
     * Resolve the Frankenstyle component from the WISA adapter namespace.
     *
     * @return void
     */
    public function test_get_component_for_source_returns_wisa_component(): void {
        $this->resetAfterTest();

        $source = new \sissource_wisa\source();

        $this->assertSame('sissource_wisa', source_factory::get_component_for_source($source));
    }

    /**
     * Resolve the Frankenstyle component from the AthenaSoft adapter namespace.
     *
     * @return void
     */
    public function test_get_component_for_source_returns_athenasoft_component(): void {
        $this->resetAfterTest();

        $source = new \sissource_athenasoft\source();

        $this->assertSame('sissource_athenasoft', source_factory::get_component_for_source($source));
    }

    /**
     * Use the configured source for test doubles outside a source namespace.
     *
     * @return void
     */
    public function test_get_component_for_source_falls_back_to_configured_source(): void {
        $this->resetAfterTest();
        set_config('active_source', 'AthenaSoft', 'local_wisa');

        $source = new source_factory_test_double();

        $this->assertSame('sissource_athenasoft', source_factory::get_component_for_source($source));
    }

    /**
     * Provide invalid full source components.
     *
     * @return array
     */
    public static function invalid_source_component_provider(): array {
        return [
            'short name only' => ['athenasoft'],
            'uppercase character' => ['sissource_AthenaSoft'],
            'invalid separator' => ['sissource_athenasoft-invalid'],
        ];
    }
}
