<?php
use PHPUnit\Framework\TestCase;

require_once TPFW_TEST_ROOT.'/lib/TicketFunctionsHarness.php';

/**
 * Dashboard cancel of a regular ticket must survive a later issue/reissue.
 */
class ManualCancelReissueTest extends TestCase
{
	private ?TPFW_Test_Wpdb $a = null;

	private ?TPFW_Test_Wpdb $b = null;

	private TPFW_Test_Ticket_Functions $fn;

	protected function setUp(): void
	{
		$mysqliA = TPFW_Test_Credentials::mysqli();
		$mysqliB = TPFW_Test_Credentials::mysqli();
		if(!$mysqliA || !$mysqliB)
		{
			$this->markTestSkipped('No database credentials');
		}
		$this->a = new TPFW_Test_Wpdb($mysqliA, 'tpfwmcir_');
		$this->b = new TPFW_Test_Wpdb($mysqliB, 'tpfwmcir_');
		TPFW_Test_Schema::install($this->a);
		tpfw_test_install_ticket_stats($this->a);
		$GLOBALS['tpfw_test_orders'] = array();
		$GLOBALS['wpdb'] = $this->b;
		$this->fn = new TPFW_Test_Ticket_Functions();
	}

	protected function tearDown(): void
	{
		$GLOBALS['tpfw_test_orders'] = array();
		unset($GLOBALS['wpdb']);
		if($this->a)
		{
			tpfw_test_drop_ticket_stats($this->a);
			TPFW_Test_Schema::drop($this->a);
			$this->a->mysqli()->close();
		}
		if($this->b)
		{
			$this->b->mysqli()->close();
		}
	}

	public function test_issue_undeletes_dashboard_cancelled_ticket(): void
	{
		$iLine  = 831;
		$iOrder = 9831;
		$sNano  = $this->seedTicket($iLine, $iOrder, 7);
		$GLOBALS['wpdb'] = $this->b;
		$this->assertTrue($this->fn->cancel_ticket($sNano)['bSuccess']);
		$this->assertNotNull($this->deletedAt($sNano));
		$this->assertNull($this->a->get_var($this->a->prepare(
			'SELECT IS_USED_LOCK(%s)',
			TPFW_Ticket_Line::lock_name($iLine)
		)));

		$oItem = $this->lineItem($iLine, 1);
		$this->putOrder($iOrder, 'completed', $iLine, 0);
		$sNow  = gmdate('Y-m-d H:i:s');
		TPFW_Ticket_Line::issue($this->a, $oItem, $iOrder, $sNow, function() {
			$this->fail('issue must not mint a second nano when the cancelled row can be undeleted');
		});
		$this->assertNotNull($this->deletedAt($sNano), 'dashboard per-nano cancel must outlive a later issue/reissue');
		$this->assertSame(0, $this->liveOnLine($iLine));
	}

	/**
	 * @param int $iLine
	 * @param int $iOrder
	 * @param int $iUser
	 * @return string
	 */
	private function seedTicket($iLine, $iOrder, $iUser)
	{
		$sTable = $this->a->prefix.'tpfw_tickets';
		$sNow   = gmdate('Y-m-d H:i:s');
		$sFrom  = gmdate('Y-m-d H:i:s', time() - 3600);
		$sTo    = gmdate('Y-m-d H:i:s', time() + 86400);
		$sNano  = 'n'.bin2hex(random_bytes(8));
		$m = $this->a->query($this->a->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, valid_from, valid_to, max_uses, created, updated)
			VALUES (%s, %d, %d, %d, %d, %d, %s, %s, %d, %s, %s)',
			array($sTable, $sNano, 11, $iUser, $iOrder, $iLine, 86400, $sFrom, $sTo, 1, $sNow, $sNow)
		));
		$this->assertTrue(TPFW_Db_Write::inserted_row($m));
		return $sNano;
	}

	/**
	 * @param string $sNano
	 * @return string|null
	 */
	private function deletedAt($sNano)
	{
		$m = $this->a->get_var($this->a->prepare(
			'SELECT deleted FROM %i WHERE nano_id = %s',
			$this->a->prefix.'tpfw_tickets',
			$sNano
		));
		return ($m === null || $m === '') ? null : (string)$m;
	}

	/**
	 * @param int $iLine
	 * @return int
	 */
	private function liveOnLine($iLine)
	{
		return (int)$this->a->get_var($this->a->prepare(
			'SELECT COUNT(*) FROM %i WHERE order_line_id = %d AND deleted IS NULL',
			$this->a->prefix.'tpfw_tickets',
			$iLine
		));
	}

	/**
	 * @param int $iLine
	 * @param int $iQty
	 * @return TPFW_Test_Refund_Item
	 */
	private function lineItem($iLine, $iQty)
	{
		$oItem = new TPFW_Test_Refund_Item($iLine, $iQty, 0);
		$oItem->productId = 11;
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
}
