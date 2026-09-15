# Work plan (historical)

This is the original integrity/refactor list written for the 1.2.3 → 1.3.0 work. Items 1–15 and the test harness below were implemented. The text is kept as the record of what was required, not as a current todo. Current behaviour lives in [architecture.md](architecture.md) and the other `docs/` pages.

Tests: `bash tests/run.sh` as a normal user. No `pkexec`, no writing to `/var/www`.

**Constraints**

- **i18n:** WordPress locale (site + user) owns the language. No plugin language switcher. No bundled `.po`/`.mo` that override WP language packs. Use WordPress’s own translated strings (`$wp_locale`, `translate_user_role`, `date_i18n`) instead of re-translating weekdays and months in this text domain.
- **Refactor:** split `TPFW_Functions`, one issue policy, shared order-line upsert, split product classes by when they run, file front-controller — but **after** the integrity bugs, and always behind tests.
- **Do not** rewrite the plugin, rename Hungarian notation, or touch vendor trees except to correct version claims.

---

## 1. Guest-pass quota race — release blocker

**Where:** `inc/pass-wc-myaccount/class--pass-wc-myaccount.php` (count then INSERT, no lock). Schema: `UNIQUE(nano_id)` only.

**Do:** Enforce the quota in the database, not only in PHP. Prefer `UNIQUE(parent_nano_id_fk, guest_slot)` with slots `1…N`, or a transaction + `GET_LOCK('tpfw_guest_' . parent_nano_id)` around count+insert. Parallel AJAX from the holder must still end at N rows.

**Tests:** Two concurrent “create guests” calls on a pass with quota 2 must yield 2 rows, never 4. Also: already at quota → no extra insert.

## 2. Guest passes valid before parent check-in — release blocker

**Where:** Same file sets `valid_from`/`valid_to` at mint. `scanner_checkin_guest` only checks that the parent row exists. `activate_guest_passes()` runs too late.

**Do:** Mint guests with `valid_from`/`valid_to` NULL. Refuse guest check-in until the parent has at least one non-deleted stats row. Activate the window **inside** the parent’s check-in lock, atomically with the parent stats INSERT.

**Tests:** Guest QR before parent scan → 202. After parent scan → 200 and a window. Parent cancelled → guest still refused.

## 3. Timeslot overbooking — release blocker

**Where:** Add-to-cart validates capacity then INSERTs a reservation later (`class--timeslot-ticket-wc-product.php`). Issue path counts sold then INSERTs tickets; a `bStatus => false` becomes an order note on an already-completed paid order.

**Do:** `SELECT … FOR UPDATE` on the timeslot row (or equivalent named lock) covering capacity + reservation write, and the same lock at issue time. If issue cannot fit, do not leave a completed order without a ticket or with a silent note only — fail visibly and keep the seat consistent.

**Tests:** Two concurrent add-to-carts for the last seat → one reservation. Issue of quantity that does not fit → no extra `tpfw_timeslot_tickets` rows. Paid order that cannot be fulfilled is asserted (note + no silent success).

## 4. Check-in lock fail-closed

**Where:** `with_checkin_lock()` refuses only `GET_LOCK() === '0'`. `NULL` (lock unavailable) continues without serialisation.

**Do:** Treat anything other than `'1'` as failure: 202 (or 503) and do not check in. Log once so an admin can see the DB does not support named locks.

**Tests:** Mock `GET_LOCK` → `'0'` and `NULL` both skip the INSERT. `'1'` still inserts once under contention.

## 5. Profile-image megapixel limit

**Where:** `class--pass-wc-myaccount.php` caps file bytes, then `imagecreatefrom*` / Imagick decode with no pixel cap.

**Do:** After `getimagesize()`, refuse if width/height &gt; 8000 or width×height &gt; 20e6, **before** decode. Set Imagick resource limits. Keep MIME + re-encode.

**Tests:** A tiny-byte fake with huge claimed dimensions is rejected. A normal JPEG under the cap is accepted. No need to allocate a 200 MP buffer.

## 6. Check-in is POST, not GET

**Where:** REST `GET /scanner/checkin/{nano_id}` and `/guest`.

**Do:** Register POST as the mutating method. Built-in scanner and docs/API settings switch to POST + nonce. Keep GET as 405 (or a short deprecation window that does not mutate). History and `.ics` stay GET.

**Tests:** POST checks in. GET does not insert a stats row. Scanner JS sends POST.

## 7. Private files without `.htaccess` or a guessable path

**Where:** `.htaccess` is ignored on nginx/IIS. Profile dir can leave the random slug (`get_profile_image_upload_dir`). File hits boot all of WordPress.

**Do:** Keep HMAC/session gate. Put QR/PDF/profile **under the random slug only** (no separate public profile path). Deny direct `/uploads/tpfw-*` in the bundled nginx snippet. Optional later: a small `tpfw-file.php` front-controller that verifies HMAC and streams without loading the rest of the plugin.

