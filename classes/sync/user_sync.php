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
 * User synchronisation from a SIS source.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_wisa\sync;

use local_wisa\logger;
use local_wisa\sync_stats;

/**
 * Synchronises generic SIS student and teacher records into Moodle users.
 */
class user_sync {
    /** Safe user-table fields supplied by generic SIS records. */
    private const CORE_FIELDS = [
        'firstname',
        'lastname',
        'email',
        'city',
        'country',
        'lang',
        'description',
        'institution',
        'department',
        'phone1',
        'phone2',
        'address',
    ];

    /** @var bool Whether to avoid database mutations. */
    private $dryrun;

    /** @var sync_stats Per-run statistics. */
    private $stats;

    /**
     * Construct the user synchronisation service.
     *
     * @param bool $dryrun Whether to avoid database mutations.
     * @param sync_stats|null $stats Per-run statistics.
     */
    public function __construct(bool $dryrun = false, ?sync_stats $stats = null) {
        $this->dryrun = $dryrun;
        $this->stats = $stats ?: new sync_stats();
    }

    /**
     * Run user synchronisation for one source-stream tuple.
     *
     * @param array $rows Source rows for one users tuple.
     * @return bool Whether all rows processed successfully.
     */
    public function run(array $rows): bool {
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->dirroot . '/user/profile/lib.php');

