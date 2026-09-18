<?php
use PHPUnit\Framework\TestCase;

/**
 * Bite 2A ticket lock: two test categories, never mixed.
 *
 * Category 1 — serialised success: a second process prints READY, then GET_LOCKs with
 * timeout 5 while this process holds the line lock. After release, that single
 * production call continues and reaches the live-row target. No extra retry.
 *
 * Category 2 — lock-timeout: timeout 0 fails closed. These tests must not claim that
 * cancel/refund later converges; production has no automatic retry.
 */
class IssueCancelRaceTest extends TestCase
{
	private ?TPFW_Test_Wpdb $issue = null;

	private ?TPFW_Test_Wpdb $cancel = null;

	protected function setUp(): void
	{
		$mysqliIssue = TPFW_Test_Credentials::mysqli();
		$mysqliCancel = TPFW_Test_Credentials::mysqli();
		if(!$mysqliIssue || !$mysqliCancel)
		{
			$this->markTestSkipped('No database credentials');
		}
		$this->issue  = new TPFW_Test_Wpdb($mysqliIssue, 'tpfwrace_');
		$this->cancel = new TPFW_Test_Wpdb($mysqliCancel, 'tpfwrace_');
		TPFW_Test_Schema::install($this->issue);
		$GLOBALS['tpfw_test_orders'] = array();
	}

	protected function tearDown(): void
	{
		$GLOBALS['tpfw_test_orders'] = array();
		if($this->issue)
		{
			TPFW_Test_Schema::drop($this->issue);
			$this->issue->mysqli()->close();
		}
		if($this->cancel)
		{
			$this->cancel->mysqli()->close();
		}
	}

	/**
	 * Category 2. Bite 1 SQL interleaving is blocked: cancel with timeout 0 cannot
	 * mutate while the issue lock is held. This is not end-to-end cancel-vs-issue
	 * convergence; the timed-out cancel is not retried.
	 */
	public function test_full_cancel_timeout_does_not_write_while_issue_lock_held(): void
	{
		$iLine  = 501;
		$iOrder = 9001;
		$iUser  = 7;
		$sTable = $this->issue->prefix.'tpfw_tickets';
		$oItem  = $this->lineItem($iLine, 1);
		$this->putOrder($iOrder, 'cancelled', $iLine, 0);

		$oLock = TPFW_Named_Lock::acquire($this->issue, TPFW_Issue_Lock::name('ticket', $iLine), 5);
		$this->assertTrue($oLock->held(), 'issue connection must hold tpfw_ticket_issue_{line}');

		$aCancel = TPFW_Ticket_Line::cancel($this->cancel, $oItem, $iOrder, null, 0);
		$this->assertFalse($aCancel['bStatus']);
		$this->assertNull($aCancel['sync']);
		$this->assertStringContainsString('another request is in progress', $aCancel['sMessage']);
		$this->assertSame(0, $this->liveCount($sTable, $iLine));

		$oLock->release();
	}

	/**
	 * Category 2. Refund reconcile with timeout 0 does not shrink. Live count stays 3.
	 * Production does not retry this call.
	 */
	public function test_partial_refund_timeout_does_not_write_while_issue_lock_held(): void
	{
		$iLine  = 502;
		$iOrder = 9002;
		$iUser  = 7;
		$sTable = $this->issue->prefix.'tpfw_tickets';
		$sNow   = gmdate('Y-m-d H:i:s');
		$oItem  = $this->lineItem($iLine, 3, 11, -2);
		$this->putOrder($iOrder, 'completed', $iLine, -2);

		for($i = 0; $i < 3; $i++)
		{
			$this->insertTicket($this->issue, $sTable, 11, $iUser, $iOrder, $iLine, $sNow);
		}
		$this->assertSame(3, $this->liveCount($sTable, $iLine));

		$oLock = TPFW_Named_Lock::acquire($this->issue, TPFW_Issue_Lock::name('ticket', $iLine), 5);
		$this->assertTrue($oLock->held());

		$aRefund = TPFW_Ticket_Line::reconcile($this->cancel, $oItem, $iOrder, null, null, 0);
		$this->assertFalse($aRefund['bStatus']);
		$this->assertStringContainsString('another request is in progress', $aRefund['sMessage']);
		$this->assertSame(3, $this->liveCount($sTable, $iLine), 'timed-out reconcile must not shrink');

		$oLock->release();
		$this->assertSame(3, $this->liveCount($sTable, $iLine), 'no automatic retry after timeout');
	}

