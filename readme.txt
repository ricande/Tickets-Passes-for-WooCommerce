=== Tickets & Passes for WooCommerce ===
Contributors: macvej
Tags: event tickets, qr code, ticket scanner, season pass, timeslot booking
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Requires Plugins: woocommerce
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell tickets, timeslot bookings and season passes in WooCommerce. Staff scan the QR at the door. No per-ticket fees, no external service.

== Description ==

Tickets & Passes adds three product types to WooCommerce: **Ticket**, **Timeslot Ticket** and **Pass**. Customers buy them like anything else in your shop, every purchase issues a QR code, and staff scan that code at the door from a phone browser.

Everything runs on your own site. There is no ticketing service to sign up for, no fee per ticket sold, and no account with anyone else.

Built for venues, attractions, museums, escape rooms, festivals, clubs and anyone who sells admission and then has to let people in.

= Three product types =

* **Ticket** - plain admission with a validity window and a set number of uses. Day tickets, entry tickets, gift admission. The customer can pick their own start date within a range you set, or you fix the date yourself.
* **Timeslot Ticket** - admission for a specific date and time, with a capacity per slot. Generate slots on a repeating schedule instead of building next month by hand. Unpaid reservations are released automatically, so an abandoned checkout never sits on a seat.
* **Pass** - valid across a period rather than a single visit: season passes, memberships, annual cards. Passes can include guest passes the holder gives away, carry a cardholder photo, and enforce a cooldown between scans.

= Check-in at the door =

* **A scanner page on your own domain**, at `/check-in/`. Full screen, no admin bar, nothing for door staff to get lost in.
* **No app to install.** Any modern mobile browser works. Where the phone has a native barcode detector the scanner uses it, otherwise it decodes in the page.
* **Colour-coded results** you choose: valid, already used, expired, on cooldown, or not found.
* **Photo check on passes.** Staff see the cardholder photo beside the scan result and can compare it with the person in front of them.
* **A Scanner user role** that grants check-in and nothing else in wp-admin.
* **Every decision is made on your server**, never on the phone, so a cancelled ticket cannot be waved through by putting the phone in airplane mode.
* **A REST check-in API** underneath, so a third-party or native scanner app can use the same endpoints.

= Running it from wp-admin =

* **A dashboard per product type** - Tickets, Timeslot Tickets and Passes - each with search, manual check-in, resend, reset and cancel.
* **Manual check-in** for when there is no phone at the door.
* **Analytics** charting check-in activity by day and by hour, with a CSV export.
* **Per-type colours and hint text**, so a ticket looks like part of your site rather than part of a plugin.
* **Shop Manager friendly.** Everything is gated on `manage_woocommerce`; nobody needs an Administrator account to run the door.

= What your customers get =

* **Tickets and Passes tabs** on the standard WooCommerce My Account page.
* **The QR code on screen** plus a printable PDF download.
* **Live status on every ticket** - active, used, expired, cancelled or not valid yet - with uses remaining.
* **Add to Calendar** on timeslot tickets, as a normal `.ics` file.
* **Guest passes** handed out from the account page, and a photo upload where a pass asks for one.

= Emails =

* The WooCommerce **order confirmation** and **completed order** emails are extended with ticket, timeslot ticket and pass details.
* **Resend Ticket** and **Resend Pass** templates for a re-sent QR code.
* **Guest Pass Invite** templates, worded separately for recipients who already have an account and those who do not.
* Every subject and body is edited in wp-admin and supports merge tags for order and ticket details.

= Customer files stay private =

QR codes, ticket PDFs and pass photos are not reachable at a guessable public URL. They live in a folder with a random name and are served through the plugin, which checks on every request that the caller holds a signed link from their own email or is signed in as the person the ticket belongs to. Responses are marked private and per-visitor, so a page cache or CDN can never hand one customer's ticket to the next visitor. Nothing to configure, and it behaves the same on Apache, nginx and IIS.

= Nothing to maintain =

An hourly background job generates the next batch of recurring timeslots. A per-minute job releases timeslot reservations that were never paid for.

= Works with =

* WooCommerce **HPOS** (High-Performance Order Storage) and the **block-based cart and checkout**.
* **Block themes** and classic themes.
* **Translation ready** - every string is translatable. The plugin ships a POT file and a Swedish catalog; WordPress language packs still win when they are present.
* **No build step.** All PHP, JavaScript and CSS ships readable and editable.

= Requirements =

* WooCommerce, installed and active. WordPress will not let the plugin activate without it.
* PHP 8.0 or newer.
* HTTPS, because phone browsers only give camera access to secure pages.

== Installation ==

**1. Install and activate**

