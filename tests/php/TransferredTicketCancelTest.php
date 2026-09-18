<?php
use PHPUnit\Framework\TestCase;

/**
 * Full order-line cancel/refund must follow product_id + order_id + order_line_id.
 *
 * Admin transfer rewrites ticket.user_id to the new holder and leaves the WooCommerce
 * order on the original purchaser. Full-line cancel must still match the line, not the
 * current holder. Dashboard per-nano cancel/reset/transfer for regular tickets take
 * tpfw_ticket_issue_{line} in TPFW_Ticket_Line; this file covers full-line cancel after
 * that holder rewrite.
 */
class TransferredTicketCancelTest extends TestCase
{
	private ?TPFW_Test_Wpdb $wpdb = null;

	protected function setUp(): void
	{
		$mysqli = TPFW_Test_Credentials::mysqli();
		if(!$mysqli)
		{
			$this->markTestSkipped('No database credentials');
		}
		$this->wpdb = new TPFW_Test_Wpdb($mysqli, 'tpfwxfer_');
		TPFW_Test_Schema::install($this->wpdb);
		$GLOBALS['tpfw_test_orders'] = array();
	}

	protected function tearDown(): void
	{
		$GLOBALS['tpfw_test_orders'] = array();
		if($this->wpdb)
		{
			TPFW_Test_Schema::drop($this->wpdb);
			$this->wpdb->mysqli()->close();
		}
	}

	public function test_full_cancel_revokes_ticket_transferred_away_from_purchaser(): void
	{
		$iLine     = 701;
		$iOrder    = 9701;
		$iProduct  = 11;
		$iBuyer    = 7;
		$iHolder   = 42;
		$sTable    = $this->wpdb->prefix.'tpfw_tickets';
		$sNow      = gmdate('Y-m-d H:i:s');
		$oItem     = $this->lineItem($iLine, 1, $iProduct);
		$this->putOrder($iOrder, 'cancelled', $iLine, 0);

		$sNano = $this->insertTicket($sTable, $iProduct, $iBuyer, $iOrder, $iLine, $sNow);
		$this->transfer($sTable, $sNano, $iHolder, $sNow);
		$this->assertSame($iHolder, $this->holder($sTable, $sNano));
		$this->assertSame(1, $this->liveOnLine($sTable, $iLine));

		$aCancel = TPFW_Ticket_Line::cancel($this->wpdb, $oItem, $iOrder);
		$this->assertStringContainsString('cancelled', $aCancel['sMessage']);
		$this->assertSame(0, $this->liveOnLine($sTable, $iLine), 'transferred ticket must still be revoked with the order line');
		$this->assertNotNull($this->deletedAt($sTable, $sNano));
	}

	public function test_full_refund_reconcile_revokes_mixed_holders_on_the_same_line(): void
	{
		$iLine     = 702;
		$iOrder    = 9702;
		$iProduct  = 11;
		$iBuyer    = 7;
		$iHolder   = 42;
		$sTable    = $this->wpdb->prefix.'tpfw_tickets';
		$sNow      = gmdate('Y-m-d H:i:s');
		$oItem     = $this->lineItem($iLine, 2, $iProduct);
		$this->putOrder($iOrder, 'refunded', $iLine, 0);

		$sKept     = $this->insertTicket($sTable, $iProduct, $iBuyer, $iOrder, $iLine, $sNow);
		$sMoved    = $this->insertTicket($sTable, $iProduct, $iBuyer, $iOrder, $iLine, $sNow);
		$this->transfer($sTable, $sMoved, $iHolder, $sNow);
		$this->assertSame(2, $this->liveOnLine($sTable, $iLine));

		$aRefund = TPFW_Ticket_Line::reconcile($this->wpdb, $oItem, $iOrder);
		$this->assertStringContainsString('cancelled', $aRefund['sMessage']);
		$this->assertSame(0, $this->liveOnLine($sTable, $iLine));
		$this->assertNotNull($this->deletedAt($sTable, $sKept));
		$this->assertNotNull($this->deletedAt($sTable, $sMoved));
	}

	public function test_full_cancel_does_not_touch_other_order_line_or_product(): void
	{
		$iLine     = 703;
		$iOrder    = 9703;
		$iProduct  = 11;
		$iBuyer    = 7;
		$sTable    = $this->wpdb->prefix.'tpfw_tickets';
		$sNow      = gmdate('Y-m-d H:i:s');
		$oItem     = $this->lineItem($iLine, 1, $iProduct);
		$this->putOrder($iOrder, 'cancelled', $iLine, 0);

		$sTarget   = $this->insertTicket($sTable, $iProduct, $iBuyer, $iOrder, $iLine, $sNow);
		$sOtherOrd = $this->insertTicket($sTable, $iProduct, $iBuyer, 9704, $iLine, $sNow);
		$sOtherLn  = $this->insertTicket($sTable, $iProduct, $iBuyer, $iOrder, 704, $sNow);
		$sOtherPr  = $this->insertTicket($sTable, 99, $iBuyer, $iOrder, $iLine, $sNow);
		$this->transfer($sTable, $sTarget, 42, $sNow);

		TPFW_Ticket_Line::cancel($this->wpdb, $oItem, $iOrder);

		$this->assertNotNull($this->deletedAt($sTable, $sTarget));
		$this->assertNull($this->deletedAt($sTable, $sOtherOrd));
		$this->assertNull($this->deletedAt($sTable, $sOtherLn));
		$this->assertNull($this->deletedAt($sTable, $sOtherPr));
	}

