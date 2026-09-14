<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * HMAC for signed file URLs, extracted so tests can sign and reject without WordPress.
 */
class TPFW_File_Token
{
	/**
	 * @param string $sType
	 * @param string $sName
	 * @param int    $iExpiry
	 * @param string $sSecret
	 * @return string
	 */
	public static function sign($sType, $sName, $iExpiry, $sSecret)
	{
		return hash_hmac('sha256', $sType.'|'.$sName.'|'.(int)$iExpiry, $sSecret);
	}

	/**
	 * @param string $sType
	 * @param string $sName
	 * @param int    $iExpiry
	 * @param string $sToken
	 * @param string $sSecret
	 * @param int    $iNow
	 * @return bool
	 */
	public static function verify($sType, $sName, $iExpiry, $sToken, $sSecret, $iNow = 0)
	{
		if(!is_string($sToken) || $sToken === '')
		{
			return false;
		}
		$iNow = $iNow > 0 ? $iNow : time();
		if((int)$iExpiry !== 0 && (int)$iExpiry < $iNow)
		{
			return false;
		}
		return hash_equals(self::sign($sType, $sName, (int)$iExpiry, $sSecret), $sToken);
	}
}