	/**
	 * Category 1. Cancel starts while issue holds the lock, waits (timeout 5), then
	 * revokes the row issued under that lock. One cancel, no extra invocation.
	 */
	public function test_waiting_cancel_revokes_ticket_issued_under_held_lock(): void
	{
		$iLine  = 511;
		$iOrder = 9011;
		$iUser  = 7;
		$sTable = $this->issue->prefix.'tpfw_tickets';
		$sNow   = gmdate('Y-m-d H:i:s');
		$this->putOrder($iOrder, 'completed', $iLine, 0);

		$oLock = TPFW_Named_Lock::acquire($this->issue, TPFW_Issue_Lock::name('ticket', $iLine), 5);
		$this->assertTrue($oLock->held());

		$aWorker = $this->startWaitWorker(array(
			'cancel', $this->issue->prefix, (string)$iLine, (string)$iOrder, (string)$iUser,
			'11', '1', '0', 'completed', '5',
		));

		$this->insertTicket($this->issue, $sTable, 11, $iUser, $iOrder, $iLine, $sNow);
		$this->assertSame(1, $this->liveCount($sTable, $iLine), 'waiting cancel must not write before release');

		$oLock->release();
		$aCancel = $this->finishWaitWorker($aWorker);
		$this->assertStringContainsString('cancelled', $aCancel['sMessage']);
		$this->assertFalse($aCancel['bStatus']);
		$this->assertSame(0, $this->liveCount($sTable, $iLine));
	}

	/**
	 * Category 1. Reconcile starts while the issue lock is held, waits, then shrinks
	 * to the current refund target. One reconcile, no extra issue/reconcile after.
	 */
	public function test_waiting_reconcile_shrinks_to_current_target_after_release(): void
	{
		$iLine  = 512;
		$iOrder = 9012;
		$iUser  = 7;
		$sTable = $this->issue->prefix.'tpfw_tickets';
		$sNow   = gmdate('Y-m-d H:i:s');
		$this->putOrder($iOrder, 'completed', $iLine, -2);

		for($i = 0; $i < 3; $i++)
		{
			$this->insertTicket($this->issue, $sTable, 11, $iUser, $iOrder, $iLine, $sNow);
		}

		$oLock = TPFW_Named_Lock::acquire($this->issue, TPFW_Issue_Lock::name('ticket', $iLine), 5);
		$this->assertTrue($oLock->held());

		$aWorker = $this->startWaitWorker(array(
			'reconcile', $this->issue->prefix, (string)$iLine, (string)$iOrder, (string)$iUser,
			'11', '3', '-2', 'completed', '5',
		));
		$this->assertSame(3, $this->liveCount($sTable, $iLine));

		$oLock->release();
		$aRefund = $this->finishWaitWorker($aWorker);
		$this->assertTrue($aRefund['bStatus']);
		$this->assertSame(1, $this->liveCount($sTable, $iLine));
	}

	/**
	 * Category 1. Order/refund state is written after the waiter printed READY and
	 * before the lock is released. The single reconcile reads target_active_quantity
	 * under the lock.
	 */
	public function test_waiting_reconcile_rereads_order_state_after_lock(): void
	{
		$iLine  = 504;
		$iOrder = 9004;
		$iUser  = 7;
		$sTable = $this->issue->prefix.'tpfw_tickets';
		$sNow   = gmdate('Y-m-d H:i:s');
		$sKv    = $this->issue->prefix.'tpfw_kv';
		$this->putOrder($iOrder, 'completed', $iLine, 0);
		$this->writeOrderKv($sKv, $iOrder, 'completed', 0);

		for($i = 0; $i < 3; $i++)
		{
			$this->insertTicket($this->issue, $sTable, 11, $iUser, $iOrder, $iLine, $sNow);
		}
		$this->assertSame(3, TPFW_Refund_Policy::target_active_quantity(wc_get_order($iOrder), $this->lineItem($iLine, 3)));

		$oLock = TPFW_Named_Lock::acquire($this->issue, TPFW_Issue_Lock::name('ticket', $iLine), 5);
		$this->assertTrue($oLock->held());

		$aWorker = $this->startWaitWorker(array(
			'reconcile-kv', $this->issue->prefix, (string)$iLine, (string)$iOrder, (string)$iUser,
			'11', '3', '0', 'completed', '5',
		));
		$this->assertSame(3, $this->liveCount($sTable, $iLine));

		$this->putOrder($iOrder, 'completed', $iLine, -2);
		$this->writeOrderKv($sKv, $iOrder, 'completed', -2);

		$oLock->release();
		$aRefund = $this->finishWaitWorker($aWorker);
		$this->assertTrue($aRefund['bStatus']);
		$this->assertSame(1, $this->liveCount($sTable, $iLine));
	}

