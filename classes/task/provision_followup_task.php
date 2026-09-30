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
 * Runs a source-pinned delta sync after successful course provisioning.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\task;

/**
 * Runs a provision follow-up against the source persisted on its terminal row.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provision_followup_task extends \core\task\adhoc_task {
    /**
     * Return the localized adhoc task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_provision_followup', 'local_wisa');
    }

    /**
     * Revalidate the durable identity and synchronise its exact persisted source.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        $provisionid = is_object($data) && isset($data->provisionid) ? (int)$data->provisionid : 0;
        $jobid = is_object($data) && isset($data->jobid) ? (string)$data->jobid : '';
        $taskuserid = (int)$this->get_userid();
        if ($provisionid <= 0 || $jobid === '' || $taskuserid <= 0) {
            return;
        }

        $repository = new \local_wisa\provisioning_repository();
        try {
            $record = $repository->get($provisionid);
        } catch (\dml_missing_record_exception $exception) {
            return;
        }
        $lock = \local_wisa\provisioning_repository::get_source_lock(
            $record->sourcecomponent,
            $record->courseidnumber
        );
        try {
            $record = $repository->get($provisionid);
            if (
                !hash_equals($record->jobid, $jobid)
                    || (int)$record->executionuserid !== $taskuserid
                    || !in_array($record->status, [
                        \local_wisa\provisioning_repository::STATUS_READY,
                        \local_wisa\provisioning_repository::STATUS_FALLBACK_READY,
                    ], true)
                    || $record->courseid === null
                    || (int)$record->courseid <= 0
                    || !preg_match('/^sissource_[a-z][a-z0-9_]*$/', $record->sourcecomponent)
            ) {
                return;
            }
            $course = $DB->get_record('course', ['id' => (int)$record->courseid]);
            if (
                $course === false
                    || $course->shortname !== $record->desiredshortname
                    || $course->idnumber !== $record->courseidnumber
            ) {
                return;
            }
            $user = \core_user::get_user((int)$record->executionuserid, '*', MUST_EXIST);
            \core_user::require_active_user($user, true, true);
            if ((int)$record->followupqueued === 0) {
                $repository->mark_followup_queued((int)$record->id);
            } else if ((int)$record->followupqueued !== 1) {
                return;
            }
        } finally {
            $lock->release();
        }

        $this->before_followup_sync($record);
        $source = \local_wisa\source_factory::get_source_for_component($record->sourcecomponent);
        if (!(new \local_wisa\sync_manager($source, null, $record->sourcecomponent))->run_full_sync(false)) {
            throw new \coding_exception('Provision follow-up synchronisation did not complete.');
        }
    }

    /**
     * Checkpoint after source and provision locks are released and before delta work begins.
     *
     * @param \stdClass $record Validated terminal provision record.
     * @return void
     */
    protected function before_followup_sync(\stdClass $record): void {
    }
}
