<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Mints guest passes up to a quota, with the quota enforced in the database.
 *
 * Slots 1…N plus UNIQUE(parent_nano_id_fk, guest_slot) mean two parallel AJAX calls cannot
 * create 2N rows. Guests are minted with a NULL validity window and stay refused at the door
 * until the parent pass has a stats row and activate_guest_passes() has opened the window.
 */
class TPFW_Guest_Pass_Issuer
{
	/** @var callable */
	private $fnNanoId;

	/**
	 * @param callable|null $fnNanoId Optional nano-id factory.
	 */
	public function __construct($fnNanoId = null)
	{
		$this->fnNanoId = $fnNanoId ?: array(__CLASS__, 'generate_nano_id');
	}

	/**
	 * @return string
	 */
	public static function generate_nano_id()
	{
		$sAlphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$iLen      = strlen($sAlphabet);
		$sOut      = '';
		for($i = 0; $i < 21; $i++)
		{
			$sOut .= $sAlphabet[random_int(0, $iLen - 1)];
		}
		return $sOut;
	}

	/**
	 * Whether a guest pass may be checked in.
	 *
	 * @param object $oGuest            Guest row.
	 * @param bool   $bParentExists     Parent pass row is live.
	 * @param int    $iParentStatsUses  Non-deleted stats rows on the parent.
	 * @return string 'ok', 'no_parent', 'parent_not_checked_in' or 'inactive'.
	 */
	public static function may_checkin($oGuest, $bParentExists, $iParentStatsUses)
	{
		if(!$bParentExists)
		{
			return 'no_parent';
		}
		if((int)$iParentStatsUses < 1)
		{
			return 'parent_not_checked_in';
		}
		$sFrom = isset($oGuest->valid_from) ? $oGuest->valid_from : null;
		$sTo   = isset($oGuest->valid_to) ? $oGuest->valid_to : null;
		if($sFrom === null || $sFrom === '' || $sTo === null || $sTo === '')
		{
			return 'inactive';
		}
		return 'ok';
	}

	/**
	 * Ensures slots 1…$iQuota exist for the parent. Existing rows are left alone.
	 *
	 * @param object $wpdb      Database handle.
	 * @param object $oParent   Parent pass row.
	 * @param int    $iQuota    Guest quantity from product meta.
	 * @param int    $iDuration Stored on the row; the window itself stays NULL until parent check-in.
	 * @param int    $iMaxUses  Guest max uses.
	 * @return object[] Live guest rows after the call.
	 */
	public function ensure_quota($wpdb, $oParent, $iQuota, $iDuration, $iMaxUses)
	{
		$iQuota    = max(0, (int)$iQuota);
		$sTable    = $wpdb->prefix.'tpfw_pass';
		$sParent   = (string)$oParent->nano_id;
		$sLockName = 'tpfw_guest_'.$sParent;
		$oLock     = TPFW_Named_Lock::acquire($wpdb, $sLockName, 5);

		if(!$oLock->held())
		{
			return $this->list_guests($wpdb, $sTable, $sParent, (int)$oParent->user_id);
		}

		try
		{
			$sNow = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
			for($iSlot = 1; $iSlot <= $iQuota; $iSlot++)
			{
				$sNano = call_user_func($this->fnNanoId);
				$oPrepared = $wpdb->prepare(
					'INSERT IGNORE INTO %i (nano_id, parent_nano_id_fk, guest_slot, product_id, user_id, user_payer_id, order_id, order_line_id, firstname, lastname, valid_duration, valid_from, valid_to, max_uses, created, updated)
					VALUES (%s, %s, %d, %d, %d, %d, %d, %d, %s, %s, %d, NULL, NULL, %d, %s, %s);',
					array(
						$sTable,
						$sNano,
						$sParent,
						$iSlot,
						(int)$oParent->product_id,
						(int)$oParent->user_id,
						(int)$oParent->user_payer_id,
						(int)$oParent->order_id,
						(int)$oParent->order_line_id,
						function_exists('__') ? __('Guest', 'tickets-passes-for-woocommerce') : 'Guest',
						function_exists('__') ? __('Pass', 'tickets-passes-for-woocommerce') : 'Pass',
						(int)$iDuration,
						max(1, (int)$iMaxUses),
						$sNow,
						$sNow,
					)
				);
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oPrepared is the return value of $wpdb->prepare() above.
				$wpdb->query($oPrepared);
			}
		}
		finally
		{
			$oLock->release();
		}

		$this->open_window_if_parent_checked_in($wpdb, $sTable, $sParent, (int)$iDuration);
		return $this->list_guests($wpdb, $sTable, $sParent, (int)$oParent->user_id);
	}

	/**
	 * Opens still-inactive guest windows when the parent has already been checked in.
	 *
	 * Guests minted after the holder's first scan would otherwise stay NULL forever, because
	 * activate_guest_passes() only runs at parent check-in.
	 *
	 * @param object $wpdb
	 * @param string $sTable
	 * @param string $sParent
	 * @param int    $iDuration
	 * @return void
	 */
	private function open_window_if_parent_checked_in($wpdb, $sTable, $sParent, $iDuration)
	{
		$iUses = (int)$wpdb->get_var($wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE nano_id_fk = %s AND deleted IS NULL;',
			$wpdb->prefix.'tpfw_pass_stats', $sParent
		));
		if($iUses < 1 || (int)$iDuration <= 0)
		{
			return;
		}

		$sNow = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
		$sTo  = function_exists('current_time')
			? gmdate('Y-m-d H:i:s', current_time('timestamp') + (int)$iDuration)
			: gmdate('Y-m-d H:i:s', time() + (int)$iDuration);

		$wpdb->query($wpdb->prepare(
			'UPDATE %i SET valid_from = %s, valid_to = %s, updated = %s
			WHERE deleted IS NULL AND parent_nano_id_fk = %s AND valid_from IS NULL;',
			array($sTable, $sNow, $sTo, $sNow, $sParent)
		));
	}

	/**
	 * @param object $wpdb
	 * @param string $sTable
	 * @param string $sParent
	 * @param int    $iUserID
	 * @return object[]
	 */
	private function list_guests($wpdb, $sTable, $sParent, $iUserID)
	{
		$oPrepared = $wpdb->prepare(
			'SELECT * FROM %i WHERE user_id = %d AND parent_nano_id_fk = %s AND deleted IS NULL ORDER BY guest_slot ASC, id ASC;',
			array($sTable, $iUserID, $sParent)
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oPrepared is the return value of $wpdb->prepare() above.
		$aRows = $wpdb->get_results($oPrepared);
		return is_array($aRows) ? $aRows : array();
	}
}
