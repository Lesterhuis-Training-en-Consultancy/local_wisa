# local_wisa — Workflow Integration for SIS Adapters

Moodle local plugin that synchronises courses, users (teachers + cursists) and
enrolments from pluggable SIS source adapters into Moodle on a recurring schedule.
The parent plugin stays `local_wisa`; the first source adapter is
`sissource_wisa` for [WISA / Schoolware](https://www.wisa.be/).

- **Component:** `local_wisa`
- **Install path:** `local/wisa/`
- **Tested with:** Moodle 5.1
- **Supported:** Moodle 4.0-5.2 (build 2022041900+)
- **Status:** Beta. Validate every configuration change in dry-run mode first. The normal first-load path is approval in the UI, while the CLI is optional (see [Going live](#going-live)).
- **Licence:** GNU GPL v3 or later (same as Moodle).

## What it does

On each scheduled run (default: every 5 minutes) the parent plugin requests the
enabled stream/phase tuples declared by the active SIS source adapter, then
reconciles the returned generic course, user, enrolment and unenrolment records
with Moodle. Each tuple independently declares full or delta watermark behavior.
The WISA adapter translates configured MCVOD queries into that generic stream
contract; AthenaSoft declares streams for courses, placements and linked teachers.

| Default query | Returns | Maps to |
|---------------|---------|---------|
| `MCVOD_C`    | one row per course | Moodle course, matched on `idnumber = KLAS_ID` |
| `MCVOD_STUD` | changed cursists | Moodle user account, matched on `idnumber` |
| `MCVOD_LKR`  | changed teachers | Moodle user account, matched on `idnumber` |
| `MCVOD_INS`  | one global enrolment feed (`KLAS_ID`, `USERNAME`, `ROL`) | manual enrolments |
| `MCVOD_UIT`  | leavers/stops | suspends the matching enrolment |

For each generic source record the plugin creates the matching Moodle entity if it does
not yet exist, or updates the differing fields if it does. New users are created
with a creation-only initial password supplied by the active source when available;
otherwise they receive a random unusable password. The global **Force password change
for new users** setting defaults to enabled. Disabling it leaves a source-issued initial
password usable for login. Routine sync never changes an existing user's password or
force-change preference.

Users are matched **only** on `idnumber` — the stable, immutable SIS key supplied by the source adapter.
Matching on email or username is deliberately avoided: a shared or changed email
would otherwise merge distinct people onto one account.

## Features

- **First-load approval gate** — the scheduled sync is paused until an administrator reviews a preview (record counts + field mapping) and approves the first full load on the **SIS preview & approval** page. Nothing is written automatically before that.
- **Source-defined stream tuples** — each adapter declares its stream keys, phases, transports, labels, defaults and watermark modes. Every enabled stream/phase tuple has independent state, and its watermark advances only after that tuple succeeds.
- **School-year scope** — limit the sync to the current, or current + next, school year (1 September – 31 August). Courses and enrolments outside the window are skipped. Can be turned off.
- **Per-tuple on/off switches** — enable or disable each source-declared stream/phase tuple independently.
- **Suspend on unenrol (reversible)** — leavers reported by the active source are *suspended* in the course (status `ENROL_USER_SUSPENDED`), never hard-unenrolled. Grades and history are preserved, and the enrolment sync reactivates them if they return.
- **Unenrolment safety valve** — if a single run would suspend more than a configurable number *or* share of all active enrolments, nothing is suspended and an alarm is logged. Protects against bad source data.
- **Nightly full reconciliation (optional)** — a separate task can run a full (since-1900) sync once a night as a safety net for changes the delta could miss. Off by default.
- **Dry-run mode** — log every change the sync *would* make without writing to the database.
- **Credential safety** — WISA and AthenaSoft credentials are encrypted at rest. They may be supplied as plaintext through `$CFG->forced_plugin_settings`; forced values take precedence and are not revealed in the settings UI. API diagnostics use stable redacted codes and do not persist endpoints, query names, script IDs, payload snippets or transport errors. Redirect following is disabled so request credentials are not forwarded to a redirect target; deployment-level proxy and upstream logging still require separate review.
- **Lock against double runs** — uses `\core\lock\lock_config`; if a sync is still running, the next cron tick is skipped instead of stacking up.
- **Per-record fault isolation** — one bad source record does not abort the whole sync; failures are logged with status `fail` and the loop continues.
- **Run caching** — course, user and enrol-instance lookups are cached per run to keep the first full load within a sane query count.
- **Run summary** — every run logs a one-line summary and stores the last-run time, mode and counters so the admin UI can show status at a glance.
- **Log retention** — daily cleanup task deletes log entries older than the configured number of days (default 30).
- **Connection test page** — one-click "Test SIS source connection" button that queues a background course-fetch check for the active source and reports its status and record count. It is not proof of full production interoperability.
- **Privacy provider** — implements `\core_privacy`'s metadata, plugin and core_userlist providers for the plugin's log table. See [Privacy / GDPR](#privacy--gdpr) for what is and is not covered.
- **English + Dutch language packs.**

## Installation

### Option A — install current functionality from source

```bash
cd /path/to/moodle/local
git clone https://github.com/Lesterhuis-Training-en-Consultancy/local_wisa.git wisa
```

Then visit `https://your-moodle/admin/index.php` (or run `php admin/cli/upgrade.php`) to complete the installation.

## Configuration

After installation, go to **Site administration → Plugins → Local plugins → Workflow Integration for SIS Adapters** and choose the active SIS source. WISA / Schoolware remains the default for existing installations. Configure WISA connection settings on the **WISA / Schoolware** source settings page:

| Setting | Default | Notes |
|---|---|---|
| Active SIS source | `WISA / Schoolware` | Selects the source adapter used by the generic sync manager. |
| WISA API URL | `https://…schoolware.be/webwisad/bin/server.fcgi/QUERY/` | WISA source setting. Base URL up to `/QUERY/`; the adapter appends the query name. |
| API username / password | _empty_ | The `_username_` / `_password_` query-param credentials. Both values are stored encrypted. |
| Institute number | `123456` | The `instellingsnummer` to query. |
| Role mapping | `{"student":"student","teacher":"editingteacher","cursist":"student","leerkracht":"editingteacher"}` | Parent setting `local_wisa/rolemap`: JSON mapping from generic source tokens to Moodle role shortnames. |
| Force password change for new users | on | Parent setting `local_wisa/force_password_change`: applies only during user creation. Off leaves a source-issued initial password usable even when it does not satisfy Moodle's new-password policy. Existing users are unaffected. |
| Default course category | `1` | Moodle category ID for new courses (in `fixed` mode, or as fallback in `from_feed` mode). |
| Course category mode | `fixed` | `fixed`: every course in the default category. `from_feed`: use the `CATEGORY` field from the course query (a name, or a `Parent / Child` path), created if missing. |
| Query codes | `MCVOD_C` / `MCVOD_STUD` / `MCVOD_LKR` / `MCVOD_INS` / `MCVOD_UIT` | The WISA query names (Q_CODE), max 10 chars. Override per school. |
| School-year scope | current + next | `off`, current only, or current + next. |
| Source stream tuples | source-defined | Enable or disable the stream/phase tuples declared by the selected adapter. WISA declares five tuples; AthenaSoft declares six tuples across three streams. |
| Nightly full reconciliation | off | Run a full sync once a night as a safety net. Heavier than a delta run. |
| Dry-run mode | off | When on, sync logs intended changes but writes nothing. |
| Debug logging | off | Logs one stable redacted request marker per API call. Grows the log quickly. |
| Unenrolment safety limit (count) | 500 | Abort suspensions if a run would exceed this many. 0 = off. |
| Unenrolment safety limit (percent) | 50 | Abort suspensions if a run would exceed this share of active enrolments. 0 = off. |
| Log retention (days) | 30 | Sync log entries older than this are pruned daily at 03:15. |

### AthenaSoft source

To use AthenaSoft, set **Active SIS source** to `AthenaSoft`, then configure the **AthenaSoft** source settings page.

| Setting | Default | Notes |
|---|---|---|
| AthenaSoft API URL | `https://api-test.example.invalid/script` | Placeholder default. Configure the AthenaSoft-provided endpoint. Documented configuration patterns include `https://api-test.<DOMEINCENTRUM>.be/script` for test and `https://api-athenasoft.<DOMEINCENTRUM>.be/script` for live use. These are configuration guidance, not endpoint-validation rules. Moodle help strings escape the angle brackets as `&lt;DOMEINCENTRUM&gt;` so browsers display the literal placeholder; `<DOMEINCENTRUM>` is the domain-centre placeholder. |
| API key | _empty_ | Sent as the `Api-Authorization-Key` HTTP header and stored encrypted. |
| Authentication user / credential | _empty_ | Sent in the JSON request body as `auth.user` and `auth.cred`; both values are stored encrypted. |
| Institution number | `0` | Sent as `instellingsnummer` and used as the AthenaSoft course idnumber namespace. `0` means unconfigured and blocks requests. |
| Period | `0` | Sent as `periode`, for example `20262027`. `0` means unconfigured and blocks requests. |
| Courses script ID | `5` | AthenaSoft `planningen` script. |
| Placements script ID | `6` | AthenaSoft `plaatsingen` script for students, student enrolments and student-only unenrolments. |
| Teachers script ID | `7` | AthenaSoft `gekoppelde leerkrachten` script for teachers and teacher enrolments. |
| Extra request parameters (`extra_params`) | _empty_ | Optional JSON object merged into every request body, for example `{"school":"main","debug":false}`. Invalid JSON is ignored and logged. |
| Field mapping overrides (`fieldmap`) | _empty_ | Optional JSON object overriding AthenaSoft field names per record type, for example `{"course":{"shortname":"ovNaam"},"user":{"email":"emailadres","password":"wachtwoord"}}`. Initial passwords use API key `password` by default; override it with the exact key through `user.password`. The mapped password is used only during account creation. Empty JSON uses the built-in mappings. |

A 404 may indicate an IP-whitelist, access, or configuration issue. It does not automatically mean that the endpoint is missing.

After saving, click **Test SIS source connection** to check active-source configuration. A successful test does not prove full production interoperability.

### Scheduled tasks

| Task | Default schedule | Notes |
|---|---|---|
| SIS synchronisation task | `*/5 * * * *` | The delta sync. |
| SIS log cleanup | `15 3 * * *` | Prunes old log rows. |
| SIS nightly reconciliation | `30 4 * * *` | Only does work when "Nightly full reconciliation" is enabled. |

Change schedules via **Site administration → Server → Tasks → Scheduled tasks**.

## Going live

The scheduled sync stays **paused until you approve the first full load** — it
writes nothing to Moodle on its own. Approve it from the preview page:

1. Configure the plugin and active source, then verify with **Test SIS source connection**.
2. Go to **Site administration → Plugins → Local plugins → SIS preview & approval**.
3. Review the **field mapping** (which generic source records are read and where they land) and click **Show preview** to count what the first load would create — this writes nothing.
4. When you are happy, click **Approve and start the first full load**. This switches off test mode and **queues the first full load as a background task** — so even a large dataset cannot hit the web-server timeout. The page returns immediately and shows "load running"; follow the progress on the logs page. The gate opens automatically once the load finishes, and the scheduled delta sync takes over from then on.
5. Prefer the command line? Run the first load directly instead (synchronous, with live output): `php local/wisa/cli/test_sync.php --approve` (count first with `--preview`).
6. Optionally enable the nightly reconciliation once the delta sync has proven itself.

The background load is picked up by cron, so make sure cron is running (it normally fires within a minute). Until step 4 the scheduled task logs "initial full load not yet approved" and does nothing; while the background load runs the preview page shows "load running", and when it completes the gate opens. You can re-pause the sync any time with **Reset approval** on the preview page.

## Logs & operation

- **Site administration → Plugins → Local plugins → View SIS sync logs** — the most recent log entries, plus a last-run summary panel and a "Run manual sync now" button.
- All entries are stored in `mdl_local_wisa_log`. Entries older than the retention setting are deleted by `\local_wisa\task\log_cleanup_task` (cron, 03:15 daily).
- A `SAFETY STOP` line with status `fail` means the unenrolment safety valve aborted a run — check the source data or raise the limit.

## Privacy / GDPR

The plugin processes personal data (names, emails, usernames) coming from the active SIS source.
A `\local_wisa\privacy\provider` is included for parent-owned logs, and
`sissource_wisa\privacy\provider` declares the external WISA API metadata:

- `local_wisa` declares the `local_wisa_log` table,
- exports a user's log entries on data-export requests,
- deletes a user's log entries on data-deletion requests (matched heuristically on `objectid = username OR idnumber`).

**Important scope notes for a DPIA** (see also [`docs/privacy-en-productie.md`](docs/privacy-en-productie.md)):

- **Created accounts persist.** Moodle user accounts created by the sync are core
  `user` records (owned by Moodle's own privacy provider, not this plugin) and are
  **not** deleted when a person is removed from the SIS source. Decommission them via your
  normal Moodle user-deletion process.
- **System-context bulk delete is a no-op** for the operational log table by design.
  Per-user erasure requests *are* honoured.
- **Log matching is heuristic.** `objectid` is a free-text field; the username/idnumber
  match can miss composite enrolment-log keys.
- **Credentials travel in the query string.** The WISA source API takes `_username_`/`_password_`
  as URL parameters over HTTPS. They are masked in this plugin's logs, but they can still
  appear in the WISA/Schoolware web-server access logs and any intermediate proxy.

## Known limitations

- **Category set at creation only.** With `category_mode = from_feed` an existing course is not moved to a new category on later syncs.
- **One person, two roles → two accounts.** If the same person appears both as a cursist (USERNAME = numeric ll_id) and as a teacher (USERNAME = ps_code) with different idnumbers, they get two Moodle accounts. This follows the WISA feed design.
- **Cancelled courses are not removed.** A course cancelled in WISA (`AFGELASTOP`) is not read; it stays visible in Moodle until handled manually.
- **Manual sync runs in the background.** The "Run manual sync now" button queues an adhoc task that cron executes. Use the CLI for synchronous direct terminal execution when that is desired.

## Development

```
local/wisa/
├── version.php
├── settings.php
├── logs.php                      Admin: log viewer + last-run panel + manual-run button
├── preview.php                   Admin: preview + approve the first load (the gate)
├── test_connection.php           Admin: one-shot active source test page
├── LICENSE                       GNU GPL v3
├── CHANGES.md                    Changelog
├── classes/
│   ├── source_interface.php       Contract for SIS source adapters
│   ├── source_factory.php         Resolves the active sissource_* subplugin
│   ├── logger.php                DB logger (writes mdl_local_wisa_log)
│   ├── sync_manager.php          Orchestrator: lock + dry-run + per-part watermark + summary
│   ├── sync_stats.php            Counter struct passed to all sync classes
│   ├── sync/
│   │   ├── course_sync.php        generic course records → mdl_course
│   │   ├── user_sync.php          generic person records → mdl_user
│   │   ├── enrollment_sync.php    generic enrolment records → manual enrol
│   │   └── unenrollment_sync.php  generic leaver records → suspend
│   ├── task/
│   │   ├── sync_task.php          Scheduled: delta sync (default */5 min)
│   │   ├── initial_load_task.php  Adhoc: the approved first full load (runs in the background)
│   │   ├── log_cleanup_task.php   Scheduled: prune logs (default 03:15 daily)
│   │   └── reconcile_task.php     Scheduled: nightly full reconciliation (opt-in)
│   └── privacy/
│       └── provider.php           Privacy: metadata + export + delete for the log table
├── db/
│   ├── install.xml                local_wisa_log table
│   ├── subplugins.json            declares sissource subplugins under source/
│   ├── upgrade.php                config migration to sissource_wisa
│   └── tasks.php                  Scheduled task defaults
├── lang/
│   ├── en/local_wisa.php
│   └── nl/local_wisa.php
├── source/
│   ├── wisa/                     sissource_wisa adapter, API client, settings, tests
│   └── athenasoft/               sissource_athenasoft adapter
├── cli/test_sync.php             CLI: trigger one full sync
└── docs/                         Query contract + privacy/production notes
```

## Changelog
Please see [CHANGES.md](CHANGES.md).

## Licence

GNU GPL v3 or later — see [LICENSE](LICENSE). This is the same licence as Moodle.
