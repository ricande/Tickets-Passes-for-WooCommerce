<?php
use PHPUnit\Framework\TestCase;

class QrRewriteErrorTest extends TestCase
{
	private ?TPFW_Test_Wpdb $wpdb = null;

	protected function setUp(): void
	{
		TPFW_Qr_Rewrite::reset_test_state();
		TPFW_Qr_Rewrite::$aJobsOverride = array();
		TPFW_As_Action_Stub::reset();
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
		TPFW_As_Action_Stub::reset();
		if($this->wpdb)
		{
			TPFW_Test_Schema::drop($this->wpdb);
			$this->wpdb->mysqli()->close();
		}
	}

	public function test_action_scheduler_zero_is_failure_for_batch_and_sweep(): void
	{
		TPFW_Qr_Rewrite::$fnSchedule = null;
		TPFW_As_Action_Stub::$mEnqueue = 0;
		$sKey = hash('sha256', 'as-zero');
		$aJob = TPFW_Qr_Rewrite::enqueue(70, 'ticket', $sKey);
		$this->assertIsArray($aJob);
		$this->assertSame('failed', $aJob['status']);
		$this->assertSame('schedule_failed', $aJob['last_error']);
		$this->assertFalse($aJob['status'] === 'queued' && $aJob['last_error'] === '');
		$this->assertGreaterThanOrEqual(1, TPFW_As_Action_Stub::$iCalls);
		$this->assertSame(TPFW_Qr_Rewrite::HOOK_BATCH, TPFW_As_Action_Stub::$aCalls[0][0]);
		$this->assertFalse(TPFW_Qr_Rewrite::schedule_sweep());
		$aSweepHooks = array();
		foreach(TPFW_As_Action_Stub::$aCalls as $aCall)
		{
			if($aCall[0] === TPFW_Qr_Rewrite::HOOK_SWEEP)
			{
				$aSweepHooks[] = $aCall;
			}
		}
		$this->assertNotSame(array(), $aSweepHooks);

		$this->insertTicket(71, 'asSweep');
		$bHandled = false;
		$aSweep = TPFW_Qr_Rewrite::upgrade_sweep(
			$this->wpdb,
			function() use ($sKey) {
				return $sKey;
			},
			function() {
				return '';
			},
			function() use (&$bHandled) {
				$bHandled = true;
			}
		);
		$this->assertFalse($aSweep['ok']);
		$this->assertFalse($bHandled);
		$aStored = TPFW_Qr_Rewrite::get_job(71, 'ticket');
		$this->assertSame('failed', $aStored['status']);
		$this->assertSame('schedule_failed', $aStored['last_error']);
	}

	public function test_action_scheduler_positive_id_is_success(): void
	{
		TPFW_Qr_Rewrite::$fnSchedule = null;
		TPFW_As_Action_Stub::$mEnqueue = 42;
		$sKey = hash('sha256', 'as-ok');
		$aJob = TPFW_Qr_Rewrite::enqueue(72, 'ticket', $sKey);
		$this->assertSame('queued', $aJob['status']);
		$this->assertSame('', (string) $aJob['last_error']);
		$this->assertTrue(TPFW_Qr_Rewrite::schedule(72, 'ticket', (int) $aJob['generation']));
		$this->assertTrue(TPFW_Qr_Rewrite::schedule_sweep());
		$this->assertTrue(TPFW_Qr_Rewrite::action_id_ok(42));
		$this->assertFalse(TPFW_Qr_Rewrite::action_id_ok(0));
		$this->assertFalse(TPFW_Qr_Rewrite::action_id_ok(false));
		$this->assertTrue(TPFW_Qr_Rewrite::cron_schedule_ok(true));
		$this->assertFalse(TPFW_Qr_Rewrite::cron_schedule_ok(false));
		$this->assertFalse(TPFW_Qr_Rewrite::cron_schedule_ok(0));
	}

