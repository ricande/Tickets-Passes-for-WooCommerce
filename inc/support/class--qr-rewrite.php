<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Background rewrite of issued QR images after appearance or renderer changes.
 *
 * Job state lives in one option but is only mutated under a MySQL named lock, after a
 * cache-busted reload. Older generations cannot persist cursor, status, target or issued-key
 * once a newer generation is stored. File publish for a rewrite uses the same lock and
 * generation check before replacing the destination.
 */
class TPFW_Qr_Rewrite
{
	const OPTION_JOBS   = 'tpfw_qr_rewrite_jobs';
	const OPTION_RENDER = 'tpfw_qr_render_version';
	const HOOK_BATCH    = 'tpfw_rewrite_qr_batch';
	const HOOK_SWEEP    = 'tpfw_rewrite_qr_upgrade_sweep';
	const GROUP         = 'tpfw-qr-rewrite';
	const LOCK_NAME     = 'tpfw_qr_rewrite_jobs';
	const KV_TABLE      = 'tpfw_kv';
	const BATCH_SIZE    = 25;
	const MAX_ATTEMPTS  = 5;

	/** @var array<string,array<string,mixed>>|null */
	public static $aJobsOverride = null;

	/** @var int|null */
	public static $iBatchSizeOverride = null;

	/** @var callable|null function(string $sHook, array $aArgs): mixed */
	public static $fnSchedule = null;

	/** @var callable|null function(int $iProductID, string $sType, int $iGeneration): void */
	public static $fnUnschedule = null;

	/** @var callable|null function(int $iProductID, string $sType, string $sKey): void */
	public static $fnSetIssuedKey = null;

	/** @var callable|null function(int $iProductID, string $sType, int $iGeneration): bool */
	public static $fnHasScheduled = null;

	/** @var object|null wpdb-like handle for GET_LOCK and optional kv storage. */
	public static $wpdbOverride = null;

	/** @var bool */
	public static $bKvStore = false;

	/** @var array{product_id:int,type:string,generation:int}|null */
	public static $aPublishContext = null;

	/**
	 * @return void
	 */
	public static function reset_test_state()
	{
		self::$aJobsOverride      = null;
		self::$iBatchSizeOverride = null;
		self::$fnSchedule         = null;
		self::$fnUnschedule       = null;
		self::$fnHasScheduled     = null;
		self::$fnSetIssuedKey     = null;
		self::$wpdbOverride       = null;
		self::$bKvStore           = false;
		self::$aPublishContext    = null;
	}

	/**
	 * @return int
	 */
	public static function batch_size()
	{
		return self::$iBatchSizeOverride !== null ? max(1, (int) self::$iBatchSizeOverride) : self::BATCH_SIZE;
	}

	/**
	 * @param string     $sIssuedKey Last successfully rewritten fingerprint, '' if never.
	 * @param string     $sTargetKey Fingerprint of the current settings + renderer.
	 * @param array|null $aJob       Current job row, or null.
	 * @return bool
	 */
	public static function needs_rewrite($sIssuedKey, $sTargetKey, $aJob)
	{
		$sTargetKey = (string) $sTargetKey;
		if($sTargetKey === '')
		{
			return false;
		}
		if(is_array($aJob))
		{
			$sStatus    = (string) ($aJob['status'] ?? '');
			$sJobTarget = (string) ($aJob['target'] ?? '');
			if($sStatus === 'failed')
			{
				return true;
			}
			if(($sStatus === 'queued' || $sStatus === 'running') && $sJobTarget === $sTargetKey)
			{
				return (string) ($aJob['last_error'] ?? '') !== '';
			}
			if(($sStatus === 'queued' || $sStatus === 'running') && $sJobTarget !== $sTargetKey)
			{
				return true;
			}
		}
		return (string) $sIssuedKey !== $sTargetKey;
	}

	/**
	 * Unique temp path on the same directory (hence filesystem) as the destination.
	 *
	 * @param string $sDest
	 * @return string
	 */
	public static function unique_temp_path($sDest)
	{
		$sDest = (string) $sDest;
		$sDir  = dirname($sDest);
		$sBase = basename($sDest);
		return $sDir.'/'.$sBase.'.'.bin2hex(random_bytes(8)).'.tmp';
	}

	/**
	 * Atomically replace $sDest with $sTmp after a successful generation.
	 *
	 * Only $sTmp is removed on failure. The destination is left untouched when the temp file
	 * is missing, empty, or cannot be renamed into place.
	 *
	 * @param string $sTmp  Temporary file that already holds the new image.
	 * @param string $sDest Final .webp path.
	 * @return bool
	 */
	public static function commit_generated_file($sTmp, $sDest)
	{
		$sTmp  = (string) $sTmp;
		$sDest = (string) $sDest;
		if($sTmp === '' || $sDest === '' || !is_file($sTmp) || filesize($sTmp) < 32)
		{
			self::unlink_own_temp($sTmp);
			return false;
		}
		clearstatcache(true, $sTmp);
		clearstatcache(true, $sDest);
		if(!@rename($sTmp, $sDest))
		{
			self::unlink_own_temp($sTmp);
			return false;
		}
		clearstatcache(true, $sDest);
		clearstatcache(true, $sTmp);
		return true;
	}

