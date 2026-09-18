# File map (first-party)

Behaviour of each area: [product-types.md](product-types.md), [check-in.md](check-in.md), [files-and-access.md](files-and-access.md), [emails-and-admin.md](emails-and-admin.md).

Third-party code under `inc/functions/lib/`, `lib/`, `jsqr.js` and ApexCharts is not listed.

## Root

| File | Role |
|---|---|
| `tickets-passes-for-woocommerce.php` | Bootstrap: constants, activation, deactivation, rewrite flush, starts `TPFW_Main` |
| `class--main.php` | `TPFW_Main` — which subsystems load; labels for order-line item meta |
| `uninstall.php` | Removes settings/role/cron; tables and files only with `TPFW_REMOVE_ALL_DATA` |
| `readme.txt` | Shop-owner text (WordPress.org) |
| `changelog.txt` | Release history |
| `docs/install.md` | Install, upgrade, uninstall, how to run tests and build the ZIP |
| `docs/release.md` | Source/review tests vs production ZIP |
| `docs/vendor-patches.md` | Local `require-dev` patches on bundled Composer manifests |
| `docs/server-config/` | nginx deny snippet for `uploads/tpfw-*`; Apache/IIS notes |
| `languages/` | POT, bundled `sv_SE` and `da_DK` `.po` / `.mo` / `.l10n.php`, `index.php` |

## `inc/product-type/`

| File | Role |
|---|---|
| `class--product-type.php` | `TPFW_Product_Type` — base for the three types: `create_entry` / `cancel_entry`, AJAX check-in, shared product-page info table |

## `inc/ticket-wc-product/`

| File | Role |
|---|---|
| `class--ticket-wc-product.php` | Product type `tpfw-ticket`: admin panels, cart validation, issue/revoke, `TPFW_Product_Ticket` |
| `js/ticket-wc-product.js` | Product editor: duration fields, QR preview, shows stock management for Ticket |

## `inc/timeslot-ticket-wc-product/`

| File | Role |
|---|---|
| `class--timeslot-ticket-wc-product.php` | Product type `tpfw-timeslot-ticket`: editor, issue/revoke, `TPFW_Product_Timeslot_Ticket` |
| `class--timeslot-ticket-checkout.php` | Cart validation, reservation, checkout hold (`TPFW_Timeslot_Ticket_Checkout`) |
| `js/timeslot-ticket-wc-product.js` | Admin: schedule, slots; hides stock management (capacity lives on the slot) |
| `js/timeslot-ticket-wc-product-frontend.js` | Product page: date and slot picker |
| `css/timeslot.module.css` / `timeslot.front.css` | Admin vs storefront |

## `inc/pass-wc-product/`

| File | Role |
|---|---|
| `class--pass-wc-product.php` | Product type `tpfw-pass`: people in the cart, guest passes, gifting, `TPFW_Product_Pass` |
| `js/pass-wc-product.js` | Product editor (pass + guest-pass QR) |
| `js/pass.front.js` | Product page: person rows |
| `css/pass.module.css` / `pass.front.css` | Admin vs storefront |

## `inc/functions/`

| File | Role |
|---|---|
| `class--functions.php` | Facade: QR, PDF, check-in, upload paths, nano id, email placeholders, reset/cancel, date format. Delegates lock, payload, file tokens and issue policy to `inc/support/` |
| `js/functions-admin.js` | Shared admin QR section (colours, preview, logo) |
| `js/help-tip-init.js` | Tooltips on settings screens |
| `css/front-product.css` | Product-page info table |
| `css/ticket-pdf.css` / `pass-pdf.css` | PDF layout |

## `inc/file-access/`

| File | Role |
|---|---|
| `class--file-access.php` | Gate for QR, PDF and pass photos. Runs in the constructor (the plugin boots too late for `init`). |

## `inc/api/`

| File | Role |
|---|---|
| `class--api.php` | REST `tpfw/v1`: POST check-in, POST guest check-in, GET 405 on those paths, history, timeslot `.ics` |
| `class--api-settings.php` | Admin tab Scanner / API |
| `setting-pages/api-page-content.php` | Template for that tab |
| `js/api-settings.js` / `css/api-settings.css` | Tab UI |

## `inc/scanner/`

| File | Role |
|---|---|
| `class--scanner.php` | Standalone page `/check-in/` (+ alias `/checkin`). No admin bar, no theme wrapper. |
| `js/scanner.js` | Camera, result colours, POSTs REST |
| `js/parse-checkin-url.js` | Extracts `nano_id` from a scanned URL without fetching it |
| `css/scanner.css` / `access-denied.css` | Scanner vs access denied |

## `inc/emails/`

| File | Role |
|---|---|
| `class--emails.php` | Attaches QR/details to order emails; Resend and guest-pass templates; admin Email tab |
| `template/page-content.php` | Template editor |
| `js/email-settings.js` / `css/email-settings.css` | Tab UI |

## `inc/settings/` and type settings

