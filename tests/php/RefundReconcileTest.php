<?php
use PHPUnit\Framework\TestCase;

class TPFW_Test_Refund_Item
{
	public $id;
	public $qty;
	public $qtyRefunded = 0;

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

class RefundReconcileTest extends TestCase
{
	private ?TPFW_Test_Wpdb $wpdb = null;

	protected function setUp(): void
	{
		$mysqli = TPFW_Test_Credentials::mysqli();
		if(!$mysqli)
		{
			$this->markTestSkipped('No database credentials');
		}
		$this->wpdb = new TPFW_Test_Wpdb($mysqli, 'tpfwtest_');
		TPFW_Test_Schema::install($this->wpdb);
	}

	protected function tearDown(): void
	{
		if($this->wpdb)
		{
			TPFW_Test_Schema::drop($this->wpdb);
			$this->wpdb->mysqli()->close();
		}
	}

	/**
	 * @param int $iWcRefundedQty Signed WC value: refund 1 → -1, none → 0.
	 */
	private function orderItem($iWcRefundedQty, $iQty = 3, $iId = 20): TPFW_Test_Refund_Item
	{
		return new TPFW_Test_Refund_Item($iId, $iQty, $iWcRefundedQty);
	}

	/**
	 * @param int $iWcRefundedQty Signed WC value from get_qty_refunded_for_item().
	 */
	private function order($sStatus, $iItemId, $iWcRefundedQty): TPFW_Test_Refund_Order
	{
		$o = new TPFW_Test_Refund_Order();
		$o->status = $sStatus;
		$o->refunded = array($iItemId => $iWcRefundedQty);
		return $o;
	}

	/**
	 * WC_Order::get_qty_refunded_for_item() returns a NEGATIVE quantity. Do not drop abs()
	 * in TPFW_Refund_Policy::refunded_item_quantity() — purchased 3 minus raw -1 is 4, not 2.
	 */
	public function test_wc_get_qty_refunded_for_item_is_negative_and_normalized_once(): void
	{
		$oItem  = $this->orderItem(-1);
		$oOrder = $this->order('completed', 20, -1);
		$this->assertSame(-1, $oOrder->get_qty_refunded_for_item(20));
		$this->assertSame(-1, $oItem->get_qty_refunded());
		$this->assertSame(1, TPFW_Refund_Policy::refunded_item_quantity($oOrder, $oItem));
		$this->assertTrue(TPFW_Refund_Policy::should_reconcile($oOrder, $oItem));
		$this->assertSame(2, TPFW_Refund_Policy::target_active_quantity($oOrder, $oItem));
	}

	public function test_processing_item_refund_one_of_three_targets_two(): void
	{
		$oItem  = $this->orderItem(-1);
		$oOrder = $this->order('completed', 20, -1);
		$this->assertTrue(TPFW_Refund_Policy::should_reconcile($oOrder, $oItem));
		$this->assertSame(2, TPFW_Refund_Policy::target_active_quantity($oOrder, $oItem));
	}

	public function test_second_item_refund_targets_one(): void
	{
		$oItem  = $this->orderItem(-2);
		$oOrder = $this->order('completed', 20, -2);
		$this->assertSame(-2, $oOrder->get_qty_refunded_for_item(20));
		$this->assertSame(1, TPFW_Refund_Policy::target_active_quantity($oOrder, $oItem));
	}

	public function test_full_item_refund_targets_zero(): void
	{
		$oItem  = $this->orderItem(-3);
		$oOrder = $this->order('completed', 20, -3);
		$this->assertSame(-3, $oOrder->get_qty_refunded_for_item(20));
		$this->assertSame(0, TPFW_Refund_Policy::target_active_quantity($oOrder, $oItem));
	}

	public function test_full_order_refund_status_targets_zero(): void
	{
		$oItem  = $this->orderItem(0);
		$oOrder = $this->order('refunded', 20, 0);
		$this->assertTrue(TPFW_Refund_Policy::should_reconcile($oOrder, $oItem));
		$this->assertSame(0, TPFW_Refund_Policy::target_active_quantity($oOrder, $oItem));
	}

