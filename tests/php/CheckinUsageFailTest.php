<?php
use PHPUnit\Framework\TestCase;

require_once TPFW_TEST_ROOT.'/lib/TicketFunctionsHarness.php';

class CheckinUsageFailTest extends TestCase
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
		$this->wpdb = new TPFW_Test_Wpdb($mysqli, 'tpfwckuf_');
		TPFW_Test_Schema::install($this->wpdb);
		tpfw_test_install_ticket_stats($this->wpdb);
		$GLOBALS['tpfw_test_orders'] = array();
		$GLOBALS['tpfw_test_post_meta'] = array();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->fn = new TPFW_Test_Ticket_Functions();
	}

	protected function tearDown(): void
	{
		$GLOBALS['tpfw_test_orders'] = array();
		$GLOBALS['tpfw_test_post_meta'] = array();
		unset($GLOBALS['wpdb']);
		if($this->wpdb)
		{
			tpfw_test_drop_ticket_stats($this->wpdb);
			TPFW_Test_Schema::drop($this->wpdb);
			$this->wpdb->mysqli()->close();
		}
	}

	public function test_normal_max_uses_still_denies_second_scan(): void
	{
		$oRow = $this->seedTicket(1);
		$m1 = $this->fn->checkin($this->wpdb, 'ticket', $oRow, 1, true);
		$this->assertIsArray($m1);
		$m2 = $this->fn->checkin($this->wpdb, 'ticket', $oRow, 1, true);
		$this->assertInstanceOf(WP_REST_Response::class, $m2);
		$this->assertSame(202, $m2->get_status());
		$this->assertSame(1, $this->statsLive($oRow->nano_id));
	}

	public function test_usage_count_error_does_not_write_another_checkin(): void
	{
		$oRow = $this->seedTicket(1);
		$m1 = $this->fn->checkin($this->wpdb, 'ticket', $oRow, 1, true);
		$this->assertIsArray($m1);
		$oFail = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) use ($oRow) {
			return str_contains($sSql, 'tpfw_tickets_stats')
				&& str_contains($sSql, 'COUNT(*)')
				&& str_contains($sSql, $oRow->nano_id);
		});
		$m2 = $this->fn->checkin($oFail, 'ticket', $oRow, 1, true);
		$this->assertInstanceOf(WP_REST_Response::class, $m2);
		$this->assertSame(401, $m2->get_status());
		$this->assertSame(1, $this->statsLive($oRow->nano_id));
	}

	public function test_cooldown_count_error_does_not_bypass_cooldown(): void
	{
		$oRow = $this->seedTicket(5);
		$GLOBALS['tpfw_test_post_meta'][11]['_tpfw_ticket_cooldown_sec'] = '3600';
		$m1 = $this->fn->checkin($this->wpdb, 'ticket', $oRow, 1, true);
		$this->assertIsArray($m1);
		$mDenied = $this->fn->checkin($this->wpdb, 'ticket', $oRow, 1, true);
		$this->assertInstanceOf(WP_REST_Response::class, $mDenied);
		$this->assertSame(202, $mDenied->get_status());
		$oFail = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) use ($oRow) {
			return str_contains($sSql, 'tpfw_tickets_stats')
				&& str_contains($sSql, 'COUNT(*)')
				&& str_contains($sSql, $oRow->nano_id);
		});
		$mErr = $this->fn->checkin($oFail, 'ticket', $oRow, 1, true);
		$this->assertInstanceOf(WP_REST_Response::class, $mErr);
		$this->assertSame(401, $mErr->get_status());
		$this->assertSame(1, $this->statsLive($oRow->nano_id));
	}

	public function test_remaining_count_error_is_not_treated_as_empty_slot(): void
	{
		$sSlot = $this->insertFullSlot();
		$sNow  = gmdate('Y-m-d H:i:s');
		$this->assertSame(0, TPFW_Timeslot_Capacity::remaining($this->wpdb, $sSlot, 1, $sNow, false));
		$oFail = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) use ($sSlot) {
			return str_contains($sSql, 'tpfw_timeslot_tickets')
				&& str_contains($sSql, 'COUNT(*)')
				&& str_contains($sSql, $sSlot);
		});
		$this->assertFalse(TPFW_Timeslot_Capacity::remaining($oFail, $sSlot, 1, $sNow, false));
	}

	public function test_reservation_path_does_not_insert_when_remaining_fails(): void
	{
		$sSlot = $this->insertFullSlot();
		$sNow  = gmdate('Y-m-d H:i:s');
		$sTo   = gmdate('Y-m-d H:i:s', time() + 600);
		$oFail = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) use ($sSlot) {
			return str_contains($sSql, 'tpfw_timeslot_tickets')
				&& str_contains($sSql, 'COUNT(*)')
				&& str_contains($sSql, $sSlot);
		});
		$m = TPFW_Timeslot_Capacity::with_lock($oFail, $sSlot, function() use ($oFail, $sSlot, $sNow, $sTo) {
			$iAvailable = TPFW_Timeslot_Capacity::remaining($oFail, $sSlot, 1, $sNow, true);
			if($iAvailable === false || $iAvailable < 1)
			{
				return array('ok' => false, 'reason' => 'capacity');
			}
			$oFail->query($oFail->prepare(
				'INSERT INTO %i (timeslot_id, reservation_id, product_id, quantity, user_id, valid_from, valid_to, created, updated)
				VALUES (%s, %s, %d, %d, %d, %s, %s, %s, %s)',
				array($oFail->prefix.'tpfw_timeslot_reservations', $sSlot, 'resfail', 1, 1, 1, $sNow, $sTo, $sNow, $sNow)
			));
			return array('ok' => true);
		});
		$this->assertIsArray($m);
		$this->assertFalse($m['ok']);
		$iRes = (int)$this->wpdb->get_var($this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE timeslot_id = %s AND deleted IS NULL',
			$this->wpdb->prefix.'tpfw_timeslot_reservations',
			$sSlot
		));
		$this->assertSame(0, $iRes);
	}

	/**
	 * @param int $iMaxUses
	 * @return object
	 */
	private function seedTicket($iMaxUses)
	{
		$sNano = 'n'.bin2hex(random_bytes(8));
		$sNow  = gmdate('Y-m-d H:i:s');
		$sFrom = gmdate('Y-m-d H:i:s', time() - 3600);
		$sTo   = gmdate('Y-m-d H:i:s', time() + 86400);
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, valid_from, valid_to, max_uses, created, updated)
			VALUES (%s, %d, %d, %d, %d, %d, %s, %s, %d, %s, %s)',
			array($this->wpdb->prefix.'tpfw_tickets', $sNano, 11, 1, 15001, 5001, 86400, $sFrom, $sTo, $iMaxUses, $sNow, $sNow)
		));
		return $this->wpdb->get_row($this->wpdb->prepare(
			'SELECT * FROM %i WHERE nano_id = %s',
			$this->wpdb->prefix.'tpfw_tickets',
			$sNano
		));
	}

	/**
	 * @param string $sNano
	 * @return int
	 */
	private function statsLive($sNano)
	{
		return (int)$this->wpdb->get_var($this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE nano_id_fk = %s AND deleted IS NULL',
			$this->wpdb->prefix.'tpfw_tickets_stats',
			$sNano
		));
	}

	/**
	 * @return string
	 */
	private function insertFullSlot()
	{
		$sSlot = 'slot'.bin2hex(random_bytes(6));
		$sWhen = gmdate('Y-m-d H:i:s', time() + 3600);
		$sNow  = gmdate('Y-m-d H:i:s');
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (id, product_id, user_id, start, end, available_slots) VALUES (%s, %d, %d, %s, %s, %d)',
			array($this->wpdb->prefix.'tpfw_timeslots', $sSlot, 1, 1, $sWhen, $sWhen, 1)
		));
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (timeslot_id, nano_id, product_id, user_id, order_id, order_line_id, max_uses, created, updated)
			VALUES (%s, %s, %d, %d, %d, %d, %d, %s, %s)',
			array($this->wpdb->prefix.'tpfw_timeslot_tickets', $sSlot, 'taken'.bin2hex(random_bytes(4)), 1, 1, 50, 60, 1, $sNow, $sNow)
		));
		return $sSlot;
	}
}