	public function test_two_issue_calls_keep_one_set_of_live_rows(): void
	{
		$iLine  = 503;
		$iOrder = 9003;
		$iUser  = 7;
		$sTable = $this->issue->prefix.'tpfw_tickets';
		$sNow   = gmdate('Y-m-d H:i:s');
		$oItem  = $this->lineItem($iLine, 2);
		$this->putOrder($iOrder, 'completed', $iLine, 0);

		$aFirst = TPFW_Ticket_Line::issue($this->issue, $oItem, $iOrder, $sNow, function() use ($sTable, $iUser, $iOrder, $iLine, $sNow) {
			return $this->insertTicket($this->issue, $sTable, 11, $iUser, $iOrder, $iLine, $sNow);
		});
		$aAgain = TPFW_Ticket_Line::issue($this->cancel, $oItem, $iOrder, $sNow, function() {
			$this->fail('second issue must reuse existing nanos');
		});

		$this->assertTrue($aFirst['bStatus']);
		$this->assertTrue($aAgain['bStatus']);
		$this->assertSame($aFirst['sync']['keep'], $aAgain['sync']['keep']);
		$this->assertSame(array(), $aAgain['sync']['inserted']);
		$this->assertSame(2, $this->liveCount($sTable, $iLine));
	}

	public function test_lock_failure_writes_nothing_and_is_not_success(): void
	{
		$iLine  = 505;
		$iOrder = 9005;
		$iUser  = 7;
		$sTable = $this->issue->prefix.'tpfw_tickets';
		$sNow   = gmdate('Y-m-d H:i:s');
		$oItem  = $this->lineItem($iLine, 1);
		$this->putOrder($iOrder, 'completed', $iLine, 0);

		$oLock = TPFW_Named_Lock::acquire($this->issue, TPFW_Issue_Lock::name('ticket', $iLine), 5);
		$this->assertTrue($oLock->held());

		$aIssue = TPFW_Ticket_Line::issue($this->cancel, $oItem, $iOrder, $sNow, function() {
			$this->fail('issue must not insert without the lock');
		}, null, 0);
		$aCancel = TPFW_Ticket_Line::cancel($this->cancel, $oItem, $iOrder, null, 0);
		$aRefund = TPFW_Ticket_Line::reconcile($this->cancel, $oItem, $iOrder, null, null, 0);

		$this->assertFalse($aIssue['bStatus']);
		$this->assertFalse($aCancel['bStatus']);
		$this->assertFalse($aRefund['bStatus']);
		$this->assertNull($aIssue['sync']);
		$this->assertStringContainsString('another request is in progress', $aIssue['sMessage']);
		$this->assertStringContainsString('another request is in progress', $aCancel['sMessage']);
		$this->assertStringContainsString('another request is in progress', $aRefund['sMessage']);
		$this->assertSame(0, $this->liveCount($sTable, $iLine));

		$oLock->release();
	}

	public function test_distinct_order_lines_use_distinct_lock_names(): void
	{
		$this->assertSame('tpfw_ticket_issue_501', TPFW_Issue_Lock::name('ticket', 501));
		$this->assertSame('tpfw_ticket_issue_502', TPFW_Issue_Lock::name('ticket', 502));
		$this->assertNotSame(
			TPFW_Issue_Lock::name('ticket', 501),
			TPFW_Issue_Lock::name('ticket', 502)
		);

		$sNow   = gmdate('Y-m-d H:i:s');
		$iOrder = 9006;
		$oHeld  = $this->lineItem(601, 1);
		$oFree  = $this->lineItem(602, 1);
		$this->putOrder($iOrder, 'completed', 602, 0);

		$oLock = TPFW_Named_Lock::acquire($this->issue, TPFW_Issue_Lock::name('ticket', 601), 5);
		$this->assertTrue($oLock->held());

		$aIssued = TPFW_Ticket_Line::issue($this->cancel, $oFree, $iOrder, $sNow, function() use ($sNow, $iOrder) {
			return $this->insertTicket($this->cancel, $this->cancel->prefix.'tpfw_tickets', 11, 7, $iOrder, 602, $sNow);
		}, null, 0);

		$this->assertTrue($aIssued['bStatus']);
		$this->assertSame(1, $this->liveCount($this->issue->prefix.'tpfw_tickets', 602));
		$this->assertSame(0, $this->liveCount($this->issue->prefix.'tpfw_tickets', 601));

		$aBlocked = TPFW_Ticket_Line::issue($this->cancel, $oHeld, $iOrder, $sNow, function() {
			$this->fail('held line must not issue without its own lock');
		}, null, 0);
		$this->assertFalse($aBlocked['bStatus']);

		$oLock->release();
	}

