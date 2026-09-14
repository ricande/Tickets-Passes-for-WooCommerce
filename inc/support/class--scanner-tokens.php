<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Per-device scanner tokens, rotatable without changing the WordPress user password.
 *
 * The built-in /check-in/ page keeps using cookie + wp_rest nonce. External apps may send
 * X-TPFW-Scanner-Token. When tpfw_scanner_tokens_required is set, Basic Auth with a password
 * is refused for the API.
 */
class TPFW_Scanner_Tokens
{
	const OPTION          = 'tpfw_scanner_tokens';
	const REQUIRE_OPTION  = 'tpfw_scanner_tokens_required';

	/**
	 * @param string $sPlain
	 * @return string
	 */
	public static function hash($sPlain)
	{
		return password_hash($sPlain, PASSWORD_DEFAULT);
	}

	/**
	 * @return bool
	 */
	public static function is_required()
	{
		if(!function_exists('get_option'))
		{
			return false;
		}
		return (bool)get_option(self::REQUIRE_OPTION);
	}

	/**
	 * @param string $sPlain
	 * @param array  $aTokens List of token records.
	 * @return array|null Matching record, or null.
	 */
	public static function verify_list($sPlain, $aTokens)
	{
		if(!is_string($sPlain) || $sPlain === '' || !is_array($aTokens))
		{
			return null;
		}
		foreach($aTokens as $aTok)
		{
			if(!is_array($aTok) || !empty($aTok['revoked']))
			{
				continue;
			}
			if(empty($aTok['hash']) || !is_string($aTok['hash']))
			{
				continue;
			}
			if(password_verify($sPlain, $aTok['hash']))
			{
				return $aTok;
			}
		}
		return null;
	}

	/**
	 * @param string $sPlain
	 * @return array|null
	 */
	public static function verify_against_option($sPlain)
	{
		if(!function_exists('get_option'))
		{
			return null;
		}
		$aTokens = get_option(self::OPTION, array());
		return self::verify_list($sPlain, is_array($aTokens) ? $aTokens : array());
	}

	/**
	 * Stores a new token and returns the plaintext once.
	 *
	 * @param string $sName
	 * @param int    $iUserID Scanner user the token acts as.
	 * @return array{id:string,plain:string,name:string,user_id:int}
	 */
	public static function create($sName, $iUserID)
	{
		$sPlain  = bin2hex(random_bytes(24));
		$sId     = bin2hex(random_bytes(8));
		$aTokens = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
		if(!is_array($aTokens))
		{
			$aTokens = array();
		}
		$aTokens[] = array(
			'id'      => $sId,
			'name'    => (string)$sName,
			'hash'    => self::hash($sPlain),
			'user_id' => (int)$iUserID,
			'revoked' => false,
			'created' => time(),
		);
		if(function_exists('update_option'))
		{
			update_option(self::OPTION, $aTokens, false);
		}
		return array(
			'id'      => $sId,
			'plain'   => $sPlain,
			'name'    => (string)$sName,
			'user_id' => (int)$iUserID,
		);
	}

	/**
	 * @param string $sId
	 * @return bool
	 */
	public static function revoke($sId)
	{
		if(!function_exists('get_option') || !function_exists('update_option'))
		{
			return false;
		}
		$aTokens = get_option(self::OPTION, array());
		if(!is_array($aTokens))
		{
			return false;
		}
		$bFound = false;
		foreach($aTokens as $i => $aTok)
		{
			if(($aTok['id'] ?? '') === $sId)
			{
				$aTokens[$i]['revoked'] = true;
				$bFound = true;
			}
		}
		if($bFound)
		{
			update_option(self::OPTION, $aTokens, false);
		}
		return $bFound;
	}
}
