<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * MySQL named lock helper.
 *
 * GET_LOCK() returns '1' when held, '0' on timeout, and NULL when the server has no such
 * function. Anything other than a held lock is a failure: check-in and capacity writes must
 * not proceed without serialisation.
 */
class TPFW_Named_Lock
{
	/** @var object wpdb-like handle. */
	private $wpdb;

	/** @var string */
	private $sName;

	/** @var string|int|null Raw GET_LOCK() result. */
	private $mRaw;

	/** @var bool */
	private static $bLoggedUnavailable = false;

	/**
	 * @param object          $wpdb  Database handle with prepare() and get_var().
	 * @param string          $sName Lock name.
	 * @param string|int|null $mRaw  Raw GET_LOCK() result.
	 */
	private function __construct($wpdb, $sName, $mRaw)
	{
		$this->wpdb  = $wpdb;
		$this->sName = $sName;
		$this->mRaw  = $mRaw;
	}

	/**
	 * @param string|int|null $mRaw GET_LOCK() result.
	 * @return bool
	 */
	public static function is_acquired($mRaw)
	{
		return $mRaw === '1' || $mRaw === 1;
	}

	/**
	 * @param object $wpdb     Database handle with prepare() and get_var().
	 * @param string $sName    Lock name (no prefix added).
	 * @param int    $iTimeout Seconds to wait.
	 * @return TPFW_Named_Lock
	 */
	public static function acquire($wpdb, $sName, $iTimeout = 5)
	{
		$mRaw = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d);', $sName, (int)$iTimeout));
		$oLock = new self($wpdb, $sName, $mRaw);
		if(!self::is_acquired($mRaw) && $mRaw !== '0' && $mRaw !== 0)
		{
			self::log_unavailable_once();
		}
		return $oLock;
	}

	/**
	 * Logs once when GET_LOCK() returned neither held nor timeout - typically NULL.
	 *
	 * @return void
	 */
	public static function log_unavailable_once()
	{
		if(self::$bLoggedUnavailable)
		{
			return;
		}
		self::$bLoggedUnavailable = true;
		if(function_exists('error_log'))
		{
			error_log('TPFW: GET_LOCK() did not return 1 or 0; named locks are unavailable. Check-in and timeslot writes will refuse rather than race.');
		}
	}

	/**
	 * @return bool
	 */
	public function held()
	{
		return self::is_acquired($this->mRaw);
	}

	/**
	 * @return string|int|null
	 */
	public function raw()
	{
		return $this->mRaw;
	}

	/**
	 * @return void
	 */
	public function release()
	{
		if(!$this->held())
		{
			return;
		}
		$this->wpdb->get_var($this->wpdb->prepare('SELECT RELEASE_LOCK(%s);', $this->sName));
		$this->mRaw = '0';
	}
}
