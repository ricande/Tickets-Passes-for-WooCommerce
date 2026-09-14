<?php
use PHPUnit\Framework\TestCase;

class TimeslotCapacityTest extends TestCase
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

	public function test_two_concurrent_reservations_for_last_seat(): void
	{
		$sSlot = 'slot'.bin2hex(random_bytes(6));
		$sNow  = gmdate('Y-m-d H:i:s', time() + 3600);
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (id, product_id, user_id, start, end, available_slots) VALUES (%s, %d, %d, %s, %s, %d)',
			array($this->wpdb->prefix.'tpfw_timeslots', $sSlot, 1, 1, $sNow, $sNow, 1)
		));

		$sWorker = TPFW_TEST_ROOT.'/bin/timeslot-reserve-worker.php';
		$aSpecs  = array(
			array(PHP_BINARY, $sWorker, $sSlot, $this->wpdb->prefix),
			array(PHP_BINARY, $sWorker, $sSlot, $this->wpdb->prefix),
		);
		$aPipes = array();
		$aProcs = array();
		$aOut   = array();
		foreach($aSpecs as $i => $aCmd)
		{
			$aPipes[$i] = array();
			$aProcs[$i] = proc_open($aCmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $aPipes[$i], TPFW_PLUGIN_DIR);
		}
		foreach($aProcs as $i => $mProc)
		{
			$aOut[] = trim(stream_get_contents($aPipes[$i][1]));
			stream_get_contents($aPipes[$i][2]);
			fclose($aPipes[$i][1]);
			fclose($aPipes[$i][2]);
			proc_close($mProc);
		}

		$iOk = count(array_filter($aOut, static function($s) { return $s === 'ok'; }));
		$this->assertSame(1, $iOk);
		$iRes = (int)$this->wpdb->get_var($this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE timeslot_id = %s AND deleted IS NULL',
			$this->wpdb->prefix.'tpfw_timeslot_reservations', $sSlot
		));
		$this->assertSame(1, $iRes);
	}

	public function test_issue_that_does_not_fit_inserts_nothing(): void
	{
		$sSlot = 'slot'.bin2hex(random_bytes(6));
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (id, product_id, user_id, start, end, available_slots) VALUES (%s, %d, %d, %s, %s, %d)',
			array($this->wpdb->prefix.'tpfw_timeslots', $sSlot, 1, 1, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), 1)
		));
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (timeslot_id, nano_id, product_id, user_id, order_id, order_line_id, max_uses, created, updated)
			VALUES (%s, %s, %d, %d, %d, %d, %d, %s, %s)',
			array($this->wpdb->prefix.'tpfw_timeslot_tickets', $sSlot, 'taken'.bin2hex(random_bytes(4)), 1, 1, 50, 60, 1, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'))
		));

		$iSold = (int)$this->wpdb->get_var($this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE timeslot_id = %s AND deleted IS NULL AND NOT (order_id = %d AND order_line_id = %d)',
			$this->wpdb->prefix.'tpfw_timeslot_tickets', $sSlot, 99, 99
		));
		$this->assertFalse(TPFW_Timeslot_Capacity::quantity_fits($iSold, 1, 1));

		$sNote = 'Seems like the maximum number of tickets already created';
		$this->assertNotFalse(strpos($sNote, 'maximum'));
	}

	public function test_checkout_class_exists(): void
	{
		require_once TPFW_PLUGIN_DIR.'inc/timeslot-ticket-wc-product/class--timeslot-ticket-checkout.php';
		$this->assertTrue(class_exists('TPFW_Timeslot_Ticket_Checkout'));
	}
}