Shared shell: `TPFW_Settings_Tab` (`class--settings-tab.php`) + `js/settings-tab.js` + `css/settings.css`.

| Directory | Class | Tab |
|---|---|---|
| `inc/settings/` | `TPFW_Settings` | General (top-level menu `Ticket & Passes`) |
| `inc/ticket-settings/` | `TPFW_Ticket_Settings` | Ticket |
| `inc/timeslot-ticket-settings/` | `TPFW_Timeslot_Ticket_Settings` | Timeslot Ticket |
| `inc/pass-settings/` | `TPFW_Pass_Settings` | Pass |

Each type tab has `setting-pages/*-page-content.php`.

## `inc/dashboard/` and type dashboards

| File | Role |
|---|---|
| `inc/dashboard/class--dashboard.php` | `TPFW_Dashboard` — shared list table, check-in/resend/download/reset/cancel/transfer |
| `inc/dashboard/js/dashboard.js` | Row actions |
| `inc/dashboard/css/dashboard.css` + `template/page-content.php` | Shell |
| `inc/ticket-dashboard/class--ticket-dashboard.php` | Tickets list |
| `inc/timeslot-ticket-dashboard/class--timeslot-ticket-dashboard.php` | Timeslot list |
| `inc/pass-dashboard/` | Passes list (guest passes, photo) + extra JS/CSS |

## My Account

| Directory | Role |
|---|---|
| `inc/ticket-wc-myaccount/` | Tab `/tpfw-tickets` — ticket + timeslot, QR, PDF, `.ics` |
| `inc/pass-wc-myaccount/` | Tab `/tpfw-pass` — passes, guest-pass hand-out, photo |

Both have `class--*.php`, `template/page-content.php`, `js/` and `css/`.

## Other admin

| Directory | Role |
|---|---|
| `inc/admin/` | Order metabox: force issue or cancel without moving order status (`js/admin-single.js`) |
| `inc/analytics-dashboard/` | Check-in charts + CSV (reads `*_stats`, not the sales tables) |
| `inc/db-installer/` | Creates/updates the nine tables (`DB_VERSION` `1.0.6`) |
| `inc/cronjobs/` | Hourly: new recurring timeslots. Minutely: release expired reservations |
| `inc/support/` | Extracted helpers loaded by `load.php` (see below) |
| `tests/` | PHPUnit + Node; `bash tests/run.sh` fetches PHPUnit via `tests/ensure-phpunit.sh` |
| `scripts/build-plugin-zip.sh` | Production plugin ZIP (see [release.md](release.md)) |

## `inc/support/`

Loaded unconditionally from `TPFW_Main` before the installer.

| File | Class | Role |
|---|---|---|
| `class--issue-policy.php` | `TPFW_Issue_Policy` | Mint on payment_complete / completed / force; revoke on cancelled/refunded/failed |
| `class--refund-policy.php` | `TPFW_Refund_Policy` | Target issued qty = purchased − refunded item qty; amount-only does not revoke |
| `class--order-line-upsert.php` | `TPFW_Order_Line_Upsert` | Idempotent issue per order line |
| `class--guest-pass-issuer.php` | `TPFW_Guest_Pass_Issuer` | Guest quota via `guest_slot`; legacy backfill including deleted rows |
| `class--timeslot-capacity.php` | `TPFW_Timeslot_Capacity` | Named lock around reserve and issue |
| `class--named-lock.php` | `TPFW_Named_Lock` | `GET_LOCK` fail-closed |
| `class--issue-lock.php` | `TPFW_Issue_Lock` | Named lock around ticket/pass issue per order line |
| `class--db-write.php` | `TPFW_Db_Write` | `$wpdb` false vs 0 for fail-closed writes |
| `class--checkin-payload.php` | `TPFW_Checkin_Payload` | Scanner success allowlist |
| `class--image-limits.php` | `TPFW_Image_Limits` | Profile-photo pixel cap before decode |
| `class--qr-render.php` | `TPFW_Qr_Render` | Centre-logo box so a product photo cannot cover the QR |
| `class--qr-rewrite.php` | `TPFW_Qr_Rewrite` | Bounded, resumable background rewrite of issued QR images |
| `class--file-paths.php` | `TPFW_File_Paths` | All files under `tpfw-{slug}/` |
| `class--file-token.php` | `TPFW_File_Token` | HMAC sign/verify |
| `class--scanner-tokens.php` | `TPFW_Scanner_Tokens` | Revocable `{token_id}.{secret}` header; one hash verify |
| `class--scanner-auth.php` | `TPFW_Scanner_Auth` | Capability / Enable API after WordPress or token auth |
| `class--email-settings.php` | `TPFW_Email_Settings` | Default vs saved templates |
| `class--datepicker-locale.php` | `TPFW_Datepicker_Locale` | `$wp_locale` for Air Datepicker |
| `class--bundled-libs.php` | `TPFW_Bundled_Libs` | Versions from `installed.json` |