	public function test_production_ticket_callers_use_ticket_line_lock(): void
	{
		$sTicket = file_get_contents(TPFW_PLUGIN_DIR.'inc/ticket-wc-product/class--ticket-wc-product.php');
		$sLine   = file_get_contents(TPFW_PLUGIN_DIR.'inc/support/class--ticket-line.php');
		$sLock   = file_get_contents(TPFW_PLUGIN_DIR.'inc/support/class--issue-lock.php');
		$sPass   = file_get_contents(TPFW_PLUGIN_DIR.'inc/pass-wc-product/class--pass-wc-product.php');
		$sSlot   = file_get_contents(TPFW_PLUGIN_DIR.'inc/timeslot-ticket-wc-product/class--timeslot-ticket-wc-product.php');
		$sType   = file_get_contents(TPFW_PLUGIN_DIR.'inc/product-type/class--product-type.php');
		$sMain   = file_get_contents(TPFW_PLUGIN_DIR.'class--main.php');
		$sBoot   = file_get_contents(TPFW_PLUGIN_DIR.'tickets-passes-for-woocommerce.php');
		$sLoad   = file_get_contents(TPFW_PLUGIN_DIR.'inc/support/load.php');

		$this->assertNotFalse(strpos($sLine, "TPFW_Issue_Lock::with_line(\$wpdb, 'ticket'"));
		$this->assertNotFalse(strpos($sLine, 'TPFW_Refund_Policy::issue_quantity'));
		$this->assertNotFalse(strpos($sLine, 'TPFW_Refund_Policy::target_active_quantity'));
		$this->assertNotFalse(strpos($sTicket, 'TPFW_Ticket_Line::issue'));
		$this->assertNotFalse(strpos($sTicket, 'TPFW_Ticket_Line::cancel'));
		$this->assertNotFalse(strpos($sTicket, 'TPFW_Ticket_Line::reconcile'));
		$this->assertFalse(strpos($sTicket, 'TPFW_Issue_Lock::sync_line'));
		$this->assertFalse(strpos($sTicket, 'issue_held'));
		$this->assertFalse(strpos($sTicket, 'cancel_held'));
		$this->assertFalse(strpos($sTicket, 'reconcile_held'));
		$this->assertFalse(strpos($sTicket, 'sync_held'));
		$iCreate = strpos($sTicket, 'function create_ticket');
		$iCancel = strpos($sTicket, 'function cancel_ticket');
		$this->assertNotFalse($iCreate);
		$this->assertNotFalse($iCancel);
		$this->assertFalse(strpos(substr($sTicket, $iCreate, $iCancel - $iCreate), 'cancel_ticket('));
		$this->assertNotFalse(strpos($sPass, "TPFW_Issue_Lock::with_line(\$wpdb, 'pass'"));
		$this->assertFalse(strpos($sPass, 'TPFW_Ticket_Line'));
		$this->assertFalse(strpos($sSlot, 'TPFW_Issue_Lock'));
		$this->assertFalse(strpos($sSlot, 'TPFW_Ticket_Line'));
		$this->assertFalse(strpos($sType, 'TPFW_Ticket_Line'));
		$this->assertNotFalse(strpos($sTicket, 'Bite 3: ticket issue still writes QR codes'));
		$iLoadTicket = strpos($sLoad, 'class--ticket-line.php');
		$iLoadLock   = strpos($sLoad, 'class--issue-lock.php');
		$iMainLoad   = strpos($sMain, 'inc/support/load.php');
		$iMainTicket = strpos($sMain, 'inc/ticket-wc-product/class--ticket-wc-product.php');
		$this->assertNotFalse($iLoadLock);
		$this->assertNotFalse($iLoadTicket);
		$this->assertTrue($iLoadLock < $iLoadTicket);
		$this->assertNotFalse($iMainLoad);
		$this->assertNotFalse($iMainTicket);
		$this->assertTrue($iMainLoad < $iMainTicket);
		$this->assertNotFalse(strpos($sBoot, 'inc/support/load.php'));
		$this->assertSame(1, preg_match('/issue_held\s*\(/', $sLine));
		$this->assertSame(1, preg_match('/function issue_held/', $sLine));
		$this->assertNotFalse(strpos($sLock, 'function sync_held'));
		$this->assertNotFalse(strpos($sLine, '@internal Call only from TPFW_Ticket_Line::issue()'));
	}

