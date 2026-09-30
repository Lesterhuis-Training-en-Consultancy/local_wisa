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
 * Persistent provisioning repository facade.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Persistent repository for course template provisioning state.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provisioning_repository {
    use provisioning_state_repository_trait;
    use provisioning_destination_repository_trait;
    use provisioning_retry_repository_trait;
    use provisioning_admin_repository_trait;

    /** Pending provision status. */
    public const STATUS_PENDING = 'pending';

    /** Running provision status. */
    public const STATUS_RUNNING = 'running';

    /** Provision status for a duplicated template. */
    public const STATUS_READY = 'ready';

    /** Provision status for an empty fallback course. */
    public const STATUS_FALLBACK_READY = 'fallback_ready';

    /** Failed provision status. */
    public const STATUS_FAILED = 'failed';

    /** Maximum stored error length. */
    private const MAX_ERROR_LENGTH = 255;

    /** Prefix for provisioning lock names. */
    private const LOCK_PREFIX = 'course_provision_';

    /** Prefix for destination shortname lock names. */
    private const SHORTNAME_LOCK_PREFIX = 'course_shortname_';

    /** Provisioning database table name. */
    private const TABLE = 'local_wisa_course_provision';
}
