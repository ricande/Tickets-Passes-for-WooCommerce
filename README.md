# Tickets & Passes for WooCommerce

Sells three product types — **Ticket**, **Timeslot Ticket** and **Pass** — and checks visitors in with a QR code on the shop's own site. No external ticketing service.

Shop-owner documentation (install, FAQ) lives in [`readme.txt`](readme.txt). Exact install, upgrade and test steps: [`docs/install.md`](docs/install.md). This is **developer documentation** of the plugin's own code.

Changelog: [`changelog.txt`](changelog.txt).

## Overview

| Document | Contents |
|---|---|
| [docs/install.md](docs/install.md) | Requirements, activate, 1.2.3 → 1.3.0, uninstall, tests |
| [docs/architecture.md](docs/architecture.md) | Boot, subsystems, purchase and check-in flow |
| [docs/file-map.md](docs/file-map.md) | First-party directories and what the files do |
| [docs/data.md](docs/data.md) | Tables, options, meta, files, REST routes |

## In depth

| Document | Contents |
|---|---|
| [docs/product-types.md](docs/product-types.md) | Ticket, timeslot and pass: stock vs uses, reservations, guest passes, idempotent issue |
| [docs/check-in.md](docs/check-in.md) | QR payload, `/check-in/`, REST auth, lock, HTTP statuses |
| [docs/files-and-access.md](docs/files-and-access.md) | Upload layout, HMAC vs session, caching |
| [docs/emails-and-admin.md](docs/emails-and-admin.md) | Order emails, My Account, dashboards, order metabox |
| [docs/work-plan.md](docs/work-plan.md) | Integrity and refactor items shipped in 1.3.0 |

## Identity

- Prefix: `tpfw`
- Text domain: `tickets-passes-for-woocommerce`
- Product types: `tpfw-ticket`, `tpfw-timeslot-ticket`, `tpfw-pass`
- A sold line is identified by a `nano_id` (21 characters). The QR code is a REST check-in URL, not a web page.

## Bundled third-party (not documented here)

- `inc/functions/lib/qrcodegen/` — QR generation (endroid/qr-code)
- `inc/functions/lib/dompdf/` — PDF
- `lib/air-datepicker/` — date picker
- `inc/scanner/js/jsqr.js` — in-browser QR decode
- `inc/analytics-dashboard/lib/apexcharts.min.js` — charts

No build step. First-party PHP, JavaScript and CSS ship readable. Some folders also have `.scss` as the source of the shipped `.css`.