	public function test_held_methods_are_only_called_from_lock_callbacks(): void
	{
		$aFiles = TPFW_Php_Scope_Scan::first_party_files();
		$aHits  = array();
		foreach($aFiles as $sFile)
		{
			$sRel = substr($sFile, strlen(TPFW_PLUGIN_DIR));
			if($sRel === 'inc/support/class--ticket-line.php' || $sRel === 'inc/support/class--issue-lock.php')
			{
				continue;
			}
			$sSrc = file_get_contents($sFile);
			foreach(array(
				'sync_held(',
				'issue_held(',
				'cancel_held(',
				'reconcile_held(',
				'cancel_nano_held(',
				'reset_nano_held(',
				'transfer_nano_held(',
			) as $sCall)
			{
				if(strpos($sSrc, $sCall) !== false)
				{
					$aHits[] = $sRel.' '.$sCall;
				}
			}
		}
		$this->assertSame(array(), $aHits, implode("\n", $aHits));
	}

	/**
	 * @param list<string> $aArgs argv after the worker script; index 2 is order_line_id.
	 * @return array{0:resource,1:array}
	 */
	private function startWaitWorker(array $aArgs)
	{
		$iLine = (int)$aArgs[2];
		$aCmd = array_merge(array(PHP_BINARY, TPFW_TEST_ROOT.'/bin/ticket-line-wait-worker.php'), $aArgs);
		$aPipes = array();
		$mProc  = proc_open($aCmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $aPipes, TPFW_PLUGIN_DIR);
		$this->assertIsResource($mProc);
		$this->waitForReadyKv($iLine);
		return array($mProc, $aPipes);
	}

	/**
	 * @param int $iLine
	 * @return void
	 */
	private function waitForReadyKv($iLine)
	{
		$sKey = 'wait-ready-'.$iLine;
		$tEnd = microtime(true) + 10;
		do
		{
			$m = $this->issue->get_var($this->issue->prepare(
				'SELECT v FROM %i WHERE k = %s',
				$this->issue->prefix.'tpfw_kv',
				$sKey
			));
			if($m !== null && $m !== '')
			{
				return;
			}
		}
		while(microtime(true) < $tEnd);
		$this->fail('worker did not publish wait-ready barrier for line '.$iLine);
	}

	/**
	 * @param array{0:resource,1:array} $aWorker
	 * @return array<string,mixed>
	 */
	private function finishWaitWorker(array $aWorker)
	{
		$mProc  = $aWorker[0];
		$aPipes = $aWorker[1];
		stream_set_timeout($aPipes[1], 10);
		$sOut = trim((string)stream_get_contents($aPipes[1]));
		$sErr = stream_get_contents($aPipes[2]);
		fclose($aPipes[1]);
		fclose($aPipes[2]);
		$iCode = proc_close($mProc);
		$this->assertSame(0, $iCode, $sErr."\n".$sOut);
		$a = json_decode($sOut, true);
		$this->assertIsArray($a, $sOut);
		return $a;
	}

	/**
	 * @param string $sTable
	 * @param int    $iOrder
	 * @param string $sStatus
	 * @param int    $iRefunded
	 * @return void
	 */
	private function writeOrderKv($sTable, $iOrder, $sStatus, $iRefunded)
	{
		$sJson = json_encode(array('status' => $sStatus, 'refunded' => $iRefunded));
		$this->issue->query($this->issue->prepare(
			'INSERT INTO %i (k, v) VALUES (%s, %s) ON DUPLICATE KEY UPDATE v = %s',
			$sTable,
			'order-'.$iOrder,
			$sJson,
			$sJson
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

	/**
	 * @param TPFW_Test_Wpdb $wpdb
	 * @param string         $sTable
	 * @param int            $iProduct
	 * @param int            $iUser
	 * @param int            $iOrder
	 * @param int            $iLine
	 * @param string         $sNow
	 * @return string
	 */
	private function insertTicket($wpdb, $sTable, $iProduct, $iUser, $iOrder, $iLine, $sNow)
	{
		$sNano = 't'.bin2hex(random_bytes(8));
		$m = $wpdb->query($wpdb->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
			VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s)',
			array($sTable, $sNano, $iProduct, $iUser, $iOrder, $iLine, 86400, 1, $sNow, $sNow)
		));
		$this->assertTrue(TPFW_Db_Write::inserted_row($m));
		return $sNano;
	}

	/**
	 * @param string $sTable
	 * @param int    $iLine
	 * @return int
	 */
	private function liveCount($sTable, $iLine)
	{
		return (int)$this->issue->get_var($this->issue->prepare(
			'SELECT COUNT(*) FROM %i WHERE order_line_id = %d AND deleted IS NULL',
			$sTable,
			$iLine
		));
	}
}
