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
	 * Assigns unique guest_slot values to every guest row, including soft-deleted ones.
	 *
	 * Per parent, existing slots are kept; NULL/0 rows get the next free slot in id ASC order.
	 * Safe to run more than once. Returns false on a database error and does not invent success.
	 *
	 * @param object $wpdb Database handle.
	 * @return bool
	 */
	public static function backfill_legacy_slots($wpdb)
	{
		if(is_object($wpdb) && property_exists($wpdb, 'last_error'))
		{
			$wpdb->last_error = '';
		}
		$sTable = $wpdb->prefix.'tpfw_pass';
		$aGuests = $wpdb->get_results($wpdb->prepare(
			'SELECT id, parent_nano_id_fk, guest_slot FROM %i
			WHERE parent_nano_id_fk IS NOT NULL AND parent_nano_id_fk != %s
			ORDER BY parent_nano_id_fk ASC, id ASC',
			$sTable,
			''
		));
		if(is_object($wpdb) && !empty($wpdb->last_error))
		{
			return false;
		}
		$aByParent = array();
		foreach((array)$aGuests as $oGuest)
		{
			$sParent = (string)$oGuest->parent_nano_id_fk;
			if(!isset($aByParent[$sParent]))
			{
				$aByParent[$sParent] = array();
			}
			$aByParent[$sParent][] = $oGuest;
		}
		foreach($aByParent as $sParent => $aRows)
		{
			if(!self::assign_slots_for_parent($wpdb, $sTable, $sParent, $aRows))
			{
				return false;
			}
		}
		return true;
	}

	/**
	 * @param object      $wpdb
	 * @param string      $sTable
	 * @param string      $sParent
	 * @param object[]|null $aRows Optional preloaded rows (id, guest_slot).
	 * @return bool
	 */
	public static function assign_slots_for_parent($wpdb, $sTable, $sParent, $aRows = null)
	{
		if($aRows === null)
		{
			$aRows = $wpdb->get_results($wpdb->prepare(
				'SELECT id, guest_slot FROM %i WHERE parent_nano_id_fk = %s ORDER BY id ASC',
				$sTable,
				$sParent
			));
			if(is_object($wpdb) && !empty($wpdb->last_error))
			{
				return false;
			}
		}
		$aClaimed = array();
		foreach((array)$aRows as $oRow)
		{
			$iSlot = (int)$oRow->guest_slot;
			if($iSlot > 0 && !isset($aClaimed[$iSlot]))
			{
				$aClaimed[$iSlot] = (int)$oRow->id;
			}
		}
		$iNext = 1;
		foreach((array)$aRows as $oRow)
		{
			$iId   = (int)$oRow->id;
			$iSlot = (int)$oRow->guest_slot;
			if($iSlot > 0 && isset($aClaimed[$iSlot]) && $aClaimed[$iSlot] === $iId)
			{
				continue;
			}
			while(isset($aClaimed[$iNext]))
			{
				$iNext++;
			}
			$m = $wpdb->query($wpdb->prepare(
				'UPDATE %i SET guest_slot = %d WHERE id = %d',
				$sTable,
				$iNext,
				$iId
			));
			if($m === false)
			{
				return false;
			}
			$aClaimed[$iNext] = $iId;
			$iNext++;
		}
		return true;
	}

	/**
	 * Reconciles guest rows to the current product quota.
	 *
	 * Slots 1…N are made live (reusing legacy nano ids, including previously deleted rows).
	 * Overflow stays or becomes soft-deleted. Missing allowed slots are inserted. Never
	 * derived from how many historical rows exist.
	 *
	 * @param object $wpdb      Database handle.
	 * @param object $oParent   Parent pass row.
	 * @param int    $iQuota    Guest quantity from current product meta.
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
			if(!self::assign_slots_for_parent($wpdb, $sTable, $sParent))
			{
				return $this->list_guests($wpdb, $sTable, $sParent, (int)$oParent->user_id);
			}

			$sNow = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
			if($iQuota > 0)
			{
				$mUndelete = $wpdb->query($wpdb->prepare(
					'UPDATE %i SET deleted = NULL, updated = %s
					WHERE parent_nano_id_fk = %s AND guest_slot >= 1 AND guest_slot <= %d',
					array($sTable, $sNow, $sParent, $iQuota)
				));
				if(TPFW_Db_Write::failed($mUndelete))
				{
					return $this->list_guests($wpdb, $sTable, $sParent, (int)$oParent->user_id);
				}
			}

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
				if(TPFW_Db_Write::failed($wpdb->query($oPrepared)))
				{
					return $this->list_guests($wpdb, $sTable, $sParent, (int)$oParent->user_id);
				}
			}

			$wpdb->query($wpdb->prepare(
				'UPDATE %i SET deleted = %s, updated = %s
				WHERE parent_nano_id_fk = %s AND deleted IS NULL
				AND (guest_slot IS NULL OR guest_slot < 1 OR guest_slot > %d)',
				array($sTable, $sNow, $sNow, $sParent, $iQuota)
			));
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
