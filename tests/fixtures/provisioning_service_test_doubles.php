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
 * Shared controlled seams for provisioning service tests.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

/**
 * Controlled duplicate seam for provisioning service tests.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provisioning_service_test_double extends provisioning_service {
    /** @var callable|null Duplicate implementation supplied by each test. */
    private $duplicatecallback;

    /** @var callable|null Destination creation checkpoint supplied by each test. */
    private $destinationcheckpoint;

    /** @var callable|null Desired identity checkpoint supplied by each test. */
    private $identitycheckpoint;

    /** @var callable|null Temporary deletion checkpoint supplied by each test. */
    private $deletioncheckpoint;

    /** @var callable|null Failure handling override supplied by each test. */
    private $failurehandlercallback;

    /** @var callable|null Follow-up publication checkpoint supplied by each test. */
    private $followuppublicationcheckpoint;

    /**
     * Create a testable provisioning service.
     *
     * @param callable|null $duplicatecallback Duplicate implementation.
     * @return void
     */
    public function __construct(?callable $duplicatecallback = null) {
        $this->duplicatecallback = $duplicatecallback;
    }

    /**
     * Replace the synchronous core duplicate call for bounded worker tests.
     *
     * @param \stdClass $record Running provisioning record.
     * @return array Duplicate-course result.
     */
    protected function duplicate_course(\stdClass $record): array {
        if ($this->duplicatecallback === null) {
            throw new \coding_exception('The provisioning test duplicate callback is required.');
        }
        return call_user_func($this->duplicatecallback, $record);
    }

    /**
     * Set a callback after a destination exists under its temporary identity.
     *
     * @param callable|null $callback Checkpoint callback.
     * @return void
     */
    public function set_destination_checkpoint(?callable $callback): void {
        $this->destinationcheckpoint = $callback;
    }

    /**
     * Set a callback after desired identity application.
     *
     * @param callable|null $callback Checkpoint callback.
     * @return void
     */
    public function set_identity_checkpoint(?callable $callback): void {
        $this->identitycheckpoint = $callback;
    }

    /**
     * Set a callback after a proved temporary course deletion.
     *
     * @param callable|null $callback Checkpoint callback.
     * @return void
     */
    public function set_deletion_checkpoint(?callable $callback): void {
        $this->deletioncheckpoint = $callback;
    }

    /**
     * Set a callback that models process loss before ordinary failure handling.
     *
     * @param callable|null $callback Failure handling callback.
     * @return void
     */
    public function set_failure_handler_callback(?callable $callback): void {
        $this->failurehandlercallback = $callback;
    }

    /**
     * Set a callback after a follow-up task exists and before its row marker is persisted.
     *
     * @param callable|null $callback Follow-up publication callback.
     * @return void
     */
    public function set_followup_publication_checkpoint(?callable $callback): void {
        $this->followuppublicationcheckpoint = $callback;
    }

    /**
     * Run the test checkpoint after destination creation.
     *
     * @param \stdClass $record Running provision record.
     * @param \stdClass $course Temporary destination course.
     * @return void
     */
    protected function after_destination_creation(\stdClass $record, \stdClass $course): void {
        if ($this->destinationcheckpoint !== null) {
            call_user_func($this->destinationcheckpoint, $record, $course);
        }
    }

    /**
     * Run the test checkpoint after desired identity application.
     *
     * @param \stdClass $record Running provision record.
     * @param \stdClass $course Destination course.
     * @return void
     */
    protected function after_desired_identity_application(\stdClass $record, \stdClass $course): void {
        if ($this->identitycheckpoint !== null) {
            call_user_func($this->identitycheckpoint, $record, $course);
        }
    }

    /**
     * Run the test checkpoint after a proved temporary-course deletion.
     *
     * @param \stdClass $record Running provision record.
     * @return void
     */
    protected function after_proven_temporary_deletion(\stdClass $record): void {
        if ($this->deletioncheckpoint !== null) {
            call_user_func($this->deletioncheckpoint, $record);
        }
    }

    /**
     * Run the test checkpoint after a follow-up task exists.
     *
     * @param \stdClass $record Terminal provision record.
     * @return void
     */
    protected function after_followup_task_exists(\stdClass $record): void {
        if ($this->followuppublicationcheckpoint !== null) {
            call_user_func($this->followuppublicationcheckpoint, $record);
        }
    }

    /**
     * Suppress ordinary failure handling only when a test models process loss.
     *
     * @param provisioning_repository $repository Provisioning repository.
     * @param \stdClass $record Running provision record.
     * @param string $jobid Durable job identity.
     * @param \Throwable $exception Provisioning failure.
     * @return void
     */
    protected function handle_provision_failure(
        provisioning_repository $repository,
        \stdClass $record,
        string $jobid,
        \Throwable $exception
    ): void {
        if ($this->failurehandlercallback !== null) {
            call_user_func($this->failurehandlercallback, $repository, $record, $jobid, $exception);
            return;
        }
        parent::handle_provision_failure($repository, $record, $jobid, $exception);
    }
}
