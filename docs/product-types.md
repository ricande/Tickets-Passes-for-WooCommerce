# Product types

Overview: [architecture.md](architecture.md). File locations: [file-map.md](file-map.md). Meta keys: [data.md](data.md).

The three types share `TPFW_Product_Type`. A subclass sets `$sType`, `$sTable`, `$sStatsTable`, `$sMetaPrefix` and `$sProductClass`, then registers hooks in `load_settings_dependencies()`. WooCommerce instantiates `TPFW_Product_Ticket`, `TPFW_Product_Timeslot_Ticket` or `TPFW_Product_Pass` from `get_type()`.

Shared product-editor cards (Access & Usage, Check-in Rules, Sales Window, Validity Window, Product Note) are rendered and saved by the base class. Duration fields are stored as **seconds**.

Issuing is `woocommerce_order_status_processing` and `woocommerce_order_status_completed` → `order_maybe_issue()` → `TPFW_Issue_Policy::should_issue()` → `order_completed()` → `create_entry()` per matching line. Cancelling is `cancelled` / `refunded` / `failed` → `cancel_entry()` (soft-delete). Both are idempotent per order line via `TPFW_Order_Line_Upsert`: existing rows are reinstated up to quantity (same `nano_id`), surplus rows are soft-deleted, only the shortfall is inserted.

## Ticket (`tpfw-ticket`)

Class: `TPFW_Ticket_WC_Product`. Table: `tpfw_tickets`.

**How many may be sold** is WooCommerce stock (`_manage_stock` + `_stock`). The Inventory tab and the “Stock management” checkbox are tagged `show_if_tpfw-ticket` so they stay visible for this type. **Max Uses** is how many times one issued QR may be scanned, not stock.

**Validity start** is one of:

1. Purchase time (default)
2. Shop-fixed date (`_tpfw_ticket_predefined_start_date`)
3. Customer-picked date on the product page (`tpfw_start_date` on the cart/order line)

(2) and (3) are mutually exclusive. `valid_to` = start + `_tpfw_ticket_valid_duration` seconds. Changing duration on the product does **not** rewrite already issued rows.

**Sales window** (`_tpfw_ticket_sales_timespan_*`) hides the buy button and fails add-to-cart outside the dates.

`create_ticket()` uses the shared upsert. A completed → refunded → completed (or processing) cycle keeps the customer’s original codes.

## Timeslot Ticket (`tpfw-timeslot-ticket`)

Class: `TPFW_Timeslot_Ticket_WC_Product`. Cart, reservation and checkout hooks live in `TPFW_Timeslot_Ticket_Checkout`. Issued rows: `tpfw_timeslot_tickets`. Capacity lives on `tpfw_timeslots`, not WooCommerce stock, and is reserved and issued under `TPFW_Timeslot_Capacity` (named lock + `SELECT … FOR UPDATE`). The Inventory tab is hidden for this type.

**Slots**

- Manual rows in `tpfw_timeslots` (`manual = 1`)
- Recurring templates in `tpfw_timeslots_recurring` (weekday, slot times, week range, capacity). Hourly cron (`create_recurring_timeslots()`) fills concrete slots forward to the product’s “weeks ahead” setting, never past the series end. Existing generated or manual slots are skipped; unsold strays outside a moved window are retired.

**Reservation**

1. Add to cart validates the chosen slot still has capacity **under the capacity lock** and writes `tpfw_timeslot_reservations` (`valid_to` = hold expiry). Cart item meta carries `tpfw_timeslot_id` and `tpfw_reservation_id`.
2. Quantity in the cart is locked (`is_sold_individually` + classic quantity filter). Buying two seats is two cart lines / two add-to-carts.
3. Expired holds are swept on cart update, checkout init, `woocommerce_check_cart_items`, and the minutely cron. Removing a cart item releases its reservation immediately.
4. Creating an order (classic `woocommerce_checkout_order_created` or blocks `woocommerce_store_api_checkout_order_processed`) extends the hold onto the order.
5. A processing or completed order issues `tpfw_timeslot_tickets` for the reserved slot, again under the capacity lock. `valid_from` is the slot start minus `_tpfw_timeslot_ticket_before_checkin_duration` seconds (how early the door may scan); `valid_to` is the slot end. If the seat no longer fits, issue fails visibly (order note) and no extra row is written. Cancelled/refunded/failed revokes the ticket and frees capacity.

`.ics` export: `GET /tpfw/v1/timeslot-ticket/ics/{nano_id}` — open route; the nano id is the secret.

## Pass (`tpfw-pass`)

Class: `TPFW_Pass_WC_Product`. Table: `tpfw_pass` (holders and guests in one table).

**How many may be sold** is WooCommerce stock, same as Ticket (`show_if_tpfw-pass` on the Inventory tab).

The product page collects **one person per pass**. Add-to-cart splits into one cart line per person (`bSplittingCart` prevents the inner adds from recursing). Quantity is expressed as number of people, not a quantity field.

Each issued row has `user_id` (holder) and `user_payer_id` (buyer). Gifting: the line’s `tpfw_email` is someone else — an account is created if needed and a gifted-pass email is sent. Failure to send that email does not roll back the pass.

**Guest passes** (`parent_nano_id_fk` set): quantity and duration come from `_tpfw_pass_guest_pass_*`. Minting assigns `guest_slot` `1…N` (`TPFW_Guest_Pass_Issuer`); `UNIQUE(parent_nano_id_fk, guest_slot)` is what enforces the quota under concurrent AJAX. Their `valid_from` / `valid_to` stay empty until the **parent pass’s first successful check-in**, which runs `activate_guest_passes()` inside the parent’s check-in lock so a guest cannot walk in ahead of the holder. Guests already dated on 1.2.3 keep those dates.

**Photo**: optional upload on My Account (`_tpfw_pass_profile_image_upload_*`). Dimensions are refused before decode if a side is over 8000 px or width×height over 20 million (`TPFW_Image_Limits`). The scanner shows `sPhotoURL` on a successful pass/guest check-in.

Validity start follows the same three modes as Ticket. Cooldown meta for guests is `_tpfw_guestpass_cooldown_sec`, not the parent pass cooldown.
