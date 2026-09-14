<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Allowlisted scanner success body.
 *
 * The door UI needs a message, a colour, a type, a holder name, a photo URL, remaining
 * uses and the validity window. It does not need the raw database row (user_id, order_id,
 * payer, …).
 */
class TPFW_Checkin_Payload
{
	/** Keys a success body may carry. */
	const ALLOWED_KEYS = array(
		'sMessage',
		'sHexColor',
		'sType',
		'sHolderName',
		'sPhotoURL',
		'max_uses',
		'iUsesRemaining',
		'valid_from',
		'valid_to',
		'iCooldown',
		'iCooldownOver',
	);

	/**
	 * @param array $aFields Candidate fields.
	 * @return array
	 */
	public static function allowlist($aFields)
	{
		$aOut = array();
		foreach(self::ALLOWED_KEYS as $sKey)
		{
			if(array_key_exists($sKey, $aFields) && $aFields[$sKey] !== null && $aFields[$sKey] !== '')
			{
				$aOut[$sKey] = $aFields[$sKey];
			}
		}
		return $aOut;
	}

	/**
	 * @param object $oRow Ticket or pass row.
	 * @return string
	 */
	public static function holder_name($oRow)
	{
		$sFirst = isset($oRow->firstname) ? trim((string)$oRow->firstname) : '';
		$sLast  = isset($oRow->lastname) ? trim((string)$oRow->lastname) : '';
		return trim($sFirst.' '.$sLast);
	}

	/**
	 * @param array $aFields
	 * @return bool True when the payload leaked a raw row field.
	 */
	public static function leaks_row_fields($aFields)
	{
		foreach(array('user_id', 'order_id', 'user_payer_id', 'ticket', 'product_id', 'order_line_id') as $sKey)
		{
			if(array_key_exists($sKey, $aFields))
			{
				return true;
			}
		}
		return false;
	}
}
