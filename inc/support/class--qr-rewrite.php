<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Background rewrite of issued QR images after appearance or renderer changes.
 *
 * Product save no longer walks every live row in the request. A job is queued only when the
 * appearance fingerprint (including TPFW_Qr_Render::RENDER_VERSION) disagrees with the last
 * successful rewrite, or when a previous job failed and can be resumed. Batches are bounded,
 * keyed by row id so deleted/revoked rows are never revived, and a newer fingerprint resets
 * the cursor so an in-flight job cannot finish on a stale look.
 */
class TPFW_Qr_Rewrite
{
	const OPTION_JOBS   = 'tpfw_qr_rewrite_jobs';
	const OPTION_RENDER = 'tpfw_qr_render_version';
	const HOOK_BATCH    = 'tpfw_rewrite_qr_batch';
	const HOOK_SWEEP    = 'tpfw_rewrite_qr_upgrade_sweep';
	const GROUP         = 'tpfw-qr-rewrite';
	const BATCH_SIZE    = 25;
	const MAX_ATTEMPTS  = 5;

	/** @var array<string,array<string,mixed>>|null */
	public static $aJobsOverride = null;

	/** @var int|null */
	public static $iBatchSizeOverride = null;

	/** @var callable|null function(string $sHook, array $aArgs): void */
	public static $fnSchedule = null;

	/** @var callable|null function(int $iProductID, string $sType, int $iGeneration): void */
	public static $fnUnschedule = null;

	/** @var callable|null function(int $iProductID, string $sType, string $sKey): void */
	public static $fnSetIssuedKey = null;

