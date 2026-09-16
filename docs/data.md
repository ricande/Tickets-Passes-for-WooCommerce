# Data

Plugin data lives in its own tables, options and a randomly named upload folder. The order itself is only the source of `order_id` / `order_line_id`.

How those records are written: [product-types.md](product-types.md), [check-in.md](check-in.md), [files-and-access.md](files-and-access.md).

## Tables

Prefix: `$wpdb->prefix` plus the name below. Schema: `inc/db-installer/class--db-installer.php` (`DB_VERSION` `1.0.4`). Rows are soft-deleted with `deleted` (timestamp); they are not removed.

| Table | Contents |
|---|---|
| `tpfw_tickets` | Issued ticket. Key: `nano_id`. Validity, `max_uses`, product/order/customer |
| `tpfw_tickets_stats` | One row per check-in (`nano_id_fk`) |
| `tpfw_timeslot_tickets` | Issued timeslot ticket + `timeslot_id`. `before_checkin_duration` (seconds) is copied from the product; `valid_from` is slot start minus that. |
| `tpfw_timeslot_tickets_stats` | Check-ins for timeslot tickets |
| `tpfw_timeslots` | Concrete slots (`id`, start/end, `available_slots`) |
| `tpfw_timeslots_recurring` | Templates that cron rolls out into `tpfw_timeslots` |
| `tpfw_timeslot_reservations` | Temporary capacity hold in checkout (`valid_to` = expiry) |
| `tpfw_pass` | Pass or guest pass. Guest has `parent_nano_id_fk` and `guest_slot` (1…N). Unique `(parent_nano_id_fk, guest_slot)`. Photo: `profile_image_type` |
| `tpfw_pass_stats` | Check-ins for pass/guest pass |

`nano_id` is unique per row table and is what appears in the QR, emails and filenames.

## Options

| Option | Contents |
|---|---|
| `tpfw_general_settings_options` | Which product types are on, analytics, date format |
| `tpfw_email_settings_options` | Email templates and subjects |
| `tpfw_api_settings_options` | API and scanner toggles, result colours |
| `tpfw_db_version` | Installed schema |
| `tpfw_rewrite_version` | Last flushed rewrite set |
| `tpfw_scanner_role_version` | Scanner role capability set |
| `tpfw_upload_slug` | Random part of `uploads/tpfw-{slug}/` |
| `tpfw_file_secret` | Signing of file links |
| `tpfw_scanner_tokens` | Per-device scanner tokens keyed by `token_id`; stores `hash(secret)` only (`TPFW_Scanner_Tokens`) |
| `tpfw_scanner_tokens_required` | Legacy option; account-password Basic Auth is no longer used |
| `tpfw_qr_rewrite_jobs` | Background QR rewrite job rows (cursor, generation, last error); mutated under `GET_LOCK` |
| `tpfw_qr_render_version` | Last applied `TPFW_Qr_Render::RENDER_VERSION`; a lower value queues a repair sweep |

### Product post meta

Prefix `_tpfw_ticket_`, `_tpfw_pass_` or `_tpfw_timeslot_ticket_` / `_tpfw_timeslot_` depending on the field. Shared cards from `save_settings_cards()`:

| Suffix | Meaning |
|---|---|
| `note` | Product note (max 350 chars), shown on the product page / email |
| `max_uses` | Scans allowed per issued QR |
| `valid_duration` | Validity length in **seconds** |
| `cooldown_sec` | Minimum seconds between scans (`_tpfw_guestpass_cooldown_sec` for guests) |
| `show_max_uses`, `show_valid_from`, `show_valid_to`, `show_predefined_date`, `show_sales_window` | Product-page table toggles |
| `predefined_start_date_enable` / `predefined_start_date` | Shop-fixed validity start |
| `user_start_date_enable` / `user_start_date_min` / `user_start_date_max` | Customer-picked start |
| `sales_timespan_enable` / `sales_timespan_start` / `sales_timespan_end` | Purchase window |

