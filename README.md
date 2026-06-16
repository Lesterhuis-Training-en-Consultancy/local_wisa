# local_wisa — WISA ↔ Moodle synchronisation

Moodle local plugin that synchronises courses, users (teachers + cursists) and
enrolments from a [WISA / Schoolware](https://www.wisa.be/) backend into Moodle
on a recurring schedule.

- **Component:** `local_wisa`
- **Install path:** `local/wisa/`
- **Tested with:** Moodle 4.5
- **Supported:** Moodle 4.0 – 4.5 (build 2022041900+)
- **Status:** Beta — validate every configuration change in dry-run mode first, and do the very first full load via CLI (see [Going live](#going-live)).
- **Licence:** GNU GPL v3 or later (same as Moodle).

## What it does

On each scheduled run (default: every 5 minutes) the plugin queries a set of
named WISA queries and reconciles the data with Moodle. The query names are
configurable; the defaults are the MCVOD delta queries. Each query receives a
`sinds` (since) parameter so that after the first load only changed rows are
fetched (delta sync).

| Default query | Returns | Maps to |
|---------------|---------|---------|
| `MCVOD_C`    | one row per course | Moodle course, matched on `idnumber = KLAS_ID` |
| `MCVOD_STUD` | changed cursists | Moodle user account, matched on `idnumber` |
| `MCVOD_LKR`  | changed teachers | Moodle user account, matched on `idnumber` |
| `MCVOD_INS`  | one global enrolment feed (`KLAS_ID`, `USERNAME`, `ROL`) | manual enrolments |
| `MCVOD_UIT`  | leavers/stops | suspends the matching enrolment |

For each WISA record the plugin creates the matching Moodle entity if it does
not yet exist, or updates the differing fields if it does. New users are created
with a random unusable password and `auth_forcepasswordchange = 1`.

Users are matched **only** on `idnumber` — the stable, immutable WISA key.
Matching on email or username is deliberately avoided: a shared or changed email
would otherwise merge distinct people onto one account.

## Features

- **First-load approval gate** — the scheduled sync is paused until an administrator reviews a preview (record counts + field mapping) and approves the first full load on the **WISA preview & approval** page. Nothing is written automatically before that.
- **Delta sync with per-part watermarks** — each part (courses, cursists, teachers, enrolments, unenrolments) keeps its own "last loaded" timestamp and only fetches changes since then. A part's watermark only advances when its fetch actually succeeded, so a failed call never silently skips records.
- **School-year scope** — limit the sync to the current, or current + next, school year (1 September – 31 August). Courses and enrolments outside the window are skipped. Can be turned off.
- **Per-part on/off switches** — enable or disable any of the five parts independently.
- **Suspend on unenrol (reversible)** — leavers reported by `MCVOD_UIT` are *suspended* in the course (status `ENROL_USER_SUSPENDED`), never hard-unenrolled. Grades and history are preserved, and the enrolment sync reactivates them if they return.
- **Unenrolment safety valve** — if a single run would suspend more than a configurable number *or* share of all active enrolments, nothing is suspended and an alarm is logged. Protects against bad `MCVOD_UIT` data.
- **Nightly full reconciliation (optional)** — a separate task can run a full (since-1900) sync once a night as a safety net for changes the delta could miss. Off by default.
- **Dry-run mode** — log every change the sync *would* make without writing to the database.
- **Credential safety** — the API password is stored with Moodle's encrypted password setting and is masked in every log line (URLs, cURL errors, HTTP bodies). cURL does not follow redirects, so credentials cannot leak off-host.
- **Lock against double runs** — uses `\core\lock\lock_config`; if a sync is still running, the next cron tick is skipped instead of stacking up.
- **Per-record fault isolation** — one bad WISA record does not abort the whole sync; failures are logged with status `fail` and the loop continues.
- **Run caching** — course, user and enrol-instance lookups are cached per run to keep the first full load within a sane query count.
- **Run summary** — every run logs a one-line summary and stores the last-run time, mode and counters so the admin UI can show status at a glance.
- **Log retention** — daily cleanup task deletes log entries older than the configured number of days (default 30).
- **Connection test page** — one-click "Test WISA connection" button in settings; shows HTTP timing and a sample record.
- **Privacy provider** — implements `\core_privacy`'s metadata, plugin and core_userlist providers for the plugin's log table. See [Privacy / GDPR](#privacy--gdpr) for what is and is not covered.
- **English + Dutch language packs.**

## Installation

### Option A — install via Moodle UI (recommended)

1. Download [`dist/local_wisa-v0.6.4.zip`](dist/local_wisa-v0.6.4.zip).
2. As a site administrator, go to **Site administration → Plugins → Install plugins**.
3. Drag-and-drop the zip, click *Install plugin from the ZIP file*, then *Continue* through the upgrade screens.

### Option B — install from source

```bash
cd /path/to/moodle/local
git clone https://github.com/tverbesselt/moodlepluginWISA.git wisa
```

Then visit `https://your-moodle/admin/index.php` (or run `php admin/cli/upgrade.php`) to complete the installation.

## Configuration

After installation, go to **Site administration → Plugins → Local plugins → WISA Synchronisation**:

| Setting | Default | Notes |
|---|---|---|
| WISA API URL | `https://…schoolware.be/webwisad/bin/server.fcgi/QUERY/` | Base URL up to `/QUERY/`. The plugin appends the query name. |
| API username / password | _empty_ | The `_username_` / `_password_` query-param credentials. Password is stored encrypted. |
| Institute number | `123456` | The `instellingsnummer` to query. |
| Teacher / cursist role | editingteacher (3) / student (5) | Roles to assign on enrolment. |
| Default course category | `1` | Moodle category ID for new courses (in `fixed` mode, or as fallback in `from_feed` mode). |
| Course category mode | `fixed` | `fixed`: every course in the default category. `from_feed`: use the `CATEGORY` field from the course query (a name, or a `Parent / Child` path), created if missing. |
| Query codes | `MCVOD_C` / `MCVOD_STUD` / `MCVOD_LKR` / `MCVOD_INS` / `MCVOD_UIT` | The WISA query names (Q_CODE), max 10 chars. Override per school. |
| School-year scope | current + next | `off`, current only, or current + next. |
| Enable courses / cursists / teachers / enrolments / unenrolments | on | Per-part on/off. |
| Enrol teachers / Enrol cursists | on | Within the enrolment feed: enable teacher and cursist enrolments separately (e.g. teachers earlier than cursists). |
| Nightly full reconciliation | off | Run a full sync once a night as a safety net. Heavier than a delta run. |
| Dry-run mode | off | When on, sync logs intended changes but writes nothing. |
| Debug logging (API URLs) | off | Logs each API call (credentials masked). Grows the log quickly. |
| Unenrolment safety limit (count) | 500 | Abort suspensions if a run would exceed this many. 0 = off. |
| Unenrolment safety limit (percent) | 50 | Abort suspensions if a run would exceed this share of active enrolments. 0 = off. |
| Log retention (days) | 30 | Sync log entries older than this are pruned daily at 03:15. |

After saving, click **Test WISA connection** to verify the credentials.

### Scheduled tasks

| Task | Default schedule | Notes |
|---|---|---|
| WISA synchronisation task | `*/5 * * * *` | The delta sync. |
| WISA log cleanup | `15 3 * * *` | Prunes old log rows. |
| WISA nightly reconciliation | `30 4 * * *` | Only does work when "Nightly full reconciliation" is enabled. |

Change schedules via **Site administration → Server → Tasks → Scheduled tasks**.

## Going live

The scheduled sync stays **paused until you approve the first full load** — it
writes nothing to Moodle on its own. Approve it from the preview page:

1. Configure the plugin and verify with **Test WISA connection**.
2. Go to **Site administration → Plugins → Local plugins → WISA preview & approval**.
3. Review the **field mapping** (what is read from WISA and where it lands) and click **Show preview** to count what the first load would create — this writes nothing.
4. When you are happy, click **Approve and start the first full load**. This switches off test mode and **queues the first full load as a background task** — so even a large dataset cannot hit the web-server timeout. The page returns immediately and shows "load running"; follow the progress on the logs page. The gate opens automatically once the load finishes, and the scheduled delta sync takes over from then on.
5. Prefer the command line? Run the first load directly instead (synchronous, with live output): `php local/wisa/cli/test_sync.php --approve` (count first with `--preview`).
6. Optionally enable the nightly reconciliation once the delta sync has proven itself.

The background load is picked up by cron, so make sure cron is running (it normally fires within a minute). Until step 4 the scheduled task logs "initial full load not yet approved" and does nothing; while the background load runs the preview page shows "load running", and when it completes the gate opens. You can re-pause the sync any time with **Reset approval** on the preview page.

## Logs & operation

- **Site administration → Plugins → Local plugins → View WISA logs** — the most recent log entries, plus a last-run summary panel and a "Run manual sync now" button.
- All entries are stored in `mdl_local_wisa_log`. Entries older than the retention setting are deleted by `\local_wisa\task\log_cleanup_task` (cron, 03:15 daily).
- A `SAFETY STOP` line with status `fail` means the unenrolment safety valve aborted a run — check the `MCVOD_UIT` data or raise the limit.

## Privacy / GDPR

The plugin processes personal data (names, emails, usernames) coming from WISA.
A `\local_wisa\privacy\provider` is included that:

- declares the `local_wisa_log` table and the external WISA API as data sources,
- exports a user's log entries on data-export requests,
- deletes a user's log entries on data-deletion requests (matched heuristically on `objectid = username OR idnumber`).

**Important scope notes for a DPIA** (see also [`docs/privacy-en-productie.md`](docs/privacy-en-productie.md)):

- **Created accounts persist.** Moodle user accounts created by the sync are core
  `user` records (owned by Moodle's own privacy provider, not this plugin) and are
  **not** deleted when a person is removed from WISA. Decommission them via your
  normal Moodle user-deletion process.
- **System-context bulk delete is a no-op** for the operational log table by design.
  Per-user erasure requests *are* honoured.
- **Log matching is heuristic.** `objectid` is a free-text field; the username/idnumber
  match can miss composite enrolment-log keys.
- **Credentials travel in the query string.** The WISA API takes `_username_`/`_password_`
  as URL parameters over HTTPS. They are masked in this plugin's logs, but they can still
  appear in the WISA/Schoolware web-server access logs and any intermediate proxy.

## Known limitations

- **Category set at creation only.** With `category_mode = from_feed` an existing course is not moved to a new category on later syncs.
- **One person, two roles → two accounts.** If the same person appears both as a cursist (USERNAME = numeric ll_id) and as a teacher (USERNAME = ps_code) with different idnumbers, they get two Moodle accounts. This follows the WISA feed design.
- **Cancelled courses are not removed.** A course cancelled in WISA (`AFGELASTOP`) is not read; it stays visible in Moodle until handled manually.
- **Manual sync runs synchronously.** The "Run manual sync now" button blocks the request for the full sync duration. For large datasets, prefer the CLI or scheduled task.

## Development

```
local/wisa/
├── version.php
├── settings.php
├── logs.php                      Admin: log viewer + last-run panel + manual-run button
├── preview.php                   Admin: preview + approve the first load (the gate)
├── test_connection.php           Admin: one-shot API test page
├── LICENSE                       GNU GPL v3
├── classes/
│   ├── api_client.php            HTTP client for the WISA QUERY API (credential masking)
│   ├── logger.php                DB logger (writes mdl_local_wisa_log)
│   ├── sync_manager.php          Orchestrator: lock + dry-run + per-part watermark + summary
│   ├── sync_stats.php            Counter struct passed to all sync classes
│   ├── sync/
│   │   ├── course_sync.php        MCVOD_C → mdl_course (+ school-year window)
│   │   ├── user_sync.php          MCVOD_LKR + MCVOD_STUD → mdl_user (idnumber match, validation)
│   │   ├── enrollment_sync.php    MCVOD_INS → manual enrol (global feed, run caching)
│   │   └── unenrollment_sync.php  MCVOD_UIT → suspend (with safety valve)
│   ├── task/
│   │   ├── sync_task.php          Scheduled: delta sync (default */5 min)
│   │   ├── initial_load_task.php  Adhoc: the approved first full load (runs in the background)
│   │   ├── log_cleanup_task.php   Scheduled: prune logs (default 03:15 daily)
│   │   └── reconcile_task.php     Scheduled: nightly full reconciliation (opt-in)
│   └── privacy/
│       └── provider.php           Privacy: metadata + export + delete for the log table
├── db/
│   ├── install.xml                local_wisa_log table
│   ├── upgrade.php                Upgrade steps (none yet)
│   └── tasks.php                  Scheduled task defaults
├── lang/
│   ├── en/local_wisa.php
│   └── nl/local_wisa.php
├── cli/test_sync.php             CLI: trigger one full sync
├── docs/                         Query contract + privacy/production notes
└── dist/local_wisa-v0.6.4.zip    Installable bundle for the Moodle UI
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

### v0.6.4 — 2026-06-16
- **Added:** name-derived e-mail addresses that contain diacritics (é, ü, ç, Á …) are now transliterated to ASCII on the local part before validation, so teachers and cursists with accented names still load with a valid address (e.g. `lpacquée@…` → `lpacquee@…`). Addresses without special characters are returned byte-identical, so no needless updates are triggered. Done in the plugin (robust) rather than in the WISA query.
- **Changed:** `$plugin->supported` extended to Moodle 5.1 (validated).

### v0.6.3 — 2026-06-15
- **Changed:** the first full load started from the **Approve** button now runs as a background (adhoc) task instead of synchronously in the web request — so even a large initial load can no longer hit the web-server timeout. The approval gate opens automatically once the background load finishes; the preview page shows a "load running" state in the meantime. (The CLI `--approve` still runs synchronously — it is not bound by the web timeout.)

### v0.6.2 — 2026-06-15
- **Added:** the enrolment sync can be split per role — separate switches *Enrol teachers in courses* and *Enrol cursists in courses* (both on by default). Each role keeps its own delta watermark, so turning one off and back on still processes the enrolments missed in between. Use it to give teachers access before cursists.

### v0.6.1 — 2026-06-14
- **Added:** first-load approval gate — the scheduled sync stays paused until the first full load is approved on the new **WISA preview & approval** page (field mapping + read-only preview counts). A successful live run (approval, manual button or CLI) opens the gate. CLI: `--preview` and `--approve`.

### v0.6.0 — 2026-06-14
- **Added:** per-part delta watermarks that only advance after a successful fetch (a failed call no longer skips records).
- **Added:** school-year scope filter (off / current / current + next).
- **Added:** per-part on/off switches.
- **Added:** unenrolment sync (`MCVOD_UIT`) that *suspends* leavers, with an absolute + percentage safety valve against bad feed data.
- **Added:** optional nightly full-reconciliation task (opt-in).
- **Added:** global enrolment feed (`MCVOD_INS`) replacing the per-course calls (no more N+1); course/user/enrol-instance caching per run.
- **Added:** Dutch language pack; English pack made fully English.
- **Added:** `db/upgrade.php`, GPL header on every file, GPL v3 `LICENSE`, `$plugin->supported`.
- **Changed:** licence MIT → GNU GPL v3 or later.
- **Security:** credentials masked in cURL/HTTP error logs as well as URLs; redirects no longer followed.
- **Hardening:** input validation of WISA data (email, username, dates, empty names), `is_array` guards on every fetch.

### v0.3.0 — 2026-06-13
- **Changed:** users matched **only** on `idnumber`; the username/email fallback was removed.
- **Added:** username-collision guard; `category_mode` (`fixed` | `from_feed`); optional `IDNUMBER` query field.

### v0.2.0 — 2026-04-28
- **Added:** dry-run mode, run-summary on logs page, lock against overlapping runs, daily log-retention cleanup, "Test WISA connection" page, privacy provider.
- **Fixed:** `&amp;` query separators (HTTP 401); missing `course/lib.php`/`user/lib.php` includes; `update_course` enddate-only payloads; password-length leak in the log; user creation hardening; lowercase username normalisation; per-record try/catch.

### v0.1.0
- Initial release.

## Licence

GNU GPL v3 or later — see [LICENSE](LICENSE). This is the same licence as Moodle.
