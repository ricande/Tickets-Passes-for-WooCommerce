<?php
use PHPUnit\Framework\TestCase;

require_once TPFW_TEST_ROOT.'/lib/TicketFunctionsHarness.php';

class TPFW_Test_Manual_Cancel_Order extends TPFW_Test_Refund_Order
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
 * Persistent dashboard ticket cancel: isolated cases, one order line each.
 */
class ManualCancelStateMatrixTest extends TestCase
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
		$this->wpdb = new TPFW_Test_Wpdb($mysqli, 'tpfwmcst_');
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

	public function test_01_qty_one_manual_cancel_survives_later_issue(): void
	{
		$iLine = 2001;
		$aNanos = $this->issueLine($iLine, 12001, 1);
		$this->assertCount(1, $aNanos);
		$this->assertSame(1, $this->liveOnLine($iLine));

		$sNano = $aNanos[0];
		$this->assertTrue(TPFW_Ticket_Line::cancel_nano($this->wpdb, $sNano)['bSuccess']);
		$this->assertSame(0, $this->liveOnLine($iLine));

		$this->reissue($iLine, 12001, 1);
		$this->assertSame(0, $this->liveOnLine($iLine), 'later issue must not undelete a dashboard-cancelled ticket');
		$this->assertSame(array($sNano), $this->nanosOnLine($iLine));
		$this->assertNotNull($this->deletedAt($sNano));
	}

	public function test_02_reset_after_manual_cancel_restores_same_nano_when_target_is_one(): void
	{
		$iLine = 2002;
		$sNano = $this->issueLine($iLine, 12002, 1)[0];
		$oRow  = $this->liveRow($sNano);
		$this->assertIsArray($this->fn->checkin_ticket($this->wpdb, $oRow, 9, false));
		$this->assertSame(1, $this->liveStats($sNano));

		$this->assertTrue(TPFW_Ticket_Line::cancel_nano($this->wpdb, $sNano)['bSuccess']);
		$aReset = TPFW_Ticket_Line::reset_nano($this->wpdb, $sNano);
		$this->assertTrue($aReset['bSuccess']);
		$this->assertNull($this->deletedAt($sNano), 'reset within target must make the same nano live');
		$this->assertFalse($this->isManual($sNano), 'reset must clear the manual block');
		$this->assertSame(1, $this->liveOnLine($iLine));
		$this->assertSame(0, $this->liveStats($sNano));
		$this->assertSame(0, $this->allStats($sNano));
		$this->assertSame(array($sNano), $this->nanosOnLine($iLine));
	}

	public function test_03_qty_three_middle_manual_cancel_is_not_replaced(): void
	{
		$iLine = 2003;
		$aNanos = $this->issueLine($iLine, 12003, 3);
		$this->assertCount(3, $aNanos);
		$this->assertTrue(TPFW_Ticket_Line::cancel_nano($this->wpdb, $aNanos[1])['bSuccess']);
		$this->assertSame(2, $this->liveOnLine($iLine));

		$this->reissue($iLine, 12003, 3);
		$this->assertSame(2, $this->liveOnLine($iLine), 'duplicate issue must not undelete the blocked middle slot');
		$this->assertNotNull($this->deletedAt($aNanos[1]));
		$this->assertSame($aNanos, $this->nanosOnLine($iLine));
		$this->assertSame(3, $this->rowCountOnLine($iLine));
	}

	public function test_04_repeated_issue_does_not_mint_or_drop_the_block(): void
	{
		$iLine = 2004;
		$aNanos = $this->issueLine($iLine, 12004, 3);
		$this->assertTrue(TPFW_Ticket_Line::cancel_nano($this->wpdb, $aNanos[1])['bSuccess']);
		$this->reissue($iLine, 12004, 3);
		$this->reissue($iLine, 12004, 3);
		$this->reissue($iLine, 12004, 3);
		$this->assertSame($aNanos, $this->nanosOnLine($iLine));
		$this->assertSame(3, $this->rowCountOnLine($iLine));
		$this->assertSame(2, $this->liveOnLine($iLine));
		$this->assertNotNull($this->deletedAt($aNanos[1]));
	}

	public function test_05_refund_to_two_with_first_slot_blocked_leaves_one_live(): void
	{
		$iLine = 2005;
		$aNanos = $this->issueLine($iLine, 12005, 3);
		$this->assertTrue(TPFW_Ticket_Line::cancel_nano($this->wpdb, $aNanos[0])['bSuccess']);
		$this->refundTo($iLine, 12005, 3, 1);
		$this->assertSame(1, $this->liveOnLine($iLine), 'a blocked retained slot must not count as a live seat');
		$this->assertNotNull($this->deletedAt($aNanos[0]));
		$this->assertTrue($this->isManual($aNanos[0]));
		$this->assertNotNull($this->deletedAt($aNanos[2]), 'slot 3 is surplus at target 2');
		$this->assertNull($this->deletedAt($aNanos[1]));
		$this->assertSame(3, $this->rowCountOnLine($iLine));
	}

	public function test_06_blocked_surplus_slot_stays_blocked_at_target_two(): void
	{
		$iLine = 2006;
		$aNanos = $this->issueLine($iLine, 12006, 3);
		$this->assertTrue(TPFW_Ticket_Line::cancel_nano($this->wpdb, $aNanos[2])['bSuccess']);
		$this->refundTo($iLine, 12006, 3, 1);
		$this->assertNull($this->deletedAt($aNanos[0]));
		$this->assertNull($this->deletedAt($aNanos[1]));
		$this->assertNotNull($this->deletedAt($aNanos[2]));
		$this->assertTrue($this->isManual($aNanos[2]));
		$this->assertSame(2, $this->liveOnLine($iLine));
	}

	public function test_07_reversing_refund_does_not_undelete_blocked_slot_or_mint(): void
	{
		$iLine = 2007;
		$aNanos = $this->issueLine($iLine, 12007, 3);
		$this->assertTrue(TPFW_Ticket_Line::cancel_nano($this->wpdb, $aNanos[2])['bSuccess']);
		$this->refundTo($iLine, 12007, 3, 1);
		$this->reissue($iLine, 12007, 3, 0);
		$this->assertSame(2, $this->liveOnLine($iLine), 'cleared refund must not revive a still-blocked slot');
		$this->assertNotNull($this->deletedAt($aNanos[2]));
		$this->assertSame($aNanos, $this->nanosOnLine($iLine));
	}

	public function test_08_reset_outside_target_clears_block_but_stays_deleted(): void
	{
		$iLine = 2008;
		$aNanos = $this->issueLine($iLine, 12008, 3);
		$this->assertTrue(TPFW_Ticket_Line::cancel_nano($this->wpdb, $aNanos[2])['bSuccess']);
		$this->refundTo($iLine, 12008, 3, 1);
		$aReset = TPFW_Ticket_Line::reset_nano($this->wpdb, $aNanos[2]);
		$this->assertTrue($aReset['bSuccess']);
		$this->assertFalse($this->isManual($aNanos[2]), 'reset must clear the manual block even when the slot stays surplus');
		$this->assertNotNull($this->deletedAt($aNanos[2]), 'reset must not make a surplus slot live');
		$this->assertSame(2, $this->liveOnLine($iLine));
	}

	public function test_09_unblocked_surplus_slot_returns_when_target_grows(): void
	{
		$iLine = 2009;
		$aNanos = $this->issueLine($iLine, 12009, 3);
		$this->assertTrue(TPFW_Ticket_Line::cancel_nano($this->wpdb, $aNanos[2])['bSuccess']);
		$this->refundTo($iLine, 12009, 3, 1);
		$this->assertTrue(TPFW_Ticket_Line::reset_nano($this->wpdb, $aNanos[2])['bSuccess']);
		$this->assertNotNull($this->deletedAt($aNanos[2]), 'reset at target 2 must leave slot 3 deleted before target grows');
		$this->reissue($iLine, 12009, 3, 0);
		$this->assertNull($this->deletedAt($aNanos[2]), 'an unblocked slot inside the new target must become live');
		$this->assertSame(3, $this->liveOnLine($iLine));
		$this->assertSame($aNanos, $this->nanosOnLine($iLine));
	}

	public function test_10_full_order_cancel_then_reissue_restores_only_unblocked_slots(): void
	{
		$iLine = 2010;
		$aNanos = $this->issueLine($iLine, 12010, 3);
		$this->assertTrue(TPFW_Ticket_Line::cancel_nano($this->wpdb, $aNanos[1])['bSuccess']);
		$this->putOrder(12010, 'cancelled', $iLine, 0);
		$aCancel = TPFW_Ticket_Line::cancel($this->wpdb, $this->lineItem($iLine, 3), 12010);
		$this->assertStringContainsString('cancelled', $aCancel['sMessage']);
		$this->assertSame(0, $this->liveOnLine($iLine));

		$this->reissue($iLine, 12010, 3, 0, 'completed');
		$this->assertSame(2, $this->liveOnLine($iLine), 'reissue after full cancel must not revive the blocked slot');
		$this->assertNull($this->deletedAt($aNanos[0]));
		$this->assertNotNull($this->deletedAt($aNanos[1]));
		$this->assertNull($this->deletedAt($aNanos[2]));
		$this->assertSame($aNanos, $this->nanosOnLine($iLine));
	}

	public function test_11_transfer_of_manually_cancelled_ticket_is_refused(): void
	{
		$iLine = 2011;
		$sNano = $this->issueLine($iLine, 12011, 1)[0];
		$this->assertTrue(TPFW_Ticket_Line::cancel_nano($this->wpdb, $sNano)['bSuccess']);
		$aXfer = TPFW_Ticket_Line::transfer_nano($this->wpdb, $sNano, 42);
		$this->assertFalse($aXfer['bSuccess']);
		$this->assertSame(7, $this->holder($sNano));
		$this->assertNotNull($this->deletedAt($sNano));
	}

	public function test_12_transfer_after_reset_is_allowed_only_when_reset_made_the_row_live(): void
	{
		$iLine = 2012;
		$sNano = $this->issueLine($iLine, 12012, 1)[0];
		$this->assertTrue(TPFW_Ticket_Line::cancel_nano($this->wpdb, $sNano)['bSuccess']);
		$this->assertTrue(TPFW_Ticket_Line::reset_nano($this->wpdb, $sNano)['bSuccess']);
		$this->assertNull($this->deletedAt($sNano));
		$aXfer = TPFW_Ticket_Line::transfer_nano($this->wpdb, $sNano, 42);
		$this->assertTrue($aXfer['bSuccess'], 'transfer is allowed only after reset made the row live inside target');
		$this->assertSame(42, $this->holder($sNano));
	}

	public function test_13_cancel_of_already_blocked_ticket_is_idempotent(): void
	{
		$iLine = 2013;
		$sNano = $this->issueLine($iLine, 12013, 1)[0];
		$this->assertTrue(TPFW_Ticket_Line::cancel_nano($this->wpdb, $sNano)['bSuccess']);
		$aAgain = TPFW_Ticket_Line::cancel_nano($this->wpdb, $sNano);
		$this->assertTrue($aAgain['bSuccess']);
		$this->assertSame(0, $this->liveOnLine($iLine));
		$this->assertSame(1, $this->rowCountOnLine($iLine));
		$this->assertSame(0, $this->liveStats($sNano));
		$this->assertTrue($this->isManual($sNano), 'idempotent cancel must keep the manual block');
	}

	public function test_14_reset_of_live_unblocked_ticket_keeps_existing_reset_semantics(): void
	{
		$iLine = 2014;
		$sNano = $this->issueLine($iLine, 12014, 1)[0];
		$oRow  = $this->liveRow($sNano);
		$this->assertIsArray($this->fn->checkin_ticket($this->wpdb, $oRow, 9, false));
		$this->assertTrue(TPFW_Ticket_Line::reset_nano($this->wpdb, $sNano)['bSuccess']);
		$this->assertNull($this->deletedAt($sNano));
		$this->assertFalse($this->isManual($sNano));
		$this->assertSame(1, $this->liveOnLine($iLine));
		$this->assertSame(0, $this->liveStats($sNano));
		$this->assertSame(0, $this->allStats($sNano));
	}

	public function test_15_failed_manual_flag_write_is_not_success(): void
	{
		$iLine = 2015;
		$sNano = $this->issueLine($iLine, 12015, 1)[0];
		$a = $this->fn->cancel_ticket($sNano);
		if(!$this->isManual($sNano))
		{
			$this->assertFalse(
				$a['bSuccess'],
				'cancel must not report success without persisting manual_cancelled_at; no QR/meta/note after that failure'
			);
		}
		$this->assertTrue($this->isManual($sNano), 'dashboard cancel must persist manual_cancelled_at');
		$this->assertSame(0, $this->liveOnLine($iLine));
	}

	public function test_16_failed_manual_flag_reset_is_not_success(): void
	{
		$iLine = 2016;
		$sNano = $this->issueLine($iLine, 12016, 1)[0];
		$this->assertTrue(TPFW_Ticket_Line::cancel_nano($this->wpdb, $sNano)['bSuccess']);
		$this->assertTrue($this->hasManualColumn(), '1.0.6 must add manual_cancelled_at before reset can clear it fail-closed');
		$oFail = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) {
			return stripos($sSql, 'UPDATE') !== false && str_contains($sSql, 'manual_cancelled_at');
		});
		$a = TPFW_Ticket_Line::reset_nano($oFail, $sNano);
		$this->assertFalse($a['bSuccess'], 'a failed manual-flag reset must not report success');
		$this->assertTrue($this->isManual($sNano), 'failed reset must not leave a false unblocked state');
	}

	/**
	 * @param int $iLine
	 * @param int $iOrder
	 * @param int $iQty
	 * @param int $iRefundedAbs
	 * @param string $sStatus
	 * @return list<string>
	 */
	private function issueLine($iLine, $iOrder, $iQty, $iRefundedAbs = 0, $sStatus = 'completed')
	{
		$iRefunded = $iRefundedAbs === 0 ? 0 : -abs($iRefundedAbs);
		$oItem = $this->lineItem($iLine, $iQty, $iRefunded);
		$this->putOrder($iOrder, $sStatus, $iLine, $iRefunded);
		$sNow = gmdate('Y-m-d H:i:s');
		$a = TPFW_Ticket_Line::issue($this->wpdb, $oItem, $iOrder, $sNow, function() use ($iLine, $iOrder) {
			return $this->insertTicket($iLine, $iOrder, 7);
		});
		$this->assertNotFalse($a['bStatus']);
		return $this->nanosOnLine($iLine);
	}

	/**
	 * @param int $iLine
	 * @param int $iOrder
	 * @param int $iQty
	 * @param int $iRefundedAbs
	 * @param string $sStatus
	 * @return void
	 */
	private function reissue($iLine, $iOrder, $iQty, $iRefundedAbs = 0, $sStatus = 'completed')
	{
		$iRefunded = $iRefundedAbs === 0 ? 0 : -abs($iRefundedAbs);
		$oItem = $this->lineItem($iLine, $iQty, $iRefunded);
		$this->putOrder($iOrder, $sStatus, $iLine, $iRefunded);
		$sNow = gmdate('Y-m-d H:i:s');
		TPFW_Ticket_Line::issue($this->wpdb, $oItem, $iOrder, $sNow, function() {
			$this->fail('issue must not mint a replacement nano for a manually blocked slot');
		});
	}

	/**
	 * @param int $iLine
	 * @param int $iOrder
	 * @param int $iQty
	 * @param int $iRefundedAbs
	 * @return void
	 */
	private function refundTo($iLine, $iOrder, $iQty, $iRefundedAbs)
	{
		$iRefunded = -abs($iRefundedAbs);
		$oItem = $this->lineItem($iLine, $iQty, $iRefunded);
		$this->putOrder($iOrder, 'completed', $iLine, $iRefunded);
		$a = TPFW_Ticket_Line::reconcile($this->wpdb, $oItem, $iOrder);
		$this->assertTrue(!empty($a['bStatus']) || str_contains((string)$a['sMessage'], 'cancelled') || $a['sMessage'] === '');
	}

	/**
	 * @param int $iLine
	 * @param int $iOrder
	 * @param int $iUser
	 * @return string
	 */
	private function insertTicket($iLine, $iOrder, $iUser)
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
	 * @return bool
	 */
	private function hasManualColumn()
	{
		$a = $this->wpdb->get_results("SHOW COLUMNS FROM `{$this->wpdb->prefix}tpfw_tickets` LIKE 'manual_cancelled_at'");
		return is_array($a) && $a !== array();
	}

	/**
	 * @param string $sNano
	 * @return bool
	 */
	private function isManual($sNano)
	{
		if(!$this->hasManualColumn())
		{
			return false;
		}
		$m = $this->wpdb->get_var($this->wpdb->prepare(
			'SELECT manual_cancelled_at FROM %i WHERE nano_id = %s',
			$this->wpdb->prefix.'tpfw_tickets',
			$sNano
		));
		return $m !== null && $m !== '' && $m !== '0';
	}

	/**
	 * @param int $iLine
	 * @return list<string>
	 */
	private function nanosOnLine($iLine)
	{
		$a = $this->wpdb->get_col($this->wpdb->prepare(
			'SELECT nano_id FROM %i WHERE order_line_id = %d ORDER BY id ASC',
			$this->wpdb->prefix.'tpfw_tickets',
			$iLine
		));
		return array_map('strval', (array)$a);
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
	 * @param int $iLine
	 * @return int
	 */
	private function rowCountOnLine($iLine)
	{
		return (int)$this->wpdb->get_var($this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE order_line_id = %d',
			$this->wpdb->prefix.'tpfw_tickets',
			$iLine
		));
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
	 * @param string $sNano
	 * @return int
	 */
	private function holder($sNano)
	{
		return (int)$this->wpdb->get_var($this->wpdb->prepare(
			'SELECT user_id FROM %i WHERE nano_id = %s',
			$this->wpdb->prefix.'tpfw_tickets',
			$sNano
		));
	}

	/**
	 * @param string $sNano
	 * @return int
	 */
	private function liveStats($sNano)
	{
		return (int)$this->wpdb->get_var($this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE nano_id_fk = %s AND deleted IS NULL',
			$this->wpdb->prefix.'tpfw_tickets_stats',
			$sNano
		));
	}

	/**
	 * @param string $sNano
	 * @return int
	 */
	private function allStats($sNano)
	{
		return (int)$this->wpdb->get_var($this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE nano_id_fk = %s',
			$this->wpdb->prefix.'tpfw_tickets_stats',
			$sNano
		));
	}

	/**
	 * @param int $iLine
	 * @param int $iQty
	 * @param int $iRefunded
	 * @return TPFW_Test_Refund_Item
	 */
	private function lineItem($iLine, $iQty, $iRefunded = 0)
	{
		$oItem = new TPFW_Test_Refund_Item($iLine, $iQty, $iRefunded);
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
		$oOrder = new TPFW_Test_Manual_Cancel_Order();
		$oOrder->status = $sStatus;
		$oOrder->refunded = array((int)$iLine => $iRefunded);
		$GLOBALS['tpfw_test_orders'][(int)$iOrder] = $oOrder;
	}
}