	public function test_lock_failure_at_start_and_after_write_is_not_success(): void
	{
		$this->insertTicket(80, 'lockA');
		$iSchedules = 0;
		TPFW_Qr_Rewrite::$fnSchedule = function() use (&$iSchedules) {
			$iSchedules++;
			return true;
		};
		$sKey = hash('sha256', 'lock-start');
		$aJob = TPFW_Qr_Rewrite::enqueue(80, 'ticket', $sKey);
		$iGen = (int) $aJob['generation'];
		$iSchedules = 0;
		TPFW_Qr_Rewrite::$wpdbOverride = $this->wpdbFailingGetLockAfter(0);
		$aStart = TPFW_Qr_Rewrite::process_batch(80, 'ticket', $iGen, $this->wpdb, function() {
			$this->fail('write must not run when the start lock fails');
		});
		$this->assertFalse($aStart['ok']);
		$this->assertSame('lock_failed', $aStart['reason']);
		$aStored = TPFW_Qr_Rewrite::get_job(80, 'ticket');
		$this->assertSame($iGen, (int) $aStored['generation']);
		$this->assertSame(0, (int) $aStored['cursor_id']);
		$this->assertNotSame('done', $aStored['status']);
		$this->assertGreaterThanOrEqual(1, $iSchedules);

		TPFW_Qr_Rewrite::$wpdbOverride = $this->wpdbFailingGetLockAfter(1);
		$iSchedules = 0;
		$aMid = TPFW_Qr_Rewrite::process_batch(80, 'ticket', $iGen, $this->wpdb, function() {
			return true;
		});
		$this->assertFalse($aMid['ok']);
		$this->assertSame('lock_failed', $aMid['reason']);
		$aAfter = TPFW_Qr_Rewrite::get_job(80, 'ticket');
		$this->assertSame($iGen, (int) $aAfter['generation']);
		$this->assertSame(0, (int) $aAfter['cursor_id']);
		$this->assertNotSame('done', $aAfter['status']);
		$this->assertGreaterThanOrEqual(1, $iSchedules);
	}

	public function test_persist_failure_at_start_and_finish_is_not_success(): void
	{
		$this->insertTicket(81, 'persA');
		$this->useKvStore();
		$iSchedules = 0;
		TPFW_Qr_Rewrite::$fnSchedule = function() use (&$iSchedules) {
			$iSchedules++;
			return true;
		};
		$sKey = hash('sha256', 'persist-err');
		$aJob = TPFW_Qr_Rewrite::enqueue(81, 'ticket', $sKey);
		$iGen = (int) $aJob['generation'];
		$bFailWrites = true;
		TPFW_Qr_Rewrite::$wpdbOverride = $this->wpdbFailingKvWrite(function() use (&$bFailWrites) {
			return $bFailWrites;
		});
		$iSchedules = 0;
		$aStart = TPFW_Qr_Rewrite::process_batch(81, 'ticket', $iGen, $this->wpdb, function() {
			$this->fail('write must not run when start persist fails');
		});
		$this->assertFalse($aStart['ok']);
		$this->assertSame('persist_failed', $aStart['reason']);
		$aStored = TPFW_Qr_Rewrite::get_job(81, 'ticket');
		$this->assertSame('queued', $aStored['status']);
		$this->assertSame(0, (int) $aStored['cursor_id']);
		$this->assertSame($iGen, (int) $aStored['generation']);
		$this->assertGreaterThanOrEqual(1, $iSchedules);

		$bFailWrites = false;
		$bFailAfterWrite = false;
		TPFW_Qr_Rewrite::$wpdbOverride = $this->wpdbFailingKvWrite(function() use (&$bFailAfterWrite) {
			return $bFailAfterWrite;
		});
		$aMid = TPFW_Qr_Rewrite::process_batch(81, 'ticket', $iGen, $this->wpdb, function() use (&$bFailAfterWrite) {
			$bFailAfterWrite = true;
			return true;
		});
		$this->assertFalse($aMid['ok']);
		$this->assertSame('persist_failed', $aMid['reason']);
		$aAfter = TPFW_Qr_Rewrite::get_job(81, 'ticket');
		$this->assertSame($iGen, (int) $aAfter['generation']);
		$this->assertSame(0, (int) $aAfter['cursor_id']);
		$this->assertNotSame('done', $aAfter['status']);

		TPFW_Qr_Rewrite::$wpdbOverride = $this->wpdb;
		TPFW_Qr_Rewrite::$fnSetIssuedKey = function() {};
		$sEmptyKey = hash('sha256', 'persist-finish');
		$aEmpty = TPFW_Qr_Rewrite::enqueue(85, 'ticket', $sEmptyKey);
		$iEmptyGen = (int) $aEmpty['generation'];
		$iKv = 0;
		TPFW_Qr_Rewrite::$wpdbOverride = $this->wpdbFailingKvWrite(function() use (&$iKv) {
			$iKv++;
			return $iKv >= 2;
		});
		$aIssued = array();
		TPFW_Qr_Rewrite::$fnSetIssuedKey = function($iPid, $sType, $sK) use (&$aIssued) {
			$aIssued[] = array($iPid, $sType, $sK);
		};
		$aFin = TPFW_Qr_Rewrite::process_batch(85, 'ticket', $iEmptyGen, $this->wpdb, function() {
			$this->fail('no rows to write');
		});
		$this->assertFalse($aFin['ok']);
		$this->assertSame('persist_failed', $aFin['reason']);
		$aEmptyJob = TPFW_Qr_Rewrite::get_job(85, 'ticket');
		$this->assertNotSame('done', $aEmptyJob['status']);
		$this->assertSame($iEmptyGen, (int) $aEmptyJob['generation']);
	}

