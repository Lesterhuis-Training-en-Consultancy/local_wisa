<?php
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

    public function run() {
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        // Sync Teachers
        $teachers = $this->api->get_teachers();
        if ($teachers) {
            logger::log('sync_users', 'system', 'teachers', 'info', 'Fetched ' . count($teachers) . ' teachers.');
            foreach ($teachers as $teacher) {
                try {
                    $this->process_user($teacher, 'teacher');
                } catch (\Throwable $e) {
                    $u = $teacher['USERNAME'] ?? 'unknown';
                    logger::log('sync_user', 'user', $u, 'fail', "Process error: " . $e->getMessage());
                    $this->stats->user_fail++;
                }
            }
        }

        // Sync Students
        $students = $this->api->get_students();
        if ($students) {
            logger::log('sync_users', 'system', 'students', 'info', 'Fetched ' . count($students) . ' students.');
            foreach ($students as $student) {
                try {
                    $this->process_user($student, 'student');
                } catch (\Throwable $e) {
                    $u = $student['USERNAME'] ?? 'unknown';
                    logger::log('sync_user', 'user', $u, 'fail', "Process error: " . $e->getMessage());
                    $this->stats->user_fail++;
                }
            }
        }
    }

    private function process_user($wisa_user, $type) {
        global $DB, $CFG;

        $idnumber = trim($wisa_user['USERNAME']); // WISA ID or login - kept verbatim as idnumber.
        $username = \core_text::strtolower($idnumber); // Moodle requires lowercase usernames.
        $email = trim($wisa_user['EMAIL']);
        $firstname = $wisa_user['FIRSTNAME'];
        $lastname = $wisa_user['LASTNAME'];

        if ($username === '') {
            logger::log('sync_user', 'user', $idnumber, 'fail', 'Empty WISA username, skipped.');
            return;
        }

        // Try to find user by ID number (WISA Username) first, then Email
        // Note: The prompt says USERNAME is unique login or ID.
        // We will map WISA USERNAME to Moodle 'idnumber' and/or 'username'.
        // To be safe, we use 'idnumber' for matching if possible, or 'username' if it looks like a login.
        // Given the examples (ady.borghgraef vs 138), teachers have login-like names, students have IDs.
        
        // Strategy: 
        // 1. Check by idnumber = $username
        // 2. Check by username = $username
        // 3. Check by email = $email

        $user = $DB->get_record('user', ['idnumber' => $idnumber, 'deleted' => 0]);
        if (!$user) {
            $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
        }
        if (!$user && !empty($email)) {
            $user = $DB->get_record('user', ['email' => $email, 'deleted' => 0]);
        }

        if ($user) {
            // Update
            $update = new \stdClass();
            $update->id = $user->id;
            $changed = false;

            if ($user->firstname !== $firstname) {
                $update->firstname = $firstname;
                $changed = true;
            }
            if ($user->lastname !== $lastname) {
                $update->lastname = $lastname;
                $changed = true;
            }
            if ($user->email !== $email && !empty($email)) {
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
            // Create.
            $new_user = new \stdClass();
            $new_user->auth = 'manual';
            $new_user->confirmed = 1;
            $new_user->mnethostid = $CFG->mnet_localhost_id;
            $new_user->username = $username;
            $new_user->password = hash_internal_user_password(complex_random_string());
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
}
