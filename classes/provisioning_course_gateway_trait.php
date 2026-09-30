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
 * Provisioning course gateway operations.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

/**
 * Provides provisioning course gateway operations.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait provisioning_course_gateway_trait {
    /**
     * Call the synchronous core duplicate API with the job-owned temporary name.
     *
     * This protected seam keeps restore-failure coverage independent from backup data.
     *
     * @param \stdClass $record Running provision record.
     * @return array Core duplicate-course result.
     */
    protected function duplicate_course(\stdClass $record): array {
        global $CFG;

        require_once($CFG->dirroot . '/course/externallib.php');
        return \core_course_external::duplicate_course(
            (int)$record->templateid,
            $record->desiredfullname,
            $record->tempshortname,
            (int)$record->categoryid
        );
    }

    /**
     * Resolve one configured template key to a positive course ID.
     *
     * @param string|null $templatekey Source template key.
     * @return int|null Mapped positive course ID, or null when unmapped.
     */
    private function resolve_templateid(?string $templatekey): ?int {
        $templatekey = trim((string)$templatekey);
        if ($templatekey === '') {
            return null;
        }

        $configured = get_config('local_wisa', 'templatemap');
        if (!is_string($configured) || trim($configured) === '') {
            return null;
        }
        $map = json_decode($configured);
        if (json_last_error() !== JSON_ERROR_NONE || !is_object($map) || !property_exists($map, $templatekey)) {
            return null;
        }

        $templateid = $map->{$templatekey};
        if (!is_int($templateid) || $templateid <= 0) {
            return null;
        }
        return $templateid;
    }

    /**
     * Create one empty-course fallback for an unmapped configured template key.
     *
     * @param provisioning_repository $repository Provision state repository.
     * @param \stdClass $record Running provision record.
     * @return void
     */
    private function execute_unmapped_fallback(provisioning_repository $repository, \stdClass $record): void {
        $this->require_destination_create_capability($record);
        $this->queue_followup_and_mark($repository, $this->create_fallback($repository, $record));
    }

    /**
     * Duplicate a mapped template and promote its returned temporary course to ready.
     *
     * @param provisioning_repository $repository Provision state repository.
     * @param \stdClass $record Running provision record.
     * @return void
     */
    private function execute_mapped_template(provisioning_repository $repository, \stdClass $record): void {
        $this->require_mapped_template_capabilities($record);
        $this->assert_no_temporary_course($record);
        $record = $repository->mark_temp_pre_call_absent((int)$record->id);
        $result = $this->duplicate_course($record);
        $courseid = isset($result['id']) ? (int)$result['id'] : 0;
        if ($courseid <= 0) {
            throw new \coding_exception('Core course duplication returned no destination course.');
        }

        $course = $this->get_only_temporary_course($record);
        if ((int)$course->id !== $courseid) {
            throw new \coding_exception('Core course duplication returned an unexpected destination course.');
        }
        $this->after_destination_creation($record, $course);
        $record = $this->resolve_available_shortname($repository, $record);
        $finished = $repository->finalize_destination(
            (int)$record->id,
            $courseid,
            provisioning_repository::STATUS_READY,
            function (\stdClass $finalizingrecord): void {
                $this->finalize_course_identity($finalizingrecord, false);
            }
        );
        $this->queue_followup_and_mark($repository, $finished);
    }

    /**
     * Require destination context capability before creating an empty fallback.
     *
     * @param \stdClass $record Running provision record.
     * @return void
     */
    private function require_destination_create_capability(\stdClass $record): void {
        $categorycontext = $this->get_destination_category_context($record);
        require_capability('moodle/course:create', $categorycontext);
    }

    /**
     * Revalidate source and destination capability for synchronous core duplication.
     *
     * @param \stdClass $record Running provision record.
     * @return void
     */
    private function require_mapped_template_capabilities(\stdClass $record): void {
        global $DB;

        $categorycontext = $this->get_destination_category_context($record);
        $template = $DB->get_record('course', ['id' => (int)$record->templateid], '*', MUST_EXIST);
        $templatecontext = \context_course::instance((int)$template->id);
        require_capability('moodle/course:create', $categorycontext);
        require_capability('moodle/restore:restorecourse', $categorycontext);
        require_capability('moodle/course:delete', $categorycontext);
        require_capability('moodle/backup:backupcourse', $templatecontext);
    }

    /**
     * Revalidate the destination and mapped-template permissions before recovery changes a course.
     *
     * @param \stdClass $record Running provision record.
     * @return void
     */
    private function require_recovery_capabilities(\stdClass $record): void {
        if ($record->templateid === null) {
            $this->require_destination_create_capability($record);
            return;
        }
        $this->require_mapped_template_capabilities($record);
    }

    /**
     * Return the existing destination category context for a running provision.
     *
     * @param \stdClass $record Running provision record.
     * @return \context_coursecat Destination category context.
     */
    private function get_destination_category_context(\stdClass $record): \context_coursecat {
        global $DB;

        $category = $DB->get_record('course_categories', ['id' => (int)$record->categoryid], '*', MUST_EXIST);
        return \context_coursecat::instance((int)$category->id);
    }

    /**
     * Ensure no course currently uses this job's temporary shortname.
     *
     * @param \stdClass $record Running provision record.
     * @return void
     */
    private function assert_no_temporary_course(\stdClass $record): void {
        global $DB;

        if ($DB->count_records('course', ['shortname' => $record->tempshortname]) !== 0) {
            throw new \coding_exception('Provisioning temporary shortname is already in use.');
        }
    }

    /**
     * Return the only course using this job's temporary shortname.
     *
     * @param \stdClass $record Running provision record.
     * @return \stdClass Job-owned temporary course.
     */
    private function get_only_temporary_course(\stdClass $record): \stdClass {
        $courses = $this->get_temporary_courses($record);
        if (count($courses) !== 1) {
            throw new \coding_exception('Provisioning temporary course ownership is not unique.');
        }
        return reset($courses);
    }

    /**
     * Return every course using the job-owned temporary shortname.
     *
     * @param \stdClass $record Running provision record.
     * @return \stdClass[] Temporary courses keyed by ID.
     */
    private function get_temporary_courses(\stdClass $record): array {
        global $DB;

        return $DB->get_records('course', ['shortname' => $record->tempshortname]);
    }

    /**
     * Return all courses that could conflict with the desired durable identity.
     *
     * @param \stdClass $record Running provision record.
     * @return \stdClass[] Candidate desired courses keyed by ID.
     */
    private function get_desired_courses(\stdClass $record): array {
        global $DB;

        $courses = $DB->get_records('course', ['shortname' => $record->desiredshortname]);
        foreach ($DB->get_records('course', ['idnumber' => $record->courseidnumber]) as $course) {
            $courses[(int)$course->id] = $course;
        }
        return $courses;
    }

    /**
     * Return the currently pointed destination course, when it still exists.
     *
     * @param \stdClass $record Running provision record.
     * @return \stdClass|null Pointed course, or null when no valid pointer exists.
     */
    private function get_pointer_course(\stdClass $record): ?\stdClass {
        global $DB;

        if ($record->courseid === null || (int)$record->courseid <= 0) {
            return null;
        }
        $course = $DB->get_record('course', ['id' => (int)$record->courseid]);
        return $course === false ? null : $course;
    }

    /**
     * Resolve a collision-safe desired shortname immediately before finalization.
     *
     * @param provisioning_repository $repository Provisioning repository.
     * @param \stdClass $record Running provision record.
     * @return \stdClass Running record with an available desired shortname.
     */
    private function resolve_available_shortname(
        provisioning_repository $repository,
        \stdClass $record
    ): \stdClass {
        global $DB;

        $conflict = $DB->get_record('course', ['shortname' => $record->desiredshortname]);
        if ($conflict === false || ($record->courseid !== null && (int)$conflict->id === (int)$record->courseid)) {
            return $record;
        }
        if ((string)$conflict->idnumber === (string)$record->courseidnumber) {
            throw new \coding_exception(provisioning_diagnostic::CODE_SHORTNAME_COLLISION);
        }

        $suffix = '_' . $record->courseidnumber;
        if (substr($record->desiredshortname, -strlen($suffix)) === $suffix) {
            throw new \coding_exception(provisioning_diagnostic::CODE_SHORTNAME_COLLISION);
        }
        $maxlength = 255 - \core_text::strlen($suffix);
        $fallback = \core_text::substr($record->desiredshortname, 0, $maxlength) . $suffix;
        $this->lock_shortname($fallback);
        $fallbackconflict = $DB->get_record('course', ['shortname' => $fallback]);
        if (
            $fallbackconflict !== false
            && ($record->courseid === null || (int)$fallbackconflict->id !== (int)$record->courseid)
        ) {
            throw new \coding_exception(provisioning_diagnostic::CODE_SHORTNAME_COLLISION);
        }
        return $repository->set_desired_shortname((int)$record->id, $fallback);
    }

    /**
     * Return whether a course exactly has this provision's desired identity.
     *
     * @param \stdClass $course Course to verify.
     * @param \stdClass $record Running provision record.
     * @return bool Whether all desired identity fields match.
     */
    private function has_desired_identity(\stdClass $course, \stdClass $record): bool {
        return $course->shortname === $record->desiredshortname
            && $course->idnumber === $record->courseidnumber
            && $course->fullname === $record->desiredfullname
            && (int)$course->category === (int)$record->categoryid
            && (int)$course->startdate === (int)$record->startdate
            && (int)$course->enddate === (int)$record->enddate;
    }

    /**
     * Update a restored course to the desired source-owned identity.
     *
     * @param \stdClass $course Restored temporary course.
     * @param \stdClass $record Running provision record.
     * @return void
     */
    private function apply_desired_identity(\stdClass $course, \stdClass $record): void {
        global $CFG;

        require_once($CFG->dirroot . '/course/lib.php');
        $course->category = (int)$record->categoryid;
        $course->shortname = $record->desiredshortname;
        $course->fullname = $record->desiredfullname;
        $course->idnumber = $record->courseidnumber;
        $course->startdate = (int)$record->startdate;
        $course->enddate = (int)$record->enddate;
        update_course($course);
    }

    /**
     * Apply, checkpoint and verify a pointed course's final desired identity.
     *
     * @param \stdClass $record Running provision record with its destination pointer.
     * @param bool $logfallback Whether the finalization is an empty fallback.
     * @return void
     */
    private function finalize_course_identity(\stdClass $record, bool $logfallback): void {
        $course = $this->get_pointer_course($record);
        if ($course === null) {
            throw new \coding_exception('Provisioning destination course no longer exists.');
        }
        $this->apply_desired_identity($course, $record);
        $this->after_desired_identity_application($record, $course);
        $verifiedcourse = $this->get_pointer_course($record);
        if (
            $verifiedcourse === null
                || (int)$verifiedcourse->id !== (int)$record->courseid
                || !$this->has_desired_identity($verifiedcourse, $record)
        ) {
            throw new \coding_exception('Provisioning destination identity could not be verified.');
        }
        if ($logfallback) {
            logger::log('provision', 'course', $record->jobid, 'fallback', 'Created empty course fallback.');
        }
    }
}
