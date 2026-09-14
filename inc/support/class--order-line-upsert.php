<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Shared exist → undelete up to qty → soft-delete surplus → insert shortfall.
 *
 * Ticket, timeslot and pass each used to copy this. Completing a refunded order must keep
 * the original nano ids rather than minting a second set of QR codes.
 */
class TPFW_Order_Line_Upsert
{
	/**
	 * @param object   $wpdb        Database handle.
	 * @param string   $sTable      Prefixed table name.
	 * @param array    $aExisting   Rows with nano_id, preferably ORDER BY -deleted.
	 * @param int      $iQuantity   Desired live count.
	 * @param string   $sNow        Datetime written to updated/deleted.
	 * @param callable $fnInsert    Called once per missing row; must return the new nano_id.
	 * @return array{keep:string[],inserted:string[],deleted:string[]}
	 */
	public static function sync($wpdb, $sTable, $aExisting, $iQuantity, $sNow, $fnInsert)
	{
		$aKeep   = array();
		$aDelete = array();
		$iQty    = max(0, (int)$iQuantity);
		$i       = 0;

		foreach((array)$aExisting as $mRow)
		{
			$sNano = is_object($mRow) ? (string)$mRow->nano_id : (string)$mRow['nano_id'];
			if($sNano === '')
			{
				continue;
			}
			if($i < $iQty)
			{
				$aKeep[] = $sNano;
				$i++;
			}
			else
			{
				$aDelete[] = $sNano;
			}
		}

		if(!empty($aKeep))
		{
			$sPlaceholders = implode(', ', array_fill(0, count($aKeep), '%s'));
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholder list matches $aKeep.
			$wpdb->query($wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IN() list is generated from count($aKeep).
				'UPDATE %i SET deleted = NULL, updated = %s WHERE nano_id IN ('.$sPlaceholders.')',
				array_merge(array($sTable, $sNow), $aKeep)
			));
		}

		if(!empty($aDelete))
		{
			$sPlaceholders = implode(', ', array_fill(0, count($aDelete), '%s'));
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholder list matches $aDelete.
			$wpdb->query($wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IN() list is generated from count($aDelete).
				'UPDATE %i SET deleted = %s, updated = %s WHERE nano_id IN ('.$sPlaceholders.')',
				array_merge(array($sTable, $sNow, $sNow), $aDelete)
			));
		}

		$aInserted = array();
		$iNeed     = $iQty - count($aKeep);
		for($n = 0; $n < $iNeed; $n++)
		{
			$sNano = call_user_func($fnInsert);
			if(is_string($sNano) && $sNano !== '')
			{
				$aInserted[] = $sNano;
			}
		}

		return array(
			'keep'      => array_merge($aKeep, $aInserted),
			'inserted'  => $aInserted,
			'deleted'   => $aDelete,
		);
	}
}
