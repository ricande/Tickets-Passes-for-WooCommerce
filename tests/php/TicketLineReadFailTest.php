<?php
use PHPUnit\Framework\TestCase;

require_once TPFW_TEST_ROOT.'/lib/TicketFunctionsHarness.php';

/**
 * Ticket issue/cancel/reconcile must fail closed on a WordPress-style empty read with last_error,
 * and still mint on a legitimate empty line.
 */
class TicketLineReadFailTest extends TestCase
{
	private ?TPFW_Test_Wpdb $wpdb = null;

	protected function setUp(): void
	{
		$mysqli = TPFW_Test_Credentials::mysqli();
		if(!$mysqli)
		{
			$this->markTestSkipped('No database credentials');
		}
		$this->wpdb = new TPFW_Test_Wpdb($mysqli, 'tpfwtlrf_');
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

	public function test_empty_line_without_error_still_issues(): void
	{
		$iLine  = 4101;
		$iOrder = 14101;
		$oItem  = $this->lineItem($iLine, 1);
		$this->putOrder($iOrder, 'completed', $iLine, 0);
		$iInserts = 0;
		$a = TPFW_Ticket_Line::issue($this->wpdb, $oItem, $iOrder, gmdate('Y-m-d H:i:s'), function() use (&$iInserts) {
			$iInserts++;
			$sNano = 'n'.bin2hex(random_bytes(8));
			$this->wpdb->query($this->wpdb->prepare(
				'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
				VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s)',
				array($this->wpdb->prefix.'tpfw_tickets', $sNano, 11, 1, 14101, 4101, 86400, 1, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'))
			));
			return $sNano;
		});
		$this->assertTrue($a['bStatus']);
		$this->assertSame(1, $iInserts);
		$this->assertSame(1, $this->liveOnLine($iLine));
	}

	public function test_issue_read_error_does_not_insert(): void
	{
		$iLine  = 4102;
		$iOrder = 14102;
		$oItem  = $this->lineItem($iLine, 1);
		$this->putOrder($iOrder, 'completed', $iLine, 0);
		$oFail = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) {
			return (bool)preg_match('/SELECT \* FROM/i', $sSql)
				&& str_contains($sSql, 'order_line_id')
				&& stripos($sSql, 'INSERT') === false;
		});
		$iInserts = 0;
		$a = TPFW_Ticket_Line::issue($oFail, $oItem, $iOrder, gmdate('Y-m-d H:i:s'), function() use (&$iInserts) {
			$iInserts++;
			return 'inj'.bin2hex(random_bytes(4));
		});
		$this->assertFalse($a['bStatus']);
		$this->assertSame(0, $iInserts);
		$this->assertSame(0, $this->liveOnLine($iLine));
		$this->assertStringContainsString('database write failed', $a['sMessage']);
	}

	public function test_cancel_read_error_is_not_already_cancelled(): void
	{
		$iLine  = 4103;
		$iOrder = 14103;
		$sNano  = $this->seedTicket($iLine, $iOrder);
		$oItem  = $this->lineItem($iLine, 1);
		$this->putOrder($iOrder, 'cancelled', $iLine, 0);
		$oFail = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) {
			return (bool)preg_match('/SELECT \* FROM/i', $sSql)
				&& str_contains($sSql, 'deleted IS NULL')
				&& str_contains($sSql, 'order_line_id');
		});
		$a = TPFW_Ticket_Line::cancel($oFail, $oItem, $iOrder);
		$this->assertFalse($a['bStatus']);
		$this->assertStringContainsString('database write failed', $a['sMessage']);
		$this->assertStringNotContainsString('already seems to be cancelled', $a['sMessage']);
		$this->assertSame(1, $this->liveOnLine($iLine));
		$this->assertNull($this->deletedAt($sNano));
	}

	public function test_reconcile_read_error_does_not_succeed(): void
	{
		$iLine  = 4104;
		$iOrder = 14104;
		$this->seedTicket($iLine, $iOrder);
		$this->seedTicket($iLine, $iOrder);
		$oItem  = $this->lineItem($iLine, 1);
		$this->putOrder($iOrder, 'completed', $iLine, 0);
		$oFail = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) {
			return (bool)preg_match('/SELECT \* FROM/i', $sSql)
				&& str_contains($sSql, 'order_line_id')
				&& stripos($sSql, 'INSERT') === false;
		});
		$a = TPFW_Ticket_Line::reconcile($oFail, $oItem, $iOrder);
		$this->assertFalse($a['bStatus']);
		$this->assertSame(2, $this->liveOnLine($iLine));
	}

	/**
	 * @param int $iLine
	 * @param int $iOrder
	 * @return string
	 */
	private function seedTicket($iLine, $iOrder)
	{
		$sNano = 'n'.bin2hex(random_bytes(8));
		$sNow  = gmdate('Y-m-d H:i:s');
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
			VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s)',
			array($this->wpdb->prefix.'tpfw_tickets', $sNano, 11, 1, $iOrder, $iLine, 86400, 1, $sNow, $sNow)
		));
		return $sNano;
	}

	/**
	 * @param int $iLine
	 * @return int
	 */
	private function liveOnLine($iLine)
	{
		return (int)$this->wpdb->get_var($this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE order_line_id = %d AND deleted IS NULL',
			$this->wpdb->prefix.'tpfw_tickets',
			$iLine
		));
	}

	/**
	 * @param string $sNano
	 * @return string|null
	 */
	private function deletedAt($sNano)
	{
		$m = $this->wpdb->get_var($this->wpdb->prepare(
			'SELECT deleted FROM %i WHERE nano_id = %s',
			$this->wpdb->prefix.'tpfw_tickets',
			$sNano
		));
		return ($m === null || $m === '') ? null : (string)$m;
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
