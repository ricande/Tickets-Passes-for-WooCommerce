<?php
/**
 * Minimal WooCommerce order/item stand-ins for refund and ticket-line tests.
 */
class TPFW_Test_Refund_Item
{
	public $id;
	public $qty;
	public $qtyRefunded = 0;
	public $productId = 11;

	public function __construct($iId, $iQty, $iRefunded = 0)
	{
		$this->id          = $iId;
		$this->qty         = $iQty;
		$this->qtyRefunded = $iRefunded;
	}

	public function get_id()
	{
		return $this->id;
	}

	public function get_product_id()
	{
		return $this->productId;
	}

	public function get_quantity()
	{
		return $this->qty;
	}

	/**
	 * Mirrors WC_Order_Item_Product::get_qty_refunded(): also signed/negative.
	 *
	 * @return int
	 */
	public function get_qty_refunded()
	{
		return $this->qtyRefunded;
	}
}

class TPFW_Test_Refund_Order
{
	public $status = 'completed';
	public $refunded = array();

	public function get_status()
	{
		return $this->status;
	}

	/**
	 * Mirrors WC_Order::get_qty_refunded_for_item(): signed sum, negative when items were refunded.
	 *
	 * @param int $iId
	 * @return int
	 */
	public function get_qty_refunded_for_item($iId)
	{
		return $this->refunded[(int)$iId] ?? 0;
	}
}
