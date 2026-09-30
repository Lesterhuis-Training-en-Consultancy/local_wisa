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
 * Provisioning shortname collision test coverage.
 *
 * @package    local_wisa
 * @category   test
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/provisioning_service_test_case.php');

/**
 * Verifies source-locked provisioning shortname collision protection.
 *
 * @package    local_wisa
 * @copyright  2026 Sebsoft.nl <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @group      local_wisa
 * @covers     \local_wisa\provisioning_service
 */
final class provisioning_shortname_collision_test extends provisioning_service_test_case {
    /**
     * A collision at fallback creation uses the deterministic unique shortname.
     *
     * @return void
     */
    public function test_actual_creation_collision_uses_unique_shortname_fallback_without_mutating_unrelated_course(): void {
        global $DB;

        // Given.
        $this->resetAfterTest();
        $context = $this->create_context();
        $record = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            null,
            'C6-CREATION-COLLISION'
        );
        $unrelated = create_course((object)[
            'category' => (int)$context['category']->id,
            'fullname' => 'Unrelated creation collision',
            'shortname' => $record->desiredshortname,
            'idnumber' => 'UNRELATED-CREATION',
        ]);

        // When.
        (new provisioning_service())->execute_provision((int)$record->id, $record->jobid, $context['userid']);
        $finished = (new provisioning_repository())->get((int)$record->id);

