# Install, upgrade and uninstall

Shop-owner steps also live in [`readme.txt`](../readme.txt) (`== Installation ==`). This page is the exact behaviour of the code.

## Requirements

| | |
|---|---|
| WordPress | 6.5 or newer |
| PHP | 8.0 or newer |
| WooCommerce | must be installed and active (`Requires Plugins: woocommerce`) |
| HTTPS | required for the phone camera on `/check-in/` |

The plugin declares compatibility with WooCommerce High-Performance Order Storage and the block cart/checkout. There is no Composer or npm build step to run the plugin.

Activation refuses (and deactivates) if WooCommerce is not active. If WooCommerce is later turned off, an admin notice appears and this plugin deactivates itself.

## Fresh install

1. Upload the zip from **Plugins → Add New → Upload Plugin**, or copy the unzipped folder into `wp-content/plugins/`. The folder name does not have to match the plugin slug; paths are derived from `__FILE__`.
2. Activate with WooCommerce already active.
3. Activation runs `TPFW_DB_Installer` (nine tables) and creates the random upload folder `uploads/tpfw-{slug}/` plus its type subfolders. It does **not** flush permalinks on that request — activation runs after `init`, so `/check-in/` and the My Account endpoints are not registered yet. The rewrite version option is cleared instead.
4. The next normal page load (any front or admin request) flushes rewrite rules once `TPFW_REWRITE_VERSION` is recorded. `/check-in/`, `/checkin`, `/tpfw-tickets` and `/tpfw-pass` work from then on. If the scanner 404s immediately after activate, load any other page or resave **Settings → Permalinks**.
5. Product types start **off**. Enable the ones you sell under **Ticket & Passes → Settings** on the Ticket, Timeslot Ticket and Pass tabs.
6. The built-in scanner and the external API default **on** when their keys have never been saved. The **Scanner / API** tab shows the door URL (`/check-in/` on this site).
7. Add door staff as WordPress users with the **Scanner** role — see [Scanner users](#scanner-users) below.

## Scanner users

Check-in rights are WordPress roles, not a plugin setting. There is no “add scanner” screen under Ticket & Passes.

**Who may scan** (`TPFW_Functions::user_can_scan()`):

| WordPress user | `/check-in/` and REST check-in | Dashboards, settings, order Create/Cancel |
|---|---|---|
| Role **Scanner** (`tpfw_scanner`) | Yes | No — capability is only `read` |
| Administrator or Shop Manager (`manage_woocommerce`) | Yes | Yes |
| Anyone else | No (403 on the page, 401 on REST) | No |

**How the role appears.** `maybe_register_scanner_role()` runs from `TPFW_Functions` on plugin load. When option `tpfw_scanner_role_version` is not `SCANNER_ROLE_VERSION` (`2`), it calls `add_role('tpfw_scanner', 'Scanner', array('read' => true))` and stores the version. That is a one-time write. Deleting the role in WordPress does **not** recreate it on the next request.

**How to add a door person**

1. Wait until the plugin has loaded at least once (so **Scanner** exists in the Role dropdown).
2. **Users → Add New**, or edit an existing user.
3. Set **Role** to **Scanner**. Save.
4. They sign in at `/check-in/` (HTTPS). They never need wp-admin.

Use one account per door or device: `*_stats.user_id` is the scanner, not the ticket holder. The **Scanner** submenu under Ticket & Passes requires `manage_woocommerce`, so door staff do not see it — they type the URL.

External apps authenticate as the same kind of user via a WordPress **Application Password** (HTTP Basic) or `X-TPFW-Scanner-Token` bound to a user id that already `user_can_scan()`. Do not store the account login password. Tokens have no settings UI; see [check-in.md](check-in.md).

## Upgrade from 1.2.3 to 1.3.0

Replace the plugin folder (or upload the new zip over the old one). Do not deactivate first.

The first request after the swap runs the installer because `tpfw_db_version` is not `1.0.4` yet:

- `CREATE TABLE IF NOT EXISTS` — no-op on tables that already exist
- `guest_slot` column on `tpfw_pass`, deterministic backfill of slots on **all** guest rows (including soft-deleted 1.2.3 rows), unique index `(parent_nano_id_fk, guest_slot)`. A failed backfill leaves the version unchanged.
- Restoring a parent pass reconciles guests to the **current** product quota (slots `1…N`); leftover historical guests stay deleted.
- extra indexes on stats, row tables and timeslots if they are missing

Existing tickets, passes, QR codes, PDFs and `tpfw_upload_slug` keep working. Guest rows that already have `valid_from` stay valid. **New** guest passes stay inactive until the holder is scanned.

## Upgrade to 1.3.4 (schema 1.0.5)

**1.3.4 RC** is a pre-release for review and staging, not a stable WordPress.org build. It replaces **1.3.3 RC**. Plugin version is 1.3.4. Database schema version is 1.0.5. There is no new table definition. There is no per-request health probe and no separate repair option.

Upload the 1.3.4 zip over 1.3.3, 1.3.2, 1.3.1 or 1.3.0. Leave the plugin active if it already is. The first request after the swap runs `install()` because `tpfw_db_version` is not `1.0.5` yet.

**Why.** Older installer code could record `1.0.4` even when a required `CREATE`/`ALTER` had failed. `schema_is_current()` trusts that marker, so those sites skip `install()` forever until the constant differs.

**What a 1.0.4 → 1.0.5 pass repairs**

- A **missing table** among the nine (`CREATE TABLE IF NOT EXISTS`).
- Column `tpfw_pass.guest_slot` (`maybe_add_column`).
- Indexes `user_created` on `tpfw_tickets_stats`, `tpfw_timeslot_tickets_stats`, `tpfw_pass_stats`; `created` on `tpfw_tickets`, `tpfw_timeslot_tickets`, `tpfw_pass`; `product_start` on `tpfw_timeslots`; unique `parent_guest_slot` on `tpfw_pass` (`maybe_add_index` / `maybe_add_unique_index`).
- Guest-slot **backfill** of NULL/0 slots. Already assigned slots stay as they are.

`CREATE TABLE IF NOT EXISTS` does **not** reshape a table that already exists. Arbitrary missing columns or indexes beyond the list above are not added. Rows that lived in a table that was dropped are **not** restored.

**On failure.** The stored version stays `1.0.4` (or whatever it was). The next request retries. Activation still constructs the installer once and reads `schema_is_current()`; it does not run a second `maybe_install()` to hide a first failure. Ordinary page loads still do not `wp_die`.

A healthy `1.0.4` site: the pass is a no-op on existing objects, live tickets/passes/guest slots keep their identities and relations, then the marker becomes `1.0.5`. A later load with `1.0.5` already stored does not start another install pass.

## Upgrade to 1.3.3 (activation)

**1.3.3 RC** is a pre-release for review and staging, not a stable WordPress.org build. It replaces **1.3.2 RC** for a fatal that blocked activation.

**Symptom.** Activating 1.3.2 with WooCommerce already active fatals:

`Class "TPFW_Guest_Pass_Issuer" not found` in `inc/db-installer/class--db-installer.php` (the `backfill_legacy_slots()` call).

**Cause.** `tpfw_activate_plugin()` constructed `TPFW_DB_Installer` before `inc/support/load.php`. The installer constructor runs `maybe_install()` → `install()` → `TPFW_Guest_Pass_Issuer::backfill_legacy_slots()`. WooCommerce being present is not enough; this is load order on `register_activation_hook`.

**Fix.** Activation now requires `inc/support/load.php` first, then constructs the installer — the same order as `TPFW_Main`. Database schema version stays `1.0.4`. QR rewrite behaviour from 1.3.2 is unchanged.

Upload the 1.3.3 zip over 1.3.2, 1.3.1 or 1.3.0. Leave the plugin active if it already is. If 1.3.2 never activated, upload and activate with WooCommerce already active.

Verification of this RC: PHPUnit `FunctionsFacadeTest` asserts that `tpfw_activate_plugin()` includes `inc/support/load.php` before `new TPFW_DB_Installer`. On a local WordPress 7.1 (sv_SE) + WooCommerce 11.1.0 + PHP 8.3.6 guest, 1.3.2 failed on **Plugins → Activate** with that fatal. After the load-order fix, `wp plugin activate` succeeded, created the nine tables, recorded `tpfw_db_version` `1.0.4`, and a second activate with that option cleared (so `install()` and backfill ran again) produced no new fatal.

## Upgrade to 1.3.2 (QR rewrite)

**1.3.2 RC** is a pre-release for review and staging, not a stable WordPress.org build.

Upload the 1.3.2 zip over 1.3.1 or 1.3.0. Leave the plugin active. The first page load records `TPFW_Qr_Render::RENDER_VERSION` if this site has not yet, and queues a one-shot background repair of live QR images (Action Scheduler, or WP-Cron if Action Scheduler is not available). Product settings do not have to change. The shop does **not** send a new mail.

QR files with a centre logo use high error correction and a longer-side cap so they remain scannable at email size. Issued images are rewritten in bounded background batches when colours, logo, label or the renderer version change. Unchanged product saves do not start a new job. Concurrent rewrite jobs are serialised; a failed write leaves the previous file; unwritable retries are capped. QR, guest and PDF URLs and ETags identify the file by content hash.

Already-sent emails and already-downloaded PDFs are not updated retroactively. Customers get the current code from **My Account**, a **new PDF download**, or a dashboard **Resend**.

A product save queues another rewrite when the look actually changed, a previous rewrite job failed, or a waiting/running job has no remaining scheduled action (including a stranded `queued` job with an empty last error). Saving the product is how a stranded job is resumed. A save that finds the same job already queued or running with a pending action does nothing.

Local live checks of this RC used WordPress, WooCommerce, Action Scheduler and HTTP. Product saves in that verification were run through WP-CLI, not a wp-admin POST.

## Upgrade to 1.3.1 (QR look)

The first request after the update records `TPFW_Qr_Render::RENDER_VERSION` and queues a background repair of live QR images (Action Scheduler, or WP-Cron if Action Scheduler is not available). Product settings do not have to change. The shop does **not** send a new mail. Customers get a current code from **My Account**, a **new PDF download**, or a dashboard **Resend**. Inbox messages already sent, and PDFs already saved on a phone, cannot be updated retroactively.

A product save queues another rewrite when colours, logo, label or the renderer version actually changed, a previous rewrite job failed, or a waiting/running job has no remaining scheduled action. A save that finds the same job already queued or running with a pending action does nothing.

Behaviour changes the shop should know:

- Check-in is **POST only**. An authenticated GET answers **405** and does not write a stats row. The built-in scanner already POSTs. An external app that still GETs must switch.
- WooCommerce `payment_complete` (paid) and **Completed** mint QR rows. Processing alone does not, so an unpaid cash-on-delivery checkout waits until Completed or the order metabox **Create**. Orders that sat in Processing under 1.2.3 without rows are not backfilled.
- A refund that includes **item quantity** reduces the number of live tickets/passes to purchased minus refunded items. A refund of amount only (no item quantity) does not remove codes; mark the order Refunded or use Cancel if every code should drop.
- External apps use a WordPress Application Password (HTTP Basic; WordPress authenticates) or `X-TPFW-Scanner-Token` as `{token_id}.{secret}`. The account login password is not accepted. Tokens created during 1.3.0 development as an opaque hex secret (no `token_id.` prefix) no longer work; create new ones. The secret is never stored.

Swedish (`sv_SE`) and Danish (`da_DK`) ship as fallbacks under `languages/`. A language pack in `wp-content/languages/plugins/` still wins. The shop language is the WordPress locale; there is no plugin language switcher.

## Deactivate vs delete

**Deactivate** clears the timeslot cron hooks, QR rewrite batch/sweep hooks, and flushes rewrite rules so `/check-in/` and the My Account tabs 404 cleanly. Settings, tables and files stay. Reactivating picks up the same `tpfw_db_version` and upload slug.

**Delete** (uninstall) always removes this plugin’s options, the Scanner role and the cron events. Tables and generated files stay unless `wp-config.php` has:

```php
define( 'TPFW_REMOVE_ALL_DATA', true );
```

That drops the nine tables, `_tpfw_*` product meta, `tpfw_*` order-item meta, and the upload folder. It cannot be undone. On multisite, uninstall walks every site.

## Developer checkout

The plugin is ordinary PHP/JS/CSS. A local WordPress install can symlink the folder into `wp-content/plugins/`.

On **nginx**, include `docs/server-config/nginx-deny-tpfw-uploads.conf` in the WordPress `server` block so `/wp-content/uploads/tpfw-*` cannot be fetched as a static file. `.htaccess` is ignored. On **IIS**, add an equivalent deny. Apache can use the plugin-written `.htaccess`. See [files-and-access.md](files-and-access.md) and [server-config/README.md](server-config/README.md). The plugin’s `?tpfw_file=` route is unchanged.

Tests do **not** boot WordPress. From the plugin directory, as a normal user:

```bash
bash tests/run.sh
```

That fetches PHPUnit 11.5.56 into `tests/phpunit.phar` if needed (`tests/ensure-phpunit.sh`), then runs PHPUnit (including concurrency workers under `tests/bin/` and package-contract checks) and `node --test tests/js/*.test.js`. No `pkexec`, no writes under `/var/www`. See [release.md](release.md). Local `require-dev` patches on two bundled Composer manifests are listed in [vendor-patches.md](vendor-patches.md).

Database-backed tests (guest quota, timeslot capacity) open MariaDB through `.wp-credentials` (`DB_NAME`, `DB_USER`, `DB_PASSWORD`, optional `DB_HOST`). The file is looked up at `../.wp-credentials` or `../../.wp-credentials` from the plugin root, or at `TPFW_TEST_CREDENTIALS`. Do not commit it.

Production ZIP (runtime only, no tests or credentials):

```bash
bash scripts/build-plugin-zip.sh
```

Writes `dist/tickets-passes-for-woocommerce-1.3.4.zip`. See [release.md](release.md).

Regenerate the POT after string changes with `wp i18n make-pot`. Bundled library versions in `readme.txt` must match `inc/functions/lib/*/composer/installed.json`.
