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
 * User synchronisation: creates and updates cursist/teacher accounts from WISA.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GNU GPL v3 or later
 */

namespace local_wisa\sync;

defined('MOODLE_INTERNAL') || die();

use local_wisa\api_client;
use local_wisa\logger;
use local_wisa\sync_stats;

class user_sync {
    private $api;
    private $student_role_id;
    private $teacher_role_id;
    private $dryrun;
    private $stats;

    public function __construct(api_client $api, bool $dryrun = false, ?sync_stats $stats = null) {
        $this->api = $api;
        $this->dryrun = $dryrun;
        $this->stats = $stats ?: new sync_stats();
        $this->student_role_id = get_config('local_wisa', 'student_role');
        $this->teacher_role_id = get_config('local_wisa', 'teacher_role');
    }

    /**
     * @param string $mode 'both', 'teachers' or 'students'.
     * @return bool True only when every requested feed was fetched successfully
     *              (a failed fetch must not advance the delta watermark).
     */
    public function run($mode = 'both') {
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        if ($mode !== 'both' && $mode !== 'teachers' && $mode !== 'students') {
            $mode = 'both';
        }

        $ok = true;
        if ($mode === 'both' || $mode === 'teachers') {
            $ok = $this->sync_set($this->api->get_teachers(), 'teacher', 'teachers') && $ok;
        }
        if ($mode === 'both' || $mode === 'students') {
            $ok = $this->sync_set($this->api->get_students(), 'student', 'students') && $ok;
        }
        return $ok;
    }

    /**
     * Process one fetched set of users.
     *
     * @param array|false $rows Result of the API fetch.
     * @param string $type 'teacher' or 'student'.
     * @param string $label Log label.
     * @return bool False when the fetch itself failed.
     */
    private function sync_set($rows, $type, $label) {
        if ($rows === false || !is_array($rows)) {
            logger::log('sync_users', 'system', $label, 'fail', "Failed to fetch $label from WISA.");
            return false;
        }
        logger::log('sync_users', 'system', $label, 'info', 'Fetched ' . count($rows) . " $label.");
        foreach ($rows as $row) {
            try {
                $this->process_user((array)$row, $type);
            } catch (\Throwable $e) {
                $u = is_array($row) ? ($row['USERNAME'] ?? 'unknown') : 'unknown';
                logger::log('sync_user', 'user', $u, 'fail', 'Process error: ' . $e->getMessage());
                $this->stats->user_fail++;
            }
        }
        return true;
    }

    private function process_user($wisa_user, $type) {
        global $DB, $CFG;

        // IDNUMBER is the stable matching key. A school may supply it as a dedicated
        // field in the query; when absent we fall back to USERNAME (backward compatible).
        $idnumber = trim($wisa_user['IDNUMBER'] ?? $wisa_user['USERNAME'] ?? '');
        $username = clean_param(\core_text::strtolower(trim($wisa_user['USERNAME'] ?? '')), PARAM_USERNAME);
        $email = trim($wisa_user['EMAIL'] ?? '');
        $firstname = \core_text::substr(trim($wisa_user['FIRSTNAME'] ?? ''), 0, 100);
        $lastname = \core_text::substr(trim($wisa_user['LASTNAME'] ?? ''), 0, 100);

        if ($username === '' || $idnumber === '') {
            logger::log('sync_user', 'user', $idnumber ?: 'unknown', 'fail',
                'Empty or invalid WISA username/idnumber, skipped.');
            $this->stats->user_fail++;
            return;
        }

        // Names can carry diacritics (é, ü, ç, Á …). A name-derived address such as
        // 'lpacquée@...' or 'aalcantaraÁlvarez@...' is not a valid e-mail, so without
        // this it would be dropped and the account created without an address. Strip the
        // diacritics from the local part so the user still loads (domain left untouched).
        if ($email !== '') {
            $email = $this->normalize_email($email);
        }

        // Ignore a malformed email from the feed rather than storing it on the account.
        if ($email !== '' && !validate_email($email)) {
            logger::log('sync_user', 'user', $idnumber, 'warn', "Invalid email '$email' from WISA ignored.");
            $email = '';
        }

        // Match ONLY on idnumber — the stable, immutable WISA key. Matching on email
        // (or username) is unsafe: a shared or changed email merges distinct people
        // onto one Moodle account. The query decides what USERNAME contains; idnumber
        // is how we recognise an existing account.
        $user = $DB->get_record('user', ['idnumber' => $idnumber, 'deleted' => 0]);

        if ($user) {
            // Update — never overwrite an existing name/email with an empty feed value.
            $update = new \stdClass();
            $update->id = $user->id;
            $changed = false;

            if ($firstname !== '' && $user->firstname !== $firstname) {
                $update->firstname = $firstname;
                $changed = true;
            }
            if ($lastname !== '' && $user->lastname !== $lastname) {
                $update->lastname = $lastname;
                $changed = true;
            }
            if ($email !== '' && $user->email !== $email) {
                $update->email = $email;
                $changed = true;
            }
            if ($user->idnumber !== $idnumber) {
                $update->idnumber = $idnumber;
                $changed = true;
            }

            if ($changed) {
                if ($this->dryrun) {
                    logger::log('sync_user', 'user', $idnumber, 'dryrun', "Would update user $firstname $lastname.");
                } else {
                    user_update_user($update, false);
                    logger::log('sync_user', 'user', $idnumber, 'update', "Updated user $firstname $lastname.");
                }
                $this->stats->user_update++;
            }

        } else {
            // No user with this idnumber exists -> create one. Guard against hijacking
            // an existing account (or a duplicate-username DB failure): if the username
            // is already taken by another record, log a conflict and skip.
            if ($DB->record_exists('user', ['username' => $username, 'deleted' => 0])) {
                logger::log('sync_user', 'user', $idnumber, 'warn',
                    "Username '$username' already exists with a different idnumber; skipped to avoid collision.");
                $this->stats->user_fail++;
                return;
            }

            $new_user = new \stdClass();
            $new_user->auth = 'manual';
            $new_user->confirmed = 1;
            $new_user->mnethostid = $CFG->mnet_localhost_id;
            $new_user->username = $username;
            $new_user->password = hash_internal_user_password(random_string(24));
            $new_user->firstname = $firstname;
            $new_user->lastname = $lastname;
            $new_user->email = $email;
            $new_user->idnumber = $idnumber;
            $new_user->lang = $CFG->lang ?? 'en';

            try {
                if ($this->dryrun) {
                    logger::log('sync_user', 'user', $idnumber, 'dryrun', "Would create user $firstname $lastname.");
                } else {
                    $user_id = user_create_user($new_user, false, false);
                    // Force password change at next login - user has no usable password.
                    set_user_preference('auth_forcepasswordchange', 1, $user_id);
                    logger::log('sync_user', 'user', $idnumber, 'create', "Created user $firstname $lastname.");
                }
                $this->stats->user_create++;
            } catch (\Exception $e) {
                logger::log('sync_user', 'user', $idnumber, 'fail', "Failed to create user: " . $e->getMessage());
                $this->stats->user_fail++;
            }
        }
    }

    /**
     * Make a name-derived e-mail valid by stripping diacritics from the local part
     * (é→e, ü→u, ç→c, ñ→n, Á→a …). The domain is left as-is, and an address with no
     * special characters is returned unchanged — so plain addresses (e.g. C19710@…)
     * are never rewritten and no needless updates are triggered.
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
            // Had diacritics: force the local part to lowercase ASCII so it matches the
            // institutional address (e.g. 'aalcantaraalvarez').
            $local = \core_text::strtolower($ascii);
        }
        return $local . $domain;
    }
}