	public function test_full_cancel_still_revokes_untransferred_purchaser_ticket(): void
	{
		$iLine    = 705;
		$iOrder   = 9705;
		$iProduct = 11;
		$iBuyer   = 7;
		$sTable   = $this->wpdb->prefix.'tpfw_tickets';
		$sNow     = gmdate('Y-m-d H:i:s');
		$oItem    = $this->lineItem($iLine, 1, $iProduct);
		$this->putOrder($iOrder, 'cancelled', $iLine, 0);

		$sNano = $this->insertTicket($sTable, $iProduct, $iBuyer, $iOrder, $iLine, $sNow);
		TPFW_Ticket_Line::cancel($this->wpdb, $oItem, $iOrder);
		$this->assertSame(0, $this->liveOnLine($sTable, $iLine));
		$this->assertNotNull($this->deletedAt($sTable, $sNano));
	}

	public function test_cancel_held_selects_line_without_holder_user_id(): void
	{
		$sSrc = file_get_contents(TPFW_PLUGIN_DIR.'inc/support/class--ticket-line.php');
		$iFn  = strpos($sSrc, 'function cancel_held');
		$this->assertNotFalse($iFn);
		$sFn = substr($sSrc, $iFn, strpos($sSrc, 'function reconcile_held') - $iFn);
		$this->assertNotFalse(strpos($sFn, 'product_id = %d AND order_id = %d AND order_line_id = %d AND deleted IS NULL'));
		$this->assertFalse(strpos($sFn, 'user_id = %d'));
		$this->assertFalse(strpos($sSrc, '$iCustomerID'));
	}

	/**
	 * @param int $iLine
	 * @param int $iQty
	 * @param int $iProduct
	 * @return TPFW_Test_Refund_Item
	 */
	private function lineItem($iLine, $iQty, $iProduct = 11)
	{
		$oItem = new TPFW_Test_Refund_Item($iLine, $iQty);
		$oItem->productId = $iProduct;
		return $oItem;
	}

	/**
	 * @param int    $iOrder
	 * @param string $sStatus
	 * @param int    $iLine
	 * @param int    $iRefunded
	 * @return void
	 */
	private function putOrder($iOrder, $sStatus, $iLine, $iRefunded)
	{
		$oOrder = new TPFW_Test_Refund_Order();
		$oOrder->status = $sStatus;
		$oOrder->refunded = array((int)$iLine => $iRefunded);
		$GLOBALS['tpfw_test_orders'][(int)$iOrder] = $oOrder;
	}

	/**
	 * @param string $sTable
	 * @param int    $iProduct
	 * @param int    $iUser
	 * @param int    $iOrder
	 * @param int    $iLine
	 * @param string $sNow
	 * @return string
	 */
	private function insertTicket($sTable, $iProduct, $iUser, $iOrder, $iLine, $sNow)
	{
		$sNano = 'x'.bin2hex(random_bytes(8));
		$m = $this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
			VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s)',
			array($sTable, $sNano, $iProduct, $iUser, $iOrder, $iLine, 86400, 1, $sNow, $sNow)
		));
		$this->assertTrue(TPFW_Db_Write::inserted_row($m));
		return $sNano;
	}

	/**
	 * @param string $sTable
	 * @param string $sNano
	 * @param int    $iHolder
	 * @param string $sNow
	 * @return void
	 */
	private function transfer($sTable, $sNano, $iHolder, $sNow)
	{
		$this->wpdb->query($this->wpdb->prepare(
			'UPDATE %i SET user_id = %d, updated = %s WHERE nano_id = %s',
			$sTable,
			$iHolder,
			$sNow,
			$sNano
		));
	}

	/**
	 * @param string $sTable
	 * @param string $sNano
	 * @return int
	 */
	private function holder($sTable, $sNano)
	{
		return (int)$this->wpdb->get_var($this->wpdb->prepare(
			'SELECT user_id FROM %i WHERE nano_id = %s',
			$sTable,
			$sNano
		));
	}

	/**
	 * @param string $sTable
	 * @param int    $iLine
	 * @return int
	 */
	private function liveOnLine($sTable, $iLine)
	{
		return (int)$this->wpdb->get_var($this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE order_line_id = %d AND deleted IS NULL',
			$sTable,
			$iLine
		));
	}

	/**
	 * @param string $sTable
	 * @param string $sNano
	 * @return string|null
	 */
	private function deletedAt($sTable, $sNano)
	{
		$m = $this->wpdb->get_var($this->wpdb->prepare(
			'SELECT deleted FROM %i WHERE nano_id = %s',
			$sTable,
			$sNano
		));
		return $m === null || $m === '' ? null : (string)$m;
	}
}
