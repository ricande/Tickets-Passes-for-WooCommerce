<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Serialises every regular-ticket admission and lifecycle write for one order line.
 *
 * The only named lock is tpfw_ticket_issue_{order_line_id}, taken through
 * TPFW_Issue_Lock::with_line(). Public wrappers acquire that lock. The *_held
 * methods do not GET_LOCK: call them only from a callback that already holds
 * the line lock so this connection never nests GET_LOCK.
 *
 * Issue, full cancel, refund/shrink, dashboard nano cancel/reset/transfer and
 * ticket check-in all use that same lock. A ticket operation never holds a
 * second named lock. Pass, timeslot and guest-pass check-in keep tpfw_checkin_{nano}.
 *
 * Full cancel/refund identifies live rows by product_id, order_id and order_line_id.
 * Holder user_id is mutable (admin transfer) and must not be part of that match.
 *
 * The first nano lookup is only for the line id. The row is re-read under the
 * lock before any write. Dashboard cancel writes deleted and manual_cancelled_at.
 * Later issue/reconcile must not undelete a still-blocked nano or mint a replacement.
 */
class TPFW_Ticket_Line
{
	const TABLE       = 'tpfw_tickets';
	const STATS_TABLE = 'tpfw_tickets_stats';

	/**
	 * @param object   $wpdb
	 * @param int      $iOrderLineId
	 * @param callable $fnHeld
	 * @param int      $iTimeout
	 * @return mixed|null
	 */
	public static function with_lock($wpdb, $iOrderLineId, $fnHeld, $iTimeout = 5)
	{
		return TPFW_Issue_Lock::with_line($wpdb, 'ticket', $iOrderLineId, $fnHeld, $iTimeout);
	}

	/**
	 * @param int $iOrderLineId
	 * @return string
	 */
	public static function lock_name($iOrderLineId)
	{
		return TPFW_Issue_Lock::name('ticket', $iOrderLineId);
	}

	/**
	 * @return array{sMessage:string,bStatus:bool,sync:null}
	 */
	public static function lock_failed_issue()
	{
		return array(
			'sMessage' => __('Could not issue tickets for this order line because another request is in progress. No extra tickets were created.', 'tickets-passes-for-woocommerce'),
			'bStatus'  => false,
			'sync'     => null,
		);
	}

	/**
	 * @return array{sMessage:string,bStatus:bool,sync:null}
	 */
	public static function lock_failed_cancel()
	{
		return array(
			'sMessage' => __('Could not cancel tickets for this order line because another request is in progress. No tickets were changed.', 'tickets-passes-for-woocommerce'),
			'bStatus'  => false,
			'sync'     => null,
		);
	}

	/**
	 * @return array{sMessage:string,bStatus:bool,sync:null}
	 */
	public static function lock_failed_reconcile()
	{
		return array(
			'sMessage' => __('Could not reconcile tickets for this order line because another request is in progress. No tickets were changed.', 'tickets-passes-for-woocommerce'),
			'bStatus'  => false,
			'sync'     => null,
		);
	}

	/**
	 * Issue or reissue live ticket rows for one line. Re-reads issue_quantity under the lock.
	 *
	 * @param object        $wpdb
	 * @param object        $oOrderItem
	 * @param int           $iOrderID
	 * @param string        $sNow
	 * @param callable      $fnInsert
	 * @param callable|null $fnAfterCancelRow
	 * @param int           $iTimeout
	 * @return array{sMessage:string,bStatus:bool,sync:?array}
	 */
	public static function issue($wpdb, $oOrderItem, $iOrderID, $sNow, $fnInsert, $fnAfterCancelRow = null, $iTimeout = 5)
	{
		$m = self::with_lock($wpdb, (int)$oOrderItem->get_id(), function() use ($wpdb, $oOrderItem, $iOrderID, $sNow, $fnInsert, $fnAfterCancelRow) {
			return self::issue_held($wpdb, $oOrderItem, $iOrderID, $sNow, $fnInsert, $fnAfterCancelRow);
		}, $iTimeout);
		if($m === null)
		{
			return self::lock_failed_issue();
		}
		return $m;
	}

	/**
	 * Full cancel of live ticket rows for one line, regardless of current holder user_id.
	 *
	 * @param object        $wpdb
	 * @param object        $oOrderItem
	 * @param int           $iOrderID
	 * @param callable|null $fnAfterRow
	 * @param int           $iTimeout
	 * @return array{sMessage:string,bStatus:bool,sync:?array}
	 */
	public static function cancel($wpdb, $oOrderItem, $iOrderID, $fnAfterRow = null, $iTimeout = 5)
	{
		$m = self::with_lock($wpdb, (int)$oOrderItem->get_id(), function() use ($wpdb, $oOrderItem, $iOrderID, $fnAfterRow) {
			return self::cancel_held($wpdb, $oOrderItem, $iOrderID, $fnAfterRow);
		}, $iTimeout);
		if($m === null)
		{
			return self::lock_failed_cancel();
		}
		return $m;
	}

