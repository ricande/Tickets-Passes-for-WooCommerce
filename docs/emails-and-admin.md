# Emails, My Account and admin

Product issuing: [product-types.md](product-types.md). Check-in from dashboards: [check-in.md](check-in.md).

## Emails

There is no separate “your ticket” mail on first purchase. QR codes ride on WooCommerce’s own order emails via `woocommerce_email_order_details` (`TPFW_Emails::add_ticket_and_pass_text`).

Templates (editable under Ticket & Passes → Email, stored in `tpfw_email_settings_options`):

| Key | When |
|---|---|
| `wc_confirmation_email` | WooCommerce order emails that are not `customer_completed_order` (processing, on-hold, …). Orders that have already fired `payment_complete` can list QR images here. A Processing order that was never marked paid still has none until Completed. The shipped default talks about waiting for completion. |
| `wc_completed_email` | Completed-order email, beside the QR images |
| `resend_ticket_email` | Dashboard / My Account resend for a ticket or timeslot ticket |
| `resend_pass_email` | Same for a pass |
| `gifted_pass_email_new_user` | Pass bought for an email with no account (username + set-password link; no password in the mail) |
| `gifted_pass_email_existing_user` | Pass bought for an existing user |

`get_default_email_texts()` is used until an admin saves their own copy, so an empty template never sends a blank mail.

Merge tags include `{{customer_name}}`, `{{site_name}}`, `{{myaccount_url}}`, `{{nano_id}}`, `{{order_id}}`, `{{qr_code_image}}`, `{{qr_code_link}}` (signed file URL), `{{product_note}}`, plus gift-specific `{{buyer_name}}` / `{{new_user_login_details}}`.

One QR row is printed **per issued code**, not per order line (quantity 3 → three images). Line meta `tpfw_ticket_id_*` / `tpfw_pass_id_*` is stripped from customer-facing email so the raw nano id is not listed next to the image. Admin order screens keep the meta.

## My Account

Endpoints are registered from `init` (the classes are constructed on `init`, so a nested `add_action('init')` would never fire). `TPFW_REWRITE_VERSION` must bump when an endpoint slug changes.

| Endpoint | Class | Contents |
|---|---|---|
| `/tpfw-tickets` | `TPFW_Ticket_WC_MyAccount` | Ticket **and** timeslot ticket rows for the logged-in user: QR, status pill, uses left, PDF, Add to Calendar (`.ics`) |
| `/tpfw-pass` | `TPFW_Pass_WC_MyAccount` | Passes, guest-pass hand-out, photo upload |

Queries are scoped to `user_id = get_current_user_id()` in SQL. QR and guest images on these pages are **signed** (`get_file_url(..., true)`), because `qr` / `guest` are HMAC-only. The URL also carries `&v={mtime}` so a rewritten code is not hidden behind a previously cached image. PDF download is AJAX that returns an unsigned `pdf` URL whose `v` follows the current QR; `TPFW_File_Access` then checks the session. Profile photos are unsigned and likewise session-gated. A forwarded PDF/photo URL is useless; a forwarded QR URL still works until the signature expires (`exp = 0` never expires).

Changing QR colours or the renderer after a ticket was emailed does **not** send a new mail. The customer gets a current image from My Account, a fresh PDF download, or a dashboard **Resend**. Old inbox URLs without `v` may still show a cached copy in that mail client.

There is no check-in button on My Account. Manual check-in is the dashboard row action.

## Dashboards

`TPFW_Dashboard` is the shared WP_List_Table shell. Subclasses set table names, AJAX suffixes and labels:

- `TPFW_Ticket_Dashboard`
- `TPFW_Timeslot_Ticket_Dashboard`
- `TPFW_Pass_Dashboard` (extra: guest passes, photos)

Row actions (`manage_woocommerce`):

- **Checkin** — `checkin(..., $bManual = true)` (AJAX)
- **Resend** — sends the resend template with a signed QR URL (AJAX)
- **Download** — printable PDF (same as My Account); omitted when no QR file exists
- **Reset** — clears `deleted` on the row and deletes its stats (AJAX)
- **Cancel** — soft-deletes the row and its stats (AJAX)
- **Transfer** — ticket and timeslot dashboards only: move the row to another customer

Each list also has **Download CSV** for the current search and status filter (all matching rows, not just the page). Analytics (`TPFW_Analytics_Dashboard`) reads `*_stats` joined to row tables (door activity, not sales). Guest-pass rows are excluded from per-product pass counts. CSV export is on that screen too. Gated by `bEnableAnalytics`.

## Order metabox (`inc/admin`)

On an order that contains a TPFW product: **Create** (`order_completed()` → `order_force_issue()`) or **Cancel** without moving WooCommerce order status. Use Create for an unpaid Processing order, a missed issue, or a re-issue. Cancel drops the codes without changing the order. Automatic revocation on refund needs a refunded **item quantity**; an amount-only refund leaves issued rows in place.

## Settings load order

`tpfw_general_settings_options` holds the type enable flags (`bEnableTicketProduct`, `bEnableTimeslotProduct`, `bEnablePassProduct`), analytics, date format, and the type tabs’ colours/hints. Ticket/Timeslot/Pass **settings screens** still load when the type is off so it can be turned back on. The **product class, My Account tab and dashboard** do not load until the type is on.

Scanner / API settings live in `tpfw_api_settings_options`. Missing `bEnableScanner` / `bEnableAPI` keys mean **on** (legacy default).