	public function test_retry_schedule_failure_marks_failed_and_unchanged_save_resumes(): void
	{
		$this->insertTicket(82, 'schedA');
		TPFW_Qr_Rewrite::$fnSchedule = function() {
			return false;
		};
		$sKey = hash('sha256', 'retry-sched');
		$aEnq = TPFW_Qr_Rewrite::enqueue(82, 'ticket', $sKey);
		$this->assertSame('failed', $aEnq['status']);
		$this->assertSame('schedule_failed', $aEnq['last_error']);
		$iGen = (int) $aEnq['generation'];
		$iCursor = (int) $aEnq['cursor_id'];

		$iSchedules = 0;
		TPFW_Qr_Rewrite::$fnSchedule = function() use (&$iSchedules) {
			$iSchedules++;
			return true;
		};
		$aResumed = TPFW_Qr_Rewrite::enqueue_if_needed(82, 'ticket', $sKey, $sKey);
		$this->assertIsArray($aResumed);
		$this->assertSame('queued', $aResumed['status']);
		$this->assertSame($iGen, (int) $aResumed['generation']);
		$this->assertSame($iCursor, (int) $aResumed['cursor_id']);
		$this->assertGreaterThanOrEqual(1, $iSchedules);

		TPFW_Qr_Rewrite::$fnSetIssuedKey = function() {};
		$aDone = TPFW_Qr_Rewrite::process_batch(82, 'ticket', $iGen, $this->wpdb, function() {
			return true;
		});
		$this->assertTrue($aDone['ok']);
		$this->assertSame('done', $aDone['reason']);
		$aJob = TPFW_Qr_Rewrite::get_job(82, 'ticket');
		$this->assertSame('done', $aJob['status']);
		$this->assertSame($iGen, (int) $aJob['generation']);

		$this->insertTicket(86, 'retryWrite');
		$iOk = 0;
		TPFW_Qr_Rewrite::$fnSchedule = function() use (&$iOk) {
			$iOk++;
			return $iOk === 1;
		};
		$sWriteKey = hash('sha256', 'retry-write-sched');
		$aWriteJob = TPFW_Qr_Rewrite::enqueue(86, 'ticket', $sWriteKey);
		$this->assertSame('queued', $aWriteJob['status']);
		$aWriteFail = TPFW_Qr_Rewrite::process_batch(86, 'ticket', (int) $aWriteJob['generation'], $this->wpdb, function() {
			return false;
		});
		$this->assertFalse($aWriteFail['ok']);
		$this->assertSame('failed', $aWriteFail['reason']);
		$aStuck = TPFW_Qr_Rewrite::get_job(86, 'ticket');
		$this->assertSame('failed', $aStuck['status']);
		$this->assertSame('schedule_failed', $aStuck['last_error']);
		$this->assertSame(0, (int) $aStuck['cursor_id']);
		TPFW_Qr_Rewrite::$fnSchedule = function() {
			return true;
		};
		$aKick = TPFW_Qr_Rewrite::enqueue_if_needed(86, 'ticket', $sWriteKey, $sWriteKey);
		$this->assertSame('queued', $aKick['status']);
		$this->assertSame((int) $aWriteJob['generation'], (int) $aKick['generation']);
		$this->assertSame(0, (int) $aKick['cursor_id']);
	}

