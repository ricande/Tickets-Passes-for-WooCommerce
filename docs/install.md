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
7. Give door staff the **Scanner** role (`tpfw_scanner`). Administrators and Shop Managers already have `manage_woocommerce` and can scan. Staff open `/check-in/` on a phone; they do not need wp-admin.

## Upgrade from 1.2.3 to 1.3.0

Replace the plugin folder (or upload the new zip over the old one). Do not deactivate first.

The first request after the swap runs the installer because `tpfw_db_version` is not `1.0.3` yet:

- `CREATE TABLE IF NOT EXISTS` — no-op on tables that already exist
- `guest_slot` column on `tpfw_pass`, backfill `1…N` on live guest rows, unique index `(parent_nano_id_fk, guest_slot)`
- extra indexes on stats, row tables and timeslots if they are missing

Existing tickets, passes, QR codes, PDFs and `tpfw_upload_slug` keep working. Guest rows that already have `valid_from` stay valid. **New** guest passes stay inactive until the holder is scanned.

Behaviour changes the shop should know:

- Check-in is **POST only**. An authenticated GET answers **405** and does not write a stats row. The built-in scanner already POSTs. An external app that still GETs must switch.
- Orders that reach **Processing** (cash on delivery, most virtual checkouts) now mint QR codes. Orders that sat in Processing under 1.2.3 without rows are not backfilled; change status or use the order metabox **Create**.
- External apps may send `X-TPFW-Scanner-Token` instead of a WordPress password. WordPress Basic Auth still works unless `tpfw_scanner_tokens_required` is set.

Swedish ships as a fallback under `languages/`. A language pack in `wp-content/languages/plugins/` still wins. The shop language is the WordPress locale; there is no plugin language switcher.

## Deactivate vs delete

**Deactivate** clears the two cron hooks and flushes rewrite rules so `/check-in/` and the My Account tabs 404 cleanly. Settings, tables and files stay. Reactivating picks up the same `tpfw_db_version` and upload slug.

**Delete** (uninstall) always removes this plugin’s options, the Scanner role and the cron events. Tables and generated files stay unless `wp-config.php` has:

```php
define( 'TPFW_REMOVE_ALL_DATA', true );
```

That drops the nine tables, `_tpfw_*` product meta, `tpfw_*` order-item meta, and the upload folder. It cannot be undone. On multisite, uninstall walks every site.

## Developer checkout

The plugin is ordinary PHP/JS/CSS. A local WordPress install can symlink the folder into `wp-content/plugins/`.

Tests do **not** boot WordPress. From the plugin directory, as a normal user:

```bash
bash tests/run.sh
```

That runs PHPUnit (`tests/phpunit.phar -c phpunit.xml`) and `node --test tests/js/*.test.js`. No `pkexec`, no writes under `/var/www`.

Database-backed tests (guest quota, timeslot capacity) open MariaDB through `.wp-credentials` (`DB_NAME`, `DB_USER`, `DB_PASSWORD`, optional `DB_HOST`). The file is looked up at `../.wp-credentials` or `../../.wp-credentials` from the plugin root, or at `TPFW_TEST_CREDENTIALS`. Do not commit it.

Regenerate the POT after string changes with `wp i18n make-pot`. Bundled library versions in `readme.txt` must match `inc/functions/lib/*/composer/installed.json`.
