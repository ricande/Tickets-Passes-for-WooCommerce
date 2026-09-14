# Architecture

In depth: [product-types.md](product-types.md), [check-in.md](check-in.md), [files-and-access.md](files-and-access.md), [emails-and-admin.md](emails-and-admin.md).

The plugin is split into **subsystems**. Each subsystem is a class that registers its own hooks from its constructor. `TPFW_Main` instantiates them and keeps only a shared `TPFW_Functions` facade. Integrity-critical helpers live in `inc/support/` (`TPFW_Issue_Policy`, `TPFW_Order_Line_Upsert`, `TPFW_Named_Lock`, `TPFW_Checkin_Payload`, …) and are loaded first via `inc/support/load.php`.

## Boot

1. `tickets-passes-for-woocommerce.php` defines constants (`TPFW_PLUGIN_DIR`, `TPFW_VERSION`, `TPFW_REWRITE_VERSION`) and hooks activation/deactivation.
2. On `init` (priority 20) it constructs `TPFW_Main`, which calls `load_dependencies()`.
3. `inc/support/load.php` then the database installer, then `TPFW_Functions` (shared facade).
4. Everything else loads **conditionally** from settings (`tpfw_general_settings_options`) and from the scanner/API toggles.

Rewrite rules (`/check-in/`, My Account tabs) flush once per `TPFW_REWRITE_VERSION`, late on `wp_loaded`, after every class has registered its rules.

Activation creates the tables and the upload folder. It does **not** flush permalinks — that happens on the next request. Deactivation clears cron and rewrite. Uninstall (`uninstall.php`) always removes settings and the scanner role; tables and QR/PDF files are removed only when `TPFW_REMOVE_ALL_DATA` is `true`.

## Subsystems

```
TPFW_Main
 ├── TPFW_DB_Installer          always
 ├── TPFW_Functions             always (shared)
 ├── TPFW_File_Access           always (QR/PDF/photo must never be switched off)
 ├── TPFW_API                   if API or scanner is on
 ├── TPFW_Scanner               if scanner is on
 ├── TPFW_Emails                always
 ├── product type + My Account  if that type is on in settings
 ├── TPFW_Cronjobs              if Ticket or Timeslot is on
 └── (admin) settings, dashboards, analytics, order metabox
```

Admin classes are constructed only inside `is_admin()`. The Ticket/Timeslot/Pass settings screens still load when the type is off, so it can be turned back on.

## Purchase → issue

```mermaid
sequenceDiagram
    participant Customer
    participant Product
    participant Order
    participant Type as Product-type class
    participant DB as tpfw_* tables
    participant Files as QR / PDF

    Customer->>Product: add to cart
    Product->>Order: line + item meta
    Order->>Type: status processing or completed
    Type->>DB: create_entry (idempotent per line)
    Type->>Files: QR (and PDF when needed)
```

- **Ticket** (`TPFW_Ticket_WC_Product`): validity window, max uses per QR, optional customer-picked start date. How many may be sold is the product's stock (`_manage_stock` / `_stock`). `valid_duration` is stored in **seconds**.
- **Timeslot Ticket**: a concrete time slot with capacity. Unpaid reservations are released by cron. Cart/checkout lives in `TPFW_Timeslot_Ticket_Checkout`.
- **Pass**: period entitlement, optional guest passes (`parent_nano_id_fk`, `guest_slot` 1…N), photo, cooldown.

Issuing uses one `TPFW_Issue_Policy`: mint on `processing` and `completed` (`order_maybe_issue()` → `order_completed()` → `create_entry()`). Cash-on-delivery and other paid orders that sit in Processing therefore get QR codes without waiting for Completed. `pending` / `on-hold` do not mint.

Cancelled / refunded / failed orders call `cancel_entry` (soft delete: `deleted` is set, the row is not removed), via `TPFW_Issue_Policy::should_revoke()`.

## Check-in

The QR payload is the REST URL

`/wp-json/tpfw/v1/scanner/checkin/{nano_id}`

The scanner at `/check-in/` does **not** open that URL in the browser. It extracts the `nano_id` (any host) and **POST**s the same REST path on its own origin while logged in. GET on the check-in routes is registered only to answer **405**. All decision logic lives on the server in `TPFW_Functions::checkin()`, under a MySQL named lock per `nano_id` (`TPFW_Named_Lock`: anything other than a held lock is a refusal, HTTP 202). The success body is an allowlist (`TPFW_Checkin_Payload`), not the raw row.

The same `checkin()` is used by:

- the door scanner
- manual check-in on the admin dashboard
- optional check-in from My Account

Permission: the `tpfw_scanner` role, or `manage_woocommerce`.

Guest passes have their own route: `/scanner/checkin/{nano_id}/guest`.

## Files

Generated files live under `uploads/tpfw-{random}/` and are **never** served as static URLs. Every link goes through `TPFW_File_Access`, which requires the signed-in owner or a signed link.
