<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Who may hit the scanner REST routes.
 *
 * WordPress owns cookie + REST nonce and Application Password (Basic) authentication.
 * This class never reads a password and never calls wp_authenticate(). It only applies
 * Enable API, plugin token verification results, and user_can_scan().
 */
class TPFW_Scanner_Auth
{
	/**
	 * @param array $aContext {
	 *     @type string        $sScannerToken
	 *     @type bool          $bTokenValid
	 *     @type object|false  $oTokenUser
	 *     @type bool          $bLoggedIn
	 *     @type object|false  $oCurrentUser
	 *     @type bool          $bHasBasicHeader
	 *     @type bool          $bApiEnabled
	 *     @type callable      $fnCanScan
	 * }
	 * @return object|false
	 */
	public static function resolve(array $aContext)
	{
		$fnCanScan = isset($aContext['fnCanScan']) && is_callable($aContext['fnCanScan'])
			? $aContext['fnCanScan']
			: static function() { return false; };

		$sToken = isset($aContext['sScannerToken']) ? (string)$aContext['sScannerToken'] : '';
		if($sToken !== '')
		{
			if(empty($aContext['bApiEnabled']) || empty($aContext['bTokenValid']))
			{
				return false;
			}
			$oTokenUser = $aContext['oTokenUser'] ?? false;
			return call_user_func($fnCanScan, $oTokenUser) ? $oTokenUser : false;
		}

		if(empty($aContext['bLoggedIn']))
		{
			return false;
		}

		$oUser = $aContext['oCurrentUser'] ?? false;
		if(!empty($aContext['bHasBasicHeader']) && empty($aContext['bApiEnabled']))
		{
			return false;
		}

		return call_user_func($fnCanScan, $oUser) ? $oUser : false;
	}
}
