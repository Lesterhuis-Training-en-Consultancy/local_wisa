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
 * English strings for local_wisa.
 *
 * @package    local_wisa
 * @copyright  2026 Tom Verbesselt <media.atelier@cvoantwerpen.be>
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'WISA Synchronisation';
$string['settings_heading'] = 'WISA Synchronisation settings';
$string['runtime_heading'] = 'Runtime options';

$string['api_url'] = 'WISA API URL';
$string['api_url_desc'] = 'Base URL for the WISA API (e.g. https://yourschool.schoolware.be/webwisad/bin/server.fcgi/QUERY/).';
$string['api_user'] = 'API username';
$string['api_user_desc'] = 'Username for WISA API authentication.';
$string['api_pass'] = 'API password';
$string['api_pass_desc'] = 'Password for WISA API authentication.';
$string['institute_num'] = 'Institute number';
$string['institute_num_desc'] = 'The institute number (instellingsnummer) to query.';
$string['teacher_role'] = 'Teacher role';
$string['teacher_role_desc'] = 'The Moodle role to assign to teachers (e.g. editingteacher).';
$string['student_role'] = 'Student role';
$string['student_role_desc'] = 'The Moodle role to assign to students (e.g. student).';
$string['default_category'] = 'Default course category';
$string['default_category_desc'] = 'Category ID where new courses are placed (in "Fixed" category mode, or as fallback in "From WISA feed" mode).';

$string['category_mode'] = 'Course category mode';
$string['category_mode_desc'] = 'How to determine the category for new courses. "Fixed" places every course in the default category above. "From WISA feed" reads the CATEGORY field returned by the course query (a name, or a path like "Languages / NT2") and looks it up, creating it if needed; it falls back to the default category when the field is empty.';
$string['category_mode_fixed'] = 'Fixed — always the default category';
$string['category_mode_from_feed'] = 'From WISA feed — use the CATEGORY field';

$string['queries_heading'] = 'WISA query codes — the query names (Q_CODE) as defined in WISA querybeheer. Defaults are the MCVOD delta queries; max 10 characters each. Each query receives a "sinds" (since) parameter for delta loading.';
$string['query_courses'] = 'Courses query';
$string['query_courses_desc'] = 'Returns one row per course (default MCVOD_C).';
$string['query_students'] = 'Students query';
$string['query_students_desc'] = 'Returns cursists changed/created since the delta watermark (default MCVOD_STUD).';
$string['query_teachers'] = 'Teachers query';
$string['query_teachers_desc'] = 'Returns teachers changed/created since the delta watermark (default MCVOD_LKR).';
$string['query_enrolments'] = 'Enrolments query';
$string['query_enrolments_desc'] = 'Global enrolment feed with KLAS_ID, USERNAME and ROL columns — replaces the per-course calls (default MCVOD_INS).';
$string['query_unenrolments'] = 'Unenrolments query';
$string['query_unenrolments_desc'] = 'Feed of leavers/stops; matched users are suspended in the course (default MCVOD_UIT).';

