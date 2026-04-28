<?php
$string['pluginname'] = 'WISA Synchronization';
$string['settings_heading'] = 'WISA Synchronization Settings';
$string['runtime_heading'] = 'Runtime options';

$string['api_url'] = 'WISA API URL';
$string['api_url_desc'] = 'Base URL for the WISA API (e.g., https://testcvoantwerpen.schoolware.be/webwisad/bin/server.fcgi/QUERY/).';
$string['api_user'] = 'API Username';
$string['api_user_desc'] = 'Username for WISA API authentication.';
$string['api_pass'] = 'API Password';
$string['api_pass_desc'] = 'Password for WISA API authentication.';
$string['institute_num'] = 'Institute Number';
$string['institute_num_desc'] = 'The institute number (instellingsnummer) to query.';
$string['sync_frequency'] = 'Sync Frequency';
$string['sync_frequency_desc'] = 'Configure under Site administration → Server → Tasks → Scheduled tasks (look for "WISA Synchronization Task").';
$string['teacher_role'] = 'Teacher Role';
$string['teacher_role_desc'] = 'The Moodle role to assign to teachers (e.g., editingteacher).';
$string['student_role'] = 'Student Role';
$string['student_role_desc'] = 'The Moodle role to assign to students (e.g., student).';
$string['default_category'] = 'Default Course Category';
$string['default_category_desc'] = 'Category ID where new courses should be placed if no mapping is found.';

$string['dry_run'] = 'Dry-run mode';
$string['dry_run_desc'] = 'When enabled, the sync logs every change it would make but does NOT write to the database. Useful to validate configuration or preview the impact of a config change before going live.';
$string['log_retention'] = 'Log retention (days)';
$string['log_retention_desc'] = 'Sync log entries older than this number of days are deleted by the daily cleanup task. Default: 30.';

$string['task_sync'] = 'WISA Synchronization Task';
$string['task_log_cleanup'] = 'WISA log cleanup';

$string['log_view'] = 'View WISA Logs';
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

$string['privacy:metadata:local_wisa_log'] = 'Synchronization log entries. Records WISA sync actions, including identifiers of synced users, courses, and enrollments.';
$string['privacy:metadata:local_wisa_log:timecreated'] = 'When the log entry was created.';
$string['privacy:metadata:local_wisa_log:action'] = 'The sync action (e.g., sync_user, sync_enrol).';
$string['privacy:metadata:local_wisa_log:objecttype'] = 'The kind of object affected (user, course, enrollment).';
$string['privacy:metadata:local_wisa_log:objectid'] = 'Identifier of the affected object — for users this is the WISA username/idnumber.';
$string['privacy:metadata:local_wisa_log:status'] = 'Outcome of the action (info, create, update, fail, warn, dryrun).';
$string['privacy:metadata:local_wisa_log:message'] = 'Free-text message; may contain user names for audit purposes.';

$string['privacy:metadata:wisa_api'] = 'The WISA API is queried by this plugin. User identifying data is sent to and received from the WISA system.';
$string['privacy:metadata:wisa_api:username'] = 'The WISA login or numeric ID.';
$string['privacy:metadata:wisa_api:firstname'] = 'First name as stored in WISA.';
$string['privacy:metadata:wisa_api:lastname'] = 'Last name as stored in WISA.';
$string['privacy:metadata:wisa_api:email'] = 'Email address as stored in WISA.';