QR colours use `_tpfw_{ticket|timeslot|pass}_qr_*`. A centre logo (`logo_id`) is drawn at most 20% of the QR’s longer side with high error correction; width-only sizing used to let a portrait product photo cover the code. After a successful rewrite the fingerprint is stored as `_tpfw_{type}_qr_issued_key`. Pass extras: `_tpfw_pass_guest_pass_*`, `_tpfw_pass_profile_image_upload_*`. Timeslot extras: `_tpfw_timeslot_ticket_recurring_enable`, `_tpfw_timeslot_ticket_recurring_future`, `_tpfw_timeslot_ticket_before_checkin_duration`, `_tpfw_timeslot_ticket_max_usage`.

### Order / cart item meta

Prefix `tpfw_`. Typical keys: `tpfw_start_date`, `tpfw_firstname`, `tpfw_lastname`, `tpfw_email`, `tpfw_timeslot_id`, `tpfw_timeslot_start`, `tpfw_timeslot_end`, `tpfw_reservation_id`, `tpfw_reservation_time`, `tpfw_ticket_id_{n}`, `tpfw_timeslot_ticket_id_{n}`, `tpfw_pass_id_{n}`.

`tpfw_reservation_id`, `tpfw_reservation_time` and `tpfw_timeslot_id` are hidden on the thank-you page. Nano-id keys are stripped from customer emails but kept on admin order screens.

## Files on disk

Base: `wp-content/uploads/tpfw-{slug}/` where `{slug}` is the 10-character hex in `tpfw_upload_slug`. Profile photos use the same base (`profile-images/`); a custom path is no longer used. Subfolders (keys of `TPFW_Functions::FILE_TYPE_FOLDERS`):

| Key | Folder | What |
|---|---|---|
| `qr` | `qr-codes/` | Ticket/pass QR (webp), named after `nano_id` |
| `guest` | `qr-guest/` | Guest-pass QR |
| `preview` | `qr-preview/` | Admin preview (rewritten when colours change) |
| `pdf` | `qr-pdf/` | Printable PDF |
| `profile` | `profile-images/` | Pass photo |

All are fetched through `TPFW_File_Access` (`?tpfw_file=`), not as a direct URL. Allowed extensions: webp, png, jpg, jpeg, gif, pdf. nginx/IIS must still deny static `/wp-content/uploads/tpfw-*` — see [server-config/](server-config/README.md).

## REST (`tpfw/v1`)

| Route | Method | Auth | Purpose |
|---|---|---|---|
| `/scanner/checkin/{nano_id}` | POST | scanner capability; cookie+nonce, Application Password, or `X-TPFW-Scanner-Token` (`token_id.secret`; not the account password) | Check-in |
| `/scanner/checkin/{nano_id}` | GET | same (then refused) | **405** after auth; **401** with no credentials |
| `/scanner/checkin/{nano_id}/guest` | POST | same as check-in | Guest-pass check-in |
| `/scanner/checkin/{nano_id}/guest` | GET | same (then refused) | **405** after auth; **401** with no credentials |
| `/scanner/history` | GET | same | Recent scans |
| `/timeslot-ticket/ics/{nano_id}` | GET | open (`nano_id` is the secret) | Calendar file |

`nano_id` is validated to at most 32 characters `[A-Za-z0-9_-]` before the database is touched.

## Cron

| Hook | Interval | Job |
|---|---|---|
| `tpfw_hourly_create_recurring_timeslots_cronjob` | hourly | Create slots from recurring templates |
| `tpfw_minut_delete_expired_reservations_cronjob` | minutely | Release reservations whose `valid_to` has passed |

Both are cleared on deactivation and uninstall.

## Role

`tpfw_scanner` — display name **Scanner**. Capability `read` only. Created by `TPFW_Functions::maybe_register_scanner_role()` when option `tpfw_scanner_role_version` is not `SCANNER_ROLE_VERSION` (`2`). Check-in permission is that role **or** `manage_woocommerce`. How to attach it to a user: [install.md](install.md#scanner-users), [check-in.md](check-in.md#who-may-scan). Uninstall calls `remove_role('tpfw_scanner')`.

## Language

Text domain `tickets-passes-for-woocommerce`. `load_plugin_textdomain()` points at `languages/`. WordPress language packs in `WP_LANG_DIR/plugins/` win. The zip ships `sv_SE` and `da_DK` (`.po` / `.mo` / `.l10n.php`) as fallbacks. There is no plugin language switcher.
