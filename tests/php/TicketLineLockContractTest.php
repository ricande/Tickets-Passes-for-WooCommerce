<?php
use PHPUnit\Framework\TestCase;

require_once TPFW_TEST_ROOT.'/lib/TicketFunctionsHarness.php';

/**
 * Regular ticket operations hold at most one named lock: tpfw_ticket_issue_{line}.
 */
class TicketLineLockContractTest extends TestCase
{
	private ?TPFW_Test_Wpdb $wpdb = null;

	private TPFW_Test_Ticket_Functions $fn;

	protected function setUp(): void
	{
		$mysqli = TPFW_Test_Credentials::mysqli();
		if(!$mysqli)
		{
			$this->markTestSkipped('No database credentials');
		}
		$this->wpdb = new TPFW_Test_Wpdb($mysqli, 'tpfwlkct_');
		TPFW_Test_Schema::install($this->wpdb);
		tpfw_test_install_ticket_stats($this->wpdb);
		$GLOBALS['tpfw_test_orders'] = array();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->fn = new TPFW_Test_Ticket_Functions();
	}

	protected function tearDown(): void
	{
		$GLOBALS['tpfw_test_orders'] = array();
		unset($GLOBALS['wpdb']);
		if($this->wpdb)
		{
			tpfw_test_drop_ticket_stats($this->wpdb);
			TPFW_Test_Schema::drop($this->wpdb);
			$this->wpdb->mysqli()->close();
		}
	}

	public function test_source_has_no_nested_ticket_locks(): void
	{
		$sLine = file_get_contents(TPFW_PLUGIN_DIR.'inc/support/class--ticket-line.php');
		$sFn   = file_get_contents(TPFW_PLUGIN_DIR.'inc/functions/class--functions.php');
		$this->assertFalse(strpos($sLine, 'SELECT GET_LOCK'));
		$this->assertFalse(strpos($sLine, "GET_LOCK("));
		$this->assertFalse(strpos($sLine, "'tpfw_checkin_"));
		$this->assertFalse(strpos($sLine, 'checkin_lock_name'));
		$this->assertFalse(strpos($sLine, 'with_nano_checkin'));
		$this->assertNotFalse(strpos($sFn, 'function checkin_ticket_under_line_lock'));
		$iTicket = strpos($sFn, 'function checkin_ticket_under_line_lock');
		$sTicket = substr($sFn, $iTicket, 2200);
		$this->assertNotFalse(strpos($sTicket, 'TPFW_Ticket_Line::with_lock'));
		$this->assertFalse(strpos($sTicket, 'with_checkin_lock'));
		$this->assertFalse(strpos($sTicket, 'tpfw_checkin_'));
	}

	public function test_instrument_records_nested_get_lock_depth(): void
	{
		$oTrace = new TPFW_Lock_Trace_Wpdb($this->wpdb);
		$oOne = TPFW_Named_Lock::acquire($oTrace, 'tpfw_ticket_issue_1', 0);
		$oTwo = TPFW_Named_Lock::acquire($oTrace, 'tpfw_checkin_nested', 0);
		$this->assertGreaterThanOrEqual(1, $oTrace->iMaxDepth);
		if($oTwo->held() && $oOne->held())
		{
			$this->assertSame(2, $oTrace->iMaxDepth);
		}
		$oTwo->release();
		$oOne->release();
		$this->assertSame(0, $oTrace->iDepth);
	}