	public function test_amount_only_refund_does_not_reconcile(): void
	{
		$oItem  = $this->orderItem(0);
		$oOrder = $this->order('completed', 20, 0);
		$this->assertSame(0, $oOrder->get_qty_refunded_for_item(20));
		$this->assertSame(0, TPFW_Refund_Policy::refunded_item_quantity($oOrder, $oItem));
		$this->assertFalse(TPFW_Refund_Policy::should_reconcile($oOrder, $oItem));
		$this->assertSame(3, TPFW_Refund_Policy::target_active_quantity($oOrder, $oItem));
		$this->assertSame(3, TPFW_Refund_Policy::issue_quantity($oOrder, $oItem));
	}

	public function test_ticket_qty_three_refund_one_then_one_then_full(): void
	{
		$table = $this->wpdb->prefix.'tpfw_tickets';
		$sNow  = gmdate('Y-m-d H:i:s');
		$aNanos = array();
		for($i = 0; $i < 3; $i++)
		{
			$sNano = 't'.$i.bin2hex(random_bytes(4));
			$aNanos[] = $sNano;
			$this->wpdb->query($this->wpdb->prepare(
				'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
				VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s)',
				array($table, $sNano, 1, 1, 10, 20, 86400, 1, $sNow, $sNow)
			));
		}

