<?php
use PHPUnit\Framework\TestCase;

class QrRewriteConcurrencyTest extends TestCase
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
		TPFW_Qr_Rewrite::reset_test_state();
		TPFW_Qr_Rewrite::$wpdbOverride = $this->wpdb;
		TPFW_Qr_Rewrite::$bKvStore     = true;
		TPFW_Qr_Rewrite::$fnSchedule   = function() {
			return true;
		};
		TPFW_Qr_Rewrite::$fnSetIssuedKey = function() {};
	}

	protected function tearDown(): void
	{
		TPFW_Qr_Rewrite::reset_test_state();
		if($this->wpdb)
		{
			TPFW_Test_Schema::drop($this->wpdb);
			$this->wpdb->mysqli()->close();
		}
	}

	public function test_two_products_keep_both_jobs(): void
	{
		$sWorker = TPFW_TEST_ROOT.'/bin/qr-rewrite-worker.php';
		$sKeyA   = hash('sha256', 'prod-a');
		$sKeyB   = hash('sha256', 'prod-b');
		$aOut    = $this->runPair(array(
			array(PHP_BINARY, $sWorker, $this->wpdb->prefix, 'enqueue', '501', 'ticket', $sKeyA),
			array(PHP_BINARY, $sWorker, $this->wpdb->prefix, 'enqueue', '502', 'ticket', $sKeyB),
		));
		$this->assertSame(array('ok', 'ok'), $aOut);
		$aA = TPFW_Qr_Rewrite::get_job(501, 'ticket');
		$aB = TPFW_Qr_Rewrite::get_job(502, 'ticket');
		$this->assertNotNull($aA);
		$this->assertNotNull($aB);
		$this->assertSame($sKeyA, $aA['target']);
		$this->assertSame($sKeyB, $aB['target']);
		$this->assertSame('queued', $aA['status']);
		$this->assertSame('queued', $aB['status']);
	}

	public function test_two_workers_same_job_cursor_and_done(): void
	{
		for($i = 1; $i <= 5; $i++)
		{
			$this->insertTicket(601, 'w'.$i);
		}
		$sKey = hash('sha256', 'same-job');
		$aJob = TPFW_Qr_Rewrite::enqueue(601, 'ticket', $sKey);
		$this->assertNotNull($aJob);
		$iGen    = (int) $aJob['generation'];
		$sWorker = TPFW_TEST_ROOT.'/bin/qr-rewrite-worker.php';
		$this->runPair(array(
			array(PHP_BINARY, $sWorker, $this->wpdb->prefix, 'batch', '601', 'ticket', (string) $iGen, '2'),
			array(PHP_BINARY, $sWorker, $this->wpdb->prefix, 'batch', '601', 'ticket', (string) $iGen, '2'),
		));
		$iMax = (int) $this->wpdb->get_var("SELECT MAX(id) FROM `{$this->wpdb->prefix}tpfw_tickets` WHERE product_id = 601 AND deleted IS NULL");
		$aAfter = TPFW_Qr_Rewrite::get_job(601, 'ticket');
		$this->assertNotNull($aAfter);
		$this->assertSame($iGen, (int) $aAfter['generation']);
		$this->assertContains($aAfter['status'], array('queued', 'running', 'done'));
		if(($aAfter['status'] ?? '') === 'done')
		{
			$this->assertSame($iMax, (int) $aAfter['cursor_id']);
		}
		else
		{
			$this->assertLessThan($iMax, (int) $aAfter['cursor_id']);
			$aFin = TPFW_Qr_Rewrite::process_batch(601, 'ticket', $iGen, $this->wpdb, function() {
				return true;
			});
			while(($aFin['reason'] ?? '') === 'continue')
			{
				$aFin = TPFW_Qr_Rewrite::process_batch(601, 'ticket', $iGen, $this->wpdb, function() {
					return true;
				});
			}
			$aAfter = TPFW_Qr_Rewrite::get_job(601, 'ticket');
		}
		$this->assertSame('done', $aAfter['status']);
		$this->assertSame($iMax, (int) $aAfter['cursor_id']);
		$this->assertSame($sKey, $aAfter['target']);
	}

	public function test_concurrent_temp_paths_are_distinct(): void
	{
		$sDir  = sys_get_temp_dir().'/tpfw-qrconc-'.bin2hex(random_bytes(4)).'/';
		mkdir($sDir, 0777, true);
		$sDest = $sDir.'shared.webp';
		file_put_contents($sDest, str_repeat('OLD-SHARED', 8));
		$sWorker = TPFW_TEST_ROOT.'/bin/qr-rewrite-worker.php';
		$sPayA   = str_repeat('TEMP-PAY-A', 8);
		$sPayB   = str_repeat('TEMP-PAY-B', 8);
		$aOut    = $this->runPair(array(
			array(PHP_BINARY, $sWorker, $this->wpdb->prefix, 'tmp', $sDest, $sPayA),
			array(PHP_BINARY, $sWorker, $this->wpdb->prefix, 'tmp', $sDest, $sPayB),
		));
		$this->assertCount(2, $aOut);
		$this->assertNotSame($aOut[0], $aOut[1]);
		$this->assertFileExists($aOut[0]);
		$this->assertFileExists($aOut[1]);
		$this->assertSame(str_repeat('OLD-SHARED', 8), file_get_contents($sDest));
		$this->assertFalse(TPFW_Qr_Rewrite::commit_generated_file($sDir.'missing.webp.tmp', $sDest));
		$this->assertSame(str_repeat('OLD-SHARED', 8), file_get_contents($sDest));
		@unlink($aOut[0]);
		@unlink($aOut[1]);
		@unlink($sDest);
		@rmdir($sDir);
	}

	/**
	 * @param array<int,array<int,string>> $aCmds
	 * @return string[]
	 */
	private function runPair(array $aCmds): array
	{
		$aPipes = array();
		$aProcs = array();
		$aOut   = array();
		foreach($aCmds as $i => $aOne)
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
	 * @param int    $iProductID
	 * @param string $sNano
	 * @return void
	 */
	private function insertTicket($iProductID, $sNano)
	{
		$sNow = gmdate('Y-m-d H:i:s');
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
			VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s)',
			array($this->wpdb->prefix.'tpfw_tickets', $sNano, $iProductID, 1, 10, 20, 86400, 1, $sNow, $sNow)
		));
	}
}
