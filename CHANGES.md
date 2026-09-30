### v0.10.4 - 2026-09-16 - first presented version by Sebsoft
- **Changed**: Finished all changes we wanted to complete to make sure the state is releasable

### v0.8.0 — unreleased first version by Sebsoft
- **Added**: sissource_athenasoft — AthenaSoft source adapter (scripts planningen/plaatsingen/gekoppelde leerkrachten), configurable settings, source-defined stream tuples, client-side delta filtering and stable redacted API diagnostics.

### v0.7.0 — unreleased refactor to new architecture by Sebsoft
- **Changed:** parent plugin repositioned as Workflow Integration for SIS Adapters. The generic sync manager now resolves an active `sissource_*` adapter through a factory instead of constructing a WISA API client directly.
- **Added:** Moodle subplugin type `sissource` under `local/wisa/source`, with `sissource_wisa` as the first working adapter and `sissource_athenasoft` as a scaffold.
- **Changed:** WISA API/query settings moved from `local_wisa` to `sissource_wisa`; the parent upgrade step migrates existing config and keeps WISA as the default active source.
- **Added:** Initial test set

### v0.6.4 — 2026-06-16
- **Added:** name-derived e-mail addresses that contain diacritics (é, ü, ç, Á …) are now transliterated to ASCII on the local part before validation, so teachers and cursists with accented names still load with a valid address (e.g. `lpacquée@…` → `lpacquee@…`). Addresses without special characters are returned byte-identical, so no needless updates are triggered. Done in the plugin (robust) rather than in the WISA query.
- **Changed:** `$plugin->supported` extended to Moodle 5.1 (validated).

### v0.6.3 — 2026-06-15
- **Changed:** the first full load started from the **Approve** button now runs as a background (adhoc) task instead of synchronously in the web request — so even a large initial load can no longer hit the web-server timeout. The approval gate opens automatically once the background load finishes; the preview page shows a "load running" state in the meantime. (The CLI `--approve` still runs synchronously — it is not bound by the web timeout.)

### v0.6.2 — 2026-06-15
- **Added:** the enrolment sync can be split per role — separate switches *Enrol teachers in courses* and *Enrol cursists in courses* (both on by default). Each role keeps its own delta watermark, so turning one off and back on still processes the enrolments missed in between. Use it to give teachers access before cursists.

### v0.6.1 — 2026-06-14
- **Added:** first-load approval gate — the scheduled sync stays paused until the first full load is approved on the new **SIS preview & approval** page (field mapping + read-only preview counts). A successful live run (approval, manual button or CLI) opens the gate. CLI: `--preview` and `--approve`.

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