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
 * Course provisioning decisions and durable queue handling.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\sync;

use local_wisa\logger;
use local_wisa\provisioning_repository;
use local_wisa\provisioning_service;

/**
 * Provides course provisioning behavior for course synchronisation.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait course_provisioning_trait {
    /**
     * Return whether this row must be provisioned asynchronously.
     *
     * @param string $templatekey Trimmed source template key.
     * @return bool Whether durable provisioning is enabled for the row.
     */
    private function should_queue_provisioning(string $templatekey): bool {
        return get_config('local_wisa', 'enable_course_provisioning') === '1' && $templatekey !== '';
    }

    /**
     * Queue one valid new course for durable template provisioning.
     *
     * @param \stdClass $newcourse Resolved destination course values.
     * @param string $templatekey Trimmed source template key.
     * @return void
     */
    private function queue_course_provision(\stdClass $newcourse, string $templatekey): void {
        global $USER;

        $repository = new provisioning_repository();
        $existingprovision = $repository->get_by_source_course($this->sourcecomponent, $newcourse->idnumber);
        if ($existingprovision !== null) {
            $this->handle_existing_provision($existingprovision, $newcourse->idnumber);
            return;
        }

        if ($this->dryrun) {
            logger::log(
                'sync_course',
                'course',
                $newcourse->idnumber,
                'dryrun',
                "Would queue course provisioning for $newcourse->fullname."
            );
            $this->stats->coursecreate++;
            return;
        }

        $executionuserid = isset($USER->id) ? (int)$USER->id : 0;
        if ($executionuserid <= 0) {
            $this->blockingprovisions = true;
            logger::log(
                'sync_course',
                'course',
                $newcourse->idnumber,
                'fail',
                'Course provisioning requires a current execution user; skipped.'
            );
            $this->stats->coursefail++;
            return;
        }

        try {
            $provision = (new provisioning_service())->queue(
                $this->sourcecomponent,
                $newcourse->idnumber,
                $newcourse->shortname,
                $newcourse->fullname,
                (int)$newcourse->category,
                $templatekey,
                (int)$newcourse->startdate,
                (int)$newcourse->enddate,
                $executionuserid
            );
            if ($provision->status !== provisioning_repository::STATUS_PENDING) {
                $this->handle_existing_provision($provision, $newcourse->idnumber);
                return;
            }

            $this->blockingprovisions = true;
            logger::log(
                'sync_course',
                'course',
                $newcourse->idnumber,
                'queue',
                "Queued course provisioning for $newcourse->fullname."
            );
            $this->stats->coursecreate++;
        } catch (\Throwable $e) {
            $existingprovision = $repository->get_by_source_course($this->sourcecomponent, $newcourse->idnumber);
            if ($existingprovision !== null) {
                $this->handle_existing_provision($existingprovision, $newcourse->idnumber);
                return;
            }

            logger::log(
                'sync_course',
                'course',
                $newcourse->idnumber,
                'fail',
                'Failed to queue course provisioning.'
            );
            $this->blockingprovisions = true;
            $this->stats->coursefail++;
        }
    }

    /**
     * Record a previously persisted provision without creating another course or task.
     *
     * @param \stdClass $provision Existing durable provision record.
     * @param string $idnumber Source course identity.
     * @param \stdClass|null $existingcourse Existing course matched by source identity.
     * @return void
     */
    private function handle_existing_provision(
        \stdClass $provision,
        string $idnumber,
        ?\stdClass $existingcourse = null
    ): void {
        global $DB;

        if (
            in_array($provision->status, [
            provisioning_repository::STATUS_PENDING,
            provisioning_repository::STATUS_RUNNING,
            ], true)
        ) {
            $this->blockingprovisions = true;
            logger::log(
                'sync_course',
                'course',
                $idnumber,
                'skip',
                'Course provisioning is already active; skipped synchronous creation.'
            );
            $this->stats->courseskip++;
            return;
        }

        if ($provision->status === provisioning_repository::STATUS_FAILED) {
            $this->blockingprovisions = true;
            logger::log(
                'sync_course',
                'course',
                $idnumber,
                'fail',
                'Course provisioning previously failed; skipped synchronous creation.'
            );
            $this->stats->coursefail++;
            return;
        }

        if (
            in_array($provision->status, [
            provisioning_repository::STATUS_READY,
            provisioning_repository::STATUS_FALLBACK_READY,
            ], true)
        ) {
            $destinationexists = $provision->courseid !== null
                && $DB->record_exists('course', ['id' => (int)$provision->courseid]);
            if (!$destinationexists) {
                $this->blockingprovisions = true;
                logger::log(
                    'sync_course',
                    'course',
                    $idnumber,
                    'fail',
                    'Course provisioning destination is missing; skipped synchronous creation.'
                );
                $this->stats->coursefail++;
                return;
            }

            if ($existingcourse !== null && (int)$provision->courseid !== (int)$existingcourse->id) {
                $this->blockingprovisions = true;
                logger::log(
                    'sync_course',
                    'course',
                    $idnumber,
                    'fail',
                    'Course provisioning destination does not match the source course; skipped synchronous update.'
                );
                $this->stats->coursefail++;
                return;
            }

            logger::log(
                'sync_course',
                'course',
                $idnumber,
                'skip',
                'Course provisioning destination already exists; skipped synchronous creation.'
            );
            $this->stats->courseskip++;
            return;
        }

        logger::log(
            'sync_course',
            'course',
            $idnumber,
            'fail',
            'Course provisioning state is invalid; skipped synchronous creation.'
        );
        $this->stats->coursefail++;
    }
}