$string['sync_parts_heading'] = 'Sync parts and school year — choose which imports run and which school years are in scope. Each part keeps its own "last loaded" timestamp; turning a part off and on again only fetches the changes since then.';
$string['schoolyear_scope'] = 'School year scope';
$string['schoolyear_scope_desc'] = 'Which school years (1 September – 31 August) are synchronised. Courses and enrolments outside the scope are skipped.';
$string['schoolyear_off'] = 'No filter (everything the queries return)';
$string['schoolyear_current'] = 'Current school year only';
$string['schoolyear_current_next'] = 'Current + next school year';
$string['enable_courses'] = 'Synchronise courses';
$string['enable_courses_desc'] = 'Create and update courses (MCVOD_C).';
$string['enable_students'] = 'Synchronise students';
$string['enable_students_desc'] = 'Create and update cursist accounts (MCVOD_STUD).';
$string['enable_teachers'] = 'Synchronise teachers';
$string['enable_teachers_desc'] = 'Create and update teacher accounts (MCVOD_LKR).';
$string['enable_enrolments'] = 'Synchronise enrolments';
$string['enable_enrolments_desc'] = 'Enrol students and teachers in their courses (MCVOD_INS).';
$string['enable_unenrolments'] = 'Synchronise unenrolments';
$string['enable_unenrolments_desc'] = 'Suspend unenrolled students in the course (MCVOD_UIT).';
$string['enrol_teachers'] = 'Enrol teachers in courses';
$string['enrol_teachers_desc'] = 'Within the enrolment feed (MCVOD_INS): enrol the teacher rows in their courses. Turn this off to skip teacher enrolments; switching it back on later still processes the ones missed in between (each role keeps its own delta watermark).';
$string['enrol_students'] = 'Enrol cursists in courses';
$string['enrol_students_desc'] = 'Within the enrolment feed (MCVOD_INS): enrol the cursist rows in their courses. Combined with the teacher switch you can give teachers early access while cursists still wait.';
$string['enable_reconcile'] = 'Nightly full reconciliation';
$string['enable_reconcile_desc'] = 'Once a night, run a full sync (since 1900) as a safety net for changes the delta watermark could miss. Leave off until the regular delta sync has been validated; a full run is heavier than a delta run.';

$string['dry_run'] = 'Dry-run mode';
$string['dry_run_desc'] = 'When enabled, the sync logs every change it would make but does NOT write to the database. Useful to validate configuration or preview the impact of a config change before going live.';
$string['debug_logging'] = 'Debug logging (API URLs)';
$string['debug_logging_desc'] = 'Log every WISA API call (URL, credentials masked) to the log table. Enable only for troubleshooting — it makes the log grow quickly.';
$string['unenrol_safety_max'] = 'Unenrolment safety limit (count)';
$string['unenrol_safety_max_desc'] = 'If a single sync would suspend more than this number of enrolments, nothing is suspended and an alarm is logged. Protects against bad MCVOD_UIT data. Set to 0 to disable. Default: 500.';
$string['unenrol_safety_pct'] = 'Unenrolment safety limit (percent)';
$string['unenrol_safety_pct_desc'] = 'If a single sync would suspend more than this percentage of all active enrolments, nothing is suspended and an alarm is logged. Set to 0 to disable. Default: 50.';
$string['log_retention'] = 'Log retention (days)';
$string['log_retention_desc'] = 'Sync log entries older than this number of days are deleted by the daily cleanup task. Default: 30.';

$string['task_sync'] = 'WISA synchronisation task';
$string['task_log_cleanup'] = 'WISA log cleanup';
$string['task_reconcile'] = 'WISA nightly reconciliation';
$string['task_initial_load'] = 'WISA first full load (background)';

$string['log_view'] = 'View WISA logs';
$string['log_action'] = 'Action';
$string['log_status'] = 'Status';
$string['log_message'] = 'Message';
$string['log_time'] = 'Time';
$string['log_type'] = 'Type';
$string['log_objectid'] = 'Object ID';

$string['last_run'] = 'Last run';
$string['no_runs_yet'] = 'No sync has run yet.';
$string['ago'] = 'ago';
$string['manual_sync_btn'] = 'Run manual sync now';
$string['manual_sync_started'] = 'Manual sync triggered.';

$string['test_connection'] = 'Test WISA connection';
$string['test_connection_btn'] = 'Open connection test page';
$string['test_again'] = 'Run test again';
$string['test_ok'] = 'Connection OK — fetched {$a->count} record(s) in {$a->ms} ms.';
$string['test_failed'] = 'Connection failed.';
$string['test_failed_help'] = 'Check API URL, username, password and institute number. See the WISA logs for the exact HTTP error.';
$string['test_sample'] = 'Sample record (first):';