In wp-admin go to **Plugins > Add New > Upload Plugin**, choose the zip and click **Install Now**, or upload the unzipped folder to `/wp-content/plugins/` over FTP. Activate from the **Plugins** screen with WooCommerce already active. Activation creates the plugin's database tables and refreshes permalinks, so the My Account tabs and the scanner URL work straight away.

**2. Turn on the product types you need**

Go to **Ticket & Passes > Settings**. Tick **Enable** on the **Ticket**, **Timeslot Ticket** and **Pass** tabs. Anything you leave off stays out of your way.

**3. Turn on the scanner**

On the **Scanner / API** tab, enable the API and the built-in scanner. The tab then shows your scanner URL, which is `/check-in/` on your own domain.

**4. Create a product**

**Products > Add New**, then pick **Ticket**, **Timeslot Ticket** or **Pass** from the **Product data** dropdown - the same dropdown that holds Simple and Variable. Validity, uses, capacity, recurring schedule and QR design appear below it.

**5. Give door staff access**

Edit the user under **Users** and set their role to **Scanner**. They can then open `/check-in/` on a phone and check people in, and see nothing else in wp-admin. Administrators and Shop Managers already have access.

= Settings at a glance =

Settings live under **Ticket & Passes > Settings** in six tabs. Only the tabs for the product types you sell need touching.

* **General** - the date and time format used across the plugin, and the switch for the Analytics screen.
* **Ticket** - enable the product type, its accent colour and its date picker styling.
* **Timeslot Ticket** - enable the product type, the date picker hint text, and seven colours for the date and timeslot picker.
* **Pass** - enable the product type, the hint text above the person fields, and five colours for the per-person cards.
* **Scanner / API** - the API switch, the built-in scanner switch, the scanner URL, the three scan-result colours, and an API endpoint reference.
* **Email** - the six editable templates and their merge tags.

== Frequently Asked Questions ==

= Does it need WooCommerce? =

Yes. The plugin adds WooCommerce product types and will not activate without WooCommerce active.

= Are there any per-ticket fees? =

No. There is no external service and nothing to sign up for. You sell through your own WooCommerce checkout, and what you charge is what you keep.

= Can I sell tickets and passes in the same shop? =

Yes. All three product types can be on at once, and they can sit in the same cart and the same order as your normal products.

= Do door staff have to install an app? =

No. The scanner is a web page that uses the phone camera. It needs a browser and HTTPS, which is what the camera API requires.

= Does the scanner work offline? =

No. Every scan is checked against your site as it happens, so the phone needs a connection at the door. That is also why nobody gets a cancelled ticket through by putting the phone in airplane mode.

= Who is allowed to check people in? =

Users with the Scanner role, and users with the `manage_woocommerce` capability, which covers Administrators and Shop Managers. The scanner page turns everyone else away.

= Can I check people in without a phone? =

Yes. Each dashboard in wp-admin has a manual check-in action, so you can find the ticket and let someone in from a computer.

= Can I use my own scanner app instead? =

Yes. Check-in runs through a REST API, and the endpoints are documented on the **Scanner / API** settings screen. The built-in scanner page and the API are independent switches, so you can run the API on its own.

= Can the same ticket be used twice? =

That is up to the product. Each ticket and pass has a maximum number of uses, and passes can have a cooldown so the same code cannot be scanned again immediately.

= Is the QR code on its own enough to let someone in? =

The QR code identifies the ticket. On passes that carry a photo, staff also see the cardholder photo at check-in and can compare it with the person in front of them.

= Does it work with HPOS and the block checkout? =

Yes. The plugin declares compatibility with WooCommerce High-Performance Order Storage and with the block-based cart and checkout, and the timeslot date picker works on block themes.

= What happens to my data if I delete the plugin? =

Settings, scheduled tasks and the Scanner role are removed. Your tickets, passes and check-in records are kept, so removing the plugin to test a conflict cannot wipe a season of admission history.

If you do want all of it gone, add this to `wp-config.php` before you delete the plugin:

`define( 'TPFW_REMOVE_ALL_DATA', true );`

That drops the plugin's database tables and deletes the generated QR codes, guest passes, PDFs and pass photos. It cannot be undone. Take a backup first.

= Does deactivating remove anything? =

No. Deactivating stops the plugin and clears its scheduled tasks. Settings and records are left alone, and reactivating picks up where you left off.

== Screenshots ==

1. A Ticket product page on the frontend, as the customer sees it before adding it to the cart.
2. A Timeslot Ticket product page, where the customer picks a date and then an available time.
3. A Pass product page, showing the pass details and the cardholder fields collected at purchase.
4. The Tickets tab in My Account, where customers view and download their tickets.
5. The ticket dashboard in wp-admin, with search, manual check-in, resend, reset and cancel.
6. The analytics screen, charting check-in activity by day and by hour.
7. The settings screen.

