# Emails, My Account and admin

Product issuing: [product-types.md](product-types.md). Check-in from dashboards: [check-in.md](check-in.md).

## Emails

There is no separate “your ticket” mail on first purchase. QR codes ride on WooCommerce’s own order emails via `woocommerce_email_order_details` (`TPFW_Emails::add_ticket_and_pass_text`).

Templates (editable under Ticket & Passes → Email, stored in `tpfw_email_settings_options`):

| Key | When |
|---|---|
| `wc_confirmation_email` | Processing / on-hold confirmation. Codes are **not** issued yet; default copy says they will arrive when the order completes. |
| `wc_completed_email` | Completed order, beside the QR images |
| `resend_ticket_email` | Dashboard / My Account resend for a ticket or timeslot ticket |
| `resend_pass_email` | Same for a pass |
| `gifted_pass_email_new_user` | Pass bought for an email with no account (includes login details) |
| `gifted_pass_email_existing_user` | Pass bought for an existing user |

`get_default_email_texts()` is used until an admin saves their own copy, so an empty template never sends a blank mail.

Merge tags include `{{customer_name}}`, `{{site_name}}`, `{{myaccount_url}}`, `{{nano_id}}`, `{{order_id}}`, `{{qr_code_image}}`, `{{qr_code_link}}` (signed file URL), `{{product_note}}`, plus gift-specific `{{buyer_name}}` / `{{new_user_login_details}}`.

One QR row is printed **per issued code**, not per order line (quantity 3 → three images). Line meta `tpfw_ticket_id_*` / `tpfw_pass_id_*` is stripped from customer-facing email so the raw nano id is not listed next to the image. Admin order screens keep the meta.

## My Account

Endpoints are registered from `init` (the classes are constructed on `init`, so a nested `add_action('init')` would never fire). `TPFW_REWRITE_VERSION` must bump when an endpoint slug changes.

| Endpoint | Class | Contents |
|---|---|---|
| `/tpfw-tickets` | `TPFW_Ticket_WC_MyAccount` | Ticket **and** timeslot ticket rows for the logged-in user: QR, status pill, uses left, PDF, Add to Calendar (`.ics`) |
| Passes tab | `TPFW_Pass_WC_MyAccount` | Passes, guest-pass hand-out, photo upload |

Queries are scoped to `user_id = get_current_user_id()` in SQL. PDF/QR links on these pages are **unsigned**; `TPFW_File_Access` checks the session, so a forwarded URL is useless.

Manual check-in from My Account calls the same `ajax_checkin_*` as the dashboard, with `$bManual` so the holder can check themselves in outside the validity window only if they **manage** the plugin — a customer checking their own code still has the window enforced (`TPFW_Product_Type::ajax_checkin_callback()`).

## Dashboards

`TPFW_Dashboard` is the shared WP_List_Table shell. Subclasses set table names, AJAX suffixes and labels:

- `TPFW_Ticket_Dashboard`
- `TPFW_Timeslot_Ticket_Dashboard`
- `TPFW_Pass_Dashboard` (extra: guest passes, photos)

Row actions (all AJAX, `manage_woocommerce`):

- **Resend** — sends the resend template with a signed QR URL
- **Reset** — clears `deleted` on the row and deletes its stats (code usable again)
- **Cancel** — soft-deletes the row and its stats
- **Check in** — `checkin(..., $bManual = true)`

Analytics (`TPFW_Analytics_Dashboard`) reads `*_stats` joined to row tables (door activity, not sales). Guest-pass rows are excluded from per-product pass counts. CSV export is on that screen. Gated by `bEnableAnalytics`.

## Order metabox (`inc/admin`)

On an order that contains a TPFW product: **force issue** (`order_completed()`) or **force cancel** without moving WooCommerce order status. Used when a payment gateway left the order in `processing` but the shop still needs QR codes, or the reverse.

## Settings load order

`tpfw_general_settings_options` holds the type enable flags (`bEnableTicketProduct`, `bEnableTimeslotProduct`, `bEnablePassProduct`), analytics, date format, and the type tabs’ colours/hints. Ticket/Timeslot/Pass **settings screens** still load when the type is off so it can be turned back on. The **product class, My Account tab and dashboard** do not load until the type is on.

Scanner / API settings live in `tpfw_api_settings_options`. Missing `bEnableScanner` / `bEnableAPI` keys mean **on** (legacy default).