**Tests:** Unsigned URL for a known nano id is 404. Signed URL works. Profile files resolve under the slug, not a custom web-reachable path.

## 8. Scanner response allowlist

**Where:** Success payload includes `'ticket' => $oRow` (user_id, order_id, payer, …).

**Do:** Return only what the door UI needs: message, colour, type, holder display name, photo URL, uses remaining, validity window. No raw row.

**Tests:** JSON fixture has no `user_id` / `order_id` / `user_payer_id`. Scanner still paints success + photo.

## 9. WordPress owns the language

No plugin locale setting. `WPLANG` / user locale decide.

**Do:**

- Keep a single text domain; regenerate the POT after string changes (`wp i18n make-pot`). Do not ship `.mo` that fights language packs.
- If a `.mo` in `wp-content/languages/plugins/` must load for a non-wordpress.org install, call `load_plugin_textdomain()` so **WP’s** files win; do not load from the plugin zip.
- Datepicker: `$wp_locale` (as timeslot admin already does), not `__('Sunday')` in this domain.
- Scanner dummy results and role labels: gettext / `translate_user_role()`, not frozen English.
- Default email bodies: built with `__()` **at send time**. Stored options only when the shop has edited the template.
- Concatenate with `sprintf`, not `'Ticket ID: ' . $id`.

**Tests:** Under `locale = sv_SE` with a test `.mo` in `wp-content/languages/plugins/` (created by the test, no root), a known msgid translates. Switching locale back to `en_US` restores English. Datepicker month names match `$wp_locale`. Unedited email template follows locale; an edited option stays as saved.

## 10. One issue policy (paid vs completed)

**Where:** All types hook only `woocommerce_order_status_completed`. COD/virtual can sit in `processing` with stock taken and no plugin rows.

**Do:** One `IssuePolicy` (or equivalent) used by ticket, timeslot and pass: when to mint, when to revoke. Virtual/ticket products should mint when paid if that is the chosen rule — not three copied hooks.

**Tests:** COD ticket order in `processing` either mints (if policy says so) or does not, consistently. `completed` / `cancelled` / `refunded` still issue and revoke. Timeslot issue uses the same policy **inside** the capacity lock from item 3.

## 11. Shared upsert per order line

**Where:** Ticket / timeslot / pass each copy exist → undelete up to qty → soft-delete surplus → insert shortfall.

**Do:** One helper; three `create_entry()` calls. Preserve original `nano_id`s on completed → refunded → completed.

**Tests:** Qty 3 → 3 rows. Refund → 3 soft-deleted. Complete again → same three nano ids, not six. Qty 3 then 1 → two remain deleted.

## 12. Split `TPFW_Functions`

After 1–11 so check-in, files and mail have tests.

**Do:** Extract `Checkin`, `Files`, `QrPdf`, `MailCopy`, `Timeslots`, `AdminUi`. Leave a thin `TPFW_Functions` facade until callers move.

**Tests:** Existing check-in, file-token and mail-placeholder tests still pass against the facade.

## 13. Split product classes by when they run

**Do:** Per type: editor (panels/save/QR tab) / checkout (validation, reservation, person rows) / lifecycle (`create_entry` / `cancel_entry`). Timeslot first (largest). No new framework.

**Tests:** Add-to-cart validation, reservation, and issue tests still pass with the new class names.

## 14. Revocable scanner credentials (later)

**Where:** External API uses `wp_authenticate` + a reusable WordPress password.

**Do:** Per-device tokens with `tpfw_scanner` scope, rotatable without changing the WP user password. Cookie+nonce path for `/check-in/` stays.

**Tests:** Valid token checks in; revoked token is 401; WP password path can be disabled when tokens are required.

## 15. Bundled library versions in `readme.txt`

**Do:** Generate the bundled-lib list from `inc/functions/lib/*/composer/installed.json` (endroid 4.8.2, bacon 2.0.8, etc., not the stale 5.x/3.x lines). Dompdf 3.1.6 stays if that is what is on disk.

**Tests:** A script or PHPUnit assertion that every version in `readme.txt` exists in `installed.json`.

---

## Test harness (done, used for items 1–15)

- PHPUnit (or WP’s test install **in the project**, not as root) + the existing Node-shaped `parse-checkin-url.js`.
- DB: the local MariaDB user already in `.wp-credentials`, or a disposable test schema that user can create. No `pkexec`.
- Parallelism tests for items 1 and 3: two PHP processes or `$wpdb` connections, not sleeps in the browser.
- Fixture products/orders built in `setUp`, torn down in `tearDown`.

Suggested run: `composer test` / `npm test` from the plugin directory as user `ricande`.
