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
 * WISA raw-row mapper.
 *
 * @package    sissource_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace sissource_wisa;

use local_wisa\source_field_mapper;

/**
 * Maps WISA transport rows to generic source records.
 *
 * @package    sissource_wisa
 * @copyright  Sebsoft.nl  <helpdesk@sebsoft.nl>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class row_mapper {
    /** @var array Default WISA raw source columns. */
    private const DEFAULT_FIELDS = [
        'course' => [
            'idnumber' => 'KLAS_ID',
            'shortname' => 'SHORTNAME',
            'fullname' => 'FULLNAME',
            'startdate' => 'BEGINDATUM',
            'enddate' => 'EINDDATUM',
            'category' => 'CATEGORY',
        ],
        'user' => [
            'idnumber' => 'IDNUMBER',
            'username' => 'USERNAME',
            'firstname' => 'FIRSTNAME',
            'lastname' => 'LASTNAME',
            'email' => 'EMAIL',
        ],
        'enrolment' => [
            'courseidnumber' => 'KLAS_ID',
            'useridnumber' => 'USERNAME',
            'role' => 'ROL',
            'startdate' => 'VAN',
            'enddate' => 'TOT',
        ],
        'unenrolment' => [
            'courseidnumber' => 'KLAS_ID',
            'useridnumber' => 'USERNAME',
        ],
    ];

    /** @var source_field_mapper WISA raw-field mapper. */
    private $mapper;

    /**
     * Construct the row mapper from WISA field-map configuration.
     *
     * @param string $fieldmap Configured raw-field map overrides.
     * @return void
     */
    public function __construct(string $fieldmap) {
        $this->mapper = new source_field_mapper('sissource_wisa', self::DEFAULT_FIELDS, $fieldmap);
    }

    /**
     * Return the effective field-map metadata for preview consumers.
     *
     * @return array Effective mappings keyed by record type and generic target.
     */
    public function get_effective_map(): array {
        return $this->mapper->get_effective_map();
    }

    /**
     * Map raw WISA rows for one source stream.
     *
     * @param array $rows Raw WISA rows.
     * @param string $stream WISA stream key.
     * @return array Generic rows.
     */
    public function map_rows(array $rows, string $stream): array {
        $mapped = [];
        foreach ($rows as $row) {
            $row = (array)$row;
            if ($stream === 'courses') {
                $mapped[] = $this->map_course($row);
            } else if ($stream === 'student_accounts' || $stream === 'teacher_accounts') {
                $mapped[] = $this->map_user($row);
            } else if ($stream === 'enrolments') {
                $mapped[] = $this->map_enrolment($row);
            } else if ($stream === 'unenrolments') {
                $mapped[] = $this->map_unenrolment($row);
            } else {
                throw new \coding_exception('Unknown WISA source stream: ' . $stream);
            }
        }
        return $mapped;
    }

    /**
     * Map a WISA course row.
     *
     * @param array $row Raw WISA row.
     * @return array Generic course record.
     */
    private function map_course(array $row): array {
        return $this->trim_mapped_record(
            $this->mapper->map_record('course', $row, $this->record_identity($row, 'course'))
        );
    }

    /**
     * Map a WISA person row.
     *
     * @param array $row Raw WISA row.
     * @return array Generic user record.
     */
    private function map_user(array $row): array {
        $mapped = $this->trim_mapped_record(
            $this->mapper->map_record('user', $row, $this->record_identity($row, 'user'))
        );
        $user = [];
        $username = $mapped['username'] ?? '';
        if (array_key_exists('idnumber', $mapped)) {
            $user['idnumber'] = $mapped['idnumber'] !== '' ? $mapped['idnumber'] : $username;
        }
        if (array_key_exists('username', $mapped)) {
            $user['username'] = $username;
        }
        foreach (['firstname', 'lastname', 'email'] as $target) {
            if (array_key_exists($target, $mapped)) {
                $user[$target] = $mapped[$target];
            }
        }
        return $this->add_additional_user_fields($user, $mapped);
    }

    /**
     * Map a WISA enrolment row.
     *
     * @param array $row Raw WISA row.
     * @return array Generic enrolment record.
     */
    private function map_enrolment(array $row): array {
        $mapped = $this->trim_mapped_record(
            $this->mapper->map_record('enrolment', $row, $this->record_identity($row, 'enrolment'))
        );
        $enrolment = [];
        foreach (['courseidnumber', 'useridnumber'] as $target) {
            if (array_key_exists($target, $mapped)) {
                $enrolment[$target] = $mapped[$target];
            }
        }
        $enrolment['role'] = array_key_exists('role', $mapped) ? $this->map_role($mapped['role']) : 'student';
        foreach (['startdate', 'enddate'] as $target) {
            if (array_key_exists($target, $mapped)) {
                $enrolment[$target] = $mapped[$target];
            }
        }
        return $enrolment;
    }

    /**
     * Map a WISA unenrolment row.
     *
     * @param array $row Raw WISA row.
     * @return array Generic unenrolment record.
     */
    private function map_unenrolment(array $row): array {
        return $this->trim_mapped_record(
            $this->mapper->map_record('unenrolment', $row, $this->record_identity($row, 'unenrolment'))
        );
    }

    /**
     * Map WISA aliases while preserving configurable source role tokens.
     *
     * @param mixed $role Raw role value.
     * @return string Source token or the semantic student/teacher fallback.
     */
    private function map_role($role): string {
        $role = \core_text::strtolower(trim((string)$role));
        if (in_array($role, ['teacher', 'leraar', 'lkr'], true)) {
            return 'teacher';
        }
        return $role === '' ? 'student' : $role;
    }

    /**
     * Trim mapped source values without changing generic target names.
     *
     * @param array $mapped Raw mapped values.
     * @return array Mapped values normalised as strings.
     */
    private function trim_mapped_record(array $mapped): array {
        foreach ($mapped as $target => $value) {
            $mapped[$target] = trim((string)$value);
        }
        return $mapped;
    }

    /**
     * Append configured extended user fields without changing core field order.
     *
     * @param array $user Generic user record.
     * @param array $mapped Raw mapped user values.
     * @return array Generic user record with configured extended fields.
     */
    private function add_additional_user_fields(array $user, array $mapped): array {
        foreach ($mapped as $target => $value) {
            if (!array_key_exists($target, $user)) {
                $user[$target] = $value;
            }
        }
        return $user;
    }

    /**
     * Build a safe WISA row identity for missing-column warnings.
     *
     * @param array $row Raw WISA row.
     * @param string $recordtype Generic record type.
     * @return string Safe source-record identity.
     */
    private function record_identity(array $row, string $recordtype): string {
        if ($recordtype === 'course') {
            return 'course:' . (string)($row['KLAS_ID'] ?? '');
        }
        if ($recordtype === 'user') {
            return 'user:' . (string)($row['IDNUMBER'] ?? $row['USERNAME'] ?? '');
        }
        return $recordtype . ':' . (string)($row['KLAS_ID'] ?? '') . '/' . (string)($row['USERNAME'] ?? '');
    }
}
