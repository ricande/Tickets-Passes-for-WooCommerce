<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Issued quantity vs WooCommerce refunded ITEM quantity.
 *
 * Never derived from money. An amount-only refund (refunded item qty = 0) does not
 * change issued rows. A full-order revoke status still targets zero.
 */
class TPFW_Refund_Policy
{
	/**
	 * Purchased quantity on the order line (original qty, not remaining).
	 *
	 * @param object $oOrderItem
	 * @return int
	 */
	public static function purchased_quantity($oOrderItem)
	{
		if(!is_object($oOrderItem) || !method_exists($oOrderItem, 'get_quantity'))
		{
			return 0;
		}
		return max(0, (int)$oOrderItem->get_quantity());
	}

	/**
	 * Refunded ITEM quantity for this line. Always a positive absolute count.
	 *
	 * WC_Order::get_qty_refunded_for_item() and WC_Order_Item_Product::get_qty_refunded()
	 * return a negative sum (refund 1 item → -1). Normalise exactly here; callers must
	 * not abs() again or treat the raw WC value as remaining stock.
	 *
	 * Never derived from a refund amount.
	 *
	 * @param object $oOrder
	 * @param object $oOrderItem
	 * @return int
	 */
	public static function refunded_item_quantity($oOrder, $oOrderItem)
	{
		$iItemId = 0;
		if(is_object($oOrderItem) && method_exists($oOrderItem, 'get_id'))
		{
			$iItemId = (int)$oOrderItem->get_id();
		}
		if($iItemId > 0 && is_object($oOrder) && method_exists($oOrder, 'get_qty_refunded_for_item'))
		{
			return abs((int)$oOrder->get_qty_refunded_for_item($iItemId));
		}
		if(is_object($oOrderItem) && method_exists($oOrderItem, 'get_qty_refunded'))
		{
			return abs((int)$oOrderItem->get_qty_refunded());
		}
		return 0;
	}

	/**
	 * How many issued rows should stay live on this line.
	 *
	 * @param object $oOrder
	 * @param object $oOrderItem
	 * @return int
	 */
	public static function target_active_quantity($oOrder, $oOrderItem)
	{
		$sStatus = '';
		if(is_object($oOrder) && method_exists($oOrder, 'get_status'))
		{
			$sStatus = $oOrder->get_status();
		}
		if(TPFW_Issue_Policy::should_revoke($sStatus))
		{
			return 0;
		}
		return max(0, self::purchased_quantity($oOrderItem) - self::refunded_item_quantity($oOrder, $oOrderItem));
	}

	/**
	 * Whether a refund event should touch issued rows.
	 *
	 * Amount-only (item qty 0) on a still-open order: no. Revoke statuses and item-qty refunds: yes.
	 *
	 * @param object $oOrder
	 * @param object $oOrderItem
	 * @return bool
	 */
	public static function should_reconcile($oOrder, $oOrderItem)
	{
		$sStatus = '';
		if(is_object($oOrder) && method_exists($oOrder, 'get_status'))
		{
			$sStatus = $oOrder->get_status();
		}
		if(TPFW_Issue_Policy::should_revoke($sStatus))
		{
			return true;
		}
		return self::refunded_item_quantity($oOrder, $oOrderItem) > 0;
	}

	/**
	 * Issue-path quantity: purchased minus refunded items. Never negative.
	 *
	 * @param object|null $oOrder
	 * @param object      $oOrderItem
	 * @return int
	 */
	public static function issue_quantity($oOrder, $oOrderItem)
	{
		if(empty($oOrder))
		{
			return self::purchased_quantity($oOrderItem);
		}
		return self::target_active_quantity($oOrder, $oOrderItem);
	}
}
