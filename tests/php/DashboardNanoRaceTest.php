<?php
use PHPUnit\Framework\TestCase;

require_once TPFW_TEST_ROOT.'/lib/TicketFunctionsHarness.php';

class TPFW_Test_Nano_Order extends TPFW_Test_Refund_Order
{
	public function get_items()
	{
		return array();
	}

	public function add_order_note($sNote)
	{
		unset($sNote);
	}

	public function save()
	{
		return $this;
	}
}

/**
 * Locked dashboard nano cancel/reset/transfer and ticket check-in on the line lock.
 *
 * Sequential issue-after-dashboard-cancel is covered by ManualCancelReissueTest
 * and ManualCancelStateMatrixTest.
 */
class DashboardNanoRaceTest extends TestCase
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
		$this->a = new TPFW_Test_Wpdb($mysqliA, 'tpfwnano_');
		$this->b = new TPFW_Test_Wpdb($mysqliB, 'tpfwnano_');
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

	public function test_ticket_dashboard_nano_ops_use_locked_ticket_line(): void
	{
		$sDash   = file_get_contents(TPFW_PLUGIN_DIR.'inc/dashboard/class--dashboard.php');
		$sTicket = file_get_contents(TPFW_PLUGIN_DIR.'inc/ticket-dashboard/class--ticket-dashboard.php');
		$sFn     = file_get_contents(TPFW_PLUGIN_DIR.'inc/functions/class--functions.php');
		$sLine   = file_get_contents(TPFW_PLUGIN_DIR.'inc/support/class--ticket-line.php');
		$sSlot   = file_get_contents(TPFW_PLUGIN_DIR.'inc/timeslot-ticket-dashboard/class--timeslot-ticket-dashboard.php');

		$iCancelTicket = strpos($sFn, 'function cancel_ticket(');
		$iResetTicket  = strpos($sFn, 'function reset_ticket(');
		$iCancelRow    = strpos($sFn, 'function cancel_ticket_row');
		$iCheckin      = strpos($sFn, 'function checkin_ticket_under_line_lock');
		$this->assertNotFalse($iCancelTicket);
		$this->assertNotFalse($iResetTicket);
		$sCancelTicket = substr($sFn, $iCancelTicket, 900);
		$sResetTicket  = substr($sFn, $iResetTicket, 900);
		$sCancelRow    = substr($sFn, $iCancelRow, 900);
		$sCheckin      = substr($sFn, $iCheckin, 1800);

		$this->assertNotFalse(strpos($sCancelTicket, 'TPFW_Ticket_Line::cancel_nano'));
		$this->assertNotFalse(strpos($sResetTicket, 'TPFW_Ticket_Line::reset_nano'));
		$this->assertFalse(strpos($sCancelRow, 'TPFW_Ticket_Line'));
		$iCheckinEntry = strpos($sFn, 'function checkin(');
		$sCheckinEntry = substr($sFn, $iCheckinEntry, 700);
		$this->assertNotFalse(strpos($sCheckin, 'deleted IS NULL'));
		$this->assertNotFalse(strpos($sCheckin, 'TPFW_Ticket_Line::with_lock'));
		$this->assertNotFalse(strpos($sCheckinEntry, "\$sType === 'ticket'"));
		$this->assertNotFalse(strpos($sTicket, 'TPFW_Ticket_Line::transfer_nano'));
		$this->assertFalse(strpos($sSlot, 'TPFW_Ticket_Line'));
		$this->assertNotFalse(strpos($sDash, 'function transfer_live_row'));
		$this->assertFalse(strpos(substr($sDash, strpos($sDash, 'function transfer_live_row'), 900), 'TPFW_Ticket_Line'));
		$this->assertNotFalse(strpos($sLine, 'function cancel_nano_held'));
		$this->assertFalse(strpos($sLine, 'with_nano_checkin'));
		$this->assertFalse(strpos($sLine, "'tpfw_checkin_"));
		$this->assertFalse(strpos($sLine, 'checkin_lock_name'));
		$this->assertNotFalse(strpos($sFn, 'TPFW_Ticket_Line::with_lock'));
		$this->assertNotFalse(strpos($sFn, 'checkin_ticket_under_line_lock'));
	}

	public function test_fresh_lookup_after_cancel_finds_no_live_row(): void
	{
		$sNano = $this->seedTicket(801, 9801, 7);
		$GLOBALS['wpdb'] = $this->b;
		$aCancel = $this->fn->cancel_ticket($sNano);
		$this->assertTrue($aCancel['bSuccess']);

		$oRow = $this->a->get_row($this->a->prepare(
			'SELECT * FROM %i WHERE nano_id = %s AND deleted IS NULL',
			$this->a->prefix.'tpfw_tickets',
			$sNano
		));
		$this->assertNull($oRow, 'a new REST/AJAX lookup after cancel must not see the row');
		$this->assertNotNull($this->deletedAt($sNano));
	}

	public function test_checkin_then_cancel_soft_deletes_the_stats_row(): void
	{
		$sNano = $this->seedTicket(802, 9802, 7);
		$oRow  = $this->liveRow($sNano);
		$mIn   = $this->fn->checkin_ticket($this->a, $oRow, 9, false);
		$this->assertIsArray($mIn);
		$this->assertSame(1, $this->liveStats($sNano));

		$GLOBALS['wpdb'] = $this->b;
		$this->assertTrue($this->fn->cancel_ticket($sNano)['bSuccess']);
		$this->assertNotNull($this->deletedAt($sNano));
		$this->assertSame(0, $this->liveStats($sNano), 'cancel after a completed check-in must retire that history');
		$this->assertSame(1, $this->allStats($sNano));
	}

	public function test_reconcile_does_not_undelete_a_dashboard_cancelled_ticket(): void
	{
		$iLine  = 803;
		$iOrder = 9803;
		$sNano  = $this->seedTicket($iLine, $iOrder, 7);
		$GLOBALS['wpdb'] = $this->b;
		$this->assertTrue($this->fn->cancel_ticket($sNano)['bSuccess']);

		$oItem = $this->lineItem($iLine, 1);
		$this->putOrder($iOrder, 'completed', $iLine, 0);
		TPFW_Ticket_Line::reconcile($this->a, $oItem, $iOrder);
		$this->assertNotNull($this->deletedAt($sNano), 'reconcile/shrink must not restore a dashboard cancel');
		$this->assertSame(0, $this->liveOnLine($iLine));
	}

	public function test_stale_checkin_is_refused_after_dashboard_cancel(): void
	{
		$sNano = $this->seedTicket(811, 9811, 7);
		$oRow  = $this->liveRow($sNano);
		$this->assertNotNull($oRow);

		$GLOBALS['wpdb'] = $this->b;
		$this->assertTrue($this->fn->cancel_ticket($sNano)['bSuccess']);
		$this->assertNotNull($this->deletedAt($sNano));

		$mIn = $this->fn->checkin_ticket($this->a, $oRow, 9, false);
		$this->assertInstanceOf(WP_REST_Response::class, $mIn, 'stale check-in after dashboard cancel must refuse');
		$this->assertSame(0, $this->liveStats($sNano), 'a refused check-in must not leave a live stats row');
	}

	public function test_waiting_checkin_and_cancel_leave_no_live_stats(): void
	{
		$sNano = $this->seedTicket(812, 9812, 7);
		$oLock = TPFW_Named_Lock::acquire($this->a, TPFW_Ticket_Line::lock_name(812), 5);
		$this->assertTrue($oLock->held());

		$aCheckin = $this->startWaitWorker(array('checkin', $this->a->prefix, $sNano, '9', '5'), $sNano);
		$aCancel  = $this->startWaitWorker(array('cancel', $this->a->prefix, $sNano, '7', '5'), $sNano);
		$this->assertNull($this->deletedAt($sNano));
		$this->assertSame(0, $this->liveStats($sNano));

		$oLock->release();
		$aIn = $this->finishWaitWorker($aCheckin);
		$aCn = $this->finishWaitWorker($aCancel);
		unset($aIn, $aCn);
		$this->assertNotNull($this->deletedAt($sNano));
		$this->assertSame(0, $this->liveStats($sNano), 'serialised cancel and check-in must not leave live stats on a cancelled ticket');
	}

	public function test_reset_timeout_does_not_write_while_checkin_lock_held(): void
	{
		$sNano = $this->seedTicket(821, 9821, 7);
		$oRow  = $this->liveRow($sNano);
		$mIn   = $this->fn->checkin_ticket($this->a, $oRow, 9, false);
		$this->assertIsArray($mIn);
		$this->assertSame(1, $this->liveStats($sNano));

		$oLock = TPFW_Named_Lock::acquire($this->a, TPFW_Ticket_Line::lock_name(821), 5);
		$this->assertTrue($oLock->held());

		$GLOBALS['wpdb'] = $this->b;
		$aReset = $this->fn->reset_ticket($sNano, 0);
		$this->assertFalse($aReset['bSuccess']);
		$this->assertStringContainsString('another request is in progress', $aReset['sMessage']);
		$this->assertSame(1, $this->liveStats($sNano));
		$this->assertNull($this->deletedAt($sNano));

		$oLock->release();
		$this->assertSame(1, $this->liveStats($sNano), 'timed-out reset must not retry');
	}

	public function test_waiting_reset_clears_stats_after_checkin_releases(): void
	{
		$sNano = $this->seedTicket(822, 9822, 7);
		$oRow  = $this->liveRow($sNano);
		$this->assertIsArray($this->fn->checkin_ticket($this->a, $oRow, 9, false));
		$this->assertSame(1, $this->liveStats($sNano));

		$oLock = TPFW_Named_Lock::acquire($this->a, TPFW_Ticket_Line::lock_name(822), 5);
		$this->assertTrue($oLock->held());

		$aWorker = $this->startWaitWorker(array('reset', $this->a->prefix, $sNano, '7', '5'), $sNano);
		$this->assertSame(1, $this->liveStats($sNano), 'waiting reset must not write before release');

		$oLock->release();
		$aReset = $this->finishWaitWorker($aWorker);
		$this->assertTrue($aReset['bSuccess']);
		$this->assertNull($this->deletedAt($sNano));
		$this->assertSame(0, $this->liveStats($sNano));
		$this->assertSame(0, $this->allStats($sNano));
	}

	public function test_cancel_timeout_does_not_write_while_line_lock_held(): void
	{
		$iLine = 832;
		$sNano = $this->seedTicket($iLine, 9832, 7);
		$oLock = TPFW_Named_Lock::acquire($this->a, TPFW_Issue_Lock::name('ticket', $iLine), 5);
		$this->assertTrue($oLock->held());

		$GLOBALS['wpdb'] = $this->b;
		$aCancel = $this->fn->cancel_ticket($sNano, 0);
		$this->assertFalse($aCancel['bSuccess']);
		$this->assertStringContainsString('another request is in progress', $aCancel['sMessage']);
		$this->assertNull($this->deletedAt($sNano));

		$oLock->release();
	}

	public function test_transfer_refuses_after_cancel_and_leaves_holder_unchanged(): void
	{
		$sNano = $this->seedTicket(841, 9841, 7);
		$oRow  = $this->liveRow($sNano);
		$this->assertSame(7, (int)$oRow->user_id);

		$GLOBALS['wpdb'] = $this->b;
		$this->assertTrue($this->fn->cancel_ticket($sNano)['bSuccess']);
		$this->assertNotNull($this->deletedAt($sNano));

		$aXfer = TPFW_Ticket_Line::transfer_nano($this->a, $sNano, 42);
		$this->assertFalse($aXfer['bSuccess']);
		$this->assertSame(7, $this->holder($sNano));
		$this->assertNotNull($this->deletedAt($sNano));
	}

	public function test_normal_transfer_rewrites_holder_once(): void
	{
		$sNano = $this->seedTicket(842, 9842, 7);
		$aXfer = TPFW_Ticket_Line::transfer_nano($this->b, $sNano, 42);
		$this->assertTrue($aXfer['bSuccess']);
		$this->assertSame(7, (int)$aXfer['iOldUserID']);
		$this->assertSame(42, $this->holder($sNano));
		$this->assertNull($this->deletedAt($sNano));
	}

	public function test_waiting_transfer_refuses_when_cancel_holds_the_line_lock(): void
	{
		$iLine = 844;
		$sNano = $this->seedTicket($iLine, 9844, 7);
		$oLock = TPFW_Named_Lock::acquire($this->a, TPFW_Issue_Lock::name('ticket', $iLine), 5);
		$this->assertTrue($oLock->held());

		$aWorker = $this->startWaitWorker(array('transfer', $this->a->prefix, $sNano, '7', '5', '42'), $sNano);
		$this->assertSame(7, $this->holder($sNano));

		$aCancel = TPFW_Ticket_Line::cancel_nano_held($this->a, $sNano, $iLine);
		$this->assertTrue($aCancel['bSuccess']);
		$oLock->release();

		$aXfer = $this->finishWaitWorker($aWorker);
		$this->assertFalse($aXfer['bSuccess']);
		$this->assertSame(7, $this->holder($sNano));
		$this->assertNotNull($this->deletedAt($sNano));
	}

	public function test_transfer_timeout_does_not_write_while_line_lock_held(): void
	{
		$iLine = 843;
		$sNano = $this->seedTicket($iLine, 9843, 7);
		$oLock = TPFW_Named_Lock::acquire($this->a, TPFW_Issue_Lock::name('ticket', $iLine), 5);
		$this->assertTrue($oLock->held());

		$aXfer = TPFW_Ticket_Line::transfer_nano($this->b, $sNano, 42, 0);
		$this->assertFalse($aXfer['bSuccess']);
		$this->assertStringContainsString('another request is in progress', $aXfer['sMessage']);
		$this->assertSame(7, $this->holder($sNano));

		$oLock->release();
	}

	public function test_other_order_line_is_not_blocked(): void
	{
		$sHeld = $this->seedTicket(851, 9851, 7);
		$sFree = $this->seedTicket(852, 9852, 7);
		$oLock = TPFW_Named_Lock::acquire($this->a, TPFW_Issue_Lock::name('ticket', 851), 5);
		$this->assertTrue($oLock->held());

		$GLOBALS['wpdb'] = $this->b;
		$aCancel = $this->fn->cancel_ticket($sFree, 0);
		$this->assertTrue($aCancel['bSuccess']);
		$this->assertNotNull($this->deletedAt($sFree));
		$this->assertNull($this->deletedAt($sHeld));

		$oLock->release();
	}

	public function test_other_nano_on_other_line_is_not_blocked_by_line_lock(): void
	{
		$sHeld = $this->seedTicket(861, 9861, 7);
		$sFree = $this->seedTicket(862, 9862, 7);
		$oLock = TPFW_Named_Lock::acquire($this->a, TPFW_Ticket_Line::lock_name(861), 5);
		$this->assertTrue($oLock->held());

		$GLOBALS['wpdb'] = $this->b;
		$aReset = $this->fn->reset_ticket($sFree, 0);
		$this->assertTrue($aReset['bSuccess']);
		$this->assertNull($this->deletedAt($sFree));

		$oLock->release();
	}

	public function test_cancel_reread_failure_is_not_success(): void
	{
		$sNano = $this->seedTicket(870, 9870, 7);
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
		$a = TPFW_Ticket_Line::cancel_nano($oFail, $sNano, 5);
		$this->assertFalse($a['bSuccess']);
		$this->assertNull($this->deletedAt($sNano));
	}

	public function test_cancel_get_lock_failure_is_not_success(): void
	{
		$sNano = $this->seedTicket(875, 9875, 7);
		$oFail = new TPFW_Failing_Wpdb($this->b, static function($sSql) {
			return str_contains($sSql, 'GET_LOCK');
		});
		$a = TPFW_Ticket_Line::cancel_nano($oFail, $sNano, 5);
		$this->assertFalse($a['bSuccess']);
		$this->assertStringContainsString('another request is in progress', $a['sMessage']);
		$this->assertNull($this->deletedAt($sNano));
	}

	public function test_cancel_ticket_update_failure_is_not_success(): void
	{
		$sNano = $this->seedTicket(871, 9871, 7);
		$oFail = new TPFW_Failing_Wpdb($this->b, static function($sSql) {
			return stripos($sSql, 'UPDATE') !== false && strpos($sSql, 'tpfw_tickets') !== false && strpos($sSql, 'tpfw_tickets_stats') === false;
		});
		$a = TPFW_Ticket_Line::cancel_nano($oFail, $sNano, 5);
		$this->assertFalse($a['bSuccess']);
		$this->assertNull($this->deletedAt($sNano));
	}

	public function test_cancel_stats_update_failure_is_not_success(): void
	{
		$sNano = $this->seedTicket(872, 9872, 7);
		$oFail = new TPFW_Failing_Wpdb($this->b, static function($sSql) {
			return stripos($sSql, 'UPDATE') !== false && strpos($sSql, 'tpfw_tickets_stats') !== false;
		});
		$a = TPFW_Ticket_Line::cancel_nano($oFail, $sNano, 5);
		$this->assertFalse($a['bSuccess']);
	}

	public function test_reset_stats_delete_failure_is_not_success(): void
	{
		$sNano = $this->seedTicket(873, 9873, 7);
		$oFail = new TPFW_Failing_Wpdb($this->b, static function($sSql) {
			return stripos($sSql, 'DELETE') !== false && strpos($sSql, 'tpfw_tickets_stats') !== false;
		});
		$a = TPFW_Ticket_Line::reset_nano($oFail, $sNano, 5);
		$this->assertFalse($a['bSuccess']);
	}

	public function test_transfer_update_failure_is_not_success(): void
	{
		$sNano = $this->seedTicket(874, 9874, 7);
		$oFail = new TPFW_Failing_Wpdb($this->b, static function($sSql) {
			return stripos($sSql, 'UPDATE') !== false && strpos($sSql, 'user_id') !== false;
		});
		$a = TPFW_Ticket_Line::transfer_nano($oFail, $sNano, 42, 5);
		$this->assertFalse($a['bSuccess']);
		$this->assertSame(7, $this->holder($sNano));
	}

	public function test_transfer_update_zero_is_not_success(): void
	{
		$sNano = $this->seedTicket(876, 9876, 7);
		$oZero = new TPFW_Zero_Write_Wpdb($this->b, static function($sSql) {
			return stripos($sSql, 'UPDATE') !== false && strpos($sSql, 'user_id') !== false;
		});
		$a = TPFW_Ticket_Line::transfer_nano($oZero, $sNano, 42, 5);
		$this->assertFalse($a['bSuccess']);
		$this->assertSame(7, $this->holder($sNano));
	}

	public function test_checkin_probe_failure_writes_nothing(): void
	{
		$sNano = $this->seedTicket(881, 9881, 7);
		$oRow = (object)array(
			'nano_id'    => $sNano,
			'product_id' => 11,
			'max_uses'   => 1,
			'valid_from' => gmdate('Y-m-d H:i:s', time() - 3600),
			'valid_to'   => gmdate('Y-m-d H:i:s', time() + 86400),
		);
		$oFail = new TPFW_Failing_Wpdb($this->b, static function($sSql) {
			return stripos($sSql, 'SELECT') !== false
				&& strpos($sSql, 'order_line_id') !== false
				&& strpos($sSql, 'GET_LOCK') === false;
		});
		$mIn = $this->fn->checkin_ticket($oFail, $oRow, 9, false);
		$this->assertInstanceOf(WP_REST_Response::class, $mIn);
		$this->assertSame(401, $mIn->get_status());
		$this->assertSame(0, $this->liveStats($sNano));
	}

	public function test_checkin_invalid_line_writes_nothing(): void
	{
		$sNano = $this->seedTicket(880, 9880, 7);
		$this->b->query($this->b->prepare(
			'UPDATE %i SET order_line_id = 0 WHERE nano_id = %s',
			$this->b->prefix.'tpfw_tickets',
			$sNano
		));
		$oRow = (object)array(
			'nano_id'    => $sNano,
			'product_id' => 11,
			'max_uses'   => 1,
			'valid_from' => gmdate('Y-m-d H:i:s', time() - 3600),
			'valid_to'   => gmdate('Y-m-d H:i:s', time() + 86400),
		);
		$mIn = $this->fn->checkin_ticket($this->b, $oRow, 9, false);
		$this->assertInstanceOf(WP_REST_Response::class, $mIn);
		$this->assertSame(202, $mIn->get_status());
		$this->assertSame(0, $this->liveStats($sNano));
	}

	public function test_checkin_lock_timeout_writes_nothing(): void
	{
		$sNano = $this->seedTicket(882, 9882, 7);
		$oLock = TPFW_Named_Lock::acquire($this->a, TPFW_Ticket_Line::lock_name(882), 5);
		$this->assertTrue($oLock->held());
		$oRow = $this->liveRow($sNano);
		$mIn = $this->fn->checkin_ticket($this->b, $oRow, 9, false, 0);
		$this->assertInstanceOf(WP_REST_Response::class, $mIn);
		$this->assertSame(202, $mIn->get_status());
		$this->assertSame(0, $this->liveStats($sNano));
		$oLock->release();
	}

	public function test_checkin_reread_failure_writes_nothing(): void
	{
		$sNano = $this->seedTicket(883, 9883, 7);
		$oRow = $this->liveRow($sNano);
		$oFail = new TPFW_Failing_Wpdb($this->b, static function($sSql) {
			return stripos($sSql, 'SELECT') !== false
				&& strpos($sSql, 'tpfw_tickets') !== false
				&& strpos($sSql, 'deleted IS NULL') !== false
				&& strpos($sSql, 'GET_LOCK') === false;
		});
		$mIn = $this->fn->checkin_ticket($oFail, $oRow, 9, false);
		$this->assertInstanceOf(WP_REST_Response::class, $mIn);
		$this->assertSame(401, $mIn->get_status());
		$this->assertSame(0, $this->liveStats($sNano));
	}

	public function test_checkin_wrong_line_is_refused(): void
	{
		$sNano = $this->seedTicket(884, 9884, 7);
		$oRow = $this->liveRow($sNano);
		$oRow->order_line_id = 999884;
		$mIn = $this->fn->checkin_ticket($this->b, $oRow, 9, false);
		$this->assertInstanceOf(WP_REST_Response::class, $mIn);
		$this->assertSame(202, $mIn->get_status());
		$this->assertSame(0, $this->liveStats($sNano));
		$this->assertNull($this->deletedAt($sNano));
	}

	public function test_checkin_deleted_ticket_writes_nothing(): void
	{
		$sNano = $this->seedTicket(885, 9885, 7);
		$oRow = $this->liveRow($sNano);
		$GLOBALS['wpdb'] = $this->b;
		$this->assertTrue($this->fn->cancel_ticket($sNano)['bSuccess']);
		$mIn = $this->fn->checkin_ticket($this->a, $oRow, 9, false);
		$this->assertInstanceOf(WP_REST_Response::class, $mIn);
		$this->assertSame(202, $mIn->get_status());
		$this->assertSame(0, $this->liveStats($sNano));
	}

	public function test_checkin_stats_insert_failure_writes_nothing(): void
	{
		$sNano = $this->seedTicket(886, 9886, 7);
		$oRow = $this->liveRow($sNano);
		$oFail = new TPFW_Failing_Wpdb($this->b, static function($sSql) {
			return stripos($sSql, 'INSERT') !== false && strpos($sSql, 'tpfw_tickets_stats') !== false;
		});
		$mIn = $this->fn->checkin_ticket($oFail, $oRow, 9, false);
		$this->assertInstanceOf(WP_REST_Response::class, $mIn);
		$this->assertSame(401, $mIn->get_status());
		$this->assertSame(0, $this->liveStats($sNano));
	}

	public function test_two_checkins_on_same_nano_keep_max_uses(): void
	{
		$sNano = $this->seedTicket(887, 9887, 7);
		$aFirst  = $this->startWaitWorker(array('checkin', $this->a->prefix, $sNano, '9', '5'), $sNano);
		$aSecond = $this->startWaitWorker(array('checkin', $this->a->prefix, $sNano, '8', '5'), $sNano);
		$aOne = $this->finishWaitWorker($aFirst);
		$aTwo = $this->finishWaitWorker($aSecond);
		$iOk = 0;
		foreach(array($aOne, $aTwo) as $a)
		{
			if(empty($a['bResponse']))
			{
				$iOk++;
			}
		}
		$this->assertSame(1, $iOk);
		$this->assertSame(1, $this->liveStats($sNano));
	}

	/**
	 * @param list<string> $aArgs
	 * @param string       $sNano
	 * @return array{0:resource,1:array}
	 */
	private function startWaitWorker(array $aArgs, $sNano)
	{
		$sOp    = (string)$aArgs[0];
		$aCmd   = array_merge(array(PHP_BINARY, TPFW_TEST_ROOT.'/bin/dashboard-nano-wait-worker.php'), $aArgs);
		$aPipes = array();
		$mProc  = proc_open($aCmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $aPipes, TPFW_PLUGIN_DIR);
		$this->assertIsResource($mProc);
		$aWorker = array($mProc, $aPipes);
		$this->aWorkers[] = $aWorker;
		$this->waitForReadyKv($sOp.'-'.$sNano);
		return $aWorker;
	}

	/**
	 * @param string $sKey
	 * @return void
	 */
	private function waitForReadyKv($sKey)
	{
		$tEnd = microtime(true) + 10;
		do
		{
			$m = $this->a->get_var($this->a->prepare(
				'SELECT v FROM %i WHERE k = %s',
				$this->a->prefix.'tpfw_kv',
				'wait-ready-'.$sKey
			));
			if($m !== null && $m !== '')
			{
				return;
			}
		}
		while(microtime(true) < $tEnd);
		$this->fail('worker did not publish wait-ready barrier for '.$sKey);
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
	 * @param string $sNano
	 * @return int
	 */
	private function allStats($sNano)
	{
		return (int)$this->a->get_var($this->a->prepare(
			'SELECT COUNT(*) FROM %i WHERE nano_id_fk = %s',
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
		$oOrder = new TPFW_Test_Nano_Order();
		$oOrder->status = $sStatus;
		$oOrder->refunded = array((int)$iLine => $iRefunded);
		$GLOBALS['tpfw_test_orders'][(int)$iOrder] = $oOrder;
	}
}
