<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Add-to-cart validation and reservation for timeslot tickets.
 *
 * Capacity check and reservation INSERT run under the same named lock so two carts cannot
 * both take the last seat. The WooCommerce product class forwards its cart hooks here.
 */
class TPFW_Timeslot_Ticket_Checkout
{
	/** @var TPFW_Functions */
	protected $oFunctions;

	/** Reservation written during validation, attached to cart item data in the action. */
	protected static $aPendingReservation = null;

	/**
	 * @param TPFW_Functions $oFunctions Shared helpers.
	 */
	public function __construct($oFunctions)
	{
		$this->oFunctions = $oFunctions;
	}

	/**
	 * @return array|null
	 */
	public static function pending_reservation()
	{
		return self::$aPendingReservation;
	}

	/**
	 * @return void
	 */
	public static function clear_pending_reservation()
	{
		self::$aPendingReservation = null;
	}

	/**
	 * @param bool $passed
	 * @param int  $product_id
	 * @param int  $request_quantity
	 * @return bool
	 */
	public function validate($passed, $product_id, $request_quantity)
	{
		self::$aPendingReservation = null;

		$oProduct = wc_get_product($product_id);
		if(empty($oProduct) || !is_a($oProduct, 'TPFW_Product_Timeslot_Ticket'))
		{
			return $passed;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only input used to validate the add-to-cart; WooCommerce's own add-to-cart flow owns the request.
		if(empty($_POST['timeslot-id']) || empty($_POST['timeslot-start']) || empty($_POST['timeslot-end']))
		{
			wc_add_notice(__('Please pick a timeslot before adding this to the cart.', 'tickets-passes-for-woocommerce'), 'error');
			return false;
		}

		if(get_post_meta($product_id, '_tpfw_timeslot_sales_timespan_enable', true) == 'yes')
		{
			$sWindowStart = get_post_meta($product_id, '_tpfw_timeslot_sales_timespan_start', true);
			$sWindowEnd   = get_post_meta($product_id, '_tpfw_timeslot_sales_timespan_end', true);
			if(!$this->oFunctions->is_within_sales_window($sWindowStart, $sWindowEnd))
			{
				wc_add_notice(__('This product is not available for purchase right now.', 'tickets-passes-for-woocommerce'), 'error');
				return false;
			}
		}

		global $wpdb;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only input used to validate the add-to-cart; WooCommerce's own add-to-cart flow owns the request.
		$sRequestedTimeslotID = sanitize_text_field(wp_unslash($_POST['timeslot-id'] ?? ''));
		$sNow                 = current_time('mysql');
		$iRequestQuantity     = max(1, absint($request_quantity));
		$bReservations        = get_post_meta($product_id, '_tpfw_timeslot_ticket_reservation_enable', true) == 'yes';
		$iReservationDuration = (int)get_post_meta($product_id, '_tpfw_timeslot_ticket_reservation_duration', true);

		$mLocked = TPFW_Timeslot_Capacity::with_lock($wpdb, $sRequestedTimeslotID, function() use ($wpdb, $sRequestedTimeslotID, $product_id, $sNow, $iRequestQuantity, $bReservations, $iReservationDuration) {
			$oTimeslot = $wpdb->get_row($wpdb->prepare(
				'SELECT id, available_slots FROM %i WHERE id = %s AND product_id = %d AND deleted IS NULL AND start >= %s',
				$wpdb->prefix.'tpfw_timeslots', $sRequestedTimeslotID, $product_id, $sNow
			));

			if(empty($oTimeslot))
			{
				return array('ok' => false, 'reason' => 'missing');
			}

			$iAvailable = TPFW_Timeslot_Capacity::remaining(
				$wpdb,
				$oTimeslot->id,
				(int)$oTimeslot->available_slots,
				$sNow,
				$bReservations
			);

			if($iAvailable <= 0 || $iAvailable < $iRequestQuantity)
			{
				return array('ok' => false, 'reason' => 'capacity');
			}

			$aReservation = null;
			if($bReservations && $iReservationDuration > 0)
			{
				$sReservationID = md5(microtime().wp_rand());
				$oCreate = $wpdb->prepare(
					'INSERT INTO %i (timeslot_id, reservation_id, product_id, quantity, user_id, valid_from, valid_to, created, updated)
					VALUES (%s, %s, %d, %d, %d, %s, %s, %s, %s);',
					array(
						$wpdb->prefix.'tpfw_timeslot_reservations',
						$sRequestedTimeslotID,
						$sReservationID,
						$product_id,
						$iRequestQuantity,
						get_current_user_id(),
						$sNow,
						gmdate('Y-m-d H:i:s', current_time('timestamp') + $iReservationDuration),
						$sNow,
						$sNow,
					)
				);
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oCreate is the return value of $wpdb->prepare() above.
				if($wpdb->query($oCreate) === false)
				{
					return array('ok' => false, 'reason' => 'capacity');
				}
				$aReservation = array(
					'reservation_id'   => $sReservationID,
					'reservation_time' => current_time('timestamp'),
				);
			}

			return array('ok' => true, 'reservation' => $aReservation);
		});

		if($mLocked === null)
		{
			wc_add_notice(__('That timeslot could not be reserved. Please try again.', 'tickets-passes-for-woocommerce'), 'error');
			return false;
		}

		if(empty($mLocked['ok']))
		{
			if(($mLocked['reason'] ?? '') === 'missing')
			{
				wc_add_notice(__('That timeslot is no longer available. Please pick another one.', 'tickets-passes-for-woocommerce'), 'error');
			}
			else
			{
				wc_add_notice(__('Unable to add because of lacking stock/quantity', 'tickets-passes-for-woocommerce'), 'error');
			}
			return false;
		}

		if(!empty($mLocked['reservation']))
		{
			self::$aPendingReservation = $mLocked['reservation'];
		}

		return $passed;
	}

	/**
	 * Copies the chosen timeslot onto the cart item. The reservation, when one was opened, was
	 * already written during validation.
	 *
	 * @param array $cart_item_data
	 * @param int   $product_id
	 * @param int   $variation_id
	 * @param int   $quantity
	 * @return array
	 */
	public function cart_item_data($cart_item_data, $product_id, $variation_id, $quantity)
	{
		$oProduct = wc_get_product($product_id);
		if(empty($oProduct) || !is_a($oProduct, 'TPFW_Product_Timeslot_Ticket'))
		{
			return $cart_item_data;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only input copied onto the cart item after validation.
		if(empty($_POST['timeslot-id']) || empty($_POST['timeslot-start']) || empty($_POST['timeslot-end']))
		{
			return $cart_item_data;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$cart_item_data['timeslot_id']    = sanitize_text_field(wp_unslash($_POST['timeslot-id'] ?? ''));
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$cart_item_data['timeslot_start'] = sanitize_text_field(wp_unslash($_POST['timeslot-start'] ?? ''));
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$cart_item_data['timeslot_end']   = sanitize_text_field(wp_unslash($_POST['timeslot-end'] ?? ''));
		$cart_item_data['unique_key']     = md5(microtime().wp_rand());

		if(is_array(self::$aPendingReservation))
		{
			$cart_item_data['reservation_id']   = self::$aPendingReservation['reservation_id'];
			$cart_item_data['reservation_time'] = self::$aPendingReservation['reservation_time'];
			$cart_item_data['unique_key']       = self::$aPendingReservation['reservation_id'];
			self::$aPendingReservation          = null;
		}

		return $cart_item_data;
	}
}
