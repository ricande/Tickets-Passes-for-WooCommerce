<?php
use PHPUnit\Framework\TestCase;

require_once TPFW_TEST_ROOT.'/lib/TicketFunctionsHarness.php';

class RecurringTimeslotLockTest extends TestCase
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
		$this->wpdb = new TPFW_Test_Wpdb($mysqli, 'tpfwrecl_');
		TPFW_Test_Schema::install($this->wpdb);
		$GLOBALS['wpdb'] = $this->wpdb;
		$GLOBALS['tpfw_test_post_meta'] = array();
		$this->fn = new TPFW_Test_Ticket_Functions();
	}

	protected function tearDown(): void
	{
		$GLOBALS['tpfw_test_post_meta'] = array();
		unset($GLOBALS['wpdb']);
		if($this->wpdb)
		{
			TPFW_Test_Schema::drop($this->wpdb);
			$this->wpdb->mysqli()->close();
		}
	}

	public function test_source_locks_before_child_reread(): void
	{
		$sSrc = file_get_contents(TPFW_PLUGIN_DIR.'inc/functions/class--functions.php');
		$iFn  = strpos($sSrc, 'function create_recurring_timeslots(');
		$this->assertNotFalse($iFn);
		$sBody = substr($sSrc, $iFn, 4500);
		$this->assertNotFalse(strpos($sBody, 'recurring_timeslot_lock_name'));
		$this->assertNotFalse(strpos($sBody, 'TPFW_Named_Lock::acquire'));
		$iLock = strpos($sBody, 'TPFW_Named_Lock::acquire');
		$iCol  = strpos($sBody, 'create_recurring_timeslot_children_held');
		$this->assertNotFalse($iCol);
		$this->assertLessThan($iCol, $iLock);
		$this->assertNotFalse(strpos($sBody, 'finally'));
		$this->assertNotFalse(strpos($sBody, 'if(!$oLock->held())'));
	}

	public function test_sequential_force_create_is_idempotent(): void
	{
		$sSeries = $this->insertSeries(31);
		$this->enableProduct(31);
		$this->fn->create_recurring_timeslots($sSeries, 5);
		$aFirst = $this->liveStarts($sSeries);
		$this->assertNotSame(array(), $aFirst);
		$this->fn->create_recurring_timeslots($sSeries, 5);
		$aSecond = $this->liveStarts($sSeries);
		$this->assertSame($aFirst, $aSecond);
		$this->assertSame(count($aFirst), count(array_unique($aFirst)));
	}

	public function test_two_processes_do_not_duplicate_start(): void
	{
		$sSeries = $this->insertSeries(32);
		$this->enableProduct(32);
		$sWorker = TPFW_TEST_ROOT.'/bin/recurring-timeslot-worker.php';
		$aOut = $this->runPair(array(PHP_BINARY, $sWorker, $this->wpdb->prefix, $sSeries, '5'));
		$this->assertSame(array('ok', 'ok'), $aOut);
		$aStarts = $this->liveStarts($sSeries);
		$this->assertNotSame(array(), $aStarts);
		$this->assertSame(count($aStarts), count(array_unique($aStarts)));
	}

	public function test_lock_timeout_writes_nothing(): void
	{
		$sSeries = $this->insertSeries(33);
		$this->enableProduct(33);
		$oLock = TPFW_Named_Lock::acquire($this->wpdb, TPFW_Functions::recurring_timeslot_lock_name($sSeries), 5);
		$this->assertTrue($oLock->held());
		$sWorker = TPFW_TEST_ROOT.'/bin/recurring-timeslot-worker.php';
		$aPipes = array();
		$mProc = proc_open(
			array(PHP_BINARY, $sWorker, $this->wpdb->prefix, $sSeries, '0'),
			array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
			$aPipes,
			TPFW_PLUGIN_DIR
		);
		$this->assertIsResource($mProc);
		$sOut = trim(stream_get_contents($aPipes[1]));
		stream_get_contents($aPipes[2]);
		fclose($aPipes[1]);
		fclose($aPipes[2]);
		proc_close($mProc);
		$this->assertSame('ok', $sOut);
		$this->assertSame(array(), $this->liveStarts($sSeries));
		$oLock->release();
	}

	public function test_waiter_succeeds_after_holder_releases(): void
	{
		$sSeries = $this->insertSeries(34);
		$this->enableProduct(34);
		$mysqli2 = TPFW_Test_Credentials::mysqli();
		$this->assertNotFalse($mysqli2);
		$wpdb2 = new TPFW_Test_Wpdb($mysqli2, $this->wpdb->prefix);
		$oLock = TPFW_Named_Lock::acquire($wpdb2, TPFW_Functions::recurring_timeslot_lock_name($sSeries), 5);
		$this->assertTrue($oLock->held());
		$sWorker = TPFW_TEST_ROOT.'/bin/recurring-timeslot-worker.php';
		$aPipes = array();
		$mProc = proc_open(
			array(PHP_BINARY, $sWorker, $this->wpdb->prefix, $sSeries, '5'),
			array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
			$aPipes,
			TPFW_PLUGIN_DIR
		);
		$this->assertIsResource($mProc);
		usleep(150000);
		$oLock->release();
		$sOut = trim(stream_get_contents($aPipes[1]));
		stream_get_contents($aPipes[2]);
		fclose($aPipes[1]);
		fclose($aPipes[2]);
		proc_close($mProc);
		$mysqli2->close();
		$this->assertSame('ok', $sOut);
		$aStarts = $this->liveStarts($sSeries);
		$this->assertNotSame(array(), $aStarts);
		$this->assertSame(count($aStarts), count(array_unique($aStarts)));
	}

	public function test_manual_slot_is_not_duplicated(): void
	{
		$sSeries = $this->insertSeries(35);
		$this->enableProduct(35);
		$this->fn->create_recurring_timeslots($sSeries, 5);
		$aStarts = $this->liveStarts($sSeries);
		$this->assertNotSame(array(), $aStarts);
		$sStart = $aStarts[0];
		$this->wpdb->query($this->wpdb->prepare(
			'UPDATE %i SET manual = 1 WHERE timeslot_recurring_id_fk = %s AND start = %s AND deleted IS NULL',
			$this->wpdb->prefix.'tpfw_timeslots',
			$sSeries,
			$sStart
		));
		$this->fn->create_recurring_timeslots($sSeries, 5);
		$iAtStart = (int)$this->wpdb->get_var($this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE timeslot_recurring_id_fk = %s AND start = %s AND deleted IS NULL',
			$this->wpdb->prefix.'tpfw_timeslots',
			$sSeries,
			$sStart
		));
		$this->assertSame(1, $iAtStart);
	}

	/**
	 * @param array $aCmd
	 * @return array
	 */
	private function runPair(array $aCmd)
	{
		$aPipes = array();
		$aProcs = array();
		$aOut   = array();
		foreach(array($aCmd, $aCmd) as $i => $aOne)
		{
			$aPipes[$i] = array();
			$aProcs[$i] = proc_open($aOne, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $aPipes[$i], TPFW_PLUGIN_DIR);
			$this->assertIsResource($aProcs[$i]);
		}
		foreach($aProcs as $i => $mProc)
		{
			$aOut[] = trim(stream_get_contents($aPipes[$i][1]));
			stream_get_contents($aPipes[$i][2]);
			fclose($aPipes[$i][1]);
			fclose($aPipes[$i][2]);
			proc_close($mProc);
		}
		return $aOut;
	}

	/**
	 * @param int $iProduct
	 * @return string
	 */
	private function insertSeries($iProduct)
	{
		$sSeries = 'ser'.bin2hex(random_bytes(6));
		$sStart  = gmdate('Y-m-d', time() + 86400).' 00:00:00';
		$sEnd    = gmdate('Y-m-d', time() + 21 * 86400).' 00:00:00';
		$sNow    = gmdate('Y-m-d H:i:s');
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (id, start, end, slot_start, slot_end, weekday, week_number_start, week_number_end, available_slots, product_id, user_id, created, updated)
			VALUES (%s, %s, %s, %s, %s, %d, %d, %d, %d, %d, %d, %s, %s)',
			array(
				$this->wpdb->prefix.'tpfw_timeslots_recurring',
				$sSeries,
				$sStart,
				$sEnd,
				'12:00:00',
				'12:30:00',
				1,
				1,
				52,
				5,
				$iProduct,
				1,
				$sNow,
				gmdate('Y-m-d H:i:s', time() - 3600),
			)
		));
		return $sSeries;
	}

	/**
	 * @param int $iProduct
	 * @return void
	 */
	private function enableProduct($iProduct)
	{
		$GLOBALS['tpfw_test_post_meta'][(int)$iProduct] = array(
			'_tpfw_timeslot_ticket_recurring_enable' => 'yes',
			'_tpfw_timeslot_ticket_recurring_future' => '2',
		);
	}

	/**
	 * @param string $sSeries
	 * @return string[]
	 */
	private function liveStarts($sSeries)
	{
		$a = $this->wpdb->get_col($this->wpdb->prepare(
			'SELECT start FROM %i WHERE timeslot_recurring_id_fk = %s AND deleted IS NULL ORDER BY start ASC, id ASC',
			$this->wpdb->prefix.'tpfw_timeslots',
			$sSeries
		));
		return array_map('strval', $a);
	}
}
