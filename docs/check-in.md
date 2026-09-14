# Check-in

Overview: [architecture.md](architecture.md). REST table: [data.md](data.md).

There is **one** implementation: `TPFW_Functions::checkin()`. The door page, REST clients, admin dashboards and My Account all end there.

## What a QR contains

Generated in `TPFW_Functions` as:

`{get_rest_url()}tpfw/v1/scanner/checkin/{nano_id}`

Guest passes append `/guest`. This is **not** a page. Opening it in Safari without auth returns 401.

## Scanner page (`/check-in/`)

`TPFW_Scanner` registers `^check-in/?$` and alias `^checkin/?$`. On `template_redirect` it exits with its own HTML — no theme, no admin bar.

- Guest → `auth_redirect()` back to `/check-in/` after login
- Logged in without `user_can_scan()` → 403 + `access-denied.css`
- Allowed: camera UI (`scanner.js`)

`user_can_scan()` is the `tpfw_scanner` role **or** `manage_woocommerce` (Shop Manager and Administrator).

The page never fetches the scanned URL. `parse-checkin-url.js` extracts `{ sNanoID, bGuest }` from:

- an exact match against the site’s own check-in base
- `/wp-json/tpfw/v1/scanner/checkin/{id}` on **any** host
- the ugly `?rest_route=/tpfw/v1/scanner/checkin/{id}` form

Scheme and host are ignored so a ticket issued on `woocommerce.local` still checks in from a LAN IP. A random URL that is not this plugin’s check-in path is refused. The page then **POST**s `sCheckinBase + nano_id` on **its own origin** with the `wp_rest` nonce.

## REST auth (`tpfw/v1`)

`TPFW_API::scanner_permission()` → `get_basic_auth_user()`:

| Caller | How |
|---|---|
| Built-in scanner | Login cookie **and** valid `X-WP-Nonce`. Core’s `rest_cookie_check_errors()` clears the user if the nonce is missing, so a CSRF request from another site cannot check anyone in. |
| External app | `Authorization: Basic` (or `PHP_AUTH_USER` / `PHP_AUTH_PW`). Goes through `wp_authenticate()` (login throttling / locked accounts apply). Only if **Enable API** is on. |
| Scanner token | Header `X-TPFW-Scanner-Token`. Looked up in `tpfw_scanner_tokens` (`TPFW_Scanner_Tokens`). Also requires **Enable API**. When `tpfw_scanner_tokens_required` is set, Basic Auth with a password is refused. There is no settings form yet; create a token with `TPFW_Scanner_Tokens::create($name, $user_id)` (plaintext is returned once). |

Either path still requires `user_can_scan()`. The resolved user is `wp_set_current_user()` so the stats row records who scanned.

`GET /timeslot-ticket/ics/{nano_id}` is open: permission_callback is `__return_true`. The nano id is the unguessable secret.

`nano_id` is validated to `[A-Za-z0-9_-]{1,32}` before any query.

## Lookup

`POST /scanner/checkin/{nano_id}` probes, in order: `tpfw_pass`, `tpfw_timeslot_tickets`, `tpfw_tickets` (`TPFW_API::CHECKIN_TABLES`). Soft-deleted rows are ignored. The same path registered as GET calls `scanner_checkin_not_allowed()` and returns **405** with `Allow: POST` — no stats row.

A pass row with `parent_nano_id_fk` is treated as `guestpass` even on the plain route (so dropping `/guest` cannot skip the parent-alive check or apply the wrong cooldown). `/scanner/checkin/{id}/guest` requires a guest row **and** a live parent.

## Decision order (`checkin_row`)

Wrapped in `with_checkin_lock()` → `TPFW_Named_Lock`: MySQL `GET_LOCK('tpfw_checkin_{nano_id}', 5)`. Only `'1'` / `1` is a held lock. Timeout (`0`) and unavailable (`NULL`) both refuse — **202**, no INSERT. Unavailable is logged once. `RELEASE_LOCK` runs in `finally` (persistent connections would otherwise wedge that code).

Nothing writes until every check passes.

1. Unknown type → 401
2. Outside `valid_from`–`valid_to` → 202 (skipped when `$bManual` is true — admin / My Account)
3. Product is the wrong WC class → 401
4. Soft-deleted stats count ≥ `max_uses` → 202
5. Cooldown (`*_cooldown_sec` on the product) still running → 202, with `iCooldownOver`
6. `INSERT` into the type’s `*_stats` table. Query failure → 401 (use is **not** consumed)
7. Type `pass` → `activate_guest_passes()` (sets guest windows to now + guest duration)
8. Success body is `TPFW_Checkin_Payload::allowlist()` (HTTP 200): `sMessage`, `sHexColor`, `sType`, `sHolderName`, `sPhotoURL` (pass/guest), `max_uses`, `iUsesRemaining`, `valid_from`, `valid_to`. No `user_id`, `order_id`, `user_payer_id` or raw `ticket` row.

HTTP meaning, as the API settings screen documents:

| Status | Meaning |
|---|---|
| 200 | Valid, use recorded |
| 202 | Refused for a reason door staff must read (window, uses, cooldown, lock, guest not yet activated) |
| 401 | Does not add up (missing code, wrong type, insert failed, auth denied, guest with no live parent) |
| 405 | GET on a check-in route — nothing written |

Colours `status_200` / `status_202` / `status_406` come from `tpfw_api_settings_options`.

## History

`GET /scanner/history` returns the 50 most recent successful check-ins **by this scanner user** (not by ticket holder), across the three stats tables.
