# Security/privacy entrypoint audit - local_wisa

Scope: `logs.php`, `preview.php`, `test_connection.php`, `cli/test_sync.php`, `settings.php`, `classes/api_client.php`, `classes/privacy/provider.php`.

## Web entrypoints

| Entrypoint | Access control | State-changing action protection | Privacy/output notes | Result |
| --- | --- | --- | --- | --- |
| `logs.php` | `admin_externalpage_setup()` plus explicit `moodle/site:config` at system context | Manual sync requires `require_sesskey()` and is rendered as a POST button | Persisted log values are escaped with `s()` before rendering | Hardened |
| `preview.php` | `admin_externalpage_setup()` plus explicit `moodle/site:config` at system context | `preview`, `approve`, `doapprove` and `reset` require `require_sesskey()`; mutating buttons are POST-backed | Preview count values are escaped before rendering | Hardened |
| `test_connection.php` | `admin_externalpage_setup()` plus explicit `moodle/site:config` at system context | The live WISA request only runs after a sesskey-protected POST-style button | The page no longer calls WISA just by opening it; sample response is escaped JSON | Hardened |
| `settings.php` | Moodle admin settings tree, site-config users only | No custom mutation outside Moodle admin settings | API password uses Moodle password setting; connection test link opens guarded page | OK |
| `cli/test_sync.php` | CLI-only via `CLI_SCRIPT` | CLI operator action, no web sesskey applicable | Outputs operational status only | OK |

## API and logging privacy

- WISA credentials remain stored in plugin config and are masked before any URL, cURL error, or HTTP response snippet is written to `local_wisa_log`.
- cURL redirects are disabled so query-string credentials are not forwarded to another host.
- Debug URL logging remains opt-in via `debug_logging`.
- The connection test uses the course query only and escapes the sample record.

## Privacy provider

- Metadata declares `local_wisa_log` and the external WISA API location.
- Context discovery is limited to system context for users whose username or idnumber appears in log rows.
- Export returns only the approved user's matching log rows.
- Deletion now respects the approved context list.
- Context-wide deletion removes user-linked log rows while preserving operational system rows that are not linked to a user.

## PHPUnit coverage added

- `tests/security_entrypoints_test.php` asserts the web entrypoints keep their admin setup, site-config capability checks, sesskey gates, and log escaping.
- `tests/privacy/provider_test.php` covers metadata, context discovery, userlist discovery, export, single-user delete, approved-user-list delete, and context-wide delete.