		$aLive = $this->wpdb->get_results("SELECT * FROM `{$table}` WHERE deleted IS NULL ORDER BY id ASC");
		$aOne  = TPFW_Order_Line_Upsert::shrink($this->wpdb, $table, $aLive, 2, $sNow);
		$this->assertSame(array($aNanos[0], $aNanos[1]), $aOne['keep']);
		$this->assertSame(array($aNanos[2]), $aOne['deleted']);
		$this->assertSame(2, (int)$this->wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE deleted IS NULL"));
		$this->assertSame(1, (int)$this->wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE deleted IS NOT NULL"));

		$aLive = $this->wpdb->get_results("SELECT * FROM `{$table}` WHERE deleted IS NULL ORDER BY id ASC");
		$aTwo  = TPFW_Order_Line_Upsert::shrink($this->wpdb, $table, $aLive, 1, $sNow);
		$this->assertSame(array($aNanos[0]), $aTwo['keep']);
		$this->assertSame(1, (int)$this->wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE deleted IS NULL"));

		$aLive = $this->wpdb->get_results("SELECT * FROM `{$table}` WHERE deleted IS NULL ORDER BY id ASC");
		$aFull = TPFW_Order_Line_Upsert::shrink($this->wpdb, $table, $aLive, 0, $sNow);
		$this->assertSame(array(), $aFull['keep']);
		$this->assertSame(0, (int)$this->wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE deleted IS NULL"));
		$this->assertSame(3, (int)$this->wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE deleted IS NOT NULL"));

		$aLive = $this->wpdb->get_results("SELECT * FROM `{$table}` WHERE deleted IS NULL ORDER BY id ASC");
		$aAgain = TPFW_Order_Line_Upsert::shrink($this->wpdb, $table, $aLive, 0, $sNow);
		$this->assertSame(array(), $aAgain['deleted']);
		$this->assertSame(array(), $aAgain['inserted']);
		$this->assertSame($aNanos, $this->wpdb->get_col("SELECT nano_id FROM `{$table}` ORDER BY id ASC"));
	}

	public function test_amount_only_leaves_all_ticket_rows(): void
	{
		$table = $this->wpdb->prefix.'tpfw_tickets';
		$sNow  = gmdate('Y-m-d H:i:s');
		$oItem  = $this->orderItem(0);
		$oOrder = $this->order('completed', 20, 0);
		$this->assertFalse(TPFW_Refund_Policy::should_reconcile($oOrder, $oItem));
		for($i = 0; $i < 3; $i++)
		{
			$this->wpdb->query($this->wpdb->prepare(
				'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
				VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s)',
				array($table, 'a'.$i.bin2hex(random_bytes(3)), 1, 1, 10, 20, 86400, 1, $sNow, $sNow)
			));
		}
		$this->assertSame(3, (int)$this->wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE deleted IS NULL"));
	}

	public function test_pass_qty_three_refund_one_and_guest_on_revoked_parent(): void
	{
		$sPass = $this->wpdb->prefix.'tpfw_pass';
		$sNow  = gmdate('Y-m-d H:i:s');
		$aParents = array();
		for($i = 0; $i < 3; $i++)
		{
			$sNano = 'p'.$i.bin2hex(random_bytes(4));
			$aParents[] = $sNano;
			$this->wpdb->query($this->wpdb->prepare(
				'INSERT INTO %i (nano_id, product_id, user_id, user_payer_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
				VALUES (%s, %d, %d, %d, %d, %d, %d, %d, %s, %s)',
				array($sPass, $sNano, 1, 1, 1, 10, 20, 86400, 1, $sNow, $sNow)
			));
		}
		$sGuestKeep = 'gk'.bin2hex(random_bytes(4));
		$sGuestDrop = 'gd'.bin2hex(random_bytes(4));
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, parent_nano_id_fk, guest_slot, product_id, user_id, user_payer_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
			VALUES (%s, %s, %d, %d, %d, %d, %d, %d, %d, %d, %s, %s)',
			array($sPass, $sGuestKeep, $aParents[0], 1, 1, 1, 1, 10, 20, 86400, 1, $sNow, $sNow)
		));
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, parent_nano_id_fk, guest_slot, product_id, user_id, user_payer_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
			VALUES (%s, %s, %d, %d, %d, %d, %d, %d, %d, %d, %s, %s)',
			array($sPass, $sGuestDrop, $aParents[2], 1, 1, 1, 1, 10, 20, 86400, 1, $sNow, $sNow)
		));

		$aLive = $this->wpdb->get_results("SELECT * FROM `{$sPass}` WHERE parent_nano_id_fk IS NULL AND deleted IS NULL ORDER BY id ASC");
		$aSync = TPFW_Order_Line_Upsert::shrink($this->wpdb, $sPass, $aLive, 2, $sNow);
		$this->assertSame(array($aParents[2]), $aSync['deleted']);
		$this->wpdb->query($this->wpdb->prepare(
			'UPDATE %i SET deleted = %s, updated = %s WHERE nano_id = %s OR parent_nano_id_fk = %s',
			$sPass, $sNow, $sNow, $aParents[2], $aParents[2]
		));

		$aActiveParents = $this->wpdb->get_col("SELECT nano_id FROM `{$sPass}` WHERE parent_nano_id_fk IS NULL AND deleted IS NULL ORDER BY id ASC");
		$this->assertSame(array($aParents[0], $aParents[1]), $aActiveParents);
		$this->assertSame(1, (int)$this->wpdb->get_var($this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE nano_id = %s AND deleted IS NULL',
			$sPass, $sGuestKeep
		)));
		$this->assertSame(0, (int)$this->wpdb->get_var($this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE nano_id = %s AND deleted IS NULL',
			$sPass, $sGuestDrop
		)));
	}

	public function test_timeslot_refund_one_of_three_and_capacity_sees_two(): void
	{
		$sSlot = 'slot'.bin2hex(random_bytes(6));
		$sNow  = gmdate('Y-m-d H:i:s');
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (id, product_id, user_id, start, end, available_slots) VALUES (%s, %d, %d, %s, %s, %d)',
			array($this->wpdb->prefix.'tpfw_timeslots', $sSlot, 1, 1, $sNow, $sNow, 10)
		));
		$table = $this->wpdb->prefix.'tpfw_timeslot_tickets';
		$aNanos = array();
		for($i = 0; $i < 3; $i++)
		{
			$sNano = 'ts'.$i.bin2hex(random_bytes(4));
			$aNanos[] = $sNano;
			$this->wpdb->query($this->wpdb->prepare(
				'INSERT INTO %i (timeslot_id, nano_id, product_id, user_id, order_id, order_line_id, max_uses, created, updated)
				VALUES (%s, %s, %d, %d, %d, %d, %d, %s, %s)',
				array($table, $sSlot, $sNano, 1, 1, 10, 20, 1, $sNow, $sNow)
			));
		}

		$this->assertSame(7, TPFW_Timeslot_Capacity::remaining($this->wpdb, $sSlot, 10, $sNow, false));

		$aLive = $this->wpdb->get_results("SELECT * FROM `{$table}` WHERE deleted IS NULL ORDER BY id ASC");
		$aSync = TPFW_Order_Line_Upsert::shrink($this->wpdb, $table, $aLive, 2, $sNow);
		$this->assertSame(array($aNanos[0], $aNanos[1]), $aSync['keep']);
		$this->assertSame(2, (int)$this->wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE deleted IS NULL"));
		$this->assertSame(8, TPFW_Timeslot_Capacity::remaining($this->wpdb, $sSlot, 10, $sNow, false));

		$aLive = $this->wpdb->get_results("SELECT * FROM `{$table}` WHERE deleted IS NULL ORDER BY id ASC");
		$aAgain = TPFW_Order_Line_Upsert::shrink($this->wpdb, $table, $aLive, 2, $sNow);
		$this->assertSame(array(), $aAgain['deleted']);
		$this->assertSame(array(), $aAgain['inserted']);
		$this->assertSame($aNanos[0], $aAgain['keep'][0]);
		$this->assertSame(8, TPFW_Timeslot_Capacity::remaining($this->wpdb, $sSlot, 10, $sNow, false));
	}

	public function test_all_three_types_hook_order_refunded(): void
	{
		$aFiles = array(
			'inc/ticket-wc-product/class--ticket-wc-product.php',
			'inc/timeslot-ticket-wc-product/class--timeslot-ticket-wc-product.php',
			'inc/pass-wc-product/class--pass-wc-product.php',
		);
		foreach($aFiles as $sRel)
		{
			$sSrc = file_get_contents(TPFW_PLUGIN_DIR.$sRel);
			$this->assertNotFalse(strpos($sSrc, "add_action('woocommerce_order_refunded'"), $sRel);
			$this->assertNotFalse(strpos($sSrc, 'order_refunded'), $sRel);
		}
		$sBase = file_get_contents(TPFW_PLUGIN_DIR.'inc/product-type/class--product-type.php');
		$this->assertNotFalse(strpos($sBase, 'function order_refunded'));
		$this->assertNotFalse(strpos($sBase, 'function shrink_issued_rows'));
		$this->assertNotFalse(strpos($sBase, 'TPFW_Refund_Policy::target_active_quantity'));
	}

	public function test_policy_never_reads_refund_amount(): void
	{
		$sSrc = file_get_contents(TPFW_PLUGIN_DIR.'inc/support/class--refund-policy.php');
		foreach(array('get_total', 'get_amount', 'get_refund_amount', 'get_total_refunded', 'get_remaining_refund_amount') as $sNeedle)
		{
			$this->assertFalse(strpos($sSrc, $sNeedle), 'must not guess quantity from '.$sNeedle);
		}
		$this->assertNotFalse(strpos($sSrc, 'abs((int)$oOrder->get_qty_refunded_for_item'), 'must keep abs() on the signed WC qty');
	}

	public function test_issue_paths_use_refund_aware_quantity(): void
	{
		$aFiles = array(
			'inc/ticket-wc-product/class--ticket-wc-product.php',
			'inc/timeslot-ticket-wc-product/class--timeslot-ticket-wc-product.php',
			'inc/pass-wc-product/class--pass-wc-product.php',
		);
		foreach($aFiles as $sRel)
		{
			$sSrc = file_get_contents(TPFW_PLUGIN_DIR.$sRel);
			$this->assertNotFalse(strpos($sSrc, 'TPFW_Refund_Policy::issue_quantity'), $sRel);
		}
	}
}