== Source Code and Third-Party Libraries ==

This plugin ships no compiled or obfuscated code. Every PHP, JavaScript and CSS file
written for this plugin is included in readable, editable form, and no build step
(npm, webpack, Composer, etc.) is required to run or modify it.

The following third-party libraries are bundled unmodified. Each is the upstream
distribution file, at the version listed:

* dompdf 3.1.6 - PDF rendering - https://github.com/dompdf/dompdf - LGPL-2.1
  (bundled in inc/functions/lib/dompdf/, installed with Composer, together with its
  dependencies dompdf/php-font-lib 1.0.2, dompdf/php-svg-lib 1.0.2, masterminds/html5
  2.10.1, sabberworm/php-css-parser 9.4.0 and thecodingmachine/safe 2.5.0)
* endroid/qr-code 4.8.2 - QR code generation - https://github.com/endroid/qr-code - MIT
  (bundled in inc/functions/lib/qrcodegen/, installed with Composer, together with its
  dependencies bacon/bacon-qr-code 2.0.8 and dasprid/enum 1.0.7)
* jsQR 1.4.0 - QR decoding in the check-in scanner - https://github.com/cozmo/jsQR - Apache-2.0
  (inc/scanner/js/jsqr.js)
* ApexCharts 6.9.0 - charts on the analytics screen - https://github.com/apexcharts/apexcharts.js - MIT
  (inc/analytics-dashboard/lib/apexcharts.min.js)
* Air Datepicker 3.6.0 - date picker for timeslots - https://github.com/t1m0n/air-datepicker - MIT
  (lib/air-datepicker/)

The two Composer-installed libraries can be regenerated from their composer.json with
`composer install`; the three JavaScript libraries are the unmodified `dist` files
published by their projects on npm, and their readable sources live in the linked
repositories.

== Changelog ==

The three most recent releases are below. The full history is in `changelog.txt` in the plugin folder.

= 1.3.0 =
* Guest passes cannot be scanned before the holder checks in, and the guest quota is enforced in the database. Timeslot seats are reserved and issued under a lock so the last place cannot be sold twice.
* Check-in is POST only (GET no longer lets anyone through). The API response is an allowlist, not the raw database row. Orders that sit in Processing (cash on delivery) now receive QR codes when they are paid.
* Swedish translations ship in the plugin; WordPress still owns the language. Existing tickets, QR codes and upload files from 1.2.3 keep working — the first load after the update migrates the guest-pass table.

= 1.2.3 =
* The colour settings on the Ticket, Timeslot Ticket and Pass tabs now sit beside a live preview of the card the customer actually sees, so a colour can be judged in place instead of by saving and opening a product page. Each tab previews its own product, and the preview can be shown against a light or a dark theme.
* Each colour row is now a swatch, a hex field and a strip of preset swatches from your theme's editor palette, with a "Use default color" link that is only live while the row differs from the colour it shipped with.

= 1.2.2 =
* Every row on the Tickets, Timeslot Tickets and Passes dashboards has a "Download" action, which gives you the holder's printable ticket or pass PDF - the same one they get from My Account. A customer who has lost their email can be helped without leaving the screen. It appears only on rows whose QR code was issued, and guest passes get their own.

Older releases: see `changelog.txt`.

== Upgrade Notice ==

= 1.3.0 =
Replace the plugin folder (or upload the new zip over 1.2.3). The first page load migrates the database. Existing tickets, passes and QR codes keep working. Check-in is now POST only — update any external scanner app that still uses GET. New guest passes stay inactive until the holder is scanned.

= 1.2.3 =
The colour settings on the Ticket, Timeslot Ticket and Pass tabs gain a live preview of the customer's card, preset swatches from your theme's palette, and a reset that shows at a glance which rows you have changed.

= 1.2.2 =
Every row on the Tickets, Timeslot Tickets and Passes dashboards gains a "Download" action that hands you the holder's printable ticket or pass PDF.

= 1.2.1 =
Bulk actions, sorting and status filters on the admin dashboards, ticket transfer to another customer, manual code entry and a flashlight in the scanner. Fixes timeslot capacity being enforced only after payment and recurring series landing on the wrong weekday. Includes several security fixes.

= 1.2.0 =
Security fix: QR codes, ticket PDFs and pass photos are no longer reachable at a public URL. Existing files move to a protected folder during the update, and links customers already hold keep working. Also lowers the requirements to PHP 8.0 and WordPress 6.5.
