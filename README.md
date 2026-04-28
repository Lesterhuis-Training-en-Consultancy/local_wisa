# local_wisa — WISA ↔ Moodle synchronization

Moodle local plugin that synchronizes courses, users (teachers + students) and
enrollments from a [WISA / Schoolware](https://www.wisa.be/) backend into
Moodle on a recurring schedule.

- **Component:** `local_wisa`
- **Install path:** `local/wisa/`
- **Tested with:** Moodle 4.5
- **Minimum Moodle:** 4.0 (build 2022041900)
- **Status:** Alpha — use at your own risk; review every config change in dry-run mode first.

## What it does

On each scheduled run (default: every 5 minutes) the plugin queries three WISA
endpoints and reconciles the data with Moodle:

| WISA endpoint | Maps to                                                |
|---------------|--------------------------------------------------------|
| `MCVO_C`      | Moodle courses, matched by `idnumber = KLAS_ID`        |
| `MCVO_LKR`    | Teacher accounts                                       |
| `MCVO_STUD`   | Student accounts                                       |
| `MCVO_*?KLAS_ID=…` | Per-course enrollments                            |

For each WISA record the plugin creates the matching Moodle entity if it does
not yet exist, or updates the differing fields if it does. New users are
created with a random unusable password and `auth_forcepasswordchange = 1`.

## Features

- **Dry-run mode** — log every change the sync *would* make without writing to the database.
- **Lock against double runs** — uses `\core\lock\lock_config`; if a sync is still running, the next cron tick is skipped instead of stacking up.
- **Per-record fault isolation** — one bad WISA record does not abort the whole sync; failures are logged with status `fail` and the loop continues.
- **Run summary** — every run logs a one-line summary and stores the last-run time, mode and counters in `mdl_config_plugins` so the admin UI can show status at a glance.
- **Log retention** — daily cleanup task deletes log entries older than the configured number of days (default 30).
- **Connection test page** — one-click "Test WISA connection" button in settings; shows HTTP timing and a sample record.
- **Privacy provider** — full implementation of `\core_privacy\local\metadata\provider`, `request\plugin\provider` and `request\core_userlist_provider`. Compliant with Moodle's GDPR data export & deletion tooling.

## Installation

### Option A — install via Moodle UI (recommended)

1. Download [`dist/local_wisa-v0.2.0.zip`](dist/local_wisa-v0.2.0.zip).
2. As a site administrator, go to **Site administration → Plugins → Install plugins**.
3. Drag-and-drop the zip, click *Install plugin from the ZIP file*, then *Continue* through the upgrade screens.

### Option B — install from source

```bash
cd /path/to/moodle/local
git clone https://github.com/tverbesselt/moodlepluginWISA.git wisa
```

Then visit `https://your-moodle/admin/index.php` (or run `php admin/cli/upgrade.php`) to complete the installation.

## Configuration

After installation, go to **Site administration → Plugins → Local plugins → WISA Synchronization**:

| Setting | Default | Notes |
|---|---|---|
| WISA API URL | `https://testcvoantwerpen.schoolware.be/webwisad/bin/server.fcgi/QUERY/` | Base URL up to `/QUERY/`. The plugin appends `MCVO_C`, `MCVO_LKR`, `MCVO_STUD`. |
| API Username / Password | _empty_ | The `_username_` / `_password_` query-param creds. |
| Institute Number | `123456` | The `instellingsnummer` to query. |
| Teacher / Student role | editingteacher (3) / student (5) | Roles to assign on enrollment. |
| Default Course Category | `1` | Moodle category ID for newly created courses. |
| Dry-run mode | off | When on, sync logs intended changes but writes nothing. |
| Log retention (days) | 30 | Sync log entries older than this are pruned daily at 03:15. |

After saving, click **Test WISA connection** to verify the credentials.

### Sync frequency

Scheduled at `*/5 * * * *` by default. Change via **Site administration → Server → Tasks → Scheduled tasks** → "WISA Synchronization Task".

## Logs & operation

- **Site administration → Plugins → Local plugins → View WISA Logs** — the 200 most recent log entries, plus a last-run summary panel and a "Run manual sync now" button.
- All entries are stored in `mdl_local_wisa_log`. Entries older than `log_retention_days` are deleted by `\local_wisa\task\log_cleanup_task` (cron, 03:15 daily).

## Privacy / GDPR

The plugin processes personal data (names, emails, usernames) coming from WISA.
A `\local_wisa\privacy\provider` is included that:

- declares the `local_wisa_log` table and the external WISA API as data sources,
- exports a user's log entries on data-export requests,
- deletes a user's log entries on data-deletion requests.

User accounts created in Moodle by the sync are NOT removed when a user is deleted from WISA — see the [known limitations](#known-limitations) below.

## Known limitations

- **No unenrol-on-disappearance.** Users that disappear from a WISA class are not automatically unenrolled from the matching Moodle course. They keep their access until manually removed.
- **Single category.** All synced courses land in the configured default category. There is no `OPLEIDINGSGEBIED → category` mapping yet.
- **Per-course API calls.** Enrollment sync makes one `MCVO_STUD` and one `MCVO_LKR` call per course (N+1).
- **Manual sync runs synchronously.** The "Run manual sync now" button on the logs page blocks the request for the full sync duration. For large datasets, prefer the scheduled task.

## Development

```
local/wisa/
├── version.php
├── settings.php
├── logs.php                      Admin: log viewer + last-run panel + manual-run button
├── test_connection.php           Admin: one-shot API test page
├── classes/
│   ├── api_client.php            HTTP client for the WISA QUERY API
│   ├── logger.php                DB logger (writes mdl_local_wisa_log)
│   ├── sync_manager.php          Orchestrator: lock + dry-run + summary
│   ├── sync_stats.php            Counter struct passed to all sync classes
│   ├── sync/
│   │   ├── course_sync.php       MCVO_C → mdl_course
│   │   ├── user_sync.php         MCVO_LKR + MCVO_STUD → mdl_user
│   │   └── enrollment_sync.php   per-course enrol via manual enrol plugin
│   ├── task/
│   │   ├── sync_task.php         Scheduled: full sync (default */5 min)
│   │   └── log_cleanup_task.php  Scheduled: prune logs (default 03:15 daily)
│   └── privacy/
│       └── provider.php          GDPR metadata + export + delete
├── db/
│   ├── install.xml               local_wisa_log table
│   └── tasks.php                 Scheduled task defaults
├── lang/en/local_wisa.php
├── cli/test_sync.php             CLI: trigger one full sync
└── dist/local_wisa-v0.2.0.zip    Installable bundle for the Moodle UI
```

### Building the dist zip

```bash
# from repo root
mkdir -p dist
rm -f dist/local_wisa-v*.zip
mkdir -p /tmp/build/wisa
git ls-files | grep -v '^dist/' | grep -v '^README.md$' | grep -v '^LICENSE$' | grep -v '^\.gitignore$' \
  | tar -cf - -T - | tar -xf - -C /tmp/build/wisa
( cd /tmp/build && zip -r "$OLDPWD/dist/local_wisa-$(grep release version.php | grep -oE 'v[0-9.]+').zip" wisa )
rm -rf /tmp/build
```

## Changelog

### v0.2.0 — 2026-04-28
- **Added:** dry-run mode, run-summary on logs page, lock against overlapping runs.
- **Added:** daily log-retention cleanup task with configurable days.
- **Added:** "Test WISA connection" admin page and button.
- **Added:** privacy provider (`\local_wisa\privacy\provider`) for GDPR.
- **Fixed:** `http_build_query` produced `&amp;` separators under Moodle, breaking auth (HTTP 401).
- **Fixed:** missing `course/lib.php` and `user/lib.php` includes caused silent fatals during sync.
- **Fixed:** `update_course` rejected payloads with only enddate; both date fields are now sent together.
- **Fixed:** silent leak of password length and hex-prefix to the log table removed.
- **Fixed:** user creation now uses `$CFG->mnet_localhost_id`, `hash_internal_user_password()` and forces password change on first login.
- **Fixed:** WISA usernames are normalised to lowercase before matching/creating.
- **Fixed:** per-record try/catch in all three sync loops so one bad record cannot abort the entire run.

### v0.1.0
- Initial release.

## License

MIT — see [LICENSE](LICENSE).