        return $this->sync_set($rows, 'stream');
    }

    /**
     * Process one fetched set of users.
     *
     * @param array $rows Result rows for one source stream tuple.
     * @param string $label Log label.
     * @return bool Whether processing did not add a user failure.
     */
    private function sync_set(array $rows, string $label): bool {
        logger::log('sync_users', 'system', $label, 'info', 'Processing ' . count($rows) . " $label rows.");
        $userfailbefore = $this->stats->userfail;
        foreach ($rows as $row) {
            try {
                $this->process_user((array)$row);
            } catch (\Throwable $e) {
                logger::log('sync_user', 'user', 'redacted', 'fail', 'USER_ROW_PROCESSING_FAILED');
                $this->stats->userfail++;
            }
        }
        return $this->stats->userfail === $userfailbefore;
    }

    /**
     * Create or update one generic user row.
     *
     * @param array $record Generic user record.
     */
    private function process_user($record) {
        global $DB, $CFG;

        $idnumber = trim($record['idnumber'] ?? $record['username'] ?? '');
        $username = clean_param(\core_text::strtolower(trim($record['username'] ?? '')), PARAM_USERNAME);
        $corevalues = $this->core_values($record);
        $email = $corevalues['email'] ?? '';
        $firstname = $corevalues['firstname'] ?? '';
        $lastname = $corevalues['lastname'] ?? '';
        $password = array_key_exists('password', $record) ? (string)$record['password'] : '';
        $haspassword = trim($password) !== '';

        if ($username === '' || $idnumber === '') {
            logger::log(
                'sync_user',
                'user',
                'redacted',
                'fail',
                'USER_ROW_REQUIRED_FIELDS_MISSING'
            );
            $this->stats->userfail++;
            return;
        }

        if ($email !== '') {
            $email = $this->normalize_email($email);
        }

        if ($email !== '' && !validate_email($email)) {
            logger::log('sync_user', 'user', 'redacted', 'warn', 'USER_EMAIL_INVALID');
            $email = '';
            unset($corevalues['email']);
        }

        $profiledata = $this->profile_data($record);

        $user = $DB->get_record('user', ['idnumber' => $idnumber, 'deleted' => 0]);

        if ($user) {
            $update = new \stdClass();
            $update->id = $user->id;
            $changed = false;

            foreach ($corevalues as $field => $value) {
                if ($user->$field !== $value) {
                    $update->$field = $value;
                    $changed = true;
                }
            }

            if ($changed || $profiledata !== null) {
                if ($this->dryrun) {
                    logger::log('sync_user', 'user', $idnumber, 'dryrun', "Would update user $firstname $lastname.");
                } else {
                    if ($changed) {
                        user_update_user($update, false);
                    }
                    if ($profiledata !== null) {
                        $profiledata->id = $user->id;
                        profile_save_data($profiledata);
                    }
                    logger::log('sync_user', 'user', $idnumber, 'update', "Updated user $firstname $lastname.");
                }
                $this->stats->userupdate++;
            }
        } else {
            if ($DB->record_exists('user', ['username' => $username, 'deleted' => 0])) {
                logger::log(
                    'sync_user',
                    'user',
                    'redacted',
                    'warn',
                    'Username already exists with a different idnumber.'
                );
                $this->stats->userfail++;
                return;
            }

            $newuser = new \stdClass();
            $newuser->auth = 'manual';
            $newuser->confirmed = 1;
            $newuser->mnethostid = $CFG->mnet_localhost_id;
            $newuser->username = $username;
            $newuser->password = hash_internal_user_password($haspassword ? $password : random_string(24));
            $newuser->idnumber = $idnumber;
            foreach ($corevalues as $field => $value) {
                $newuser->$field = $value;
            }
            $newuser->firstname = $firstname;
            $newuser->lastname = $lastname;
            $newuser->email = $email;
            if (!isset($newuser->lang)) {
                $newuser->lang = $CFG->lang ?? 'en';
            }

            try {
                if (!$this->dryrun) {
                    $userid = user_create_user($newuser, false, false);
                    if ($this->force_password_change_enabled()) {
                        set_user_preference('auth_forcepasswordchange', 1, $userid);
                    }
                }
            } catch (\Exception $e) {
                logger::log('sync_user', 'user', 'redacted', 'fail', 'USER_CREATE_FAILED');
                $this->stats->userfail++;
                return;
            }

            if (!$this->dryrun && $profiledata !== null) {
                $profiledata->id = $userid;
                profile_save_data($profiledata);
            }
            logger::log(
                'sync_user',
                'user',
                $idnumber,
                $this->dryrun ? 'dryrun' : 'create',
                $this->dryrun ? "Would create user $firstname $lastname." : "Created user $firstname $lastname."
            );
            $this->stats->usercreate++;
        }
    }

    /**
     * Return whether newly created users must change their initial password.
     *
     * @return bool True when the policy is enabled or has not been configured yet.
     */
    private function force_password_change_enabled(): bool {
        $setting = get_config('local_wisa', 'force_password_change');
        return $setting === false || (bool)$setting;
    }

    /**
     * Collect non-empty safe core fields from a generic SIS record.
     *
     * @param array $record Generic user record.
     * @return array Safe core fields indexed by user-table field name.
     */
    private function core_values(array $record): array {
        $values = [];
        foreach (self::CORE_FIELDS as $field) {
            if (!array_key_exists($field, $record)) {
                continue;
            }
            $value = trim((string)$record[$field]);
            if ($value === '') {
                continue;
            }
            if ($field === 'firstname' || $field === 'lastname') {
                $value = \core_text::substr($value, 0, 100);
            }
            if ($field === 'email') {
                $value = $this->normalize_email($value);
            }
            $values[$field] = $value;
        }
        return $values;
    }

    /**
     * Collect supplied custom profile fields that exist in Moodle.
     *
     * @param array $record Generic user record.
     * @return \stdClass|null Data accepted by the Moodle profile API, or null when none is supplied.
     */
    private function profile_data(array $record): ?\stdClass {
        global $DB;

        $profiledata = new \stdClass();
        foreach ($record as $field => $value) {
            if (strpos($field, 'profile_field_') !== 0) {
                continue;
            }
            $shortname = substr($field, 14);
            if ($shortname === '' || !$DB->record_exists('user_info_field', ['shortname' => $shortname])) {
                logger::log(
                    'sync_user',
                    'user',
                    'redacted',
                    'warn',
                    'Unknown profile field shortname from source ignored.'
                );
                continue;
            }
            $profiledata->$field = $value;
        }

        return get_object_vars($profiledata) ? $profiledata : null;
    }

    /**
     * Make a name-derived e-mail valid by stripping diacritics from the local part.
     *
     * @param string $email
     * @return string
     */
    private function normalize_email($email) {
        $at = \core_text::strpos($email, '@');
        $local = ($at === false) ? $email : \core_text::substr($email, 0, $at);
        $domain = ($at === false) ? '' : \core_text::substr($email, $at);
        $ascii = \core_text::specialtoascii($local);
        if ($ascii !== $local) {
            $local = \core_text::strtolower($ascii);
        }
        return $local . $domain;
    }
}
