<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * When plugin rows should be minted or revoked.
 *
 * Automatic mint is an event, not a payment-method list:
 *   payment_complete — WooCommerce says the order is paid
 *   completed        — merchant (or a gateway) moved the order to completed
 *   force            — admin Create metabox
 *
 * `processing` alone never mints. Offline checkouts that only reach processing
 * wait until completed (or Create).
 *
 * One policy for ticket, timeslot and pass.
 */
class TPFW_Issue_Policy
{
	const PAYMENT_COMPLETE = 'payment_complete';
	const COMPLETED        = 'completed';
	const FORCE            = 'force';
	const PROCESSING       = 'processing';

	/**
	 * @param string $sIntent One of the INTENT constants.
	 * @param string $sStatus Optional current order status (wc- prefix ok). Revoked statuses never mint.
	 * @return bool
	 */
	public static function should_issue_for_intent($sIntent, $sStatus = '')
	{
		if($sIntent === self::FORCE)
		{
			return true;
		}
		if($sStatus !== '' && self::should_revoke($sStatus))
		{
			return false;
		}
		return in_array($sIntent, array(self::PAYMENT_COMPLETE, self::COMPLETED), true);
	}

	/**
	 * Status-only helper. A bare processing/pending/on-hold string is not a mint event.
	 *
	 * @param string $sStatus
	 * @return bool
	 */
	public static function should_issue($sStatus)
	{
		return self::normalize_status($sStatus) === 'completed';
	}

	/**
	 * @param string $sStatus WooCommerce order status, with or without the wc- prefix.
	 * @return bool
	 */
	public static function should_revoke($sStatus)
	{
		return in_array(self::normalize_status($sStatus), array('cancelled', 'refunded', 'failed'), true);
	}

	/**
	 * @param mixed $mStatus
	 * @return string
	 */
	public static function normalize_status($mStatus)
	{
		$s = strtolower((string)$mStatus);
		if(strpos($s, 'wc-') === 0)
		{
			$s = substr($s, 3);
		}
		return $s;
	}
}
