<?php
use PHPUnit\Framework\TestCase;

class IssueLockTest extends TestCase
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

	private function runPair(array $aCmd): array
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

	private function liveTickets(int $iLineId): array
	{
		return $this->wpdb->get_results($this->wpdb->prepare(
			'SELECT * FROM %i WHERE order_line_id = %d AND deleted IS NULL ORDER BY id ASC',
			$this->wpdb->prefix.'tpfw_tickets',
			$iLineId
		));
	}

	private function liveParents(int $iLineId): array
	{
		return $this->wpdb->get_results($this->wpdb->prepare(
			'SELECT * FROM %i WHERE parent_nano_id_fk IS NULL AND order_line_id = %d AND deleted IS NULL ORDER BY id ASC',
			$this->wpdb->prefix.'tpfw_pass',
			$iLineId
		));
	}

	public function test_lock_name_uses_type_and_line(): void
	{
		$this->assertSame('tpfw_ticket_issue_20', TPFW_Issue_Lock::name('ticket', 20));
		$this->assertSame('tpfw_pass_issue_7', TPFW_Issue_Lock::name('pass', 7));
	}

	public function test_ticket_qty_one_two_workers_one_row(): void
	{
		$iLine = random_int(10000, 99999);
		$sWorker = TPFW_TEST_ROOT.'/bin/issue-ticket-worker.php';
		$this->runPair(array(PHP_BINARY, $sWorker, '1', '1', '10', (string)$iLine, '1', $this->wpdb->prefix));
		$this->assertCount(1, $this->liveTickets($iLine));
	}

	public function test_ticket_qty_three_two_workers_three_rows(): void
	{
		$iLine = random_int(10000, 99999);
		$sWorker = TPFW_TEST_ROOT.'/bin/issue-ticket-worker.php';
		$this->runPair(array(PHP_BINARY, $sWorker, '1', '1', '10', (string)$iLine, '3', $this->wpdb->prefix));
		$this->assertCount(3, $this->liveTickets($iLine));
	}

	public function test_pass_qty_one_two_workers_one_parent(): void
	{
		$iLine = random_int(10000, 99999);
		$sWorker = TPFW_TEST_ROOT.'/bin/issue-pass-worker.php';
		$this->runPair(array(PHP_BINARY, $sWorker, '19', '7', '100', (string)$iLine, '1', $this->wpdb->prefix));
		$this->assertCount(1, $this->liveParents($iLine));
	}

	public function test_pass_qty_three_two_workers_three_parents(): void
	{
		$iLine = random_int(10000, 99999);
		$sWorker = TPFW_TEST_ROOT.'/bin/issue-pass-worker.php';
		$this->runPair(array(PHP_BINARY, $sWorker, '19', '7', '100', (string)$iLine, '3', $this->wpdb->prefix));
		$this->assertCount(3, $this->liveParents($iLine));
	}

	public function test_ticket_retry_keeps_nano_ids(): void
	{
		$sTable = $this->wpdb->prefix.'tpfw_tickets';
		$sNow   = gmdate('Y-m-d H:i:s');
		$iLine  = 42;
		$sSelect = $this->wpdb->prepare(
			'SELECT * FROM %i WHERE product_id = %d AND order_id = %d AND order_line_id = %d ORDER BY (deleted IS NULL) DESC, id ASC',
			$sTable, 1, 10, $iLine
		);
		$fn = function() use ($sTable, $sNow, $iLine) {
			$sNano = 'r'.bin2hex(random_bytes(8));
			$this->wpdb->query($this->wpdb->prepare(
				'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
				VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s)',
				array($sTable, $sNano, 1, 1, 10, $iLine, 86400, 1, $sNow, $sNow)
			));
			return $sNano;
		};
		$aFirst = TPFW_Issue_Lock::sync_line($this->wpdb, 'ticket', $sTable, $sSelect, $iLine, 2, $sNow, $fn);
		$aAgain = TPFW_Issue_Lock::sync_line($this->wpdb, 'ticket', $sTable, $sSelect, $iLine, 2, $sNow, $fn);
		$this->assertNotNull($aFirst);
		$this->assertSame($aFirst['keep'], $aAgain['keep']);
		$this->assertSame(array(), $aAgain['inserted']);
		$this->assertCount(2, $this->liveTickets($iLine));
	}

	public function test_lock_failure_writes_nothing(): void
	{
		$sTable = $this->wpdb->prefix.'tpfw_tickets';
		$sNow   = gmdate('Y-m-d H:i:s');
		$iLine  = 77;
		$sName  = TPFW_Issue_Lock::name('ticket', $iLine);
		$holder = TPFW_Named_Lock::acquire($this->wpdb, $sName, 5);
		$this->assertTrue($holder->held());

		$mysqli2 = TPFW_Test_Credentials::mysqli();
		$wpdb2   = new TPFW_Test_Wpdb($mysqli2, $this->wpdb->prefix);
		$sSelect = $wpdb2->prepare(
			'SELECT * FROM %i WHERE order_line_id = %d ORDER BY id ASC',
			$sTable,
			$iLine
		);
		$bInserted = false;
		$m = TPFW_Issue_Lock::sync_line($wpdb2, 'ticket', $sTable, $sSelect, $iLine, 1, $sNow, function() use (&$bInserted) {
			$bInserted = true;
			return 'should-not';
		}, 0);
		$this->assertNull($m);
		$this->assertFalse($bInserted);
		$this->assertCount(0, $this->liveTickets($iLine));

		$holder->release();
		$mysqli2->close();
	}

	public function test_create_ticket_and_pass_use_issue_lock(): void
	{
		$sTicket = file_get_contents(TPFW_PLUGIN_DIR.'inc/ticket-wc-product/class--ticket-wc-product.php');
		$sPass   = file_get_contents(TPFW_PLUGIN_DIR.'inc/pass-wc-product/class--pass-wc-product.php');
		$this->assertNotFalse(strpos($sTicket, "TPFW_Issue_Lock::sync_line"));
		$this->assertNotFalse(strpos($sPass, "TPFW_Issue_Lock::with_line(\$wpdb, 'pass'"));
		$sTimeslot = file_get_contents(TPFW_PLUGIN_DIR.'inc/timeslot-ticket-wc-product/class--timeslot-ticket-wc-product.php');
		$this->assertFalse(strpos($sTimeslot, 'TPFW_Issue_Lock'));
	}
}