	/**
	 * Partial-refund shrink or full revoke. Re-reads target_active_quantity under the lock.
	 *
	 * @param object        $wpdb
	 * @param object        $oOrderItem
	 * @param int           $iOrderID
	 * @param callable|null $fnAfterRevoke
	 * @param callable|null $fnAfterCancelRow
	 * @param int           $iTimeout
	 * @return array{sMessage:string,bStatus:bool,sync:?array}
	 */
	public static function reconcile($wpdb, $oOrderItem, $iOrderID, $fnAfterRevoke = null, $fnAfterCancelRow = null, $iTimeout = 5)
	{
		$m = self::with_lock($wpdb, (int)$oOrderItem->get_id(), function() use ($wpdb, $oOrderItem, $iOrderID, $fnAfterRevoke, $fnAfterCancelRow) {
			return self::reconcile_held($wpdb, $oOrderItem, $iOrderID, $fnAfterRevoke, $fnAfterCancelRow);
		}, $iTimeout);
		if($m === null)
		{
			return self::lock_failed_reconcile();
		}
		return $m;
	}

	/**
	 * @internal Call only from TPFW_Ticket_Line::issue() while tpfw_ticket_issue_{line} is held.
	 *           Does not GET_LOCK. issue_quantity is read here, not before the lock.
	 *
	 * @param object        $wpdb
	 * @param object        $oOrderItem
	 * @param int           $iOrderID
	 * @param string        $sNow
	 * @param callable      $fnInsert
	 * @param callable|null $fnAfterCancelRow
	 * @return array{sMessage:string,bStatus:bool,sync:?array}
	 */
	public static function issue_held($wpdb, $oOrderItem, $iOrderID, $sNow, $fnInsert, $fnAfterCancelRow = null)
	{
		$oOrder = function_exists('wc_get_order') ? wc_get_order($iOrderID) : null;
		$iQty   = TPFW_Refund_Policy::issue_quantity($oOrder, $oOrderItem);
		if($iQty <= 0)
		{
			return self::cancel_held($wpdb, $oOrderItem, $iOrderID, $fnAfterCancelRow);
		}

		$sTable  = $wpdb->prefix.self::TABLE;
		$aExisting = $wpdb->get_results($wpdb->prepare(
			'SELECT * FROM %i WHERE product_id = %d AND order_id = %d AND order_line_id = %d ORDER BY id ASC',
			array(
				$sTable,
				$oOrderItem->get_product_id(),
				$iOrderID,
				$oOrderItem->get_id(),
			)
		));
		if($aExisting === false)
		{
			return array(
				'sMessage' => __('Could not issue tickets for this order line because the database write failed. No extra tickets were created.', 'tickets-passes-for-woocommerce'),
				'bStatus'  => false,
				'sync'     => null,
			);
		}
		$aSync = self::sync_slots_held($wpdb, $sTable, $aExisting, $iQty, $sNow, $fnInsert);
		if(empty($aSync['ok']))
		{
			return array(
				'sMessage' => __('Could not issue tickets for this order line because the database write failed. No extra tickets were created.', 'tickets-passes-for-woocommerce'),
				'bStatus'  => false,
				'sync'     => $aSync,
			);
		}
		return array(
			'sMessage' => __('Ticket for order line created', 'tickets-passes-for-woocommerce'),
			'bStatus'  => true,
			'sync'     => $aSync,
		);
	}

