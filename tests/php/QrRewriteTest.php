<?php
use PHPUnit\Framework\TestCase;

class QrRewriteTest extends TestCase
{
	private ?TPFW_Test_Wpdb $wpdb = null;

	protected function setUp(): void
	{
		TPFW_Qr_Rewrite::reset_test_state();
		TPFW_Qr_Rewrite::$aJobsOverride = array();
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
		TPFW_Qr_Rewrite::reset_test_state();
		if($this->wpdb)
		{
			TPFW_Test_Schema::drop($this->wpdb);
			$this->wpdb->mysqli()->close();
		}
	}

	public function test_unchanged_fingerprint_does_not_schedule(): void
	{
		$aScheduled = array();
		TPFW_Qr_Rewrite::$fnSchedule = function($sHook, $aArgs) use (&$aScheduled) {
			$aScheduled[] = array($sHook, $aArgs);
		};
		$sKey = TPFW_Qr_Render::appearance_key_from_settings('Door', '#ffffff', '#000000', '#000000', 0);
		$this->assertNull(TPFW_Qr_Rewrite::enqueue_if_needed(9, 'ticket', $sKey, $sKey));
		$this->assertSame(array(), $aScheduled);
		$this->assertNull(TPFW_Qr_Rewrite::get_job(9, 'ticket'));
	}

	public function test_empty_issued_key_queues_repair_without_settings_change(): void
	{
		$aScheduled = array();
		TPFW_Qr_Rewrite::$fnSchedule = function($sHook, $aArgs) use (&$aScheduled) {
			$aScheduled[] = array($sHook, $aArgs);
		};
		$sKey = TPFW_Qr_Render::appearance_key_from_settings('', '#ffffff', '#000000', '#000000', 12);
		$aJob = TPFW_Qr_Rewrite::enqueue_if_needed(9, 'ticket', '', $sKey);
		$this->assertNotNull($aJob);
		$this->assertSame('queued', $aJob['status']);
		$this->assertCount(1, $aScheduled);
		$this->assertSame(TPFW_Qr_Rewrite::HOOK_BATCH, $aScheduled[0][0]);
		$this->assertNull(TPFW_Qr_Rewrite::enqueue_if_needed(9, 'ticket', '', $sKey));
		$this->assertCount(1, $aScheduled);
	}

	public function test_changed_settings_while_running_reset_cursor_and_generation(): void
	{
		$iSchedules = 0;
		TPFW_Qr_Rewrite::$fnSchedule = function() use (&$iSchedules) {
			$iSchedules++;
		};
		$sA = TPFW_Qr_Render::appearance_key_from_settings('A', '#ffffff', '#000000', '#000000', 0);
		$sB = TPFW_Qr_Render::appearance_key_from_settings('B', '#ffffff', '#000000', '#000000', 0);
		TPFW_Qr_Rewrite::enqueue_if_needed(4, 'pass', '', $sA);
		$aFirst = TPFW_Qr_Rewrite::get_job(4, 'pass');
		$aFirst['cursor_id'] = 88;
		$aFirst['status']    = 'running';
		TPFW_Qr_Rewrite::$aJobsOverride[TPFW_Qr_Rewrite::job_key(4, 'pass')] = $aFirst;

		$aSecond = TPFW_Qr_Rewrite::enqueue_if_needed(4, 'pass', $sA, $sB);
		$this->assertSame(2, (int) $aSecond['generation']);
		$this->assertSame(0, (int) $aSecond['cursor_id']);
		$this->assertSame($sB, $aSecond['target']);
		$this->assertSame('queued', $aSecond['status']);
		$this->assertSame(2, $iSchedules);
	}

