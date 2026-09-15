# Tickets & Passes for WooCommerce

Sell tickets and passes in WooCommerce.

Email a QR code. Scan guests at the door.

No external ticketing platform.

[![Release](https://img.shields.io/github/v/release/ricande/Tickets-Passes-for-WooCommerce?include_prereleases&label=release)](https://github.com/ricande/Tickets-Passes-for-WooCommerce/releases/tag/1.3.0-RC)
[![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-21759b)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-required-7f54b3)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)](https://www.php.net)
[![License](https://img.shields.io/badge/license-GPLv2%2B-blue.svg)](LICENSE)

**1.3.0 RC** is a pre-release. Use it for review and staging, not as a “stable” WordPress.org build.

[**Download 1.3.0 RC**](https://github.com/ricande/Tickets-Passes-for-WooCommerce/releases/download/1.3.0-RC/tickets-passes-for-woocommerce-1.3.0.zip)
· [Documentation](#documentation)
· [Report an issue](https://github.com/ricande/Tickets-Passes-for-WooCommerce/issues/new/choose)

Shop-owner FAQ also lives in [`readme.txt`](readme.txt). Changelog: [`changelog.txt`](changelog.txt).

## Features

| | |
|---|---|
| **Tickets** | Admission with a validity window and a set number of uses |
| **Timeslot tickets** | Date and time slots with capacity per slot |
| **Passes** | Season / membership-style passes, including guest passes |
| **QR delivery** | Codes ride on WooCommerce order emails (HTML) and can be printed as PDF |
| **Door scanner** | Built-in `/check-in/` page on your own site — phone camera, no extra app store |
| **WooCommerce orders** | Paid and Completed orders issue codes; unpaid COD stays on Processing until Completed |
| **Refunds** | Item-quantity refunds reduce live codes; amount-only refunds do not |
| **Self-hosted** | No ticketing SaaS, no per-ticket fee, files stay on your WordPress host |

## How it works

1. **Sell** — the customer buys a ticket or pass in WooCommerce.
2. **Deliver** — a QR code is generated and sent with the order email.
3. **Scan** — staff open `/check-in/` and record the visit.

## Screenshots

Product editor, QR ticket, door scanner, and admin overview images are not in this repository yet. Nothing is linked here so the README does not show broken pictures. The four shots to add later are listed in [`docs/screenshots/README.md`](docs/screenshots/README.md).

## Quick start

**Requirements:** WordPress 6.5+, WooCommerce (active), PHP 8.0+, HTTPS for the door camera.

1. Download the [1.3.0 RC plugin zip](https://github.com/ricande/Tickets-Passes-for-WooCommerce/releases/download/1.3.0-RC/tickets-passes-for-woocommerce-1.3.0.zip) — not GitHub’s *Source code* archive.
2. In wp-admin: **Plugins → Add New → Upload Plugin**.
3. Activate with WooCommerce already active.
4. Enable the product types you sell under **Ticket & Passes → Settings**.
5. Create a ticket or pass product, then add door staff as WordPress users with the **Scanner** role.

Full install, upgrade from 1.2.3, and scanner-user steps: [`docs/install.md`](docs/install.md).

## Scanner and access

- Check-in uses WordPress capabilities (Scanner role, or Administrator / Shop Manager).
- External scanner apps use a WordPress **Application Password** (HTTP Basic) or a revocable `X-TPFW-Scanner-Token`. The account login password is not accepted.
- Customer files (QR images, PDFs, pass photos) are served through a signed `?tpfw_file=` route, not as public media URLs.
- On nginx or IIS, the server must deny direct HTTP to `/wp-content/uploads/tpfw-*`. Apache/LiteSpeed can use the plugin’s `.htaccess`.

This is a self-hosted model, not a security guarantee. Details: [`docs/check-in.md`](docs/check-in.md), [`docs/files-and-access.md`](docs/files-and-access.md), [`docs/server-config/`](docs/server-config/).

## Documentation

**User / setup**

| Document | Contents |
|---|---|
| [docs/install.md](docs/install.md) | Requirements, activate, scanner users, 1.2.3 → 1.3.0, uninstall |
| [docs/product-types.md](docs/product-types.md) | Ticket, timeslot and pass behaviour |
| [docs/check-in.md](docs/check-in.md) | Who may scan, QR payload, REST auth |
| [docs/emails-and-admin.md](docs/emails-and-admin.md) | Order emails, My Account, dashboards |
| [readme.txt](readme.txt) | Shop-owner install notes and FAQ |

**Operations / access**

| Document | Contents |
|---|---|
| [docs/files-and-access.md](docs/files-and-access.md) | Upload layout, signed file links |
| [docs/server-config/](docs/server-config/) | nginx / IIS deny for private uploads |
| [docs/release.md](docs/release.md) | Tests vs production ZIP |

**Developer**

| Document | Contents |
|---|---|
| [docs/architecture.md](docs/architecture.md) | Boot, subsystems, purchase and check-in flow |
| [docs/data.md](docs/data.md) | Tables, options, meta, files, REST routes |
| [docs/file-map.md](docs/file-map.md) | First-party directories |
| [docs/work-plan.md](docs/work-plan.md) | Historical 1.2.3→1.3.0 list (not a current todo) |

## Development and testing

From a clean checkout of this repository (PHP 8.0+, `curl` or `wget`, Node for the JS tests):

```bash
bash tests/run.sh
```

That fetches a pinned PHPUnit phar, runs the PHPUnit suite (including concurrency workers and package-contract checks), and `node --test tests/js/*.test.js`.

Production ZIP (no tests, no this README):

```bash
bash scripts/build-plugin-zip.sh
```

See [`docs/release.md`](docs/release.md). There is no Composer or npm build step to run the plugin. First-party PHP, JavaScript and CSS ship readable.

**Identity (developers):** prefix `tpfw`; text domain `tickets-passes-for-woocommerce`; product types `tpfw-ticket`, `tpfw-timeslot-ticket`, `tpfw-pass`. A sold line is identified by a 21-character `nano_id`. The QR payload is a REST check-in URL.

**Bundled third-party (not documented here):** `inc/functions/lib/qrcodegen/` (QR), `inc/functions/lib/dompdf/` (PDF), `lib/air-datepicker/`, `inc/scanner/js/jsqr.js`, `inc/analytics-dashboard/lib/apexcharts.min.js`.

## Project provenance

**Original author:** Magnus V. (WordPress.org contributor [macvej](https://profiles.wordpress.org/macvej/)). The plugin header `Author:` and `readme.txt` `Contributors:` field keep that attribution.

**Upstream baseline:** Tickets & Passes for WooCommerce 1.2.3. This repository continues that work — development, security hardening, and refactoring. It is not a rewrite that replaces the original plugin, and the current maintainer did not author the 1.2.3 baseline.

**Current development:** [ricande/Tickets-Passes-for-WooCommerce](https://github.com/ricande/Tickets-Passes-for-WooCommerce).

## License

GPLv2 or later. See [`LICENSE`](LICENSE).

## Download

Ready to try it? Download the latest release candidate below.

**Tickets & Passes for WooCommerce 1.3.0 RC**

| | |
|---|---|
| Version | 1.3.0 RC |
| Status | Pre-release |
| File | `tickets-passes-for-woocommerce-1.3.0.zip` |
| SHA-256 | `207b087ea45b841b59a2a05d030650c51b6d2bce54847ad4eaa9ae60c2839002` |

[⬇ Download the WordPress plugin](https://github.com/ricande/Tickets-Passes-for-WooCommerce/releases/download/1.3.0-RC/tickets-passes-for-woocommerce-1.3.0.zip)

> This is a release candidate. Do not use GitHub's automatically generated **Source code** archives as the WordPress plugin package.