	public function test_transient_lock_and_persist_errors_then_complete_same_generation(): void
	{
		$this->insertTicket(83, 'n1');
		$this->insertTicket(83, 'n2');
		$sKey = hash('sha256', 'transient');
		TPFW_Qr_Rewrite::$fnSchedule = function() {
			return true;
		};
		TPFW_Qr_Rewrite::$fnSetIssuedKey = function() {};
		$this->useKvStore();
		$aJob = TPFW_Qr_Rewrite::enqueue(83, 'ticket', $sKey);
		$iGen = (int) $aJob['generation'];

		TPFW_Qr_Rewrite::$wpdbOverride = $this->wpdbFailingGetLockAfter(0);
		$aLock = TPFW_Qr_Rewrite::process_batch(83, 'ticket', $iGen, $this->wpdb, function() {
			return true;
		});
		$this->assertFalse($aLock['ok']);
		$this->assertSame(0, (int) TPFW_Qr_Rewrite::get_job(83, 'ticket')['cursor_id']);

		TPFW_Qr_Rewrite::$wpdbOverride = null;
		$this->useKvStore();
		$bFailWrites = true;
		TPFW_Qr_Rewrite::$wpdbOverride = $this->wpdbFailingKvWrite(function() use (&$bFailWrites) {
			return $bFailWrites;
		});
		$aPers = TPFW_Qr_Rewrite::process_batch(83, 'ticket', $iGen, $this->wpdb, function() {
			return true;
		});
		$this->assertFalse($aPers['ok']);
		$this->assertSame($iGen, (int) TPFW_Qr_Rewrite::get_job(83, 'ticket')['generation']);
		$this->assertSame(0, (int) TPFW_Qr_Rewrite::get_job(83, 'ticket')['cursor_id']);

		$bFailWrites = false;
		$aDone = TPFW_Qr_Rewrite::process_batch(83, 'ticket', $iGen, $this->wpdb, function() {
			return true;
		});
		$this->assertTrue($aDone['ok']);
		$this->assertSame('done', $aDone['reason']);
		$aFinal = TPFW_Qr_Rewrite::get_job(83, 'ticket');
		$this->assertSame('done', $aFinal['status']);
		$this->assertSame($iGen, (int) $aFinal['generation']);
		$iMax = (int) $this->wpdb->get_var("SELECT MAX(id) FROM `{$this->wpdb->prefix}tpfw_tickets` WHERE product_id = 83 AND deleted IS NULL");
		$this->assertSame($iMax, (int) $aFinal['cursor_id']);
	}

	public function test_running_job_resumes_on_unchanged_save(): void
	{
		$this->insertTicket(84, 'runA');
		$aPending = array();
		$this->bindPendingSchedule($aPending);
		$sKey = hash('sha256', 'running-resume');
		TPFW_Qr_Rewrite::enqueue(84, 'ticket', $sKey);
		$aPending = array();
		$aJob = TPFW_Qr_Rewrite::get_job(84, 'ticket');
		$aJob['status']    = 'running';
		$aJob['cursor_id'] = 3;
		TPFW_Qr_Rewrite::$aJobsOverride[TPFW_Qr_Rewrite::job_key(84, 'ticket')] = $aJob;
		$aResumed = TPFW_Qr_Rewrite::enqueue_if_needed(84, 'ticket', $sKey, $sKey);
		$this->assertIsArray($aResumed);
		$this->assertSame('queued', $aResumed['status']);
		$this->assertSame(3, (int) $aResumed['cursor_id']);
		$this->assertSame((int) $aJob['generation'], (int) $aResumed['generation']);
		$this->assertCount(1, $aPending);
		$this->assertSame(array(84, 'ticket', (int) $aJob['generation']), $aPending[0]);
	}