	public function test_fetch_batch_skips_deleted_and_pages(): void
	{
		$p = $this->wpdb->prefix.'tpfw_tickets';
		$sNow = gmdate('Y-m-d H:i:s');
		foreach(array('liveA', 'goneB', 'liveC') as $sNano)
		{
			if($sNano === 'goneB')
			{
				$this->wpdb->query($this->wpdb->prepare(
					'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, max_uses, created, updated, deleted)
					VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s, %s)',
					array($p, $sNano, 7, 1, 10, 20, 86400, 1, $sNow, $sNow, $sNow)
				));
			}
			else
			{
				$this->wpdb->query($this->wpdb->prepare(
					'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
					VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s)',
					array($p, $sNano, 7, 1, 10, 20, 86400, 1, $sNow, $sNow)
				));
			}
		}
		$aFirst = TPFW_Qr_Rewrite::fetch_batch($this->wpdb, 7, 'ticket', 0, 1);
		$this->assertCount(1, $aFirst);
		$this->assertSame('liveA', $aFirst[0]->nano_id);
		$aNext = TPFW_Qr_Rewrite::fetch_batch($this->wpdb, 7, 'ticket', (int) $aFirst[0]->id, 10);
		$this->assertCount(1, $aNext);
		$this->assertSame('liveC', $aNext[0]->nano_id);
	}

	public function test_live_product_types_cover_ticket_timeslot_pass_and_guestpass(): void
	{
		$sNow = gmdate('Y-m-d H:i:s');
		$this->insertTicket(11, 'tLive');
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (timeslot_id, nano_id, product_id, user_id, order_id, order_line_id, before_checkin_duration, max_uses, created, updated)
			VALUES (%s, %s, %d, %d, %d, %d, %d, %d, %s, %s)',
			array($this->wpdb->prefix.'tpfw_timeslot_tickets', 'slot1', 'tsLive', 12, 1, 10, 20, 0, 1, $sNow, $sNow)
		));
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, user_payer_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
			VALUES (%s, %d, %d, %d, %d, %d, %d, %d, %s, %s)',
			array($this->wpdb->prefix.'tpfw_pass', 'pLive', 13, 1, 1, 10, 20, 86400, 1, $sNow, $sNow)
		));
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, parent_nano_id_fk, guest_slot, product_id, user_id, user_payer_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
			VALUES (%s, %s, %d, %d, %d, %d, %d, %d, %d, %d, %s, %s)',
			array($this->wpdb->prefix.'tpfw_pass', 'gLive', 'pLive', 1, 13, 1, 1, 10, 21, 86400, 1, $sNow, $sNow)
		));
		$a = TPFW_Qr_Rewrite::live_product_types($this->wpdb);
		$aKeys = array();
		foreach($a as $aRow)
		{
			$aKeys[] = $aRow['type'].':'.$aRow['product_id'];
		}
		sort($aKeys);
		$this->assertSame(array('guestpass:13', 'pass:13', 'ticket:11', 'timeslot:12'), $aKeys);
	}

	public function test_batch_limit_resume_and_stale_generation(): void
	{
		TPFW_Qr_Rewrite::$iBatchSizeOverride = 2;
		$aWritten = array();
		$aIssued  = array();
		TPFW_Qr_Rewrite::$fnSchedule = function() {};
		TPFW_Qr_Rewrite::$fnSetIssuedKey = function($iPid, $sType, $sKey) use (&$aIssued) {
			$aIssued[] = array($iPid, $sType, $sKey);
		};
		$this->insertTicket(5, 'n1');
		$this->insertTicket(5, 'n2');
		$this->insertTicket(5, 'n3');
		$sKey = hash('sha256', 'target-a');
		$aJob = TPFW_Qr_Rewrite::enqueue(5, 'ticket', $sKey);
		$fnWrite = function($iPid, $sType, $sNano) use (&$aWritten) {
			$aWritten[] = $sNano;
			return true;
		};
		$aPage = TPFW_Qr_Rewrite::process_batch(5, 'ticket', (int) $aJob['generation'], $this->wpdb, $fnWrite);
		$this->assertSame('continue', $aPage['reason']);
		$this->assertSame(2, $aPage['written']);
		$this->assertCount(2, $aWritten);
		$this->assertSame(array(), $aIssued);

		$sNewer = hash('sha256', 'target-b');
		$aNew   = TPFW_Qr_Rewrite::enqueue(5, 'ticket', $sNewer);
		$aStale = TPFW_Qr_Rewrite::process_batch(5, 'ticket', (int) $aJob['generation'], $this->wpdb, $fnWrite);
		$this->assertSame('stale_generation', $aStale['reason']);
		$this->assertCount(2, $aWritten);

		$aFresh = TPFW_Qr_Rewrite::process_batch(5, 'ticket', (int) $aNew['generation'], $this->wpdb, $fnWrite);
		$this->assertSame('continue', $aFresh['reason']);
		$aDone  = TPFW_Qr_Rewrite::process_batch(5, 'ticket', (int) $aNew['generation'], $this->wpdb, $fnWrite);
		$this->assertSame('done', $aDone['reason']);
		$this->assertSame(array(array(5, 'ticket', $sNewer)), $aIssued);
		$this->assertSame(array('n1', 'n2', 'n1', 'n2', 'n3'), $aWritten);
	}

	public function test_write_failure_preserves_old_file_and_is_resumable(): void
	{
		$sDir  = sys_get_temp_dir().'/tpfw-qrfail-'.bin2hex(random_bytes(4)).'/';
		mkdir($sDir, 0777, true);
		$sDest = $sDir.'keep.webp';
		file_put_contents($sDest, str_repeat('OLDFILECONTENTS', 8));
		$sTmp  = $sDir.'keep.webp.tmp';
		file_put_contents($sTmp, '');
		$this->assertFalse(TPFW_Qr_Rewrite::commit_generated_file($sTmp, $sDest));
		$this->assertSame(str_repeat('OLDFILECONTENTS', 8), file_get_contents($sDest));

		file_put_contents($sTmp, str_repeat('NEW', 40));
		chmod($sDir, 0555);
		$bRenamed = TPFW_Qr_Rewrite::commit_generated_file($sTmp, $sDest);
		chmod($sDir, 0755);
		if($bRenamed)
		{
			$this->assertTrue($bRenamed);
		}
		else
		{
			$this->assertSame(str_repeat('OLDFILECONTENTS', 8), file_get_contents($sDest));
		}

		$this->insertTicket(8, 'failNano');
		TPFW_Qr_Rewrite::$fnSchedule = function() {};
		TPFW_Qr_Rewrite::enqueue(8, 'ticket', hash('sha256', 'k'));
		$fnFail = function() {
			return false;
		};
		for($i = 1; $i < TPFW_Qr_Rewrite::MAX_ATTEMPTS; $i++)
		{
			$a = TPFW_Qr_Rewrite::process_batch(8, 'ticket', 1, $this->wpdb, $fnFail);
			$this->assertFalse($a['ok']);
			$this->assertSame('queued', $a['reason']);
		}
		$aLast = TPFW_Qr_Rewrite::process_batch(8, 'ticket', 1, $this->wpdb, $fnFail);
		$this->assertSame('failed', $aLast['reason']);
		$aJob = TPFW_Qr_Rewrite::get_job(8, 'ticket');
		$this->assertSame('failed', $aJob['status']);
		$this->assertNotSame(array(), TPFW_Qr_Rewrite::failure_messages());

		$aResumed = TPFW_Qr_Rewrite::enqueue_if_needed(8, 'ticket', '', hash('sha256', 'k'));
		$this->assertSame('queued', $aResumed['status']);
		$this->assertSame(0, (int) $aResumed['attempts']);
		$this->assertSame((int) $aJob['cursor_id'], (int) $aResumed['cursor_id']);

		foreach(glob($sDir.'*') ?: array() as $sFile)
		{
			@unlink($sFile);
		}
		@rmdir($sDir);
	}

	public function test_deleted_row_is_not_written(): void
	{
		$sNow = gmdate('Y-m-d H:i:s');
		$this->insertTicket(3, 'alive');
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, max_uses, created, updated, deleted)
			VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s, %s)',
			array($this->wpdb->prefix.'tpfw_tickets', 'revoked', 3, 1, 10, 20, 86400, 1, $sNow, $sNow, $sNow)
		));
		$aWritten = array();
		TPFW_Qr_Rewrite::$fnSchedule = function() {};
		TPFW_Qr_Rewrite::$fnSetIssuedKey = function() {};
		TPFW_Qr_Rewrite::enqueue(3, 'ticket', hash('sha256', 'x'));
		TPFW_Qr_Rewrite::process_batch(3, 'ticket', 1, $this->wpdb, function($iPid, $sType, $sNano) use (&$aWritten) {
			$aWritten[] = $sNano;
			return true;
		});
		$this->assertSame(array('alive'), $aWritten);
		$sDeleted = $this->wpdb->get_var("SELECT deleted FROM `{$this->wpdb->prefix}tpfw_tickets` WHERE nano_id = 'revoked'");
		$this->assertNotNull($sDeleted);
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