	/**
	 * Replace a QR file only when this rewrite generation is still current.
	 *
	 * @param string $sTmp
	 * @param string $sDest
	 * @param int    $iProductID
	 * @param string $sType
	 * @param int    $iGeneration
	 * @return bool
	 */
	public static function publish_rewrite_file($sTmp, $sDest, $iProductID, $sType, $iGeneration)
	{
		try
		{
			$bOk = self::with_jobs_lock(function() use ($sTmp, $sDest, $iProductID, $sType, $iGeneration) {
				$aJob = self::get_job($iProductID, $sType);
				if($aJob === null || (int) ($aJob['generation'] ?? 0) !== (int) $iGeneration)
				{
					self::unlink_own_temp($sTmp);
					return false;
				}
				return self::commit_generated_file($sTmp, $sDest);
			});
			return $bOk === true;
		}
		catch(\RuntimeException $oException)
		{
			self::unlink_own_temp($sTmp);
			return false;
		}
	}

	/**
	 * @param string $sType
	 * @return array{table:string,extra:string}|null
	 */
	public static function table_spec($sType)
	{
		if($sType === 'ticket')
		{
			return array('table' => 'tpfw_tickets', 'extra' => '');
		}
		if($sType === 'timeslot')
		{
			return array('table' => 'tpfw_timeslot_tickets', 'extra' => '');
		}
		if($sType === 'pass')
		{
			return array('table' => 'tpfw_pass', 'extra' => ' AND parent_nano_id_fk IS NULL');
		}
		if($sType === 'guestpass')
		{
			return array('table' => 'tpfw_pass', 'extra' => ' AND parent_nano_id_fk IS NOT NULL');
		}
		return null;
	}