	public function test_persistent_lock_failure_stops_after_max_scheduled_recoveries(): void
	{
		$this->insertTicket(90, 'lockCap');
		$aPending = array();
		$this->bindPendingSchedule($aPending);
		$sKey = hash('sha256', 'lock-cap');
		$aJob = TPFW_Qr_Rewrite::enqueue(90, 'ticket', $sKey);
		$iGen = (int) $aJob['generation'];
		$this->assertSame(array(array(90, 'ticket', $iGen)), $aPending);

		TPFW_Qr_Rewrite::$wpdbOverride = $this->wpdbFailingGetLockAfter(0);
		$aRecoveries = array();
		$aLast = $this->drainBookedBatches($aPending, $this->wpdb, function() {
			$this->fail('write must not run when the lock fails');
		}, $aRecoveries);

		$this->assertSame(array(1, 2, 3, 4), $aRecoveries);
		$this->assertSame(array(), $aPending);
		$this->assertFalse($aLast['ok']);
		$this->assertSame('lock_failed', $aLast['reason']);
		$this->assertNotSame('failed', $aLast['reason']);
		$aStored = TPFW_Qr_Rewrite::get_job(90, 'ticket');
		$this->assertSame('queued', $aStored['status']);
		$this->assertSame('', (string) $aStored['last_error']);
		$this->assertSame($iGen, (int) $aStored['generation']);
		$this->assertSame(0, (int) $aStored['cursor_id']);
		$this->assertSame(0, (int) $aStored['attempts']);
	}

	public function test_persistent_persist_failure_stops_after_max_scheduled_recoveries(): void
	{
		$this->insertTicket(91, 'persCap');
		$this->useKvStore();
		$aPending = array();
		$this->bindPendingSchedule($aPending);
		$sKey = hash('sha256', 'persist-cap');
		$aJob = TPFW_Qr_Rewrite::enqueue(91, 'ticket', $sKey);
		$iGen = (int) $aJob['generation'];
		$this->assertSame(array(array(91, 'ticket', $iGen)), $aPending);

		TPFW_Qr_Rewrite::$wpdbOverride = $this->wpdbFailingKvWrite(function() {
			return true;
		});
		$aRecoveries = array();
		$aLast = $this->drainBookedBatches($aPending, $this->wpdb, function() {
			$this->fail('write must not run when start persist fails');
		}, $aRecoveries);

		$this->assertSame(array(1, 2, 3, 4), $aRecoveries);
		$this->assertSame(array(), $aPending);
		$this->assertFalse($aLast['ok']);
		$this->assertSame('persist_failed', $aLast['reason']);
		$this->assertNotSame('failed', $aLast['reason']);
		$aStored = TPFW_Qr_Rewrite::get_job(91, 'ticket');
		$this->assertSame('queued', $aStored['status']);
		$this->assertSame('', (string) $aStored['last_error']);
		$this->assertSame($iGen, (int) $aStored['generation']);
		$this->assertSame(0, (int) $aStored['cursor_id']);
	}

