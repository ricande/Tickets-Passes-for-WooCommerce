<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Scheduled maintenance for timeslot products.
 *
 * Two jobs: generating the next batch of recurring timeslots, and releasing reservations
 * whose hold has expired.
 *
 * @package Tickets_Passes_For_WooCommerce
 */
class TPFW_Cronjobs
{
	protected $oFunctions;

	/**
	 * @param TPFW_Functions $oFunctions     Shared helper instance.
	 */
	public function __construct($oFunctions) 
	{
		$this->oFunctions 		= $oFunctions;
		$this->load_cronjobs();
	}


	/**
	 * Registers the two recurring events and schedules them if they are not already queued.
	 *
	 * Both are cleared again on deactivation and uninstall - see tpfw_deactivate_plugin().
	 *
	 * @return void
	 */
	private function load_cronjobs() 
	{	
		add_filter('cron_schedules',                                array($this, 'tpfw_add_every_minutes_schedules'));

		add_action('tpfw_hourly_create_recurring_timeslots_cronjob', array($this, 'tpfw_hourly_create_recurring_timeslots_function'));
		if(!wp_next_scheduled('tpfw_hourly_create_recurring_timeslots_cronjob')) 
		{
			wp_schedule_event(time(), 'hourly', 'tpfw_hourly_create_recurring_timeslots_cronjob');
		}

		add_action('tpfw_minut_delete_expired_reservations_cronjob',                         array($this, 'tpfw_minut_delete_expired_reservations_function'));
		if(!wp_next_scheduled('tpfw_minut_delete_expired_reservations_cronjob')) 
		{
			wp_schedule_event(time(), 'tpfw_every_minute', 'tpfw_minut_delete_expired_reservations_cronjob');
		}

	}


	
	/**
	 * Adds the one-minute interval WP-Cron does not ship with.
	 *
	 * Reservations hold stock, so releasing them on the default five-minute floor would keep
	 * sold-out timeslots looking sold out for minutes after the cart expired.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public function tpfw_add_every_minutes_schedules($schedules) 
    {
        $schedules['tpfw_every_minute'] = array(
                'interval'  => 60,
                'display'   => __('Once per Minute', 'tickets-passes-for-woocommerce')
        );
        return $schedules;
    }



	/**
	 * Hourly: rolls recurring timeslot templates forward into concrete timeslots.
	 *
	 * @return void
	 */
	public function tpfw_hourly_create_recurring_timeslots_function() 
	{
		$this->oFunctions->create_recurring_timeslots();		
	}


	
	/**
	 * Every minute: releases timeslot reservations whose valid_to has passed.
	 *
	 * Reservations fall into two groups. Ones already attached to an order are only released
	 * if that order is still in a status the product marks as abandonable (default: anything
	 * short of paid), and the matching order line is removed with them. Ones never attached
	 * to an order - a cart that was simply left - are released unconditionally.
	 *
	 * Rows are soft-deleted (deleted = now) rather than removed, so the history stays intact.
	 *
	 * @return void
	 */
	public function tpfw_minut_delete_expired_reservations_function() 
    {					
        global $wpdb;
        $sReservationTableName = $wpdb->prefix . "tpfw_timeslot_reservations";
        $sCurrentDatetime      = current_time('mysql');
		$sUpdateReservationSQL = $wpdb->prepare('	SELECT * FROM %i				
				WHERE deleted IS NULL AND valid_to <= %s AND order_id IS NOT NULL AND order_line_id IS NOT NULL', $sReservationTableName, $sCurrentDatetime);                                                        
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sUpdateReservationSQL is the return value of $wpdb->prepare() above.
		$oOrderCreatedReservationResult = $wpdb->query($sUpdateReservationSQL); 	
		if(!empty($oOrderCreatedReservationResult))
		{
			foreach($oOrderCreatedReservationResult as $iOrderCreatedReservationKey => $oOrderCreatedReservation)
			{
				$oOrder = wc_get_order($oOrderCreatedReservation->order_id);
				if(empty($oOrder)) continue;
				if(empty($oOrder->get_items())) continue;

                $_timeslot_ticket_reservation_status_delete = get_post_meta($oOrderCreatedReservation->product_id, '_tpfw_timeslot_ticket_reservation_status_delete', true);
				// Matches the admin field's own default (product-edit-screen-woocommerce-general-tab) -
				// an unset/empty setting must not silently mean "never release", otherwise reservations on
				// products whose admin never touched this field are held forever past their valid_to.
				if(empty($_timeslot_ticket_reservation_status_delete) || !is_array($_timeslot_ticket_reservation_status_delete))
				{
					$_timeslot_ticket_reservation_status_delete = array('on-hold', 'cancelled', 'failed', 'checkout-draft', 'pending', 'processing');
				}
				// An order that ended without ever completing (refunded, trashed) is outside both
				// status lists by default, so its reservation used to be re-fetched here every
				// minute for ever. Nothing can issue a ticket from it any more, so it is released.
				$bOrderIsFinished = in_array($oOrder->get_status(), array('refunded', 'trash'), true);
				if($bOrderIsFinished || in_array($oOrder->get_status(), $_timeslot_ticket_reservation_status_delete, true))
				{
					$sUpdateReservationSQL = $wpdb->prepare('	UPDATE %i
																SET   deleted        = %s, updated = %s
																WHERE deleted IS NULL AND valid_to <= %s AND reservation_id=%s', $sReservationTableName, $sCurrentDatetime, $sCurrentDatetime, $sCurrentDatetime, $oOrderCreatedReservation->reservation_id);
        			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sUpdateReservationSQL is the return value of $wpdb->prepare() above.
        			$wpdb->query($sUpdateReservationSQL);
				}

				$bOrderChanged                            = false;
				$_timeslot_ticket_orderline_status_delete = get_post_meta($oOrderCreatedReservation->product_id, '_tpfw_timeslot_ticket_orderline_status_delete', true);
				if(empty($_timeslot_ticket_orderline_status_delete) || !is_array($_timeslot_ticket_orderline_status_delete))
				{
					$_timeslot_ticket_orderline_status_delete = array('on-hold', 'cancelled', 'failed', 'checkout-draft', 'pending', 'processing');
				}
				if(in_array($oOrder->get_status(), $_timeslot_ticket_orderline_status_delete, true))
				{
					foreach($oOrder->get_items() as $iOrderItemKey => $oOrderItem)
					{
						$oProduct = wc_get_product($oOrderItem->get_product_id());
						if(!is_a($oProduct, 'TPFW_Product_Timeslot_Ticket')) continue;
						if($oOrderItem->get_meta('tpfw_reservation_id', true) == null || $oOrderItem->get_meta('tpfw_reservation_id', true) == "") continue;
						if($oOrderItem->get_meta('tpfw_reservation_id', true) != $oOrderCreatedReservation->reservation_id) continue;
						$oOrder->remove_item($oOrderItem->get_id());
						$bOrderChanged = true;
					}
				}

				// This ran unconditionally, so every minute the job recalculated and re-saved
				// each matching order even when no line item had been touched - churning order
				// totals, notes and post revisions for nothing.
				if($bOrderChanged)
				{
					$oOrder->calculate_totals();
					$oOrder->save();
				}
			}
		}		
        $sUpdateReservationSQL = $wpdb->prepare('	UPDATE %i
                                                        SET   deleted        = %s, updated = %s
                                                        WHERE deleted IS NULL AND valid_to <= %s AND order_id IS NULL AND order_line_id IS NULL', $sReservationTableName, $sCurrentDatetime, $sCurrentDatetime, $sCurrentDatetime);                                                        
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sUpdateReservationSQL is the return value of $wpdb->prepare() above.
        $wpdb->query($sUpdateReservationSQL);                
    }
}