	public function test_issue_holds_only_the_line_lock(): void
	{
		$iLine  = 1201;
		$iOrder = 11201;
		$oTrace = new TPFW_Lock_Trace_Wpdb($this->wpdb);
		$oItem  = $this->lineItem($iLine, 1);
		$this->putOrder($iOrder, 'completed', $iLine, 0);
		$sNow   = gmdate('Y-m-d H:i:s');
		$sTable = $this->wpdb->prefix.'tpfw_tickets';
		$a = TPFW_Ticket_Line::issue($oTrace, $oItem, $iOrder, $sNow, function() use ($sTable, $iLine, $iOrder) {
			$sNano = 'n'.bin2hex(random_bytes(8));
			$this->wpdb->query($this->wpdb->prepare(
				'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, valid_from, valid_to, max_uses, created, updated)
				VALUES (%s, %d, %d, %d, %d, %d, %s, %s, %d, %s, %s)',
				array($sTable, $sNano, 11, 7, $iOrder, $iLine, 86400, gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', time() + 86400), 1, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'))
			));
			return $sNano;
		});
		$this->assertTrue($a['bStatus']);
		$this->assertSingleLineLock($oTrace, $iLine);
	}

	public function test_cancel_holds_only_the_line_lock(): void
	{
		$iLine  = 1202;
		$iOrder = 11202;
		$sNano  = $this->seedTicket($iLine, $iOrder, 7);
		unset($sNano);
		$oTrace = new TPFW_Lock_Trace_Wpdb($this->wpdb);
		$oItem  = $this->lineItem($iLine, 1);
		$this->putOrder($iOrder, 'cancelled', $iLine, 0);
		$a = TPFW_Ticket_Line::cancel($oTrace, $oItem, $iOrder);
		$this->assertStringContainsString('cancelled', $a['sMessage']);
		$this->assertSingleLineLock($oTrace, $iLine);
	}

	public function test_reconcile_holds_only_the_line_lock(): void
	{
		$iLine  = 1203;
		$iOrder = 11203;
		$this->seedTicket($iLine, $iOrder, 7);
		$this->seedTicket($iLine, $iOrder, 7);
		$oTrace = new TPFW_Lock_Trace_Wpdb($this->wpdb);
		$oItem  = $this->lineItem($iLine, 2, 11, -1);
		$this->putOrder($iOrder, 'completed', $iLine, -1);
		TPFW_Ticket_Line::reconcile($oTrace, $oItem, $iOrder);
		$this->assertSingleLineLock($oTrace, $iLine);
	}

	public function test_dashboard_nano_ops_hold_only_the_line_lock(): void
	{
		$iLine  = 1204;
		$sNano  = $this->seedTicket($iLine, 11204, 7);
		foreach(array('cancel', 'reset', 'transfer') as $sOp)
		{
			$oTrace = new TPFW_Lock_Trace_Wpdb($this->wpdb);
			if($sOp === 'cancel')
			{
				$a = TPFW_Ticket_Line::cancel_nano($oTrace, $sNano);
				$this->assertTrue($a['bSuccess']);
			}
			elseif($sOp === 'reset')
			{
				$a = TPFW_Ticket_Line::reset_nano($oTrace, $sNano);
				$this->assertTrue($a['bSuccess']);
			}
			else
			{
				$a = TPFW_Ticket_Line::transfer_nano($oTrace, $sNano, 42);
				$this->assertTrue($a['bSuccess']);
			}
			$this->assertSingleLineLock($oTrace, $iLine);
		}
	}

	public function test_ticket_checkin_uses_the_same_line_lock_name(): void
	{
		$iLine = 1205;
		$sNano = $this->seedTicket($iLine, 11205, 7);
		$oRow  = $this->liveRow($sNano);
		$oTrace = new TPFW_Lock_Trace_Wpdb($this->wpdb);
		$mIn = $this->fn->checkin_ticket($oTrace, $oRow, 9, false);
		$this->assertIsArray($mIn);
		$this->assertSingleLineLock($oTrace, $iLine);
		$this->assertSame(array(TPFW_Ticket_Line::lock_name($iLine)), $oTrace->aAcquired);
		$this->assertSame(TPFW_Issue_Lock::name('ticket', $iLine), $oTrace->aAcquired[0]);
	}

	public function test_lock_is_released_in_finally_after_exception(): void
	{
		$iLine = 1206;
		$oTrace = new TPFW_Lock_Trace_Wpdb($this->wpdb);
		try
		{
			TPFW_Ticket_Line::with_lock($oTrace, $iLine, static function() {
				throw new RuntimeException('held path exploded');
			}, 5);
			$this->fail('with_lock must rethrow');
		}
		catch(RuntimeException $e)
		{
			$this->assertSame('held path exploded', $e->getMessage());
		}
		$this->assertSame(0, $oTrace->iDepth);
		$this->assertSame(1, $oTrace->iMaxDepth);
		$this->assertSame(array(TPFW_Ticket_Line::lock_name($iLine)), $oTrace->aAcquired);
		$this->assertNull($this->wpdb->get_var($this->wpdb->prepare(
			'SELECT IS_USED_LOCK(%s)',
			TPFW_Ticket_Line::lock_name($iLine)
		)));
	}

	/**
	 * @param TPFW_Lock_Trace_Wpdb $oTrace
	 * @param int                  $iLine
	 * @return void
	 */
	private function assertSingleLineLock($oTrace, $iLine)
	{
		$sName = TPFW_Ticket_Line::lock_name($iLine);
		$this->assertLessThanOrEqual(1, $oTrace->iMaxDepth);
		$this->assertSame(0, $oTrace->iDepth);
		$this->assertNotSame(array(), $oTrace->aAcquired);
		foreach($oTrace->aAcquired as $sGot)
		{
			$this->assertSame($sName, $sGot);
			$this->assertStringStartsWith('tpfw_ticket_issue_', $sGot);
			$this->assertFalse(str_starts_with($sGot, 'tpfw_checkin_'));
		}
		$iGets = 0;
		$iRel  = 0;
		foreach($oTrace->aEvents as $aEv)
		{
			if($aEv[0] === 'get' && TPFW_Named_Lock::is_acquired($aEv[2]))
			{
				$iGets++;
			}
			if($aEv[0] === 'release')
			{
				$iRel++;
			}
		}
		$this->assertSame($iGets, $iRel);
	}

	/**
	 * @param int $iLine
	 * @param int $iOrder
	 * @param int $iUser
	 * @return string
	 */
	private function seedTicket($iLine, $iOrder, $iUser)
	{
		$sTable = $this->wpdb->prefix.'tpfw_tickets';
		$sNow   = gmdate('Y-m-d H:i:s');
		$sNano  = 'n'.bin2hex(random_bytes(8));
		$m = $this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, valid_from, valid_to, max_uses, created, updated)
			VALUES (%s, %d, %d, %d, %d, %d, %s, %s, %d, %s, %s)',
			array($sTable, $sNano, 11, $iUser, $iOrder, $iLine, 86400, gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', time() + 86400), 1, $sNow, $sNow)
		));
		$this->assertTrue(TPFW_Db_Write::inserted_row($m));
		return $sNano;
	}

	/**
	 * @param string $sNano
	 * @return object|null
	 */
	private function liveRow($sNano)
	{
		return $this->wpdb->get_row($this->wpdb->prepare(
			'SELECT * FROM %i WHERE nano_id = %s AND deleted IS NULL',
			$this->wpdb->prefix.'tpfw_tickets',
			$sNano
		));
	}

	/**
	 * @param int $iLine
	 * @param int $iQty
	 * @param int $iProduct
	 * @param int $iRefunded
	 * @return TPFW_Test_Refund_Item
	 */
	private function lineItem($iLine, $iQty, $iProduct = 11, $iRefunded = 0)
	{
		$oItem = new TPFW_Test_Refund_Item($iLine, $iQty, $iRefunded);
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
}