	public function test_stranded_queued_job_resumes_on_unchanged_save_and_completes(): void
	{
		$this->insertTicket(92, 'strandA');
		$this->insertTicket(92, 'strandB');
		$aPending = array();
		$this->bindPendingSchedule($aPending);
		$sKey = hash('sha256', 'strand-resume');
		$aJob = TPFW_Qr_Rewrite::enqueue(92, 'ticket', $sKey);
		$iGen = (int) $aJob['generation'];
		$iFirst = (int) $this->wpdb->get_var("SELECT MIN(id) FROM `{$this->wpdb->prefix}tpfw_tickets` WHERE product_id = 92 AND deleted IS NULL");
		$aJob['cursor_id'] = $iFirst;
		TPFW_Qr_Rewrite::$aJobsOverride[TPFW_Qr_Rewrite::job_key(92, 'ticket')] = $aJob;

		$this->bindPendingSchedule($aPending, true);
		TPFW_Qr_Rewrite::$wpdbOverride = $this->wpdbFailingGetLockAfter(0);
		$aStart = $this->runBookedBatch($aPending, $this->wpdb, function() {
			$this->fail('write must not run when the start lock fails');
		});
		$this->assertFalse($aStart['ok']);
		$this->assertSame('schedule_failed', $aStart['reason']);
		$this->assertSame(array(), $aPending);
		$aStuck = TPFW_Qr_Rewrite::get_job(92, 'ticket');
		$this->assertSame('queued', $aStuck['status']);
		$this->assertSame('', (string) $aStuck['last_error']);
		$this->assertSame($iGen, (int) $aStuck['generation']);
		$this->assertSame($iFirst, (int) $aStuck['cursor_id']);

		TPFW_Qr_Rewrite::$wpdbOverride = null;
		$this->bindPendingSchedule($aPending);
		$aKick = TPFW_Qr_Rewrite::enqueue_if_needed(92, 'ticket', $sKey, $sKey);
		$this->assertIsArray($aKick);
		$this->assertSame('queued', $aKick['status']);
		$this->assertSame($iGen, (int) $aKick['generation']);
		$this->assertSame($iFirst, (int) $aKick['cursor_id']);
		$this->assertCount(1, $aPending);
		$this->assertSame(array(92, 'ticket', $iGen), $aPending[0]);

		TPFW_Qr_Rewrite::$fnSetIssuedKey = function() {};
		$aDone = $this->runBookedBatch($aPending, $this->wpdb, function() {
			return true;
		});
		$this->assertTrue($aDone['ok']);
		$this->assertSame('done', $aDone['reason']);
		$aFinal = TPFW_Qr_Rewrite::get_job(92, 'ticket');
		$this->assertSame('done', $aFinal['status']);
		$this->assertSame($iGen, (int) $aFinal['generation']);
		$iMax = (int) $this->wpdb->get_var("SELECT MAX(id) FROM `{$this->wpdb->prefix}tpfw_tickets` WHERE product_id = 92 AND deleted IS NULL");
		$this->assertSame($iMax, (int) $aFinal['cursor_id']);
	}

	public function test_stranded_resume_keeps_generation_and_stale_generation_cannot_mutate(): void
	{
		$this->insertTicket(93, 'genA');
		$aPending = array();
		$this->bindPendingSchedule($aPending);
		$sOld = hash('sha256', 'gen-old');
		$sNew = hash('sha256', 'gen-new');
		$aJob = TPFW_Qr_Rewrite::enqueue(93, 'ticket', $sOld);
		$iOldGen = (int) $aJob['generation'];
		$aJob['cursor_id'] = 7;
		TPFW_Qr_Rewrite::$aJobsOverride[TPFW_Qr_Rewrite::job_key(93, 'ticket')] = $aJob;

		$this->bindPendingSchedule($aPending, true);
		TPFW_Qr_Rewrite::$wpdbOverride = $this->wpdbFailingGetLockAfter(0);
		$this->runBookedBatch($aPending, $this->wpdb, function() {
			return true;
		});
		$this->assertSame(array(), $aPending);

		TPFW_Qr_Rewrite::$wpdbOverride = null;
		$this->bindPendingSchedule($aPending);
		$aKick = TPFW_Qr_Rewrite::enqueue_if_needed(93, 'ticket', $sOld, $sOld);
		$this->assertSame($iOldGen, (int) $aKick['generation']);
		$this->assertSame(7, (int) $aKick['cursor_id']);

		$aNewer = TPFW_Qr_Rewrite::enqueue_if_needed(93, 'ticket', $sOld, $sNew);
		$this->assertSame($iOldGen + 1, (int) $aNewer['generation']);
		$this->assertSame(0, (int) $aNewer['cursor_id']);
		$iNewGen = (int) $aNewer['generation'];

		$aStale = TPFW_Qr_Rewrite::process_batch(93, 'ticket', $iOldGen, $this->wpdb, function() {
			$this->fail('stale generation must not write');
		});
		$this->assertTrue($aStale['ok']);
		$this->assertSame('stale_generation', $aStale['reason']);
		$aStored = TPFW_Qr_Rewrite::get_job(93, 'ticket');
		$this->assertSame($iNewGen, (int) $aStored['generation']);
		$this->assertSame(0, (int) $aStored['cursor_id']);
		$this->assertSame($sNew, $aStored['target']);
	}