	/**
	 * @param int    $iProductID
	 * @param string $sType
	 * @return string
	 */
	public static function job_key($iProductID, $sType)
	{
		return (int) $iProductID.':'.$sType;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function load_jobs()
	{
		if(self::$aJobsOverride !== null && !self::$bKvStore)
		{
			return self::$aJobsOverride;
		}
		if(self::$bKvStore)
		{
			return self::kv_read_jobs();
		}
		self::bust_jobs_cache();
		$a = get_option(self::OPTION_JOBS, array());
		return is_array($a) ? $a : array();
	}

	/**
	 * @param int    $iProductID
	 * @param string $sType
	 * @return array<string,mixed>|null
	 */
	public static function get_job($iProductID, $sType)
	{
		$aJobs = self::load_jobs();
		$sKey  = self::job_key($iProductID, $sType);
		return isset($aJobs[$sKey]) && is_array($aJobs[$sKey]) ? $aJobs[$sKey] : null;
	}

	/**
	 * Queue a rewrite only when the fingerprint or a failed/stale job requires it.
	 *
	 * @param int    $iProductID
	 * @param string $sType
	 * @param string $sIssuedKey
	 * @param string $sTargetKey
	 * @return array<string,mixed>|false|null The job, false on lock/persist failure, null when nothing was queued.
	 */
	public static function enqueue_if_needed($iProductID, $sType, $sIssuedKey, $sTargetKey)
	{
		try
		{
			return self::with_jobs_lock(function() use ($iProductID, $sType, $sIssuedKey, $sTargetKey) {
				$aJob = self::get_job($iProductID, $sType);
				if(self::needs_rewrite($sIssuedKey, $sTargetKey, $aJob))
				{
					$aQueued = self::enqueue_locked($iProductID, $sType, $sTargetKey, $aJob);
					return $aQueued === null ? false : $aQueued;
				}
				if(self::is_inflight_same_target($aJob, $sTargetKey)
					&& !self::has_scheduled($iProductID, $sType, (int) ($aJob['generation'] ?? 0)))
				{
					return self::reschedule_locked($iProductID, $sType, $aJob);
				}
				return null;
			});
		}
		catch(\RuntimeException $oException)
		{
			return false;
		}
	}

	/**
	 * Queue or refresh a rewrite for one product panel.
	 *
	 * @param int    $iProductID
	 * @param string $sType
	 * @param string $sTargetKey
	 * @return array<string,mixed>|null
	 */
	public static function enqueue($iProductID, $sType, $sTargetKey)
	{
		$iProductID = (int) $iProductID;
		$sTargetKey = (string) $sTargetKey;
		if($iProductID < 1 || self::table_spec($sType) === null || $sTargetKey === '')
		{
			return null;
		}
		try
		{
			return self::with_jobs_lock(function() use ($iProductID, $sType, $sTargetKey) {
				return self::enqueue_locked($iProductID, $sType, $sTargetKey, self::get_job($iProductID, $sType));
			});
		}
		catch(\RuntimeException $oException)
		{
			return null;
		}
	}

	/**
	 * One bounded page of live rows. Never selects deleted rows.
	 *
	 * @param object $wpdb
	 * @param int    $iProductID
	 * @param string $sType
	 * @param int    $iCursorId
	 * @param int    $iLimit
	 * @return array{ok:bool, rows:array<int,object>, error?:string}
	 */
	public static function fetch_batch($wpdb, $iProductID, $sType, $iCursorId, $iLimit)
	{
		$aSpec = self::table_spec($sType);
		if($aSpec === null || empty($wpdb))
		{
			return array('ok' => false, 'rows' => array(), 'error' => 'bad_spec');
		}
		$iLimit    = max(1, (int) $iLimit);
		$iCursorId = max(0, (int) $iCursorId);
		$sTable    = $wpdb->prefix.$aSpec['table'];
		$sExtra    = $aSpec['extra'];
		$sSql      = $wpdb->prepare(
			'SELECT id, nano_id FROM %i WHERE product_id = %d AND deleted IS NULL'.$sExtra.' AND id > %d ORDER BY id ASC LIMIT %d',
			$sTable,
			(int) $iProductID,
			$iCursorId,
			$iLimit
		);
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sSql is $wpdb->prepare() above; $sExtra is a literal from table_spec().
		$aRows = $wpdb->get_results($sSql);
		if($aRows === false || (isset($wpdb->last_error) && $wpdb->last_error !== ''))
		{
			return array(
				'ok'    => false,
				'rows'  => array(),
				'error' => (string) ($wpdb->last_error !== '' ? $wpdb->last_error : 'query_failed'),
			);
		}
		return array('ok' => true, 'rows' => is_array($aRows) ? $aRows : array());
	}

	/**
	 * @param int      $iProductID
	 * @param string   $sType
	 * @param int      $iGeneration
	 * @param object   $wpdb
	 * @param callable $fnWrite function(int $iProductID, string $sType, string $sNanoID): bool
	 * @param int      $iRecovery 0 for a normal or legacy run; raised only when job state could not be written.
	 * @return array<string,mixed>
	 */
	public static function process_batch($iProductID, $sType, $iGeneration, $wpdb, $fnWrite, $iRecovery = 0)
	{
		$iProductID  = (int) $iProductID;
		$iGeneration = (int) $iGeneration;
		$iRecovery   = max(0, (int) $iRecovery);
		$aPrev       = self::$aPublishContext;
		self::$aPublishContext = array(
			'product_id' => $iProductID,
			'type'       => $sType,
			'generation' => $iGeneration,
		);
		try
		{
			return self::process_batch_body($iProductID, $sType, $iGeneration, $wpdb, $fnWrite, $iRecovery);
		}
		finally
		{
			self::$aPublishContext = $aPrev;
		}
	}

	/**
	 * Product ids that still have live issued rows, for the post-upgrade repair sweep.
	 *
	 * @param object $wpdb
	 * @return array{ok:bool, items:array<int,array{product_id:int,type:string}>, error?:string}
	 */
	public static function live_product_types($wpdb)
	{
		$aOut = array();
		if(empty($wpdb))
		{
			return array('ok' => false, 'items' => array(), 'error' => 'no_wpdb');
		}
		foreach(array('ticket', 'timeslot', 'pass', 'guestpass') as $sType)
		{
			$aSpec = self::table_spec($sType);
			$sSql  = $wpdb->prepare(
				'SELECT DISTINCT product_id FROM %i WHERE deleted IS NULL'.$aSpec['extra'],
				$wpdb->prefix.$aSpec['table']
			);
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sSql is $wpdb->prepare(); extra is a literal.
			$aRows = $wpdb->get_results($sSql);
			if($aRows === false || (isset($wpdb->last_error) && $wpdb->last_error !== ''))
			{
				return array(
					'ok'    => false,
					'items' => array(),
					'error' => (string) ($wpdb->last_error !== '' ? $wpdb->last_error : 'query_failed'),
				);
			}
			if(!is_array($aRows))
			{
				continue;
			}
			foreach($aRows as $oRow)
			{
				$iPid = isset($oRow->product_id) ? (int) $oRow->product_id : 0;
				if($iPid > 0)
				{
					$aOut[] = array('product_id' => $iPid, 'type' => $sType);
				}
			}
		}
		return array('ok' => true, 'items' => $aOut);
	}

	/**
	 * Queue a rewrite for every live product/type after a renderer bump.
	 *
	 * A database error, persist failure or schedule failure leaves the sweep incomplete so
	 * RENDER_VERSION is not recorded as handled.
	 *
	 * @param object      $wpdb
	 * @param callable    $fnTargetKey   function(int $iProductID, string $sType): string
	 * @param callable    $fnIssuedKey   function(int $iProductID, string $sType): string
	 * @param callable    $fnMarkHandled function(): void Called only after a complete sweep.
	 * @return array{ok:bool, queued:int, error?:string}
	 */
	public static function upgrade_sweep($wpdb, $fnTargetKey, $fnIssuedKey, $fnMarkHandled)
	{
		$aLive = self::live_product_types($wpdb);
		if(empty($aLive['ok']))
		{
			return array('ok' => false, 'queued' => 0, 'error' => (string) ($aLive['error'] ?? 'query_failed'));
		}
		$iQueued = 0;
		$bOk     = true;
		foreach($aLive['items'] as $aItem)
		{
			$iPid    = (int) ($aItem['product_id'] ?? 0);
			$sType   = (string) ($aItem['type'] ?? '');
			$sTarget = (string) $fnTargetKey($iPid, $sType);
			$sIssued = (string) $fnIssuedKey($iPid, $sType);
			$mJob    = self::enqueue_if_needed($iPid, $sType, $sIssued, $sTarget);
			if($mJob === false)
			{
				$bOk = false;
				continue;
			}
			if(is_array($mJob))
			{
				$iQueued++;
				if(($mJob['status'] ?? '') === 'failed')
				{
					$bOk = false;
				}
			}
		}
		if(!$bOk)
		{
			return array('ok' => false, 'queued' => $iQueued, 'error' => 'incomplete');
		}
		if(is_callable($fnMarkHandled))
		{
			$fnMarkHandled();
		}
		return array('ok' => true, 'queued' => $iQueued);
	}

	/**
	 * @return string[]
	 */
	public static function failure_messages()
	{
		$aOut = array();
		foreach(self::load_jobs() as $sKey => $aJob)
		{
			if(!is_array($aJob) || (($aJob['status'] ?? '') !== 'failed'))
			{
				continue;
			}
			$aOut[] = sprintf(
				/* translators: 1: product_id:type job key, 2: last error */
				__('QR rewrite for %1$s stopped after repeated write failures (%2$s). Open the product and save it, or wait for the next repair sweep, to resume from the last successful code. Deleted tickets are skipped.', 'tickets-passes-for-woocommerce'),
				$sKey,
				(string) ($aJob['last_error'] ?? 'write_failed')
			);
		}
		return $aOut;
	}

	/**
	 * @param int    $iProductID
	 * @param string $sType
	 * @param int    $iGeneration
	 * @param int    $iRecovery 0 uses the legacy 3-argument hook payload.
	 * @return array<int,int|string>
	 */
	public static function batch_hook_args($iProductID, $sType, $iGeneration, $iRecovery = 0)
	{
		$aArgs = array((int) $iProductID, (string) $sType, (int) $iGeneration);
		if((int) $iRecovery > 0)
		{
			$aArgs[] = (int) $iRecovery;
		}
		return $aArgs;
	}

	/**
	 * @param int    $iProductID
	 * @param string $sType
	 * @param int    $iGeneration
	 * @param int    $iRecovery
	 * @return bool
	 */
	public static function schedule($iProductID, $sType, $iGeneration, $iRecovery = 0)
	{
		$aArgs = self::batch_hook_args($iProductID, $sType, $iGeneration, $iRecovery);
		if(is_callable(self::$fnSchedule))
		{
			return self::hook_schedule_ok((self::$fnSchedule)(self::HOOK_BATCH, $aArgs));
		}
		if(function_exists('as_enqueue_async_action'))
		{
			return self::action_id_ok(as_enqueue_async_action(self::HOOK_BATCH, $aArgs, self::GROUP));
		}
		if(function_exists('wp_schedule_single_event'))
		{
			return self::cron_schedule_ok(wp_schedule_single_event(time(), self::HOOK_BATCH, $aArgs));
		}
		return false;
	}

	/**
	 * @return bool
	 */
	public static function schedule_sweep()
	{
		if(is_callable(self::$fnSchedule))
		{
			return self::hook_schedule_ok((self::$fnSchedule)(self::HOOK_SWEEP, array()));
		}
		if(function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::HOOK_SWEEP, null, self::GROUP))
		{
			return true;
		}
		if(function_exists('as_enqueue_async_action'))
		{
			return self::action_id_ok(as_enqueue_async_action(self::HOOK_SWEEP, array(), self::GROUP));
		}
		if(function_exists('wp_next_scheduled') && wp_next_scheduled(self::HOOK_SWEEP))
		{
			return true;
		}
		if(function_exists('wp_schedule_single_event'))
		{
			return self::cron_schedule_ok(wp_schedule_single_event(time(), self::HOOK_SWEEP));
		}
		return false;
	}

