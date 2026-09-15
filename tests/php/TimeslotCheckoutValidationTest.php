<?php
use PHPUnit\Framework\TestCase;

require_once TPFW_PLUGIN_DIR.'inc/timeslot-ticket-wc-product/class--timeslot-ticket-checkout.php';

class TimeslotCheckoutValidationTest extends TestCase
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
		TPFW_Timeslot_Ticket_Checkout::clear_pending_reservation();
	}

	protected function tearDown(): void
	{
		if($this->wpdb)
		{
			TPFW_Test_Schema::drop($this->wpdb);
			$this->wpdb->mysqli()->close();
		}
	}

	public function test_incoming_passed_false_writes_nothing(): void
	{
		$sSlot = $this->insertSlot(1);
		$iBefore = $this->reservationCount($sSlot);
		$oCheckout = new TPFW_Timeslot_Ticket_Checkout((object)array());
		$this->assertFalse($oCheckout->validate(false, 1, 1));
		$this->assertNull(TPFW_Timeslot_Ticket_Checkout::pending_reservation());
		$this->assertSame($iBefore, $this->reservationCount($sSlot));
		$this->assertSame(0, $this->reservationCount($sSlot));
	}

	public function test_passed_true_with_a_seat_creates_a_reservation(): void
	{
		$sSlot = $this->insertSlot(1);
		$m = $this->holdLikeValidate($sSlot, 1, 1);
		$this->assertTrue($m['ok']);
		$this->assertSame(1, $this->reservationCount($sSlot));
	}

	public function test_passed_true_without_a_seat_does_not_reserve(): void
	{
		$sSlot = $this->insertSlot(1);
		$this->holdLikeValidate($sSlot, 1, 1);
		$m = $this->holdLikeValidate($sSlot, 1, 1);
		$this->assertFalse($m['ok']);
		$this->assertSame('capacity', $m['reason']);
		$this->assertSame(1, $this->reservationCount($sSlot));
	}

	public function test_validate_returns_false_before_capacity_lock(): void
	{
		$sSrc = file_get_contents(TPFW_PLUGIN_DIR.'inc/timeslot-ticket-wc-product/class--timeslot-ticket-checkout.php');
		$iFn  = strpos($sSrc, 'function validate(');
		$this->assertNotFalse($iFn);
		$sBody = substr($sSrc, $iFn, 2500);
		$iEarly = strpos($sBody, 'if(!$passed)');
		$iLock  = strpos($sBody, 'TPFW_Timeslot_Capacity::with_lock');
		$this->assertNotFalse($iEarly);
		$this->assertNotFalse($iLock);
		$this->assertLessThan($iLock, $iEarly);
	}

	/**
	 * @param string $sSlot
	 * @param int    $iCapacity
	 * @param int    $iQty
	 * @return array{ok:bool,reason?:string}
	 */
	private function holdLikeValidate($sSlot, $iCapacity, $iQty)
	{
		$wpdb = $this->wpdb;
		$sNow = gmdate('Y-m-d H:i:s');
		$sTo  = gmdate('Y-m-d H:i:s', time() + 600);
		$m = TPFW_Timeslot_Capacity::with_lock($wpdb, $sSlot, function() use ($wpdb, $sSlot, $sNow, $sTo, $iCapacity, $iQty) {
			$iAvailable = TPFW_Timeslot_Capacity::remaining($wpdb, $sSlot, $iCapacity, $sNow, true);
			if($iAvailable <= 0 || $iAvailable < $iQty)
			{
				return array('ok' => false, 'reason' => 'capacity');
			}
			$sRes = bin2hex(random_bytes(8));
			$mIns = $wpdb->query($wpdb->prepare(
				'INSERT INTO %i (timeslot_id, reservation_id, product_id, quantity, user_id, valid_from, valid_to, created, updated)
				VALUES (%s, %s, %d, %d, %d, %s, %s, %s, %s)',
				array($wpdb->prefix.'tpfw_timeslot_reservations', $sSlot, $sRes, 1, $iQty, 1, $sNow, $sTo, $sNow, $sNow)
			));
			if($mIns === false)
			{
				return array('ok' => false, 'reason' => 'capacity');
			}
			return array('ok' => true);
		});
		return is_array($m) ? $m : array('ok' => false, 'reason' => 'lock');
	}

	/**
	 * @param int $iSlots
	 * @return string
	 */
	private function insertSlot($iSlots)
	{
		$sSlot = 'slot'.bin2hex(random_bytes(6));
		$sWhen = gmdate('Y-m-d H:i:s', time() + 3600);
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (id, product_id, user_id, start, end, available_slots) VALUES (%s, %d, %d, %s, %s, %d)',
			array($this->wpdb->prefix.'tpfw_timeslots', $sSlot, 1, 1, $sWhen, $sWhen, $iSlots)
		));
		return $sSlot;
	}

	/**
	 * @param string $sSlot
	 * @return int
	 */
	private function reservationCount($sSlot)
	{
		return (int)$this->wpdb->get_var($this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE timeslot_id = %s AND deleted IS NULL',
			$this->wpdb->prefix.'tpfw_timeslot_reservations',
			$sSlot
		));
	}
}
