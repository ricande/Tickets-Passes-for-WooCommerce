<?php
use PHPUnit\Framework\TestCase;

require_once TPFW_TEST_ROOT.'/lib/TicketFunctionsHarness.php';

/**
 * Bite 2A.2b: order-line cancel/shrink versus ticket check-in.
 *
 * Safe end state: never a successful check-in plus a live stats row on a
 * cancelled or shrinked ticket. Sequential issue-after-dashboard-cancel lives
 * in tests/repro/, not this suite.
 */
class LineRevokeCheckinRaceTest extends TestCase
{
	private ?TPFW_Test_Wpdb $a = null;

	private ?TPFW_Test_Wpdb $b = null;

	private TPFW_Test_Ticket_Functions $fn;

	/** @var list<array{0:resource|false,1:array}> */
	private array $aWorkers = array();

	protected function setUp(): void
	{
		$mysqliA = TPFW_Test_Credentials::mysqli();
		$mysqliB = TPFW_Test_Credentials::mysqli();
		if(!$mysqliA || !$mysqliB)
		{
			$this->markTestSkipped('No database credentials');
		}
		$this->a = new TPFW_Test_Wpdb($mysqliA, 'tpfwlnrv_');
		$this->b = new TPFW_Test_Wpdb($mysqliB, 'tpfwlnrv_');
		TPFW_Test_Schema::install($this->a);
		tpfw_test_install_ticket_stats($this->a);
		$GLOBALS['tpfw_test_orders'] = array();
		$GLOBALS['wpdb'] = $this->b;
		$this->fn = new TPFW_Test_Ticket_Functions();
	}

