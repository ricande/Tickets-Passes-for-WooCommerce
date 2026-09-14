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
| `languages/` | POT + `index.php` to silence directory listing |

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
| `class--timeslot-ticket-wc-product.php` | Product type `tpfw-timeslot-ticket`: slots, capacity, reservations, `TPFW_Product_Timeslot_Ticket` |
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
| `class--functions.php` | Shared helper: QR, PDF, check-in + lock, upload paths, nano id, email placeholders, reset/cancel, date format |
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
| `class--api.php` | REST `tpfw/v1`: check-in, guest check-in, history, timeslot `.ics` |
| `class--api-settings.php` | Admin tab Scanner / API |
| `setting-pages/api-page-content.php` | Template for that tab |
| `js/api-settings.js` / `css/api-settings.css` | Tab UI |

## `inc/scanner/`

| File | Role |
|---|---|
| `class--scanner.php` | Standalone page `/check-in/` (+ alias `/checkin`). No admin bar, no theme wrapper. |
| `js/scanner.js` | Camera, result colours, calls REST |
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
| `inc/dashboard/class--dashboard.php` | `TPFW_Dashboard` — shared list table, resend/reset/cancel/manual check-in |
| `inc/dashboard/js/dashboard.js` | Row actions |
| `inc/dashboard/css/dashboard.css` + `template/page-content.php` | Shell |
| `inc/ticket-dashboard/class--ticket-dashboard.php` | Tickets list |
| `inc/timeslot-ticket-dashboard/class--timeslot-ticket-dashboard.php` | Timeslot list |
| `inc/pass-dashboard/` | Passes list (guest passes, photo) + extra JS/CSS |

## My Account

| Directory | Role |
|---|---|
| `inc/ticket-wc-myaccount/` | Tab `/tpfw-tickets` — ticket + timeslot, QR, PDF, `.ics` |
| `inc/pass-wc-myaccount/` | Passes tab, guest-pass hand-out, photo |

Both have `class--*.php`, `template/page-content.php`, `js/` and `css/`.

## Other admin

| Directory | Role |
|---|---|
| `inc/admin/` | Order metabox: force issue or cancel without moving order status (`js/admin-single.js`) |
| `inc/analytics-dashboard/` | Check-in charts + CSV (reads `*_stats`, not the sales tables) |
| `inc/db-installer/` | Creates/updates the nine tables (`DB_VERSION`) |
| `inc/cronjobs/` | Hourly: new recurring timeslots. Minutely: release expired reservations |
