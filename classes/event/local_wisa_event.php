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
 * Base Moodle event for local_wisa high-level audit logging.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\event;

/**
 * Base Moodle event for local_wisa high-level audit logging.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class local_wisa_event extends \core\event\base {
    /** @var string CRUD operation type. */
    protected const CRUD = 'r';

    /**
     * Initialise event metadata.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = static::CRUD;
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Return localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_' . static::event_key(), 'local_wisa');
    }

    /**
     * Return non-localised event description.
     *
     * @return string
     */
    public function get_description(): string {
        $other = $this->data['other'] ?? [];
        $source = isset($other['source']) ? (string)$other['source'] : 'unknown';
        return "The user with id '{$this->userid}' triggered local_wisa event '" . static::event_key()
            . "' for SIS source '{$source}'.";
    }

    /**
     * Return event class key used by language strings.
     *
     * @return string
     */
    protected static function event_key(): string {
        $parts = explode('\\', static::class);
        return end($parts);
    }
}
