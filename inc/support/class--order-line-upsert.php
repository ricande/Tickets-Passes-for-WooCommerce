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
	 * @param callable $fnInsert    Called once per missing row; return nano_id or false on write failure.
	 * @return array{keep:string[],inserted:string[],deleted:string[],ok:bool}
	 */
	public static function sync($wpdb, $sTable, $aExisting, $iQuantity, $sNow, $fnInsert)
	{
		if(!is_string($sTable) || $sTable === '')
		{
			throw new InvalidArgumentException('TPFW_Order_Line_Upsert::sync needs a prefixed table name');
		}

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
			$mUndelete = $wpdb->query($wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IN() list is generated from count($aKeep).
				'UPDATE %i SET deleted = NULL, updated = %s WHERE nano_id IN ('.$sPlaceholders.')',
				array_merge(array($sTable, $sNow), $aKeep)
			));
			if(TPFW_Db_Write::failed($mUndelete))
			{
				return array('keep' => $aKeep, 'inserted' => array(), 'deleted' => array(), 'ok' => false);
			}
		}

		if(!empty($aDelete))
		{
			$sPlaceholders = implode(', ', array_fill(0, count($aDelete), '%s'));
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholder list matches $aDelete.
			$mDelete = $wpdb->query($wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IN() list is generated from count($aDelete).
				'UPDATE %i SET deleted = %s, updated = %s WHERE nano_id IN ('.$sPlaceholders.')',
				array_merge(array($sTable, $sNow, $sNow), $aDelete)
			));
			if(TPFW_Db_Write::failed($mDelete))
			{
				return array('keep' => $aKeep, 'inserted' => array(), 'deleted' => $aDelete, 'ok' => false);
			}
		}

		$aInserted = array();
		$iNeed     = $iQty - count($aKeep);
		for($n = 0; $n < $iNeed; $n++)
		{
			$sNano = call_user_func($fnInsert);
			if($sNano === false)
			{
				return array(
					'keep'     => array_merge($aKeep, $aInserted),
					'inserted' => $aInserted,
					'deleted'  => $aDelete,
					'ok'       => false,
				);
			}
			if(is_string($sNano) && $sNano !== '')
			{
				$aInserted[] = $sNano;
			}
		}

		return array(
			'keep'      => array_merge($aKeep, $aInserted),
			'inserted'  => $aInserted,
			'deleted'   => $aDelete,
			'ok'        => true,
		);
	}

	/**
	 * Soft-deletes surplus live rows down to $iQuantity. Never inserts. Never undeletes.
	 *
	 * $aExisting must already be the live rows in deterministic order (id ASC): keep the
	 * first N, revoke the rest. Safe to call again with the same target.
	 *
	 * @param object   $wpdb
	 * @param string   $sTable
	 * @param array    $aExisting Live rows with nano_id, ordered id ASC.
	 * @param int      $iQuantity Desired live count.
	 * @param string   $sNow
	 * @return array{keep:string[],inserted:string[],deleted:string[],ok:bool}
	 */
	public static function shrink($wpdb, $sTable, $aExisting, $iQuantity, $sNow)
	{
		if(!is_string($sTable) || $sTable === '')
		{
			throw new InvalidArgumentException('TPFW_Order_Line_Upsert::shrink needs a prefixed table name');
		}

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

		if(!empty($aDelete))
		{
			$sPlaceholders = implode(', ', array_fill(0, count($aDelete), '%s'));
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholder list matches $aDelete.
			$mDelete = $wpdb->query($wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IN() list is generated from count($aDelete).
				'UPDATE %i SET deleted = %s, updated = %s WHERE nano_id IN ('.$sPlaceholders.')',
				array_merge(array($sTable, $sNow, $sNow), $aDelete)
			));
			if(TPFW_Db_Write::failed($mDelete))
			{
				return array(
					'keep'     => $aKeep,
					'inserted' => array(),
					'deleted'  => $aDelete,
					'ok'       => false,
				);
			}
		}

		return array(
			'keep'     => $aKeep,
			'inserted' => array(),
			'deleted'  => $aDelete,
			'ok'       => true,
		);
	}
}
