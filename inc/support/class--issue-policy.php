<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * When a paid order should mint or revoke plugin rows.
 *
 * Virtual/COD orders often sit in processing with stock already taken. Minting only on
 * completed left those orders without tickets. One policy for ticket, timeslot and pass.
 */
class TPFW_Issue_Policy
{
	/**
	 * @param string $sStatus WooCommerce order status, without the wc- prefix.
	 * @return bool
	 */
	public static function should_issue($sStatus)
	{
		return in_array((string)$sStatus, array('processing', 'completed'), true);
	}

	/**
	 * @param string $sStatus WooCommerce order status, without the wc- prefix.
	 * @return bool
	 */
	public static function should_revoke($sStatus)
	{
		return in_array((string)$sStatus, array('cancelled', 'refunded', 'failed'), true);
	}
}