$string['preview_title'] = 'WISA preview & approval';
$string['preview_intro'] = 'Review what the first full load will create before it runs. The scheduled sync stays paused until you approve it here.';
$string['preview_status_pending'] = 'The first full load has NOT been approved yet — the scheduled sync is paused and will not write anything.';
$string['preview_status_done'] = 'The first full load has been approved — the scheduled sync is active.';
$string['preview_status_queued'] = 'The first full load is running in the background. The scheduled sync activates automatically once it finishes.';
$string['preview_fieldmap_heading'] = 'What is loaded, and into which fields';
$string['fieldmap_part'] = 'Part';
$string['fieldmap_wisa'] = 'WISA field';
$string['fieldmap_moodle'] = 'Moodle field';
$string['fieldmap_note'] = 'Note';
$string['fieldmap_matchkey'] = 'matching key';
$string['fieldmap_catnote'] = 'only in "from WISA feed" category mode';
$string['fieldmap_usernote'] = 'lowercased and cleaned';
$string['fieldmap_emailnote'] = 'validated; ignored if invalid';
$string['fieldmap_windownote'] = 'used for the school-year filter';
$string['fieldmap_suspendnote'] = 'suspends the matching enrolment';
$string['preview_counts_heading'] = 'Preview of the next full load';
$string['preview_window'] = 'School-year window: {$a}';
$string['preview_col_part'] = 'Part';
$string['preview_col_fetched'] = 'Fetched from WISA';
$string['preview_col_inscope'] = 'In scope';
$string['preview_col_new'] = 'New';
$string['part_courses'] = 'Courses';
$string['part_students'] = 'Cursists';
$string['part_teachers'] = 'Teachers';
$string['part_enrolments'] = 'Enrolments';
$string['part_unenrolments'] = 'Unenrolments';
$string['preview_fetch_error'] = 'fetch error';
$string['preview_note'] = 'Click "Show preview" to count what would be loaded. This can take up to a minute for a large dataset.';
$string['preview_btn'] = 'Show preview (count only)';
$string['approve_intro'] = 'Approving switches off test mode and starts the first full load as a background task — so even a large load cannot hit the web-server timeout. The scheduled sync activates automatically once the load finishes.';
$string['approve_btn'] = 'Approve and start the first full load';
$string['approve_confirm'] = 'This switches off test mode and starts a background task that loads all courses, users and enrolments from WISA into Moodle. Continue?';
$string['approve_done'] = 'First full load approved and started.';
$string['approve_queued'] = 'First full load queued. It starts on the next cron run (usually within a minute) and runs in the background.';
$string['approve_queued_info'] = 'The load runs as a background task and cannot time out. Refresh this page or open the logs to follow its progress; when it finishes, the scheduled sync takes over automatically.';
$string['refresh_btn'] = 'Refresh status';
$string['reset_btn'] = 'Reset approval (pause the sync again)';
$string['reset_done'] = 'Approval reset — the scheduled sync is paused again.';
$string['gate_pending'] = 'The first full load has not been approved yet. The scheduled sync is paused.';

$string['privacy:metadata:local_wisa_log'] = 'Synchronisation log entries. Records WISA sync actions, including identifiers of synced users, courses and enrolments.';
$string['privacy:metadata:local_wisa_log:timecreated'] = 'When the log entry was created.';
$string['privacy:metadata:local_wisa_log:action'] = 'The sync action (e.g. sync_user, sync_enrol).';
$string['privacy:metadata:local_wisa_log:objecttype'] = 'The kind of object affected (user, course, enrollment).';
$string['privacy:metadata:local_wisa_log:objectid'] = 'Identifier of the affected object — for users this is the WISA username/idnumber.';
$string['privacy:metadata:local_wisa_log:status'] = 'Outcome of the action (info, create, update, fail, warn, dryrun).';
$string['privacy:metadata:local_wisa_log:message'] = 'Free-text message; may contain user names for audit purposes.';

$string['privacy:metadata:wisa_api'] = 'The WISA API is queried by this plugin. User identifying data is sent to and received from the WISA system.';
$string['privacy:metadata:wisa_api:username'] = 'The WISA login or numeric ID.';
$string['privacy:metadata:wisa_api:firstname'] = 'First name as stored in WISA.';
$string['privacy:metadata:wisa_api:lastname'] = 'Last name as stored in WISA.';
$string['privacy:metadata:wisa_api:email'] = 'Email address as stored in WISA.';