	/**
	 * @return void
	 */
	public static function reset_test_state()
	{
		self::$aJobsOverride      = null;
		self::$iBatchSizeOverride = null;
		self::$fnSchedule         = null;
		self::$fnUnschedule       = null;
		self::$fnSetIssuedKey     = null;
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
				return false;
			}
			if(($sStatus === 'queued' || $sStatus === 'running') && $sJobTarget !== $sTargetKey)
			{
				return true;
			}
		}
		return (string) $sIssuedKey !== $sTargetKey;
	}

	/**
	 * Atomically replace $sDest with $sTmp after a successful generation.
	 *
	 * The destination is left untouched when the temp file is missing, empty, or cannot be
	 * renamed into place, so a working QR is not destroyed by a failed rewrite.
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
			if($sTmp !== '' && is_file($sTmp))
			{
				@unlink($sTmp);
			}
			return false;
		}
		if(!@rename($sTmp, $sDest))
		{
			@unlink($sTmp);
			return false;
		}
		return true;
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
		if(self::$aJobsOverride !== null)
		{
			return self::$aJobsOverride;
		}
		$a = get_option(self::OPTION_JOBS, array());
		return is_array($a) ? $a : array();
	}

	/**
	 * @param array<string,array<string,mixed>> $aJobs
	 * @return void
	 */
	public static function save_jobs($aJobs)
	{
		if(self::$aJobsOverride !== null)
		{
			self::$aJobsOverride = $aJobs;
			return;
		}
		update_option(self::OPTION_JOBS, $aJobs, false);
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
	 * @return array<string,mixed>|null The job after the call, or null when nothing was queued.
	 */
	public static function enqueue_if_needed($iProductID, $sType, $sIssuedKey, $sTargetKey)
	{
		return self::needs_rewrite($sIssuedKey, $sTargetKey, self::get_job($iProductID, $sType))
			? self::enqueue($iProductID, $sType, $sTargetKey)
			: null;
	}

	/**
	 * Queue or refresh a rewrite for one product panel.
	 *
	 * Same fingerprint while a job is already queued/running is a no-op (repeated save).
	 * A new fingerprint bumps generation and resets the cursor. A failed job with the same
	 * target keeps the cursor and clears the attempt counter so it can resume.
	 *
	 * @param int    $iProductID
	 * @param string $sType
	 * @param string $sTargetKey
	 * @return array<string,mixed>|null The job after the call, or null when nothing was queued.
	 */
	public static function enqueue($iProductID, $sType, $sTargetKey)
	{
		$iProductID = (int) $iProductID;
		$sTargetKey = (string) $sTargetKey;
		if($iProductID < 1 || self::table_spec($sType) === null || $sTargetKey === '')
		{
			return null;
		}

		$aJobs   = self::load_jobs();
		$sKey    = self::job_key($iProductID, $sType);
		$aJob    = isset($aJobs[$sKey]) && is_array($aJobs[$sKey]) ? $aJobs[$sKey] : array();
		$sStatus = (string) ($aJob['status'] ?? '');
		$sPrev   = (string) ($aJob['target'] ?? '');
		$iGen    = (int) ($aJob['generation'] ?? 0);

		if(($sStatus === 'queued' || $sStatus === 'running') && $sPrev === $sTargetKey)
		{
			return $aJob;
		}

		if($sStatus === 'failed' && $sPrev === $sTargetKey)
		{
			$aJob['status']     = 'queued';
			$aJob['attempts']   = 0;
			$aJob['last_error'] = '';
			$aJobs[$sKey]       = $aJob;
			self::save_jobs($aJobs);
			self::schedule($iProductID, $sType, (int) $aJob['generation']);
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
		$aJobs[$sKey] = $aJob;
		self::save_jobs($aJobs);
		self::schedule($iProductID, $sType, $iGen);
		return $aJob;
	}

	/**
	 * One bounded page of live rows. Never selects deleted rows.
	 *
	 * @param object $wpdb
	 * @param int    $iProductID
	 * @param string $sType
	 * @param int    $iCursorId
	 * @param int    $iLimit
	 * @return array<int,object>
	 */
	public static function fetch_batch($wpdb, $iProductID, $sType, $iCursorId, $iLimit)
	{
		$aSpec = self::table_spec($sType);
		if($aSpec === null || empty($wpdb))
		{
			return array();
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
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sSql is $wpdb->prepare() above; $sExtra is a literal from table_spec().
		$aRows = $wpdb->get_results($sSql);
		return is_array($aRows) ? $aRows : array();
	}

	/**
	 * @param int      $iProductID
	 * @param string   $sType
	 * @param int      $iGeneration
	 * @param object   $wpdb
	 * @param callable $fnWrite function(int $iProductID, string $sType, string $sNanoID): bool
	 * @return array<string,mixed> Result snapshot for tests and logging.
	 */
	public static function process_batch($iProductID, $sType, $iGeneration, $wpdb, $fnWrite)
	{
		$iProductID  = (int) $iProductID;
		$iGeneration = (int) $iGeneration;
		$aJob        = self::get_job($iProductID, $sType);
		if($aJob === null)
		{
			return array('ok' => true, 'reason' => 'no_job', 'written' => 0);
		}
		if((int) ($aJob['generation'] ?? 0) !== $iGeneration)
		{
			return array('ok' => true, 'reason' => 'stale_generation', 'written' => 0);
		}

		$aJob['status'] = 'running';
		self::put_job($iProductID, $sType, $aJob);

		$aRows    = self::fetch_batch($wpdb, $iProductID, $sType, (int) ($aJob['cursor_id'] ?? 0), self::batch_size());
		$iWritten = 0;
		foreach($aRows as $oRow)
		{
			$aFresh = self::get_job($iProductID, $sType);
			if($aFresh === null || (int) ($aFresh['generation'] ?? 0) !== $iGeneration)
			{
				return array('ok' => true, 'reason' => 'superseded', 'written' => $iWritten);
			}
			$sNano = isset($oRow->nano_id) ? (string) $oRow->nano_id : '';
			$iId   = isset($oRow->id) ? (int) $oRow->id : 0;
			if($sNano === '' || $iId < 1)
			{
				continue;
			}
			$bOk = false;
			try
			{
				$bOk = (bool) $fnWrite($iProductID, $sType, $sNano);
			}
			catch(\Throwable $oThrowable)
			{
				$bOk = false;
				$aJob['last_error'] = $oThrowable->getMessage();
			}
			if(!$bOk)
			{
				$aJob['attempts'] = (int) ($aJob['attempts'] ?? 0) + 1;
				$aJob['status']   = ($aJob['attempts'] >= self::MAX_ATTEMPTS) ? 'failed' : 'queued';
				if(($aJob['last_error'] ?? '') === '')
				{
					$aJob['last_error'] = 'write_failed';
				}
				self::put_job($iProductID, $sType, $aJob);
				if($aJob['status'] === 'queued')
				{
					self::schedule($iProductID, $sType, $iGeneration);
				}
				return array(
					'ok'      => false,
					'reason'  => $aJob['status'],
					'written' => $iWritten,
					'error'   => (string) $aJob['last_error'],
				);
			}
			$aJob['cursor_id']  = $iId;
			$aJob['last_error'] = '';
			$aJob['attempts']   = 0;
			self::put_job($iProductID, $sType, $aJob);
			$iWritten++;
		}

		if(count($aRows) < self::batch_size())
		{
			$aJob['status'] = 'done';
			self::put_job($iProductID, $sType, $aJob);
			if(is_callable(self::$fnSetIssuedKey))
			{
				(self::$fnSetIssuedKey)($iProductID, $sType, (string) $aJob['target']);
			}
			elseif(function_exists('update_post_meta'))
			{
				update_post_meta($iProductID, '_tpfw_'.$sType.'_qr_issued_key', (string) $aJob['target']);
			}
			return array('ok' => true, 'reason' => 'done', 'written' => $iWritten);
		}

		$aJob['status'] = 'queued';
		self::put_job($iProductID, $sType, $aJob);
		self::schedule($iProductID, $sType, $iGeneration);
		return array('ok' => true, 'reason' => 'continue', 'written' => $iWritten);
	}

	/**
	 * Product ids that still have live issued rows, for the post-upgrade repair sweep.
	 *
	 * @param object $wpdb
	 * @return array<int,array{product_id:int,type:string}>
	 */
	public static function live_product_types($wpdb)
	{
		$aOut = array();
		if(empty($wpdb))
		{
			return $aOut;
		}
		foreach(array('ticket', 'timeslot', 'pass', 'guestpass') as $sType)
		{
			$aSpec = self::table_spec($sType);
			$sSql  = $wpdb->prepare(
				'SELECT DISTINCT product_id FROM %i WHERE deleted IS NULL'.$aSpec['extra'],
				$wpdb->prefix.$aSpec['table']
			);
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sSql is $wpdb->prepare(); extra is a literal.
			$aRows = $wpdb->get_results($sSql);
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
		return $aOut;
	}

	/**
	 * Human-readable failures for wp-admin. Empty when nothing is stuck.
	 *
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
	 * @return void
	 */
	public static function schedule($iProductID, $sType, $iGeneration)
	{
		$aArgs = array((int) $iProductID, (string) $sType, (int) $iGeneration);
		if(is_callable(self::$fnSchedule))
		{
			(self::$fnSchedule)(self::HOOK_BATCH, $aArgs);
			return;
		}
		if(function_exists('as_enqueue_async_action'))
		{
			as_enqueue_async_action(self::HOOK_BATCH, $aArgs, self::GROUP);
			return;
		}
		if(function_exists('wp_schedule_single_event'))
		{
			wp_schedule_single_event(time(), self::HOOK_BATCH, $aArgs);
		}
	}

	/**
	 * @return void
	 */
	public static function schedule_sweep()
	{
		if(is_callable(self::$fnSchedule))
		{
			(self::$fnSchedule)(self::HOOK_SWEEP, array());
			return;
		}
		if(function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::HOOK_SWEEP, null, self::GROUP))
		{
			return;
		}
		if(function_exists('as_enqueue_async_action'))
		{
			as_enqueue_async_action(self::HOOK_SWEEP, array(), self::GROUP);
			return;
		}
		if(function_exists('wp_next_scheduled') && wp_next_scheduled(self::HOOK_SWEEP))
		{
			return;
		}
		if(function_exists('wp_schedule_single_event'))
		{
			wp_schedule_single_event(time(), self::HOOK_SWEEP);
		}
	}

	/**
	 * @param int    $iProductID
	 * @param string $sType
	 * @param int    $iGeneration
	 * @return void
	 */
	public static function unschedule($iProductID, $sType, $iGeneration)
	{
		$aArgs = array((int) $iProductID, (string) $sType, (int) $iGeneration);
		if(is_callable(self::$fnUnschedule))
		{
			(self::$fnUnschedule)($iProductID, $sType, $iGeneration);
			return;
		}
		if(function_exists('as_unschedule_all_actions'))
		{
			as_unschedule_all_actions(self::HOOK_BATCH, $aArgs, self::GROUP);
		}
		if(function_exists('wp_clear_scheduled_hook'))
		{
			wp_clear_scheduled_hook(self::HOOK_BATCH, $aArgs);
		}
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
	 * @param int                 $iProductID
	 * @param string              $sType
	 * @param array<string,mixed> $aJob
	 * @return void
	 */
	private static function put_job($iProductID, $sType, $aJob)
	{
		$aJobs = self::load_jobs();
		$aJobs[self::job_key($iProductID, $sType)] = $aJob;
		self::save_jobs($aJobs);
	}
}
