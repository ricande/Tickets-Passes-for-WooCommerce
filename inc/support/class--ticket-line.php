<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Serialises every ticket-row mutation for one WooCommerce order line.
 *
 * Lock name: tpfw_ticket_issue_{order_line_id}, taken through TPFW_Issue_Lock::with_line().
 * Public wrappers acquire that lock. The *_held methods are public for same-class
 * composition only: they do not GET_LOCK. Call them only from issue()/cancel()/
 * reconcile()/with_lock() callbacks so the same connection never GET_LOCKs the line
 * twice and so no unlocked caller mutates ticket rows.
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
	 * @param object      $wpdb
	 * @param object      $oOrderItem
	 * @param int         $iOrderID
	 * @param int         $iCustomerID
	 * @param string      $sNow
	 * @param callable    $fnInsert
	 * @param callable|null $fnAfterCancelRow
	 * @param int         $iTimeout
	 * @return array{sMessage:string,bStatus:bool,sync:?array}
	 */
	public static function issue($wpdb, $oOrderItem, $iOrderID, $iCustomerID, $sNow, $fnInsert, $fnAfterCancelRow = null, $iTimeout = 5)
	{
		$m = self::with_lock($wpdb, (int)$oOrderItem->get_id(), function() use ($wpdb, $oOrderItem, $iOrderID, $iCustomerID, $sNow, $fnInsert, $fnAfterCancelRow) {
			return self::issue_held($wpdb, $oOrderItem, $iOrderID, $iCustomerID, $sNow, $fnInsert, $fnAfterCancelRow);
		}, $iTimeout);
		if($m === null)
		{
			return self::lock_failed_issue();
		}
		return $m;
	}

	/**
	 * Full cancel of live ticket rows for one line.
	 *
	 * @param object        $wpdb
	 * @param object        $oOrderItem
	 * @param int           $iOrderID
	 * @param int           $iCustomerID
	 * @param callable|null $fnAfterRow
	 * @param int           $iTimeout
	 * @return array{sMessage:string,bStatus:bool,sync:?array}
	 */
	public static function cancel($wpdb, $oOrderItem, $iOrderID, $iCustomerID, $fnAfterRow = null, $iTimeout = 5)
	{
		$m = self::with_lock($wpdb, (int)$oOrderItem->get_id(), function() use ($wpdb, $oOrderItem, $iOrderID, $iCustomerID, $fnAfterRow) {
			return self::cancel_held($wpdb, $oOrderItem, $iOrderID, $iCustomerID, $fnAfterRow);
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
	 * @param int           $iCustomerID
	 * @param callable|null $fnAfterRevoke
	 * @param callable|null $fnAfterCancelRow
	 * @param int           $iTimeout
	 * @return array{sMessage:string,bStatus:bool,sync:?array}
	 */
	public static function reconcile($wpdb, $oOrderItem, $iOrderID, $iCustomerID, $fnAfterRevoke = null, $fnAfterCancelRow = null, $iTimeout = 5)
	{
		$m = self::with_lock($wpdb, (int)$oOrderItem->get_id(), function() use ($wpdb, $oOrderItem, $iOrderID, $iCustomerID, $fnAfterRevoke, $fnAfterCancelRow) {
			return self::reconcile_held($wpdb, $oOrderItem, $iOrderID, $iCustomerID, $fnAfterRevoke, $fnAfterCancelRow);
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
	 * @param int           $iCustomerID
	 * @param string        $sNow
	 * @param callable      $fnInsert
	 * @param callable|null $fnAfterCancelRow
	 * @return array{sMessage:string,bStatus:bool,sync:?array}
	 */
	public static function issue_held($wpdb, $oOrderItem, $iOrderID, $iCustomerID, $sNow, $fnInsert, $fnAfterCancelRow = null)
	{
		$oOrder = function_exists('wc_get_order') ? wc_get_order($iOrderID) : null;
		$iQty   = TPFW_Refund_Policy::issue_quantity($oOrder, $oOrderItem);
		if($iQty <= 0)
		{
			return self::cancel_held($wpdb, $oOrderItem, $iOrderID, $iCustomerID, $fnAfterCancelRow);
		}

		$sTable  = $wpdb->prefix.self::TABLE;
		$sSelect = $wpdb->prepare(
			'SELECT * FROM %i WHERE product_id = %d AND order_id = %d AND order_line_id = %d ORDER BY (deleted IS NULL) DESC, id ASC',
			array(
				$sTable,
				$oOrderItem->get_product_id(),
				$iOrderID,
				$oOrderItem->get_id(),
			)
		);
		$aSync = TPFW_Issue_Lock::sync_held($wpdb, $sTable, $sSelect, $iQty, $sNow, $fnInsert);
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
	 * @param int           $iCustomerID
	 * @param callable|null $fnAfterRow function(object $oRow, int $iMetaIndex): void
	 * @return array{sMessage:string,bStatus:bool,sync:?array}
	 */
	public static function cancel_held($wpdb, $oOrderItem, $iOrderID, $iCustomerID, $fnAfterRow = null)
	{
		$sTable = $wpdb->prefix.self::TABLE;
		$sStats = $wpdb->prefix.self::STATS_TABLE;
		$sNow   = current_time('mysql');
		$oExistsPrepared = $wpdb->prepare(
			'SELECT * FROM %i WHERE product_id = %d AND order_id = %d AND order_line_id = %d AND user_id = %d AND deleted IS NULL',
			array(
				$sTable,
				$oOrderItem->get_product_id(),
				$iOrderID,
				$oOrderItem->get_id(),
				$iCustomerID,
			)
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oExistsPrepared is the return value of $wpdb->prepare() above.
		$oExistsResult = $wpdb->get_results($oExistsPrepared);
		if(empty($oExistsResult))
		{
			return array(
				'sMessage' => __('Ticket for this order line already seems to be cancelled', 'tickets-passes-for-woocommerce'),
				'bStatus'  => false,
				'sync'     => null,
			);
		}

		$iQuantity = 1;
		foreach($oExistsResult as $oExistResult)
		{
			$mUpdate = $wpdb->query($wpdb->prepare(
				'UPDATE %i SET deleted = %s, updated = %s WHERE nano_id = %s',
				$sTable,
				$sNow,
				$sNow,
				$oExistResult->nano_id
			));
			if(TPFW_Db_Write::failed($mUpdate))
			{
				return array(
					'sMessage' => __('Could not cancel tickets for this order line because the database write failed.', 'tickets-passes-for-woocommerce'),
					'bStatus'  => false,
					'sync'     => null,
				);
			}

			$wpdb->query($wpdb->prepare(
				'UPDATE %i SET deleted = %s, updated = %s WHERE nano_id_fk = %s',
				$sStats,
				$sNow,
				$sNow,
				$oExistResult->nano_id
			));

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
	 * @internal Call only from TPFW_Ticket_Line::reconcile() or a with_lock() callback
	 *           while tpfw_ticket_issue_{line} is held. Does not GET_LOCK.
	 *           target_active_quantity is read here, not from a pre-lock snapshot.
	 *
	 * @param object        $wpdb
	 * @param object        $oOrderItem
	 * @param int           $iOrderID
	 * @param int           $iCustomerID
	 * @param callable|null $fnAfterRevoke
	 * @param callable|null $fnAfterCancelRow
	 * @return array{sMessage:string,bStatus:bool,sync:?array}
	 */
	public static function reconcile_held($wpdb, $oOrderItem, $iOrderID, $iCustomerID, $fnAfterRevoke = null, $fnAfterCancelRow = null)
	{
		$oOrder  = function_exists('wc_get_order') ? wc_get_order($iOrderID) : null;
		$iTarget = TPFW_Refund_Policy::target_active_quantity($oOrder, $oOrderItem);
		if($iTarget <= 0)
		{
			return self::cancel_held($wpdb, $oOrderItem, $iOrderID, $iCustomerID, $fnAfterCancelRow);
		}

		$sNow   = current_time('mysql');
		$sTable = $wpdb->prefix.self::TABLE;
		$aLive  = $wpdb->get_results($wpdb->prepare(
			'SELECT * FROM %i WHERE product_id = %d AND order_id = %d AND order_line_id = %d AND deleted IS NULL ORDER BY id ASC',
			array(
				$sTable,
				$oOrderItem->get_product_id(),
				$iOrderID,
				$oOrderItem->get_id(),
			)
		));
		if($aLive === false)
		{
			$aLive = array();
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
		if(is_callable($fnAfterRevoke))
		{
			foreach($aSync['deleted'] as $sNano)
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
}