	public function test_repeat_saves_do_not_duplicate_waiting_or_running_jobs(): void
	{
		$this->insertTicket(94, 'dupA');
		$aPending = array();
		$this->bindPendingSchedule($aPending);
		$sKey = hash('sha256', 'no-dup');
		$aJob = TPFW_Qr_Rewrite::enqueue_if_needed(94, 'ticket', '', $sKey);
		$this->assertSame('queued', $aJob['status']);
		$this->assertCount(1, $aPending);
		$aFirstArgs = $aPending[0];

		$this->assertNull(TPFW_Qr_Rewrite::enqueue_if_needed(94, 'ticket', $sKey, $sKey));
		$this->assertNull(TPFW_Qr_Rewrite::enqueue_if_needed(94, 'ticket', $sKey, $sKey));
		$this->assertCount(1, $aPending);
		$this->assertSame($aFirstArgs, $aPending[0]);
		$this->assertSame(array(94, 'ticket', (int) $aJob['generation']), $aPending[0]);

		$aRun = TPFW_Qr_Rewrite::get_job(94, 'ticket');
		$aRun['status'] = 'running';
		TPFW_Qr_Rewrite::$aJobsOverride[TPFW_Qr_Rewrite::job_key(94, 'ticket')] = $aRun;
		$this->assertNull(TPFW_Qr_Rewrite::enqueue_if_needed(94, 'ticket', $sKey, $sKey));
		$this->assertCount(1, $aPending);
		$this->assertSame('running', TPFW_Qr_Rewrite::get_job(94, 'ticket')['status']);
		$this->assertSame((int) $aJob['generation'], (int) TPFW_Qr_Rewrite::get_job(94, 'ticket')['generation']);
	}

	public function test_legacy_three_argument_batch_still_runs(): void
	{
		$this->insertTicket(95, 'legacyA');
		$aPending = array();
		$this->bindPendingSchedule($aPending);
		$sKey = hash('sha256', 'legacy-args');
		$aJob = TPFW_Qr_Rewrite::enqueue(95, 'ticket', $sKey);
		$iGen = (int) $aJob['generation'];
		$this->assertCount(1, $aPending);
		$this->assertSame(array(95, 'ticket', $iGen), $aPending[0]);
		$this->assertCount(3, $aPending[0]);

		TPFW_Qr_Rewrite::$fnSetIssuedKey = function() {};
		$aArgs = $aPending[0];
		array_shift($aPending);
		$aDone = TPFW_Qr_Rewrite::process_batch((int) $aArgs[0], (string) $aArgs[1], (int) $aArgs[2], $this->wpdb, function() {
			return true;
		});
		$this->assertTrue($aDone['ok']);
		$this->assertSame('done', $aDone['reason']);
		$this->assertSame('done', TPFW_Qr_Rewrite::get_job(95, 'ticket')['status']);

		$oRef = new ReflectionMethod(TPFW_Qr_Rewrite::class, 'process_batch');
		$aParams = $oRef->getParameters();
		$this->assertTrue($aParams[5]->isDefaultValueAvailable());
		$this->assertSame(0, $aParams[5]->getDefaultValue());
		$sFn = file_get_contents(TPFW_PLUGIN_DIR.'inc/functions/class--functions.php');
		$this->assertNotFalse(strpos($sFn, 'function run_qr_rewrite_batch($iProductID, $sType, $iGeneration, $iRecovery = 0)'));
		$this->assertNotFalse(strpos($sFn, "add_action(TPFW_Qr_Rewrite::HOOK_BATCH, array(\$this, 'run_qr_rewrite_batch'), 10, 4)"));
	}