	/**
	 * @internal Call only from issue_held(), reconcile_held(), or TPFW_Ticket_Line::cancel()
	 *           while tpfw_ticket_issue_{line} is held. Does not GET_LOCK.
	 *
	 * @param object        $wpdb
	 * @param object        $oOrderItem
	 * @param int           $iOrderID
	 * @param callable|null $fnAfterRow function(object $oRow, int $iMetaIndex): void
	 * @return array{sMessage:string,bStatus:bool,sync:?array}
	 */
	public static function cancel_held($wpdb, $oOrderItem, $iOrderID, $fnAfterRow = null)
	{
		$sTable = $wpdb->prefix.self::TABLE;
		$oExistsPrepared = $wpdb->prepare(
			'SELECT * FROM %i WHERE product_id = %d AND order_id = %d AND order_line_id = %d AND deleted IS NULL ORDER BY id ASC',
			array(
				$sTable,
				$oOrderItem->get_product_id(),
				$iOrderID,
				$oOrderItem->get_id(),
			)
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oExistsPrepared is the return value of $wpdb->prepare() above.
		$oExistsResult = $wpdb->get_results($oExistsPrepared);
		if($oExistsResult === false)
		{
			return array(
				'sMessage' => __('Could not cancel tickets for this order line because the database write failed.', 'tickets-passes-for-woocommerce'),
				'bStatus'  => false,
				'sync'     => null,
			);
		}
		if(empty($oExistsResult))
		{
			return array(
				'sMessage' => __('Ticket for this order line already seems to be cancelled', 'tickets-passes-for-woocommerce'),
				'bStatus'  => false,
				'sync'     => null,
			);
		}
		return self::cancel_live_rows($wpdb, $oOrderItem, $iOrderID, $oExistsResult, $fnAfterRow);
	}

	/**
	 * @internal Call only from TPFW_Ticket_Line::reconcile() or a with_lock() callback
	 *           while tpfw_ticket_issue_{line} is held. Does not GET_LOCK.
	 *           target_active_quantity is read here, not from a pre-lock snapshot.
	 *
	 * @param object        $wpdb
	 * @param object        $oOrderItem
	 * @param int           $iOrderID
	 * @param callable|null $fnAfterRevoke
	 * @param callable|null $fnAfterCancelRow
	 * @return array{sMessage:string,bStatus:bool,sync:?array}
	 */
	public static function reconcile_held($wpdb, $oOrderItem, $iOrderID, $fnAfterRevoke = null, $fnAfterCancelRow = null)
	{
		$oOrder  = function_exists('wc_get_order') ? wc_get_order($iOrderID) : null;
		$iTarget = TPFW_Refund_Policy::target_active_quantity($oOrder, $oOrderItem);
		if($iTarget <= 0)
		{
			return self::cancel_held($wpdb, $oOrderItem, $iOrderID, $fnAfterCancelRow);
		}

		$sNow   = current_time('mysql');
		$sTable = $wpdb->prefix.self::TABLE;
		$aRows  = $wpdb->get_results($wpdb->prepare(
			'SELECT * FROM %i WHERE product_id = %d AND order_id = %d AND order_line_id = %d ORDER BY id ASC',
			array(
				$sTable,
				$oOrderItem->get_product_id(),
				$iOrderID,
				$oOrderItem->get_id(),
			)
		));
		if($aRows === false)
		{
			return array(
				'sMessage' => __('Could not reconcile issued quantity because the database write failed.', 'tickets-passes-for-woocommerce'),
				'bStatus'  => false,
				'sync'     => null,
			);
		}
		$iBlockedRetained = 0;
		$iSlot            = 0;
		foreach((array)$aRows as $oRow)
		{
			if(!is_object($oRow) || (string)$oRow->nano_id === '')
			{
				continue;
			}
			if($iSlot < $iTarget && self::row_is_manual_cancelled($oRow))
			{
				$iBlockedRetained++;
			}
			$iSlot++;
		}
		$aLive = array();
		foreach((array)$aRows as $oRow)
		{
			if(is_object($oRow) && (string)$oRow->nano_id !== '' && !self::row_is_deleted($oRow))
			{
				$aLive[] = $oRow;
			}
		}
		$iLiveTarget = max(0, $iTarget - $iBlockedRetained);
		$aDelete     = self::surplus_nanos($aLive, $iLiveTarget);
		if($aDelete === array())
		{
			return array(
				'sMessage' => '',
				'bStatus'  => true,
				'sync'     => array(
					'keep'     => self::keep_nanos($aLive, $iLiveTarget),
					'inserted' => array(),
					'deleted'  => array(),
					'ok'       => true,
				),
			);
		}
		return self::shrink_live_rows($wpdb, $oOrderItem, $iOrderID, $sTable, $aLive, $aDelete, $iLiveTarget, $sNow, $fnAfterRevoke);
	}

	/**
	 * @return array{bSuccess:bool,sMessage:string,oRow:?object}
	 */
	public static function lock_failed_nano_cancel()
	{
		return array(
			'bSuccess' => false,
			'sMessage' => __('Could not cancel this ticket because another request is in progress. The ticket was not changed.', 'tickets-passes-for-woocommerce'),
			'oRow'     => null,
		);
	}

	/**
	 * @return array{bSuccess:bool,sMessage:string,oRow:?object}
	 */
	public static function lock_failed_nano_reset()
	{
		return array(
			'bSuccess' => false,
			'sMessage' => __('Could not reset this ticket because another request is in progress. The ticket was not changed.', 'tickets-passes-for-woocommerce'),
			'oRow'     => null,
		);
	}

	/**
	 * @return array{bSuccess:bool,sMessage:string,oRow:?object}
	 */
	public static function lock_failed_nano_transfer()
	{
		return array(
			'bSuccess' => false,
			'sMessage' => __('Could not transfer this ticket because another request is in progress. The ticket was not changed.', 'tickets-passes-for-woocommerce'),
			'oRow'     => null,
		);
	}

	/**
	 * Dashboard per-nano cancel. Probe is not authoritative.
	 *
	 * @param object $wpdb
	 * @param string $sNanoID
	 * @param int    $iTimeout
	 * @return array{bSuccess:bool,sMessage:string,oRow:?object}
	 */
	public static function cancel_nano($wpdb, $sNanoID, $iTimeout = 5)
	{
		$aProbe = self::probe_line($wpdb, $sNanoID);
		if($aProbe['bSuccess'] === false)
		{
			return $aProbe;
		}
		$iLine = (int)$aProbe['iLine'];
		$m = self::with_lock($wpdb, $iLine, function() use ($wpdb, $sNanoID, $iLine) {
			return self::cancel_nano_held($wpdb, $sNanoID, $iLine);
		}, $iTimeout);
		if($m === null)
		{
			return self::lock_failed_nano_cancel();
		}
		return $m;
	}

	/**
	 * @internal Call only from cancel_nano() while tpfw_ticket_issue_{line} is held.
	 *
	 * @param object $wpdb
	 * @param string $sNanoID
	 * @param int    $iOrderLineId
	 * @return array{bSuccess:bool,sMessage:string,oRow:?object}
	 */
	public static function cancel_nano_held($wpdb, $sNanoID, $iOrderLineId)
	{
		$oRow = self::read_nano($wpdb, $sNanoID);
		if($oRow === false)
		{
			return self::nano_write_failed_cancel();
		}
		if(!self::row_matches_line($oRow, $sNanoID, $iOrderLineId))
		{
			return self::nano_gone();
		}
		$sNow    = current_time('mysql');
		$sTable  = $wpdb->prefix.self::TABLE;
		$mTicket = $wpdb->query($wpdb->prepare(
			'UPDATE %i SET deleted = %s, manual_cancelled_at = %s, updated = %s WHERE nano_id = %s AND order_line_id = %d',
			$sTable,
			$sNow,
			$sNow,
			$sNow,
			$sNanoID,
			$iOrderLineId
		));
		if(TPFW_Db_Write::failed($mTicket))
		{
			return self::nano_write_failed_cancel();
		}
		$oAgain = self::read_nano($wpdb, $sNanoID);
		if($oAgain === false)
		{
			return self::nano_write_failed_cancel();
		}
		if(!self::row_matches_line($oAgain, $sNanoID, $iOrderLineId))
		{
			return self::nano_gone();
		}
		if(!self::row_is_deleted($oAgain) || !self::row_is_manual_cancelled($oAgain))
		{
			return self::nano_write_failed_cancel();
		}
		$mStats = self::revoke_stats($wpdb, $sNanoID, $sNow);
		if($mStats === false)
		{
			return self::nano_write_failed_cancel();
		}
		return array(
			'bSuccess' => true,
			'sMessage' => sprintf(
				/* translators: %s: ticket nano id */
				__('Cancelled Ticket with Nano ID: %s', 'tickets-passes-for-woocommerce'),
				$sNanoID
			),
			'oRow'     => $oRow,
		);
	}

	/**
	 * Dashboard per-nano reset.
	 *
	 * @param object $wpdb
	 * @param string $sNanoID
	 * @param int    $iTimeout
	 * @return array{bSuccess:bool,sMessage:string,oRow:?object}
	 */
	public static function reset_nano($wpdb, $sNanoID, $iTimeout = 5)
	{
		$aProbe = self::probe_line($wpdb, $sNanoID);
		if($aProbe['bSuccess'] === false)
		{
			return $aProbe;
		}
		$iLine = (int)$aProbe['iLine'];
		$m = self::with_lock($wpdb, $iLine, function() use ($wpdb, $sNanoID, $iLine) {
			return self::reset_nano_held($wpdb, $sNanoID, $iLine);
		}, $iTimeout);
		if($m === null)
		{
			return self::lock_failed_nano_reset();
		}
		return $m;
	}

	/**
	 * @internal Call only from reset_nano() while tpfw_ticket_issue_{line} is held.
	 *
	 * @param object $wpdb
	 * @param string $sNanoID
	 * @param int    $iOrderLineId
	 * @return array{bSuccess:bool,sMessage:string,oRow:?object}
	 */
	public static function reset_nano_held($wpdb, $sNanoID, $iOrderLineId)
	{
		$oRow = self::read_nano($wpdb, $sNanoID);
		if($oRow === false)
		{
			return self::nano_write_failed_reset();
		}
		if(!self::row_matches_line($oRow, $sNanoID, $iOrderLineId))
		{
			return self::nano_gone();
		}
		$sNow    = current_time('mysql');
		$sTable  = $wpdb->prefix.self::TABLE;
		$iTarget = self::reset_target_quantity($wpdb, $oRow);
		if($iTarget === false)
		{
			return self::nano_write_failed_reset();
		}
		$iSlot = self::slot_index_held($wpdb, $oRow, $sNanoID);
		if($iSlot === false)
		{
			return self::nano_write_failed_reset();
		}
		$bLive = $iSlot >= 0 && $iSlot < (int)$iTarget;
		if($bLive)
		{
			$mTicket = $wpdb->query($wpdb->prepare(
				'UPDATE %i SET deleted = NULL, manual_cancelled_at = NULL, updated = %s WHERE nano_id = %s AND order_line_id = %d',
				$sTable,
				$sNow,
				$sNanoID,
				$iOrderLineId
			));
		}
		else
		{
			$mTicket = $wpdb->query($wpdb->prepare(
				'UPDATE %i SET deleted = %s, manual_cancelled_at = NULL, updated = %s WHERE nano_id = %s AND order_line_id = %d',
				$sTable,
				$sNow,
				$sNow,
				$sNanoID,
				$iOrderLineId
			));
		}
		if(TPFW_Db_Write::failed($mTicket))
		{
			return self::nano_write_failed_reset();
		}
		$oAgain = self::read_nano($wpdb, $sNanoID);
		if($oAgain === false)
		{
			return self::nano_write_failed_reset();
		}
		if(!self::row_matches_line($oAgain, $sNanoID, $iOrderLineId))
		{
			return self::nano_gone();
		}
		if(self::row_is_manual_cancelled($oAgain) || ($bLive && self::row_is_deleted($oAgain)) || (!$bLive && !self::row_is_deleted($oAgain)))
		{
			return self::nano_write_failed_reset();
		}
		$mStats = $wpdb->query($wpdb->prepare(
			'DELETE FROM %i WHERE nano_id_fk = %s',
			$wpdb->prefix.self::STATS_TABLE,
			$sNanoID
		));
		if(TPFW_Db_Write::failed($mStats))
		{
			return self::nano_write_failed_reset();
		}
		return array(
			'bSuccess' => true,
			'sMessage' => sprintf(
				/* translators: %s: ticket nano id */
				__('Ticket with ID, %s, has been reset', 'tickets-passes-for-woocommerce'),
				$sNanoID
			),
			'oRow'     => $oRow,
		);
	}

	/**
	 * Dashboard transfer. Refuses a row that is no longer live.
	 *
	 * @param object $wpdb
	 * @param string $sNanoID
	 * @param int    $iNewUserID
	 * @param int    $iTimeout
	 * @return array{bSuccess:bool,sMessage:string,oRow:?object}
	 */
	public static function transfer_nano($wpdb, $sNanoID, $iNewUserID, $iTimeout = 5)
	{
		$iNewUserID = (int)$iNewUserID;
		if($iNewUserID <= 0)
		{
			return self::nano_gone();
		}
		$aProbe = self::probe_line($wpdb, $sNanoID);
		if($aProbe['bSuccess'] === false)
		{
			return $aProbe;
		}
		$iLine = (int)$aProbe['iLine'];
		$m = self::with_lock($wpdb, $iLine, function() use ($wpdb, $sNanoID, $iLine, $iNewUserID) {
			return self::transfer_nano_held($wpdb, $sNanoID, $iLine, $iNewUserID);
		}, $iTimeout);
		if($m === null)
		{
			return self::lock_failed_nano_transfer();
		}
		return $m;
	}

	/**
	 * @internal Call only from transfer_nano() while tpfw_ticket_issue_{line} is held.
	 *
	 * @param object $wpdb
	 * @param string $sNanoID
	 * @param int    $iOrderLineId
	 * @param int    $iNewUserID
	 * @return array{bSuccess:bool,sMessage:string,oRow:?object}
	 */
	public static function transfer_nano_held($wpdb, $sNanoID, $iOrderLineId, $iNewUserID)
	{
		$oRow = self::read_nano($wpdb, $sNanoID);
		if($oRow === false)
		{
			return self::nano_write_failed_transfer();
		}
		if(!self::row_matches_line($oRow, $sNanoID, $iOrderLineId) || self::row_is_deleted($oRow))
		{
			return array(
				'bSuccess' => false,
				'sMessage' => __('No active row was found for the provided Nano ID', 'tickets-passes-for-woocommerce'),
				'oRow'     => null,
			);
		}
		if((int)$oRow->user_id === (int)$iNewUserID)
		{
			return array(
				'bSuccess' => false,
				'sMessage' => __('That account already holds this item.', 'tickets-passes-for-woocommerce'),
				'oRow'     => $oRow,
			);
		}
		$iOldUserID = (int)$oRow->user_id;
		$sNow   = current_time('mysql');
		$mUp    = $wpdb->query($wpdb->prepare(
			'UPDATE %i SET user_id = %d, updated = %s WHERE nano_id = %s AND order_line_id = %d AND deleted IS NULL',
			$wpdb->prefix.self::TABLE,
			(int)$iNewUserID,
			$sNow,
			$sNanoID,
			$iOrderLineId
		));
		if(TPFW_Db_Write::failed($mUp))
		{
			return self::nano_write_failed_transfer();
		}
		if((int)$mUp === 0)
		{
			$oAgain = self::read_nano($wpdb, $sNanoID);
			if($oAgain === false)
			{
				return self::nano_write_failed_transfer();
			}
			return array(
				'bSuccess' => false,
				'sMessage' => __('No active row was found for the provided Nano ID', 'tickets-passes-for-woocommerce'),
				'oRow'     => null,
			);
		}
		$oFresh = self::read_nano($wpdb, $sNanoID);
		if($oFresh === false)
		{
			return self::nano_write_failed_transfer();
		}
		if($oFresh === null
			|| self::row_is_deleted($oFresh)
			|| (int)$oFresh->order_line_id !== (int)$iOrderLineId
			|| (int)$oFresh->user_id !== (int)$iNewUserID)
		{
			return array(
				'bSuccess' => false,
				'sMessage' => __('No active row was found for the provided Nano ID', 'tickets-passes-for-woocommerce'),
				'oRow'     => null,
			);
		}
		return array(
			'bSuccess'   => true,
			'sMessage'   => '',
			'oRow'       => $oFresh,
			'iOldUserID' => $iOldUserID,
		);
	}

	/**
	 * @param object        $wpdb
	 * @param object        $oOrderItem
	 * @param int           $iOrderID
	 * @param array         $aRows
	 * @param callable|null $fnAfterRow
	 * @return array{sMessage:string,bStatus:bool,sync:?array}
	 */
	private static function cancel_live_rows($wpdb, $oOrderItem, $iOrderID, $aRows, $fnAfterRow)
	{
		$sTable    = $wpdb->prefix.self::TABLE;
		$sNow      = current_time('mysql');
		$iQuantity = 1;
		foreach($aRows as $oExistResult)
		{
			$oFresh = self::read_nano($wpdb, (string)$oExistResult->nano_id);
			if($oFresh === false)
			{
				return array(
					'sMessage' => __('Could not cancel tickets for this order line because the database write failed.', 'tickets-passes-for-woocommerce'),
					'bStatus'  => false,
					'sync'     => null,
				);
			}
			if(!self::victim_matches($oFresh, $oExistResult->nano_id, $oOrderItem, $iOrderID))
			{
				return array(
					'sMessage' => __('Could not cancel tickets for this order line because the ticket row changed.', 'tickets-passes-for-woocommerce'),
					'bStatus'  => false,
					'sync'     => null,
				);
			}
			if(!self::row_is_deleted($oFresh))
			{
				$mUpdate = $wpdb->query($wpdb->prepare(
					'UPDATE %i SET deleted = %s, updated = %s WHERE nano_id = %s AND order_line_id = %d',
					$sTable,
					$sNow,
					$sNow,
					$oExistResult->nano_id,
					(int)$oOrderItem->get_id()
				));
				if(TPFW_Db_Write::failed($mUpdate))
				{
					return array(
						'sMessage' => __('Could not cancel tickets for this order line because the database write failed.', 'tickets-passes-for-woocommerce'),
						'bStatus'  => false,
						'sync'     => null,
					);
				}
			}
			$mStats = self::revoke_stats($wpdb, (string)$oExistResult->nano_id, $sNow);
			if($mStats === false)
			{
				return array(
					'sMessage' => __('Could not cancel tickets for this order line because the database write failed.', 'tickets-passes-for-woocommerce'),
					'bStatus'  => false,
					'sync'     => null,
				);
			}
			if(is_callable($fnAfterRow))
			{
				call_user_func($fnAfterRow, $oExistResult, $iQuantity);
			}
			$iQuantity++;
		}
		return array(
			'sMessage' => __('All related tickets have been cancelled', 'tickets-passes-for-woocommerce'),
			'bStatus'  => false,
			'sync'     => null,
		);
	}

	/**
	 * @param object        $wpdb
	 * @param object        $oOrderItem
	 * @param int           $iOrderID
	 * @param string        $sTable
	 * @param array         $aLive
	 * @param list<string>  $aDelete
	 * @param int           $iTarget
	 * @param string        $sNow
	 * @param callable|null $fnAfterRevoke
	 * @return array{sMessage:string,bStatus:bool,sync:?array}
	 */
	private static function shrink_live_rows($wpdb, $oOrderItem, $iOrderID, $sTable, $aLive, $aDelete, $iTarget, $sNow, $fnAfterRevoke)
	{
		foreach($aDelete as $sNano)
		{
			$oFresh = self::read_nano($wpdb, $sNano);
			if($oFresh === false)
			{
				return array(
					'sMessage' => __('Could not reconcile issued quantity because the database write failed.', 'tickets-passes-for-woocommerce'),
					'bStatus'  => false,
					'sync'     => null,
				);
			}
			if(!self::victim_matches($oFresh, $sNano, $oOrderItem, $iOrderID))
			{
				return array(
					'sMessage' => __('Could not reconcile issued quantity because the ticket row changed.', 'tickets-passes-for-woocommerce'),
					'bStatus'  => false,
					'sync'     => null,
				);
			}
		}
		$aSync = TPFW_Order_Line_Upsert::shrink($wpdb, $sTable, $aLive, $iTarget, $sNow);
		if(empty($aSync['ok']))
		{
			return array(
				'sMessage' => __('Could not reconcile issued quantity because the database write failed.', 'tickets-passes-for-woocommerce'),
				'bStatus'  => false,
				'sync'     => $aSync,
			);
		}
		foreach($aSync['deleted'] as $sNano)
		{
			$mStats = self::revoke_stats($wpdb, (string)$sNano, $sNow);
			if($mStats === false)
			{
				return array(
					'sMessage' => __('Could not reconcile issued quantity because the database write failed.', 'tickets-passes-for-woocommerce'),
					'bStatus'  => false,
					'sync'     => $aSync,
				);
			}
			if(is_callable($fnAfterRevoke))
			{
				call_user_func($fnAfterRevoke, $sNano);
			}
		}
		if(empty($aSync['deleted']))
		{
			return array(
				'sMessage' => '',
				'bStatus'  => true,
				'sync'     => $aSync,
			);
		}
		return array(
			'sMessage' => sprintf(
				/* translators: 1: product type label, 2: comma-separated nano ids */
				__('%1$s quantity reconciled; cancelled: %2$s', 'tickets-passes-for-woocommerce'),
				'ticket',
				implode(', ', $aSync['deleted'])
			),
			'bStatus'  => true,
			'sync'     => $aSync,
		);
	}

	/**
	 * Surplus nano ids in the same id-ASC order as TPFW_Order_Line_Upsert::shrink().
	 *
	 * @param array $aLive
	 * @param int   $iTarget
	 * @return list<string>
	 */
	private static function surplus_nanos($aLive, $iTarget)
	{
		$aDelete = array();
		$iQty    = max(0, (int)$iTarget);
		$i       = 0;
		foreach((array)$aLive as $mRow)
		{
			$sNano = is_object($mRow) ? (string)$mRow->nano_id : '';
			if($sNano === '')
			{
				continue;
			}
			if($i < $iQty)
			{
				$i++;
				continue;
			}
			$aDelete[] = $sNano;
		}
		return $aDelete;
	}

	/**
	 * @param array $aLive
	 * @param int   $iTarget
	 * @return list<string>
	 */
	private static function keep_nanos($aLive, $iTarget)
	{
		$aKeep = array();
		$iQty  = max(0, (int)$iTarget);
		$i     = 0;
		foreach((array)$aLive as $mRow)
		{
			$sNano = is_object($mRow) ? (string)$mRow->nano_id : '';
			if($sNano === '')
			{
				continue;
			}
			if($i < $iQty)
			{
				$aKeep[] = $sNano;
				$i++;
			}
		}
		return $aKeep;
	}

	/**
	 * @param object|null $oRow
	 * @param string      $sNanoID
	 * @param object      $oOrderItem
	 * @param int         $iOrderID
	 * @return bool
	 */
	private static function victim_matches($oRow, $sNanoID, $oOrderItem, $iOrderID)
	{
		return is_object($oRow)
			&& (string)$oRow->nano_id === (string)$sNanoID
			&& (int)$oRow->product_id === (int)$oOrderItem->get_product_id()
			&& (int)$oRow->order_id === (int)$iOrderID
			&& (int)$oRow->order_line_id === (int)$oOrderItem->get_id();
	}

	/**
	 * @param object $wpdb
	 * @param string $sNanoID
	 * @param string $sNow
	 * @return mixed false on write error
	 */
	private static function revoke_stats($wpdb, $sNanoID, $sNow)
	{
		$mStats = $wpdb->query($wpdb->prepare(
			'UPDATE %i SET deleted = %s, updated = %s WHERE nano_id_fk = %s',
			$wpdb->prefix.self::STATS_TABLE,
			$sNow,
			$sNow,
			$sNanoID
		));
		if(TPFW_Db_Write::failed($mStats))
		{
			return false;
		}
		return $mStats;
	}

	/**
	 * @param object $wpdb
	 * @param string $sNanoID
	 * @return array{bSuccess:bool,sMessage:string,oRow:?object,iLine?:int}
	 */
	private static function probe_line($wpdb, $sNanoID)
	{
		$sNanoID = (string)$sNanoID;
		if($sNanoID === '')
		{
			return self::nano_gone();
		}
		$oProbe = $wpdb->get_row($wpdb->prepare(
			'SELECT nano_id, order_line_id FROM %i WHERE nano_id = %s LIMIT 1',
			$wpdb->prefix.self::TABLE,
			$sNanoID
		));
		if($oProbe === false || ($oProbe === null && !empty($wpdb->last_error)))
		{
			return self::nano_write_failed_cancel();
		}
		if(empty($oProbe) || (int)$oProbe->order_line_id <= 0)
		{
			return self::nano_gone();
		}
		return array(
			'bSuccess' => true,
			'sMessage' => '',
			'oRow'     => null,
			'iLine'    => (int)$oProbe->order_line_id,
		);
	}

	/**
	 * @param object $wpdb
	 * @param string $sNanoID
	 * @return object|null|false false on a database error; null when the row is missing.
	 */
	private static function read_nano($wpdb, $sNanoID)
	{
		$oRow = $wpdb->get_row($wpdb->prepare(
			'SELECT * FROM %i WHERE nano_id = %s LIMIT 1',
			$wpdb->prefix.self::TABLE,
			$sNanoID
		));
		if($oRow === false || ($oRow === null && !empty($wpdb->last_error)))
		{
			return false;
		}
		return $oRow ?: null;
	}

	/**
	 * Slot-aware ticket upsert. Existing rows occupy id-ASC slots. A manually
	 * cancelled row stays deleted, keeps its nano, and is not replaced.
	 *
	 * @internal Call only while tpfw_ticket_issue_{line} is held. Does not GET_LOCK.
	 *
	 * @param object   $wpdb
	 * @param string   $sTable
	 * @param array    $aExisting
	 * @param int      $iQuantity
	 * @param string   $sNow
	 * @param callable $fnInsert
	 * @return array{keep:string[],inserted:string[],deleted:string[],ok:bool}
	 */
	private static function sync_slots_held($wpdb, $sTable, $aExisting, $iQuantity, $sNow, $fnInsert)
	{
		$aKeepLive = array();
		$aDelete   = array();
		$iQty      = max(0, (int)$iQuantity);
		$iHave     = 0;
		$i         = 0;

		foreach((array)$aExisting as $mRow)
		{
			$sNano = is_object($mRow) ? (string)$mRow->nano_id : (string)$mRow['nano_id'];
			if($sNano === '')
			{
				continue;
			}
			$iHave++;
			if($i < $iQty)
			{
				if(!self::row_is_manual_cancelled($mRow))
				{
					$aKeepLive[] = $sNano;
				}
				$i++;
			}
			else
			{
				$aDelete[] = $sNano;
			}
		}

		if(!empty($aKeepLive))
		{
			$sPlaceholders = implode(', ', array_fill(0, count($aKeepLive), '%s'));
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholder list matches $aKeepLive.
			$mUndelete = $wpdb->query($wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IN() list is generated from count($aKeepLive).
				'UPDATE %i SET deleted = NULL, updated = %s WHERE nano_id IN ('.$sPlaceholders.')',
				array_merge(array($sTable, $sNow), $aKeepLive)
			));
			if(TPFW_Db_Write::failed($mUndelete))
			{
				return array('keep' => $aKeepLive, 'inserted' => array(), 'deleted' => array(), 'ok' => false);
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
				return array('keep' => $aKeepLive, 'inserted' => array(), 'deleted' => $aDelete, 'ok' => false);
			}
		}

		$aInserted = array();
		$iNeed     = $iQty - $iHave;
		for($n = 0; $n < $iNeed; $n++)
		{
			$sNano = call_user_func($fnInsert);
			if($sNano === false)
			{
				return array(
					'keep'     => array_merge($aKeepLive, $aInserted),
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
			'keep'     => array_merge($aKeepLive, $aInserted),
			'inserted' => $aInserted,
			'deleted'  => $aDelete,
			'ok'       => true,
		);
	}

	/**
	 * @param object $wpdb
	 * @param object $oRow
	 * @return int|false
	 */
	private static function reset_target_quantity($wpdb, $oRow)
	{
		$oOrder = function_exists('wc_get_order') ? wc_get_order((int)$oRow->order_id) : null;
		$oItem  = self::order_item_for_row($oOrder, $oRow);
		if($oItem)
		{
			return TPFW_Refund_Policy::target_active_quantity($oOrder, $oItem);
		}
		$sStatus = '';
		if(is_object($oOrder) && method_exists($oOrder, 'get_status'))
		{
			$sStatus = $oOrder->get_status();
		}
		if(TPFW_Issue_Policy::should_revoke($sStatus))
		{
			return 0;
		}
		$iIssued = $wpdb->get_var($wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE product_id = %d AND order_id = %d AND order_line_id = %d',
			$wpdb->prefix.self::TABLE,
			(int)$oRow->product_id,
			(int)$oRow->order_id,
			(int)$oRow->order_line_id
		));
		if($iIssued === null && !empty($wpdb->last_error))
		{
			return false;
		}
		$iRefunded = 0;
		if(is_object($oOrder) && method_exists($oOrder, 'get_qty_refunded_for_item'))
		{
			$iRefunded = abs((int)$oOrder->get_qty_refunded_for_item((int)$oRow->order_line_id));
		}
		return max(0, (int)$iIssued - $iRefunded);
	}

	/**
	 * @param object|null $oOrder
	 * @param object      $oRow
	 * @return object|null
	 */
	private static function order_item_for_row($oOrder, $oRow)
	{
		if(!is_object($oOrder) || !method_exists($oOrder, 'get_items'))
		{
			return null;
		}
		$aItems = $oOrder->get_items();
		if(!is_array($aItems) && !($aItems instanceof \Traversable))
		{
			return null;
		}
		foreach($aItems as $oLine)
		{
			if(is_object($oLine) && method_exists($oLine, 'get_id') && (int)$oLine->get_id() === (int)$oRow->order_line_id)
			{
				return $oLine;
			}
		}
		return null;
	}

	/**
	 * @param object $wpdb
	 * @param object $oRow
	 * @param string $sNanoID
	 * @return int|false
	 */
	private static function slot_index_held($wpdb, $oRow, $sNanoID)
	{
		$aSlots = $wpdb->get_results($wpdb->prepare(
			'SELECT nano_id FROM %i WHERE product_id = %d AND order_id = %d AND order_line_id = %d ORDER BY id ASC',
			$wpdb->prefix.self::TABLE,
			(int)$oRow->product_id,
			(int)$oRow->order_id,
			(int)$oRow->order_line_id
		));
		if($aSlots === false)
		{
			return false;
		}
		$i = 0;
		foreach((array)$aSlots as $oSlot)
		{
			if(!is_object($oSlot) || (string)$oSlot->nano_id === '')
			{
				continue;
			}
			if((string)$oSlot->nano_id === (string)$sNanoID)
			{
				return $i;
			}
			$i++;
		}
		return -1;
	}

	/**
	 * @param object $oRow
	 * @return bool
	 */
	private static function row_is_deleted($oRow)
	{
		return is_object($oRow) && $oRow->deleted !== null && $oRow->deleted !== '';
	}

	/**
	 * @param object|array $mRow
	 * @return bool
	 */
	private static function row_is_manual_cancelled($mRow)
	{
		$mAt = is_object($mRow)
			? (isset($mRow->manual_cancelled_at) ? $mRow->manual_cancelled_at : null)
			: (isset($mRow['manual_cancelled_at']) ? $mRow['manual_cancelled_at'] : null);
		return $mAt !== null && $mAt !== '' && $mAt !== '0';
	}

	/**
	 * @param object|null $oRow
	 * @param string      $sNanoID
	 * @param int         $iOrderLineId
	 * @return bool
	 */
	private static function row_matches_line($oRow, $sNanoID, $iOrderLineId)
	{
		return is_object($oRow)
			&& (string)$oRow->nano_id === (string)$sNanoID
			&& (int)$oRow->order_line_id === (int)$iOrderLineId;
	}

	/**
	 * @return array{bSuccess:bool,sMessage:string,oRow:?object}
	 */
	private static function nano_gone()
	{
		return array(
			'bSuccess' => false,
			'sMessage' => __('Ticket with given Nano ID does not seem to exist', 'tickets-passes-for-woocommerce'),
			'oRow'     => null,
		);
	}

	/**
	 * @return array{bSuccess:bool,sMessage:string,oRow:?object}
	 */
	private static function nano_write_failed_cancel()
	{
		return array(
			'bSuccess' => false,
			'sMessage' => __('Could not cancel the ticket because the database write failed.', 'tickets-passes-for-woocommerce'),
			'oRow'     => null,
		);
	}

	/**
	 * @return array{bSuccess:bool,sMessage:string,oRow:?object}
	 */
	private static function nano_write_failed_reset()
	{
		return array(
			'bSuccess' => false,
			'sMessage' => __('Could not reset the ticket because the database write failed.', 'tickets-passes-for-woocommerce'),
			'oRow'     => null,
		);
	}

	/**
	 * @return array{bSuccess:bool,sMessage:string,oRow:?object}
	 */
	private static function nano_write_failed_transfer()
	{
		return array(
			'bSuccess' => false,
			'sMessage' => __('Could not transfer the ticket because the database write failed.', 'tickets-passes-for-woocommerce'),
			'oRow'     => null,
		);
	}
}