	protected function tearDown(): void
	{
		foreach($this->aWorkers as $aWorker)
		{
			$this->stopWorker($aWorker);
		}
		$this->aWorkers = array();
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

	public function test_full_cancel_timeout_does_not_write_while_checkin_lock_held(): void
	{
		$iLine = 901;
		$sNano = $this->seedTicket($iLine, 9901, 7);
		$oItem = $this->lineItem($iLine, 1);
		$this->putOrder(9901, 'cancelled', $iLine, 0);

		$oLock = TPFW_Named_Lock::acquire($this->a, TPFW_Ticket_Line::lock_name($iLine), 5);
		$this->assertTrue($oLock->held());

		$aCancel = TPFW_Ticket_Line::cancel($this->b, $oItem, 9901, null, 0);
		$this->assertStringContainsString('another request is in progress', $aCancel['sMessage']);
		$this->assertNull($this->deletedAt($sNano), 'full cancel must not write through tpfw_ticket_issue_{line}');
		$this->assertSame(0, $this->liveStats($sNano));

		$oLock->release();
	}

	public function test_paused_checkin_then_full_cancel_leaves_no_live_stats(): void
	{
		$iLine = 902;
		$sNano = $this->seedTicket($iLine, 9902, 7);
		$oItem = $this->lineItem($iLine, 1);
		$this->putOrder(9902, 'cancelled', $iLine, 0);

		$aCheckin = $this->startPauseCheckin($sNano);
		$this->waitForKv('wait-ready-pause-insert-'.$sNano);
		$this->assertNull($this->deletedAt($sNano));
		$this->assertSame(0, $this->liveStats($sNano));

		$aCancelW = $this->startLineWorker(array(
			'cancel', $this->a->prefix, (string)$iLine, '9902', '7', '11', '1', '0', 'cancelled', '5',
		), $iLine);
		$this->assertNull($this->deletedAt($sNano), 'cancel must wait; ticket still live during paused check-in');
		$this->assertFalse($this->kvReady('wait-go-pause-insert-'.$sNano));

		$this->publishKv('wait-go-pause-insert-'.$sNano);
		$aIn = $this->finishWorker($aCheckin);
		$aCn = $this->finishWorker($aCancelW);
		$this->assertFalse($aIn['bResponse'] ?? true);
		$this->assertStringContainsString('cancelled', $aCn['sMessage']);
		$this->assertNotNull($this->deletedAt($sNano));
		$this->assertSame(0, $this->liveStats($sNano), 'serialised cancel must retire the check-in stats row');
	}

	public function test_full_cancel_first_refuses_later_checkin(): void
	{
		$iLine = 903;
		$sNano = $this->seedTicket($iLine, 9903, 7);
		$oItem = $this->lineItem($iLine, 1);
		$this->putOrder(9903, 'cancelled', $iLine, 0);

		$aCancel = TPFW_Ticket_Line::cancel($this->b, $oItem, 9903);
		$this->assertStringContainsString('cancelled', $aCancel['sMessage']);
		$this->assertNotNull($this->deletedAt($sNano));

		$oRow = (object)array(
			'nano_id'    => $sNano,
			'product_id' => 11,
			'max_uses'   => 1,
			'valid_from' => gmdate('Y-m-d H:i:s', time() - 3600),
			'valid_to'   => gmdate('Y-m-d H:i:s', time() + 86400),
		);
		$mIn = $this->fn->checkin_ticket($this->a, $oRow, 9, false);
		$this->assertInstanceOf(WP_REST_Response::class, $mIn);
		$this->assertSame(0, $this->liveStats($sNano));
	}

	public function test_shrink_timeout_does_not_write_while_victim_checkin_lock_held(): void
	{
		$iLine = 911;
		$aNanos = $this->seedLine($iLine, 9911, 7, 3);
		$sVictim = $aNanos[2];
		$oItem = $this->lineItem($iLine, 3, 11, -1);
		$this->putOrder(9911, 'completed', $iLine, -1);

		$oLock = TPFW_Named_Lock::acquire($this->a, TPFW_Ticket_Line::lock_name($iLine), 5);
		$this->assertTrue($oLock->held());

		$aRef = TPFW_Ticket_Line::reconcile($this->b, $oItem, 9911, null, null, 0);
		$this->assertStringContainsString('another request is in progress', $aRef['sMessage']);
		$this->assertNull($this->deletedAt($sVictim), 'shrink must not write through tpfw_ticket_issue_{line}');
		$this->assertSame(3, $this->liveOnLine($iLine));

		$oLock->release();
	}

	public function test_paused_victim_checkin_then_shrink_leaves_no_live_stats(): void
	{
		$iLine = 912;
		$aNanos = $this->seedLine($iLine, 9912, 7, 3);
		$sVictim = $aNanos[2];
		$oItem = $this->lineItem($iLine, 3, 11, -1);
		$this->putOrder(9912, 'completed', $iLine, -1);

		$aCheckin = $this->startPauseCheckin($sVictim);
		$this->waitForKv('wait-ready-pause-insert-'.$sVictim);
		$this->assertNull($this->deletedAt($sVictim));

		$aShrinkW = $this->startLineWorker(array(
			'reconcile', $this->a->prefix, (string)$iLine, '9912', '7', '11', '3', '-1', 'completed', '5',
		), $iLine);
		$this->assertNull($this->deletedAt($sVictim), 'shrink must wait on the victim check-in');

		$this->publishKv('wait-go-pause-insert-'.$sVictim);
		$aIn = $this->finishWorker($aCheckin);
		$aSh = $this->finishWorker($aShrinkW);
		$this->assertFalse($aIn['bResponse'] ?? true);
		$this->assertNotFalse(strpos((string)$aSh['sMessage'], $sVictim));
		$this->assertNotNull($this->deletedAt($sVictim));
		$this->assertSame(0, $this->liveStats($sVictim));
		$this->assertNull($this->deletedAt($aNanos[0]));
		$this->assertNull($this->deletedAt($aNanos[1]));
		$this->assertSame(2, $this->liveOnLine($iLine));
	}

	public function test_shrink_first_refuses_victim_checkin(): void
	{
		$iLine = 913;
		$aNanos = $this->seedLine($iLine, 9913, 7, 3);
		$sVictim = $aNanos[2];
		$oItem = $this->lineItem($iLine, 3, 11, -1);
		$this->putOrder(9913, 'completed', $iLine, -1);

		$aRef = TPFW_Ticket_Line::reconcile($this->b, $oItem, 9913);
		$this->assertNotFalse(strpos((string)$aRef['sMessage'], $sVictim));
		$this->assertNotNull($this->deletedAt($sVictim));

		$oRow = $this->staleRow($sVictim);
		$mIn = $this->fn->checkin_ticket($this->a, $oRow, 9, false);
		$this->assertInstanceOf(WP_REST_Response::class, $mIn);
		$this->assertSame(0, $this->liveStats($sVictim));
	}

	public function test_non_victim_checkin_is_serialised_on_the_same_line(): void
	{
		$iLine = 914;
		$aNanos = $this->seedLine($iLine, 9914, 7, 3);
		$oLock = TPFW_Named_Lock::acquire($this->a, TPFW_Ticket_Line::lock_name($iLine), 5);
		$this->assertTrue($oLock->held());

		$oRow = $this->liveRow($aNanos[0]);
		$mBusy = $this->fn->checkin_ticket($this->b, $oRow, 9, false, 0);
		$this->assertInstanceOf(WP_REST_Response::class, $mBusy);
		$this->assertSame(0, $this->liveStats($aNanos[0]));

		$oLock->release();
		$mIn = $this->fn->checkin_ticket($this->b, $oRow, 9, false, 5);
		$this->assertIsArray($mIn);
		$this->assertSame(1, $this->liveStats($aNanos[0]));
	}

	public function test_line_lock_timeout_on_full_cancel_writes_nothing(): void
	{
		$iLine = 921;
		$aNanos = $this->seedLine($iLine, 9921, 7, 3);
		$oItem = $this->lineItem($iLine, 3);
		$this->putOrder(9921, 'cancelled', $iLine, 0);

		$oLock = TPFW_Named_Lock::acquire($this->a, TPFW_Ticket_Line::lock_name($iLine), 5);
		$this->assertTrue($oLock->held());

		$aCancel = TPFW_Ticket_Line::cancel($this->b, $oItem, 9921, null, 0);
		$this->assertStringContainsString('another request is in progress', $aCancel['sMessage']);
		$this->assertSame(3, $this->liveOnLine($iLine));
		foreach($aNanos as $sNano)
		{
			$this->assertNull($this->deletedAt($sNano));
			$this->assertSame(0, $this->liveStats($sNano));
		}

		$oLock->release();
		$this->assertSame(3, $this->liveOnLine($iLine), 'timed-out cancel must not retry');
	}

	public function test_transferred_holder_is_still_revoked_by_full_cancel(): void
	{
		$iLine = 931;
		$sNano = $this->seedTicket($iLine, 9931, 7);
		$this->a->query($this->a->prepare(
			'UPDATE %i SET user_id = %d WHERE nano_id = %s',
			$this->a->prefix.'tpfw_tickets',
			42,
			$sNano
		));
		$oItem = $this->lineItem($iLine, 1);
		$this->putOrder(9931, 'cancelled', $iLine, 0);
		$aCancel = TPFW_Ticket_Line::cancel($this->b, $oItem, 9931);
		$this->assertStringContainsString('cancelled', $aCancel['sMessage']);
		$this->assertNotNull($this->deletedAt($sNano));
		$this->assertSame(42, $this->holder($sNano));
	}

	public function test_two_line_ops_still_share_the_line_lock(): void
	{
		$iLine = 932;
		$this->seedTicket($iLine, 9932, 7);
		$oItem = $this->lineItem($iLine, 1);
		$this->putOrder(9932, 'cancelled', $iLine, 0);

		$oLock = TPFW_Named_Lock::acquire($this->a, TPFW_Issue_Lock::name('ticket', $iLine), 5);
		$this->assertTrue($oLock->held());
		$aCancel = TPFW_Ticket_Line::cancel($this->b, $oItem, 9932, null, 0);
		$this->assertStringContainsString('another request is in progress', $aCancel['sMessage']);
		$oLock->release();
	}

	public function test_other_order_line_is_not_blocked_by_foreign_line_lock(): void
	{
		$sHeld = $this->seedTicket(941, 9941, 7);
		$sFree = $this->seedTicket(942, 9942, 7);
		$oLock = TPFW_Named_Lock::acquire($this->a, TPFW_Ticket_Line::lock_name(941), 5);
		$this->assertTrue($oLock->held());

		$oRow = $this->liveRow($sFree);
		$mIn = $this->fn->checkin_ticket($this->b, $oRow, 9, false, 0);
		$this->assertIsArray($mIn);
		$this->assertSame(1, $this->liveStats($sFree));

		$oItem = $this->lineItem(942, 1);
		$this->putOrder(9942, 'cancelled', 942, 0);
		$aCancel = TPFW_Ticket_Line::cancel($this->b, $oItem, 9942, null, 0);
		$this->assertStringContainsString('cancelled', $aCancel['sMessage']);
		$this->assertNotNull($this->deletedAt($sFree));
		$this->assertSame(0, $this->liveStats($sFree));
		$this->assertNull($this->deletedAt($sHeld));

		$oLock->release();
	}

	public function test_same_nano_checkins_serialise_and_keep_max_uses(): void
	{
		$iLine = 951;
		$sNano = $this->seedTicket($iLine, 9951, 7);
		$oLock = TPFW_Named_Lock::acquire($this->a, TPFW_Ticket_Line::lock_name($iLine), 5);
		$this->assertTrue($oLock->held());

		$aFirst  = $this->startWaitCheckin($sNano);
		$aSecond = $this->startWaitCheckin($sNano);
		$oRow = $this->liveRow($sNano);
		$mBusy = $this->fn->checkin_ticket($this->b, $oRow, 9, false, 0);
		$this->assertInstanceOf(WP_REST_Response::class, $mBusy);
		$this->assertSame(0, $this->liveStats($sNano));

		$oLock->release();
		$aOne = $this->finishWorker($aFirst);
		$aTwo = $this->finishWorker($aSecond);
		$iOk = 0;
		$iBusy = 0;
		foreach(array($aOne, $aTwo) as $a)
		{
			if(empty($a['bResponse']))
			{
				$iOk++;
				continue;
			}
			$iBusy++;
		}
		$this->assertSame(1, $iOk);
		$this->assertSame(1, $iBusy);
		$this->assertSame(1, $this->liveStats($sNano));
	}

	public function test_checkin_timeout_writes_no_stats(): void
	{
		$iLine = 952;
		$sNano = $this->seedTicket($iLine, 9952, 7);
		$oLock = TPFW_Named_Lock::acquire($this->a, TPFW_Ticket_Line::lock_name($iLine), 5);
		$this->assertTrue($oLock->held());
		$oRow = $this->liveRow($sNano);
		$mIn = $this->fn->checkin_ticket($this->b, $oRow, 9, false, 0);
		$this->assertInstanceOf(WP_REST_Response::class, $mIn);
		$this->assertSame(202, $mIn->get_status());
		$this->assertSame(0, $this->liveStats($sNano));
		$oLock->release();
		$this->assertSame(0, $this->liveStats($sNano), 'timed-out check-in must not retry');
	}

	public function test_full_cancel_reread_failure_is_not_success(): void
	{
		$iLine = 961;
		$this->seedTicket($iLine, 9961, 7);
		$oItem = $this->lineItem($iLine, 1);
		$this->putOrder(9961, 'cancelled', $iLine, 0);
		$iSelects = 0;
		$oFail = new TPFW_Failing_Wpdb($this->b, static function($sSql) use (&$iSelects) {
			if(stripos($sSql, 'SELECT') !== false
				&& strpos($sSql, 'tpfw_tickets') !== false
				&& strpos($sSql, 'GET_LOCK') === false
				&& strpos($sSql, 'RELEASE_LOCK') === false)
			{
				$iSelects++;
				return $iSelects >= 2;
			}
			return false;
		});
		$a = TPFW_Ticket_Line::cancel($oFail, $oItem, 9961, null, 5);
		$this->assertStringContainsString('database write failed', $a['sMessage']);
		$this->assertSame(1, $this->liveOnLine($iLine));
	}

	public function test_full_cancel_ticket_update_failure_is_not_success(): void
	{
		$iLine = 962;
		$sNano = $this->seedTicket($iLine, 9962, 7);
		$oItem = $this->lineItem($iLine, 1);
		$this->putOrder(9962, 'cancelled', $iLine, 0);
		$oFail = new TPFW_Failing_Wpdb($this->b, static function($sSql) {
			return stripos($sSql, 'UPDATE') !== false && strpos($sSql, 'tpfw_tickets') !== false && strpos($sSql, 'tpfw_tickets_stats') === false;
		});
		$a = TPFW_Ticket_Line::cancel($oFail, $oItem, 9962, null, 5);
		$this->assertStringContainsString('database write failed', $a['sMessage']);
		$this->assertNull($this->deletedAt($sNano));
	}

	public function test_full_cancel_stats_update_failure_is_not_success(): void
	{
		$iLine = 963;
		$sNano = $this->seedTicket($iLine, 9963, 7);
		$oItem = $this->lineItem($iLine, 1);
		$this->putOrder(9963, 'cancelled', $iLine, 0);
		$oFail = new TPFW_Failing_Wpdb($this->b, static function($sSql) {
			return stripos($sSql, 'UPDATE') !== false && strpos($sSql, 'tpfw_tickets_stats') !== false;
		});
		$a = TPFW_Ticket_Line::cancel($oFail, $oItem, 9963, null, 5);
		$this->assertStringContainsString('database write failed', $a['sMessage']);
		$this->assertNotNull($this->deletedAt($sNano), 'ticket write may have landed; success must still be refused');
	}

	/**
	 * @param string $sNano
	 * @return array{0:resource,1:array}
	 */
	private function startPauseCheckin($sNano)
	{
		$aCmd = array(
			PHP_BINARY,
			TPFW_TEST_ROOT.'/bin/dashboard-nano-wait-worker.php',
			'checkin-pause-insert',
			$this->a->prefix,
			$sNano,
			'9',
			'5',
		);
		$aPipes = array();
		$mProc = proc_open($aCmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $aPipes, TPFW_PLUGIN_DIR);
		$this->assertIsResource($mProc);
		$aWorker = array($mProc, $aPipes);
		$this->aWorkers[] = $aWorker;
		return $aWorker;
	}

	/**
	 * @param string $sNano
	 * @return array{0:resource,1:array}
	 */
	private function startWaitCheckin($sNano)
	{
		$aCmd = array(
			PHP_BINARY,
			TPFW_TEST_ROOT.'/bin/dashboard-nano-wait-worker.php',
			'checkin',
			$this->a->prefix,
			$sNano,
			'9',
			'5',
		);
		$aPipes = array();
		$mProc = proc_open($aCmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $aPipes, TPFW_PLUGIN_DIR);
		$this->assertIsResource($mProc);
		$aWorker = array($mProc, $aPipes);
		$this->aWorkers[] = $aWorker;
		$this->waitForKv('wait-ready-checkin-'.$sNano);
		return $aWorker;
	}

	/**
	 * @param list<string> $aArgs
	 * @param int          $iLine
	 * @return array{0:resource,1:array}
	 */
	private function startLineWorker(array $aArgs, $iLine)
	{
		$aCmd = array_merge(array(PHP_BINARY, TPFW_TEST_ROOT.'/bin/ticket-line-wait-worker.php'), $aArgs);
		$aPipes = array();
		$mProc = proc_open($aCmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $aPipes, TPFW_PLUGIN_DIR);
		$this->assertIsResource($mProc);
		$aWorker = array($mProc, $aPipes);
		$this->aWorkers[] = $aWorker;
		$this->waitForKv('wait-ready-'.$iLine);
		return $aWorker;
	}

	/**
	 * @param array{0:resource,1:array} $aWorker
	 * @return array<string,mixed>
	 */
	private function finishWorker(array $aWorker)
	{
		$mProc  = $aWorker[0];
		$aPipes = $aWorker[1];
		stream_set_timeout($aPipes[1], 12);
		$sOut = trim((string)stream_get_contents($aPipes[1]));
		$sErr = stream_get_contents($aPipes[2]);
		fclose($aPipes[1]);
		fclose($aPipes[2]);
		$iCode = proc_close($mProc);
		$this->forgetWorker($aWorker);
		$this->assertSame(0, $iCode, $sErr."\n".$sOut);
		$a = json_decode($sOut, true);
		$this->assertIsArray($a, $sOut);
		return $a;
	}

	/**
	 * @param array{0:resource|false,1:array} $aWorker
	 * @return void
	 */
	private function forgetWorker(array $aWorker)
	{
		$this->aWorkers = array_values(array_filter(
			$this->aWorkers,
			static function($aKeep) use ($aWorker) {
				return $aKeep[0] !== $aWorker[0];
			}
		));
	}

	/**
	 * @param array{0:resource|false,1:array} $aWorker
	 * @return void
	 */
	private function stopWorker(array $aWorker)
	{
		$mProc = $aWorker[0];
		if(!is_resource($mProc))
		{
			return;
		}
		$aStatus = proc_get_status($mProc);
		if(!empty($aStatus['running']))
		{
			proc_terminate($mProc);
		}
		foreach($aWorker[1] as $mPipe)
		{
			if(is_resource($mPipe))
			{
				fclose($mPipe);
			}
		}
		proc_close($mProc);
		$this->forgetWorker($aWorker);
	}

	/**
	 * @param string $sKey
	 * @return void
	 */
	private function waitForKv($sKey)
	{
		$tEnd = microtime(true) + 10;
		do
		{
			if($this->kvReady($sKey))
			{
				return;
			}
		}
		while(microtime(true) < $tEnd);
		$this->fail('missing kv barrier '.$sKey);
	}

	/**
	 * @param string $sKey
	 * @return bool
	 */
	private function kvReady($sKey)
	{
		$m = $this->a->get_var($this->a->prepare(
			'SELECT v FROM %i WHERE k = %s',
			$this->a->prefix.'tpfw_kv',
			$sKey
		));
		return $m !== null && $m !== '';
	}

	/**
	 * @param string $sKey
	 * @return void
	 */
	private function publishKv($sKey)
	{
		$this->a->query($this->a->prepare(
			'INSERT INTO %i (k, v) VALUES (%s, %s) ON DUPLICATE KEY UPDATE v = %s',
			$this->a->prefix.'tpfw_kv',
			$sKey,
			'1',
			'1'
		));
	}

	/**
	 * @param int $iLine
	 * @param int $iOrder
	 * @param int $iUser
	 * @param int $iCount
	 * @return list<string>
	 */
	private function seedLine($iLine, $iOrder, $iUser, $iCount)
	{
		$a = array();
		for($i = 0; $i < $iCount; $i++)
		{
			$a[] = $this->seedTicket($iLine, $iOrder, $iUser);
		}
		return $a;
	}

	/**
	 * @param int    $iLine
	 * @param int    $iOrder
	 * @param int    $iUser
	 * @param string $sNano
	 * @return string
	 */
	private function seedNamedTicket($iLine, $iOrder, $iUser, $sNano)
	{
		$sTable = $this->a->prefix.'tpfw_tickets';
		$sNow   = gmdate('Y-m-d H:i:s');
		$sFrom  = gmdate('Y-m-d H:i:s', time() - 3600);
		$sTo    = gmdate('Y-m-d H:i:s', time() + 86400);
		$m = $this->a->query($this->a->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, valid_from, valid_to, max_uses, created, updated)
			VALUES (%s, %d, %d, %d, %d, %d, %s, %s, %d, %s, %s)',
			array($sTable, $sNano, 11, $iUser, $iOrder, $iLine, 86400, $sFrom, $sTo, 1, $sNow, $sNow)
		));
		$this->assertTrue(TPFW_Db_Write::inserted_row($m));
		return $sNano;
	}

	/**
	 * @param int $iLine
	 * @param int $iOrder
	 * @param int $iUser
	 * @return string
	 */
	private function seedTicket($iLine, $iOrder, $iUser)
	{
		return $this->seedNamedTicket($iLine, $iOrder, $iUser, 'n'.bin2hex(random_bytes(8)));
	}

	/**
	 * @param string $sNano
	 * @return object|null
	 */
	private function liveRow($sNano)
	{
		return $this->a->get_row($this->a->prepare(
			'SELECT * FROM %i WHERE nano_id = %s AND deleted IS NULL',
			$this->a->prefix.'tpfw_tickets',
			$sNano
		));
	}

	/**
	 * @param string $sNano
	 * @return object
	 */
	private function staleRow($sNano)
	{
		return (object)array(
			'nano_id'    => $sNano,
			'product_id' => 11,
			'max_uses'   => 1,
			'valid_from' => gmdate('Y-m-d H:i:s', time() - 3600),
			'valid_to'   => gmdate('Y-m-d H:i:s', time() + 86400),
		);
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
	 * @param string $sNano
	 * @return int
	 */
	private function holder($sNano)
	{
		return (int)$this->a->get_var($this->a->prepare(
			'SELECT user_id FROM %i WHERE nano_id = %s',
			$this->a->prefix.'tpfw_tickets',
			$sNano
		));
	}

	/**
	 * @param string $sNano
	 * @return int
	 */
	private function liveStats($sNano)
	{
		return (int)$this->a->get_var($this->a->prepare(
			'SELECT COUNT(*) FROM %i WHERE nano_id_fk = %s AND deleted IS NULL',
			$this->a->prefix.'tpfw_tickets_stats',
			$sNano
		));
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