	/**
	 * @param array<int,array<int,int|string>> $aPending
	 * @param bool                             $bFail
	 * @return void
	 */
	private function bindPendingSchedule(array &$aPending, $bFail = false)
	{
		TPFW_Qr_Rewrite::$fnSchedule = function($sHook, $aArgs) use (&$aPending, $bFail) {
			if($bFail)
			{
				return false;
			}
			if((string) $sHook === TPFW_Qr_Rewrite::HOOK_BATCH)
			{
				$aPending[] = $aArgs;
			}
			return true;
		};
		TPFW_Qr_Rewrite::$fnHasScheduled = function($iPid, $sType, $iGen) use (&$aPending) {
			foreach($aPending as $aArgs)
			{
				if((int) $aArgs[0] === (int) $iPid
					&& (string) $aArgs[1] === (string) $sType
					&& (int) $aArgs[2] === (int) $iGen)
				{
					return true;
				}
			}
			return false;
		};
	}

	/**
	 * @param array<int,array<int,int|string>> $aPending
	 * @param object                           $wpdb
	 * @param callable                         $fnWrite
	 * @return array<string,mixed>
	 */
	private function runBookedBatch(array &$aPending, $wpdb, $fnWrite)
	{
		$this->assertNotSame(array(), $aPending);
		$aArgs = array_shift($aPending);
		$iRec  = isset($aArgs[3]) ? (int) $aArgs[3] : 0;
		return TPFW_Qr_Rewrite::process_batch(
			(int) $aArgs[0],
			(string) $aArgs[1],
			(int) $aArgs[2],
			$wpdb,
			$fnWrite,
			$iRec
		);
	}

	/**
	 * @param array<int,array<int,int|string>> $aPending
	 * @param object                           $wpdb
	 * @param callable                         $fnWrite
	 * @param array<int,int>                   $aRecoveries
	 * @return array<string,mixed>
	 */
	private function drainBookedBatches(array &$aPending, $wpdb, $fnWrite, array &$aRecoveries)
	{
		$aLast  = null;
		$iGuard = 0;
		while($aPending !== array())
		{
			$iGuard++;
			if($iGuard > 20)
			{
				$this->fail('retry drain did not stop');
			}
			$aLast = $this->runBookedBatch($aPending, $wpdb, $fnWrite);
			if($aPending === array())
			{
				break;
			}
			$this->assertCount(1, $aPending);
			$this->assertArrayHasKey(3, $aPending[0]);
			$aRecoveries[] = (int) $aPending[0][3];
		}
		$this->assertSame(TPFW_Qr_Rewrite::MAX_ATTEMPTS, $iGuard);
		return $aLast;
	}

	/**
	 * @param int $iOkCount
	 * @return TPFW_Failing_Wpdb
	 */
	private function wpdbFailingGetLockAfter($iOkCount)
	{
		$i = 0;
		return new TPFW_Failing_Wpdb($this->wpdb, function($sSql) use (&$i, $iOkCount) {
			if(!str_contains($sSql, 'GET_LOCK'))
			{
				return false;
			}
			$i++;
			return $i > $iOkCount;
		});
	}

	/**
	 * @param callable $fnShouldFail
	 * @return TPFW_Failing_Wpdb
	 */
	private function wpdbFailingKvWrite($fnShouldFail)
	{
		return new TPFW_Failing_Wpdb($this->wpdb, function($sSql) use ($fnShouldFail) {
			if(!str_contains($sSql, 'tpfw_kv'))
			{
				return false;
			}
			if(strpos($sSql, 'INSERT') === false)
			{
				return false;
			}
			return (bool) $fnShouldFail();
		});
	}

	/**
	 * @return void
	 */
	private function useKvStore()
	{
		TPFW_Qr_Rewrite::$aJobsOverride = null;
		TPFW_Qr_Rewrite::$bKvStore      = true;
		TPFW_Qr_Rewrite::$wpdbOverride  = $this->wpdb;
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