        // Then.
        $this->assertSame(provisioning_repository::STATUS_FALLBACK_READY, $finished->status);
        $destination = $DB->get_record('course', ['id' => (int)$finished->courseid], '*', MUST_EXIST);
        $unchanged = $DB->get_record('course', ['id' => (int)$unrelated->id], '*', MUST_EXIST);
        $this->assertSame($record->desiredshortname . '_' . $record->courseidnumber, $destination->shortname);
        $this->assertSame($record->desiredshortname, $unchanged->shortname);
        $this->assertSame('UNRELATED-CREATION', $unchanged->idnumber);
    }

    /**
     * A collision introduced after restore but before final rename uses the same fallback.
     *
     * @return void
     */
    public function test_final_rename_collision_uses_unique_shortname_fallback_without_mutating_unrelated_course(): void {
        global $DB;

        // Given.
        $this->resetAfterTest();
        $context = $this->create_context();
        set_config('templatemap', json_encode(['template-one' => (int)$context['template']->id]), 'local_wisa');
        $record = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            'template-one',
            'C6-RENAME-COLLISION'
        );
        $service = new provisioning_service_test_double(function (\stdClass $running): array {
            $course = create_course((object)[
                'category' => (int)$running->categoryid,
                'fullname' => 'Temporary restored course',
                'shortname' => $running->tempshortname,
            ]);
            return ['id' => (int)$course->id];
        });
        $service->set_destination_checkpoint(function (\stdClass $running): void {
            create_course((object)[
                'category' => (int)$running->categoryid,
                'fullname' => 'Unrelated rename collision',
                'shortname' => $running->desiredshortname,
                'idnumber' => 'UNRELATED-RENAME',
            ]);
        });

        // When.
        $service->execute_provision((int)$record->id, $record->jobid, $context['userid']);
        $finished = (new provisioning_repository())->get((int)$record->id);

        // Then.
        $this->assertSame(provisioning_repository::STATUS_READY, $finished->status);
        $destination = $DB->get_record('course', ['id' => (int)$finished->courseid], '*', MUST_EXIST);
        $unrelated = $DB->get_record('course', ['idnumber' => 'UNRELATED-RENAME'], '*', MUST_EXIST);
        $this->assertSame($record->desiredshortname . '_' . $record->courseidnumber, $destination->shortname);
        $this->assertSame($record->desiredshortname, $unrelated->shortname);
        $this->assertSame('UNRELATED-RENAME', $unrelated->idnumber);
    }

    /**
     * An unrelated collision before mapped duplication selects the fallback before creating a destination.
     *
     * @return void
     */
    public function test_mapped_pre_execution_collision_uses_fallback_before_duplicate(): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        set_config('templatemap', json_encode(['template-one' => (int)$context['template']->id]), 'local_wisa');
        $record = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            'template-one',
            'C6-MAPPED-PRE-COLLISION'
        );
        $unrelated = create_course((object)[
            'category' => (int)$context['category']->id,
            'fullname' => 'Unrelated mapped collision',
            'shortname' => $record->desiredshortname,
            'idnumber' => 'UNRELATED-MAPPED-PRE',
        ]);
        $service = new provisioning_service_test_double(function (\stdClass $running): array {
            $course = create_course((object)[
                'category' => (int)$running->categoryid,
                'fullname' => 'Temporary mapped destination',
                'shortname' => $running->tempshortname,
            ]);
            return ['id' => (int)$course->id];
        });

        $service->execute_provision((int)$record->id, $record->jobid, $context['userid']);

        $finished = (new provisioning_repository())->get((int)$record->id);
        $destination = $DB->get_record('course', ['id' => (int)$finished->courseid], '*', MUST_EXIST);
        $unchanged = $DB->get_record('course', ['id' => (int)$unrelated->id], '*', MUST_EXIST);
        $this->assertSame(provisioning_repository::STATUS_READY, $finished->status);
        $this->assertSame($record->desiredshortname . '_' . $record->courseidnumber, $destination->shortname);
        $this->assertSame($record->desiredshortname, $unchanged->shortname);
        $this->assertSame('UNRELATED-MAPPED-PRE', $unchanged->idnumber);
    }

    /**
     * Occupied desired and fallback shortnames leave the provision failed and unrelated courses intact.
     *
     * @return void
     */
    public function test_occupied_desired_and_fallback_shortnames_persist_collision_diagnostic_without_mutation(): void {
        global $DB;

        // Given.
        $this->resetAfterTest();
        $context = $this->create_context();
        $record = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            null,
            'C6-DOUBLE-COLLISION'
        );
        $desired = create_course((object)[
            'category' => (int)$context['category']->id,
            'fullname' => 'Unrelated desired collision',
            'shortname' => $record->desiredshortname,
            'idnumber' => 'UNRELATED-DESIRED',
        ]);
        $fallback = create_course((object)[
            'category' => (int)$context['category']->id,
            'fullname' => 'Unrelated fallback collision',
            'shortname' => $record->desiredshortname . '_' . $record->courseidnumber,
            'idnumber' => 'UNRELATED-FALLBACK',
        ]);

        // When.
        (new provisioning_service())->execute_provision((int)$record->id, $record->jobid, $context['userid']);
        $finished = (new provisioning_repository())->get((int)$record->id);
        $unchangeddesired = $DB->get_record('course', ['id' => (int)$desired->id], '*', MUST_EXIST);
        $unchangedfallback = $DB->get_record('course', ['id' => (int)$fallback->id], '*', MUST_EXIST);

        // Then.
        $this->assertSame(provisioning_repository::STATUS_FAILED, $finished->status);
        $this->assertSame(provisioning_diagnostic::CODE_SHORTNAME_COLLISION, $finished->lasterror);
        $this->assertNull($finished->courseid);
        $this->assertSame($record->desiredshortname, $unchangeddesired->shortname);
        $this->assertSame($record->desiredshortname . '_' . $record->courseidnumber, $unchangedfallback->shortname);
    }

    /**
     * Different source identities targeting one shortname share the same execution lock.
     *
     * @return void
     */
    public function test_cross_identity_shortname_lock_records_concurrency_diagnostic(): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        $repository = new provisioning_repository();
        $record = $repository->create_pending(
            'sissource_athenasoft',
            'C6-CROSS-IDENTITY',
            'C6-SHARED-SHORTNAME',
            'C6 shared shortname',
            (int)$context['category']->id,
            null,
            null,
            0,
            0,
            $context['userid']
        );
        $service = new class extends provisioning_service {
            /**
             * Simulate a competing worker that owns the shared shortname lock.
             *
             * @param string $shortname Desired shortname.
             * @return \core\lock\lock
             */
            protected function get_shortname_lock(string $shortname): \core\lock\lock {
                unset($shortname);
                throw new \coding_exception('Provisioning shortname is already being provisioned.');
            }
        };

        $service->execute_provision((int)$record->id, $record->jobid, $context['userid']);

        $finished = $repository->get((int)$record->id);
        $this->assertSame(provisioning_repository::STATUS_FAILED, $finished->status);
        $this->assertSame(provisioning_diagnostic::CODE_LOCK_CONCURRENCY, $finished->lasterror);
        $this->assertSame(0, $DB->count_records('course', ['shortname' => $record->desiredshortname]));
        $source = file_get_contents(__DIR__ . '/../classes/provisioning_state_repository_trait.php');
        $this->assertStringContainsString("\$normalized = \\core_text::strtolower(trim(\$shortname))", $source);
        $this->assertStringContainsString("hash('sha256', \$normalized)", $source);
    }

    /**
     * A computed fallback is locked before it becomes the durable desired shortname.
     *
     * @return void
     */
    public function test_fallback_lock_contention_records_concurrency_without_destination(): void {
        global $DB;

        $this->resetAfterTest();
        $context = $this->create_context();
        $record = $this->queue_record(
            new provisioning_service(),
            (int)$context['category']->id,
            $context['userid'],
            null,
            'C6-FALLBACK-LOCK'
        );
        create_course((object)[
            'category' => (int)$context['category']->id,
            'fullname' => 'Original shortname conflict',
            'shortname' => $record->desiredshortname,
            'idnumber' => 'UNRELATED-FALLBACK-LOCK',
        ]);
        $fallback = $record->desiredshortname . '_' . $record->courseidnumber;
        $service = new class ($fallback) extends provisioning_service {
            /** @var string Shortname whose lock is unavailable. */
            private $blockedshortname;

            /**
             * Store the unavailable fallback shortname.
             *
             * @param string $blockedshortname Unavailable shortname.
             */
            public function __construct(string $blockedshortname) {
                $this->blockedshortname = $blockedshortname;
            }

            /**
             * Simulate contention only for the computed fallback.
             *
             * @param string $shortname Requested shortname.
             * @return \core\lock\lock
             */
            protected function get_shortname_lock(string $shortname): \core\lock\lock {
                if ($shortname === $this->blockedshortname) {
                    throw new \coding_exception('Provisioning shortname is already being provisioned.');
                }
                return parent::get_shortname_lock($shortname);
            }
        };

        $service->execute_provision((int)$record->id, $record->jobid, $context['userid']);

        $finished = (new provisioning_repository())->get((int)$record->id);
        $this->assertSame(provisioning_repository::STATUS_FAILED, $finished->status);
        $this->assertSame(provisioning_diagnostic::CODE_LOCK_CONCURRENCY, $finished->lasterror);
        $this->assertNull($finished->courseid);
        $this->assertFalse($DB->record_exists('course', ['shortname' => $fallback]));
    }
}