	/**
	 * Action Scheduler returns a positive action ID on success and 0 on failure.
	 *
	 * @param mixed $mId
	 * @return bool
	 */
	public static function action_id_ok($mId)
	{
		return is_numeric($mId) && (int) $mId > 0;
	}

	/**
	 * WP-Cron returns true when the event was scheduled.
	 *
	 * @param mixed $m
	 * @return bool
	 */
	public static function cron_schedule_ok($m)
	{
		return $m === true;
	}

	/**
	 * @param mixed $m
	 * @return bool
	 */
	private static function hook_schedule_ok($m)
	{
		return $m !== false && $m !== 0 && $m !== '0';
	}

	/**
	 * @param int    $iProductID
	 * @param string $sType
	 * @param int    $iGeneration
	 * @return void
	 */
	public static function unschedule($iProductID, $sType, $iGeneration)
	{
		$iProductID  = (int) $iProductID;
		$sType       = (string) $sType;
		$iGeneration = (int) $iGeneration;
		if(is_callable(self::$fnUnschedule))
		{
			(self::$fnUnschedule)($iProductID, $sType, $iGeneration);
			return;
		}
		$aArgSets = array(self::batch_hook_args($iProductID, $sType, $iGeneration, 0));
		for($i = 1; $i <= self::MAX_ATTEMPTS; $i++)
		{
			$aArgSets[] = self::batch_hook_args($iProductID, $sType, $iGeneration, $i);
		}
		if(function_exists('as_unschedule_all_actions'))
		{
			foreach($aArgSets as $aArgs)
			{
				as_unschedule_all_actions(self::HOOK_BATCH, $aArgs, self::GROUP);
			}
		}
		if(function_exists('wp_clear_scheduled_hook'))
		{
			foreach($aArgSets as $aArgs)
			{
				wp_clear_scheduled_hook(self::HOOK_BATCH, $aArgs);
			}
		}
	}

