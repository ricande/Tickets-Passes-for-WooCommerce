<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Serialises ticket/pass issue for one order line.
 *
 * Two WooCommerce hooks can fire issue at once; without a lock both read the same
 * shortfall and INSERT duplicate nano ids. Timeslot and guest quota have their own locks.
 */
class TPFW_Issue_Lock
{
	/**
	 * @param string $sType        'ticket' or 'pass'.
	 * @param int    $iOrderLineId WooCommerce order line id.
	 * @return string
	 */
	public static function name($sType, $iOrderLineId)
	{
		return 'tpfw_'.$sType.'_issue_'.(int)$iOrderLineId;
	}

	/**
	 * Runs $fnBody only while the line lock is held. Null means no lock — caller must not write.
	 *
	 * @param object   $wpdb
	 * @param string   $sType
	 * @param int      $iOrderLineId
	 * @param callable $fnBody
	 * @param int      $iTimeout
	 * @return mixed|null
	 */
	public static function with_line($wpdb, $sType, $iOrderLineId, $fnBody, $iTimeout = 5)
	{
		$oLock = TPFW_Named_Lock::acquire($wpdb, self::name($sType, $iOrderLineId), $iTimeout);
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
	 * Locked read + upsert for a ticket (or pass-parent) line.
	 *
	 * @param object   $wpdb
	 * @param string   $sType     Lock type: 'ticket' or 'pass'.
	 * @param string   $sTable    Prefixed table.
	 * @param string   $sSelect   Prepared SELECT of existing rows for this line.
	 * @param int      $iOrderLineId
	 * @param int      $iQuantity
	 * @param string   $sNow
	 * @param callable $fnInsert
	 * @param int      $iTimeout
	 * @return array{keep:string[],inserted:string[],deleted:string[]}|null
	 */
	public static function sync_line($wpdb, $sType, $sTable, $sSelect, $iOrderLineId, $iQuantity, $sNow, $fnInsert, $iTimeout = 5)
	{
		return self::with_line($wpdb, $sType, $iOrderLineId, function() use ($wpdb, $sTable, $sSelect, $iQuantity, $sNow, $fnInsert) {
			$sPrefix    = (string)$wpdb->prefix;
			$sSyncTable = $wpdb->prefix.(strpos((string)$sTable, $sPrefix) === 0 ? substr((string)$sTable, strlen($sPrefix)) : (string)$sTable);
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sSelect is the return value of $wpdb->prepare() at the caller.
			$aExisting = $wpdb->get_results($sSelect);
			return TPFW_Order_Line_Upsert::sync($wpdb, $sSyncTable, $aExisting, $iQuantity, $sNow, $fnInsert);
		}, $iTimeout);
	}
}
