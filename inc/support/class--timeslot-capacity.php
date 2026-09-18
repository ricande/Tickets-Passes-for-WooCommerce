<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Remaining seats on a timeslot, serialised with a named lock.
 *
 * Add-to-cart used to validate capacity and INSERT the reservation later, so two carts could
 * both pass the check for the last seat. Issue counted sold tickets and then inserted, so a
 * paid order could be completed with only an order note when it did not fit.
 */
class TPFW_Timeslot_Capacity
{
	/**
	 * @param object $wpdb
	 * @param string $sTimeslotID
	 * @param int    $iTimeout
	 * @return TPFW_Named_Lock
	 */
	public static function acquire_lock($wpdb, $sTimeslotID, $iTimeout = 5)
	{
		return TPFW_Named_Lock::acquire($wpdb, 'tpfw_timeslot_'.$sTimeslotID, $iTimeout);
	}

	/**
	 * @param object   $wpdb
	 * @param string   $sTimeslotID
	 * @param callable $fnBody
	 * @return mixed|null Null when the lock was not held.
	 */
	public static function with_lock($wpdb, $sTimeslotID, $fnBody)
	{
		$oLock = self::acquire_lock($wpdb, $sTimeslotID);
		if(!$oLock->held())
		{
			return null;
		}
		try
		{
			return call_user_func($fnBody);
		}
		finally
		{
			$oLock->release();
		}
	}

	/**
	 * Seats still free on the slot.
	 *
	 * @param object $wpdb
	 * @param string $sTimeslotID
	 * @param int    $iCapacity
	 * @param string $sNow                 MySQL datetime; reservations with valid_to after this count.
	 * @param bool   $bCountReservations
	 * @param int    $iExcludeOrderID      Order whose own tickets should not count (issue path).
	 * @param int    $iExcludeLineID
	 * @return int|false Remaining seats, or false when a capacity read failed.
	 */
	public static function remaining($wpdb, $sTimeslotID, $iCapacity, $sNow, $bCountReservations, $iExcludeOrderID = 0, $iExcludeLineID = 0)
	{
		$sTickets = $wpdb->prefix.'tpfw_timeslot_tickets';
		if((int)$iExcludeOrderID > 0 && (int)$iExcludeLineID > 0)
		{
			$mSold = $wpdb->get_var($wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE timeslot_id = %s AND deleted IS NULL AND NOT (order_id = %d AND order_line_id = %d);',
				$sTickets, $sTimeslotID, (int)$iExcludeOrderID, (int)$iExcludeLineID
			));
		}
		else
		{
			$mSold = $wpdb->get_var($wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE timeslot_id = %s AND deleted IS NULL;',
				$sTickets, $sTimeslotID
			));
		}
		if(TPFW_Db_Read::var_failed($wpdb, $mSold))
		{
			return false;
		}
		$iSold = (int)$mSold;

		$iReserved = 0;
		if($bCountReservations)
		{
			$mReserved = $wpdb->get_var($wpdb->prepare(
				'SELECT COALESCE(SUM(quantity), 0) FROM %i WHERE deleted IS NULL AND valid_to > %s AND timeslot_id = %s;',
				$wpdb->prefix.'tpfw_timeslot_reservations', $sNow, $sTimeslotID
			));
			if(TPFW_Db_Read::var_failed($wpdb, $mReserved))
			{
				return false;
			}
			$iReserved = (int)$mReserved;
		}

		return max(0, (int)$iCapacity - $iSold - $iReserved);
	}

	/**
	 * @param int $iSold
	 * @param int $iQuantity
	 * @param int $iCapacity
	 * @return bool
	 */
	public static function quantity_fits($iSold, $iQuantity, $iCapacity)
	{
		return ((int)$iSold + (int)$iQuantity) <= (int)$iCapacity;
	}
}