	/**
	 * True when a batch for this generation is already pending or in progress.
	 *
	 * @param int    $iProductID
	 * @param string $sType
	 * @param int    $iGeneration
	 * @return bool
	 */
	public static function has_scheduled($iProductID, $sType, $iGeneration)
	{
		$iProductID  = (int) $iProductID;
		$sType       = (string) $sType;
		$iGeneration = (int) $iGeneration;
		if(is_callable(self::$fnHasScheduled))
		{
			return (bool) (self::$fnHasScheduled)($iProductID, $sType, $iGeneration);
		}
		if(is_callable(self::$fnSchedule))
		{
			return true;
		}
		$aArgSets = array(self::batch_hook_args($iProductID, $sType, $iGeneration, 0));
		for($i = 1; $i <= self::MAX_ATTEMPTS; $i++)
		{
			$aArgSets[] = self::batch_hook_args($iProductID, $sType, $iGeneration, $i);
		}
		if(function_exists('as_has_scheduled_action'))
		{
			foreach($aArgSets as $aArgs)
			{
				if(as_has_scheduled_action(self::HOOK_BATCH, $aArgs, self::GROUP))
				{
					return true;
				}
			}
		}
		if(function_exists('wp_next_scheduled'))
		{
			foreach($aArgSets as $aArgs)
			{
				if(wp_next_scheduled(self::HOOK_BATCH, $aArgs))
				{
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @return void
	 */
	public static function clear_scheduled()
	{
		if(function_exists('as_unschedule_all_actions'))
		{
			as_unschedule_all_actions(self::HOOK_BATCH, null, self::GROUP);
			as_unschedule_all_actions(self::HOOK_SWEEP, null, self::GROUP);
		}
		if(function_exists('wp_clear_scheduled_hook'))
		{
			wp_clear_scheduled_hook(self::HOOK_BATCH);
			wp_clear_scheduled_hook(self::HOOK_SWEEP);
		}
	}

	/**
	 * @param callable $fn
	 * @return mixed
	 */
	public static function with_jobs_lock($fn)
	{
		$wpdb = self::lock_wpdb();
		if($wpdb === null)
		{
			return $fn();
		}
		$oLock = TPFW_Named_Lock::acquire($wpdb, self::LOCK_NAME, 5);
		if(!$oLock->held())
		{
			throw new RuntimeException('qr_rewrite_lock');
		}
		try
		{
			self::bust_jobs_cache();
			return $fn();
		}
		finally
		{
			$oLock->release();
		}
	}

	/**
	 * @param int    $iProductID
	 * @param string $sType
	 * @param string $sTargetKey
	 * @param array<string,mixed>|null $aJob
	 * @return array<string,mixed>|null
	 */
	private static function enqueue_locked($iProductID, $sType, $sTargetKey, $aJob)
	{
		$aJob    = is_array($aJob) ? $aJob : array();
		$sStatus = (string) ($aJob['status'] ?? '');
		$sPrev   = (string) ($aJob['target'] ?? '');
		$iGen    = (int) ($aJob['generation'] ?? 0);

		if(($sStatus === 'queued' || $sStatus === 'running') && $sPrev === $sTargetKey)
		{
			if(self::has_scheduled($iProductID, $sType, $iGen))
			{
				return $aJob;
			}
			return self::reschedule_locked($iProductID, $sType, $aJob);
		}

		if($sStatus === 'failed' && $sPrev === $sTargetKey)
		{
			$aJob['status']     = 'queued';
			$aJob['attempts']   = 0;
			$aJob['last_error'] = '';
			if(!self::persist_job($iProductID, $sType, $aJob))
			{
				$aJob['status']     = 'failed';
				$aJob['last_error'] = 'persist_failed';
				return $aJob;
			}
			if(!self::schedule($iProductID, $sType, (int) $aJob['generation']))
			{
				$aJob['status']     = 'failed';
				$aJob['last_error'] = 'schedule_failed';
				self::persist_job($iProductID, $sType, $aJob);
			}
			return $aJob;
		}

		self::unschedule($iProductID, $sType, $iGen);
		$iGen++;
		$aJob = array(
			'generation' => $iGen,
			'target'     => $sTargetKey,
			'cursor_id'  => 0,
			'status'     => 'queued',
			'attempts'   => 0,
			'last_error' => '',
		);
		if(!self::persist_job($iProductID, $sType, $aJob))
		{
			return null;
		}
		if(!self::schedule($iProductID, $sType, $iGen))
		{
			$aJob['status']     = 'failed';
			$aJob['last_error'] = 'schedule_failed';
			self::persist_job($iProductID, $sType, $aJob);
		}
		return $aJob;
	}

	/**
	 * @param int      $iProductID
	 * @param string   $sType
	 * @param int      $iGeneration
	 * @param object   $wpdb
	 * @param callable $fnWrite
	 * @param int      $iRecovery
	 * @return array<string,mixed>
	 */
	private static function process_batch_body($iProductID, $sType, $iGeneration, $wpdb, $fnWrite, $iRecovery)
	{
		try
		{
			$aStart = self::apply_generation($iProductID, $sType, $iGeneration, function($aJob) {
				$aJob['status'] = 'running';
				return $aJob;
			});
		}
		catch(\RuntimeException $oException)
		{
			return self::recover_batch($iProductID, $sType, $iGeneration, 'lock_failed', 0, $iRecovery);
		}
		$sStartReason = (string) ($aStart['reason'] ?? 'no_job');
		if(empty($aStart['ok']) || $sStartReason === 'persist_failed' || $sStartReason === 'lock_failed')
		{
			return self::recover_batch(
				$iProductID,
				$sType,
				$iGeneration,
				(string) ($aStart['error'] ?? $sStartReason),
				0,
				$iRecovery
			);
		}
		if($sStartReason !== 'applied')
		{
			return array(
				'ok'      => true,
				'reason'  => $sStartReason,
				'written' => 0,
			);
		}
		$aJob     = $aStart['job'];
		$aFetched = self::fetch_batch($wpdb, $iProductID, $sType, (int) ($aJob['cursor_id'] ?? 0), self::batch_size());
		if(empty($aFetched['ok']))
		{
			return self::recover_batch($iProductID, $sType, $iGeneration, (string) ($aFetched['error'] ?? 'query_failed'), 0, $iRecovery);
		}
		$aRows    = $aFetched['rows'];
		$iWritten = 0;
		foreach($aRows as $oRow)
		{
			$sNano = isset($oRow->nano_id) ? (string) $oRow->nano_id : '';
			$iId   = isset($oRow->id) ? (int) $oRow->id : 0;
			if($sNano === '' || $iId < 1)
			{
				continue;
			}
			$bOk         = false;
			$sWriteError = '';
			try
			{
				$bOk = (bool) $fnWrite($iProductID, $sType, $sNano);
			}
			catch(\Throwable $oThrowable)
			{
				$bOk = false;
				$sWriteError = $oThrowable->getMessage();
			}
			try
			{
				$aAfter = self::apply_generation($iProductID, $sType, $iGeneration, function($aFresh) use ($bOk, $iId, $sWriteError) {
					if((int) ($aFresh['cursor_id'] ?? 0) >= $iId && $bOk)
					{
						return $aFresh;
					}
					if(!$bOk)
					{
						$aFresh['attempts']   = (int) ($aFresh['attempts'] ?? 0) + 1;
						$aFresh['status']     = ($aFresh['attempts'] >= self::MAX_ATTEMPTS) ? 'failed' : 'queued';
						$aFresh['last_error'] = (isset($sWriteError) && $sWriteError !== '') ? $sWriteError : 'write_failed';
						return $aFresh;
					}
					if($iId <= (int) ($aFresh['cursor_id'] ?? 0))
					{
						return $aFresh;
					}
					$aFresh['cursor_id']  = $iId;
					$aFresh['last_error'] = '';
					$aFresh['attempts']   = 0;
					$aFresh['status']     = 'running';
					return $aFresh;
				});
			}
			catch(\RuntimeException $oException)
			{
				return self::recover_batch($iProductID, $sType, $iGeneration, 'lock_failed', $iWritten, $iRecovery);
			}
			if(($aAfter['reason'] ?? '') === 'stale_generation' || ($aAfter['reason'] ?? '') === 'no_job')
			{
				return array('ok' => true, 'reason' => 'superseded', 'written' => $iWritten);
			}
			if(empty($aAfter['ok']))
			{
				return self::recover_batch(
					$iProductID,
					$sType,
					$iGeneration,
					(string) ($aAfter['error'] ?? 'persist_failed'),
					$iWritten,
					$iRecovery
				);
			}
			$aJob = $aAfter['job'];
			if(!$bOk)
			{
				if(($aJob['status'] ?? '') === 'queued' && !self::schedule($iProductID, $sType, $iGeneration))
				{
					return self::recover_batch($iProductID, $sType, $iGeneration, 'schedule_failed', $iWritten, $iRecovery);
				}
				return array(
					'ok'      => false,
					'reason'  => (string) ($aJob['status'] ?? 'failed'),
					'written' => $iWritten,
					'error'   => (string) ($aJob['last_error'] ?? 'write_failed'),
				);
			}
			$iWritten++;
		}

		return self::finish_page($iProductID, $sType, $iGeneration, $wpdb, $iWritten, $iRecovery);
	}

	/**
	 * @param int    $iProductID
	 * @param string $sType
	 * @param int    $iGeneration
	 * @param object $wpdb
	 * @param int    $iWritten
	 * @param int    $iRecovery
	 * @return array<string,mixed>
	 */
	private static function finish_page($iProductID, $sType, $iGeneration, $wpdb, $iWritten, $iRecovery)
	{
		try
		{
			$aEnd = self::apply_generation($iProductID, $sType, $iGeneration, function($aJob) use ($wpdb, $iProductID, $sType) {
				$aMore = self::fetch_batch($wpdb, $iProductID, $sType, (int) ($aJob['cursor_id'] ?? 0), 1);
				if(empty($aMore['ok']))
				{
					$aJob['attempts']   = (int) ($aJob['attempts'] ?? 0) + 1;
					$aJob['status']     = ($aJob['attempts'] >= self::MAX_ATTEMPTS) ? 'failed' : 'queued';
					$aJob['last_error'] = (string) ($aMore['error'] ?? 'query_failed');
					return $aJob;
				}
				if($aMore['rows'] === array())
				{
					self::store_issued_key($iProductID, $sType, (string) ($aJob['target'] ?? ''));
					$aJob['status']     = 'done';
					$aJob['last_error'] = '';
					return $aJob;
				}
				$aJob['status'] = 'queued';
				return $aJob;
			});
		}
		catch(\RuntimeException $oException)
		{
			return self::recover_batch($iProductID, $sType, $iGeneration, 'lock_failed', $iWritten, $iRecovery);
		}
		if(($aEnd['reason'] ?? '') === 'stale_generation' || ($aEnd['reason'] ?? '') === 'no_job')
		{
			return array('ok' => true, 'reason' => 'superseded', 'written' => $iWritten);
		}
		if(empty($aEnd['ok']))
		{
			return self::recover_batch(
				$iProductID,
				$sType,
				$iGeneration,
				(string) ($aEnd['error'] ?? 'persist_failed'),
				$iWritten,
				$iRecovery
			);
		}
		$aJob = $aEnd['job'];
		if(($aJob['status'] ?? '') === 'done')
		{
			return array('ok' => true, 'reason' => 'done', 'written' => $iWritten);
		}
		if(($aJob['status'] ?? '') === 'failed')
		{
			return array('ok' => false, 'reason' => 'failed', 'written' => $iWritten, 'error' => (string) ($aJob['last_error'] ?? 'query_failed'));
		}
		if(!self::schedule($iProductID, $sType, $iGeneration))
		{
			return self::recover_batch($iProductID, $sType, $iGeneration, 'schedule_failed', $iWritten, $iRecovery);
		}
		return array('ok' => true, 'reason' => 'continue', 'written' => $iWritten);
	}

	/**
	 * @param int    $iProductID
	 * @param string $sType
	 * @param int    $iGeneration
	 * @param string $sError
	 * @param int    $iWritten
	 * @param int    $iRecovery
	 * @return array<string,mixed>
	 */
	private static function recover_batch($iProductID, $sType, $iGeneration, $sError, $iWritten, $iRecovery)
	{
		$iRecovery = max(0, (int) $iRecovery);
		try
		{
			$aFail = self::apply_generation($iProductID, $sType, $iGeneration, function($aJob) use ($sError) {
				$aJob['attempts']   = (int) ($aJob['attempts'] ?? 0) + 1;
				$aJob['status']     = ($aJob['attempts'] >= self::MAX_ATTEMPTS) ? 'failed' : 'queued';
				$aJob['last_error'] = $sError;
				return $aJob;
			});
		}
		catch(\RuntimeException $oException)
		{
			return self::recover_unpersisted($iProductID, $sType, $iGeneration, $sError, $iWritten, $iRecovery, 'lock_failed');
		}
		if(($aFail['reason'] ?? '') === 'stale_generation' || ($aFail['reason'] ?? '') === 'no_job')
		{
			return array('ok' => true, 'reason' => 'superseded', 'written' => $iWritten);
		}
		if(empty($aFail['ok']))
		{
			return self::recover_unpersisted($iProductID, $sType, $iGeneration, $sError, $iWritten, $iRecovery, 'persist_failed');
		}
		$aJob = $aFail['job'] ?? array();
		if(($aJob['status'] ?? '') === 'queued' && !self::schedule($iProductID, $sType, $iGeneration, 0))
		{
			try
			{
				$aMark = self::apply_generation($iProductID, $sType, $iGeneration, function($aFresh) {
					$aFresh['status']     = 'failed';
					$aFresh['last_error'] = 'schedule_failed';
					return $aFresh;
				});
			}
			catch(\RuntimeException $oException)
			{
				return array('ok' => false, 'reason' => 'schedule_failed', 'written' => $iWritten, 'error' => 'schedule_failed');
			}
			if(($aMark['reason'] ?? '') === 'stale_generation' || ($aMark['reason'] ?? '') === 'no_job')
			{
				return array('ok' => true, 'reason' => 'superseded', 'written' => $iWritten);
			}
			if(empty($aMark['ok']) || ($aMark['reason'] ?? '') !== 'applied')
			{
				return array('ok' => false, 'reason' => 'persist_failed', 'written' => $iWritten, 'error' => 'schedule_failed');
			}
			return array('ok' => false, 'reason' => 'failed', 'written' => $iWritten, 'error' => 'schedule_failed');
		}
		return array(
			'ok'      => false,
			'reason'  => (string) ($aJob['status'] ?? 'failed'),
			'written' => $iWritten,
			'error'   => $sError,
		);
	}

	/**
	 * Book a same-generation retry whose attempt number lives in the hook arguments.
	 *
	 * @param int    $iProductID
	 * @param string $sType
	 * @param int    $iGeneration
	 * @param string $sError
	 * @param int    $iWritten
	 * @param int    $iRecovery
	 * @param string $sReason
	 * @return array<string,mixed>
	 */
	private static function recover_unpersisted($iProductID, $sType, $iGeneration, $sError, $iWritten, $iRecovery, $sReason)
	{
		if((int) $iRecovery + 1 >= self::MAX_ATTEMPTS)
		{
			return array(
				'ok'      => false,
				'reason'  => $sReason,
				'written' => $iWritten,
				'error'   => $sError,
			);
		}
		$bSched = self::schedule($iProductID, $sType, $iGeneration, (int) $iRecovery + 1);
		return array(
			'ok'      => false,
			'reason'  => $bSched ? $sReason : 'schedule_failed',
			'written' => $iWritten,
			'error'   => $bSched ? $sError : 'schedule_failed',
		);
	}

	/**
	 * Resume a same-generation job without moving the cursor.
	 *
	 * @param int                 $iProductID
	 * @param string              $sType
	 * @param array<string,mixed> $aJob
	 * @return array<string,mixed>
	 */
	private static function reschedule_locked($iProductID, $sType, $aJob)
	{
		$iGen = (int) ($aJob['generation'] ?? 0);
		if(!self::schedule($iProductID, $sType, $iGen, 0))
		{
			$aFail = $aJob;
			$aFail['status']     = 'failed';
			$aFail['last_error'] = 'schedule_failed';
			if(!self::persist_job($iProductID, $sType, $aFail))
			{
				return $aJob;
			}
			return $aFail;
		}
		$aNext = $aJob;
		$aNext['status']     = 'queued';
		$aNext['last_error'] = '';
		if(!self::persist_job($iProductID, $sType, $aNext))
		{
			return $aJob;
		}
		return $aNext;
	}

	/**
	 * @param array<string,mixed>|null $aJob
	 * @param string                   $sTargetKey
	 * @return bool
	 */
	private static function is_inflight_same_target($aJob, $sTargetKey)
	{
		if(!is_array($aJob))
		{
			return false;
		}
		$sStatus = (string) ($aJob['status'] ?? '');
		return ($sStatus === 'queued' || $sStatus === 'running')
			&& (string) ($aJob['target'] ?? '') === (string) $sTargetKey;
	}

	/**
	 * Reload, require generation, mutate and persist inside the jobs lock.
	 *
	 * @param int      $iProductID
	 * @param string   $sType
	 * @param int      $iGeneration
	 * @param callable $fn function(array $aJob): array
	 * @return array<string,mixed>
	 */
	private static function apply_generation($iProductID, $sType, $iGeneration, $fn)
	{
		return self::with_jobs_lock(function() use ($iProductID, $sType, $iGeneration, $fn) {
			$aJob = self::get_job($iProductID, $sType);
			if($aJob === null)
			{
				return array('ok' => true, 'reason' => 'no_job', 'job' => null);
			}
			if((int) ($aJob['generation'] ?? 0) !== (int) $iGeneration)
			{
				return array('ok' => true, 'reason' => 'stale_generation', 'job' => $aJob);
			}
			$aNext = $fn($aJob);
			if(!is_array($aNext))
			{
				return array('ok' => true, 'reason' => 'aborted', 'job' => $aJob);
			}
			if(!self::persist_job($iProductID, $sType, $aNext))
			{
				return array('ok' => false, 'reason' => 'persist_failed', 'job' => $aJob, 'error' => 'persist_failed');
			}
			return array('ok' => true, 'reason' => 'applied', 'job' => $aNext);
		});
	}

	/**
	 * @param int                 $iProductID
	 * @param string              $sType
	 * @param array<string,mixed> $aJob
	 * @return bool
	 */
	private static function persist_job($iProductID, $sType, $aJob)
	{
		$aJobs = self::load_jobs();
		$aJobs[self::job_key($iProductID, $sType)] = $aJob;
		return self::save_jobs($aJobs);
	}

	/**
	 * @param array<string,array<string,mixed>> $aJobs
	 * @return bool
	 */
	private static function save_jobs($aJobs)
	{
		if(self::$aJobsOverride !== null && !self::$bKvStore)
		{
			self::$aJobsOverride = $aJobs;
			return true;
		}
		if(self::$bKvStore)
		{
			return self::kv_write_jobs($aJobs);
		}
		$b = update_option(self::OPTION_JOBS, $aJobs, false);
		self::bust_jobs_cache();
		$aRead = get_option(self::OPTION_JOBS, null);
		return $b !== false || (is_array($aRead) && $aRead === $aJobs);
	}

	/**
	 * @return void
	 */
	private static function bust_jobs_cache()
	{
		if(function_exists('wp_cache_delete'))
		{
			wp_cache_delete(self::OPTION_JOBS, 'options');
		}
	}

	/**
	 * @return object|null
	 */
	private static function lock_wpdb()
	{
		if(self::$wpdbOverride !== null)
		{
			return self::$wpdbOverride;
		}
		return isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : null;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function kv_read_jobs()
	{
		$wpdb = self::$wpdbOverride;
		if($wpdb === null)
		{
			return array();
		}
		$wpdb->last_error = '';
		$sJson = $wpdb->get_var($wpdb->prepare(
			'SELECT v FROM %i WHERE k = %s LIMIT 1',
			$wpdb->prefix.self::KV_TABLE,
			self::OPTION_JOBS
		));
		if($wpdb->last_error !== '')
		{
			return array();
		}
		$a = is_string($sJson) ? json_decode($sJson, true) : array();
		return is_array($a) ? $a : array();
	}

	/**
	 * @param array<string,array<string,mixed>> $aJobs
	 * @return bool
	 */
	private static function kv_write_jobs($aJobs)
	{
		$wpdb = self::$wpdbOverride;
		if($wpdb === null)
		{
			return false;
		}
		$sJson = function_exists('wp_json_encode') ? wp_json_encode($aJobs) : json_encode($aJobs);
		if(!is_string($sJson))
		{
			return false;
		}
		$sTable = $wpdb->prefix.self::KV_TABLE;
		$m = $wpdb->query($wpdb->prepare(
			'INSERT INTO %i (k, v) VALUES (%s, %s) ON DUPLICATE KEY UPDATE v = %s',
			$sTable,
			self::OPTION_JOBS,
			$sJson,
			$sJson
		));
		return $m !== false;
	}

	/**
	 * @param string $sTmp
	 * @return void
	 */
	private static function unlink_own_temp($sTmp)
	{
		if($sTmp !== '' && is_file($sTmp))
		{
			@unlink($sTmp);
		}
	}

	/**
	 * @param int    $iProductID
	 * @param string $sType
	 * @param string $sTargetKey
	 * @return void
	 */
	private static function store_issued_key($iProductID, $sType, $sTargetKey)
	{
		if(is_callable(self::$fnSetIssuedKey))
		{
			(self::$fnSetIssuedKey)($iProductID, $sType, $sTargetKey);
			return;
		}
		if(function_exists('update_post_meta'))
		{
			update_post_meta($iProductID, '_tpfw_'.$sType.'_qr_issued_key', $sTargetKey);
		}
	}
}
