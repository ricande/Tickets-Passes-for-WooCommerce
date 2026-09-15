<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Per-device scanner tokens, rotatable without changing the WordPress user password.
 *
 * Header X-TPFW-Scanner-Token is "{token_id}.{secret}". token_id is a public lookup key;
 * only hash(secret) is stored. Verification is one option lookup plus at most one
 * password_verify(). Opaque 1.3.0-dev secrets (no token_id prefix) are rejected without
 * hashing: recreate tokens via create() — plaintext is not stored, so they cannot be migrated.
 *
 * The built-in /check-in/ page keeps using cookie + wp_rest nonce. When
 * Application Passwords are authenticated by WordPress, not by this class.
 */
class TPFW_Scanner_Tokens
{
	const OPTION          = 'tpfw_scanner_tokens';
	const REQUIRE_OPTION  = 'tpfw_scanner_tokens_required';
	const ID_HEX_LEN      = 16;
	const SECRET_HEX_LEN  = 48;

	/** @var int password_verify() calls in this process (tests reset this). */
	public static $iSecretVerifies = 0;

	/**
	 * @return void
	 */
	public static function reset_secret_verify_count()
	{
		self::$iSecretVerifies = 0;
	}

	/**
	 * @return int
	 */
	public static function secret_verify_count()
	{
		return self::$iSecretVerifies;
	}

	/**
	 * @param string $sSecret
	 * @return string
	 */
	public static function hash($sSecret)
	{
		return password_hash($sSecret, PASSWORD_DEFAULT);
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
	 * @param string $sPresented Header value.
	 * @return array{id:string,secret:string}|null
	 */
	public static function parse($sPresented)
	{
		if(!is_string($sPresented) || $sPresented === '')
		{
			return null;
		}
		$iDot = strpos($sPresented, '.');
		if($iDot === false || strpos($sPresented, '.', $iDot + 1) !== false)
		{
			return null;
		}
		$sId     = strtolower(substr($sPresented, 0, $iDot));
		$sSecret = strtolower(substr($sPresented, $iDot + 1));
		if(!self::is_token_id($sId) || !self::is_secret($sSecret))
		{
			return null;
		}
		return array(
			'id'     => $sId,
			'secret' => $sSecret,
		);
	}

	/**
	 * @param mixed $sId
	 * @return bool
	 */
	public static function is_token_id($sId)
	{
		return is_string($sId) && preg_match('/^[a-f0-9]{'.self::ID_HEX_LEN.'}$/', $sId) === 1;
	}

	/**
	 * @param mixed $sSecret
	 * @return bool
	 */
	public static function is_secret($sSecret)
	{
		return is_string($sSecret) && preg_match('/^[a-f0-9]{'.self::SECRET_HEX_LEN.'}$/', $sSecret) === 1;
	}

	/**
	 * Cheap id lookup. Does not call password_verify().
	 *
	 * @param array  $aTokens Keyed by token_id, or a legacy list of records.
	 * @param string $sId
	 * @return array|null
	 */
	public static function find_record($aTokens, $sId)
	{
		if(!self::is_token_id($sId) || !is_array($aTokens))
		{
			return null;
		}
		if(isset($aTokens[$sId]) && is_array($aTokens[$sId]))
		{
			$sStored = isset($aTokens[$sId]['id']) ? (string)$aTokens[$sId]['id'] : $sId;
			if(hash_equals($sId, strtolower($sStored)))
			{
				return $aTokens[$sId];
			}
		}
		foreach($aTokens as $aTok)
		{
			if(!is_array($aTok) || !isset($aTok['id']))
			{
				continue;
			}
			if(hash_equals($sId, strtolower((string)$aTok['id'])))
			{
				return $aTok;
			}
		}
		return null;
	}

	/**
	 * @param string $sPresented "{token_id}.{secret}"
	 * @param array  $aTokens
	 * @return array|null Matching record, or null.
	 */
	public static function verify_list($sPresented, $aTokens)
	{
		$aParsed = self::parse($sPresented);
		if($aParsed === null)
		{
			return null;
		}
		$aTok = self::find_record(is_array($aTokens) ? $aTokens : array(), $aParsed['id']);
		if($aTok === null || !empty($aTok['revoked']))
		{
			return null;
		}
		if(empty($aTok['hash']) || !is_string($aTok['hash']))
		{
			return null;
		}
		if(!self::verify_secret($aParsed['secret'], $aTok['hash']))
		{
			return null;
		}
		return $aTok;
	}

	/**
	 * @param string $sPresented
	 * @return array|null
	 */
	public static function verify_against_option($sPresented)
	{
		if(!function_exists('get_option'))
		{
			return null;
		}
		$aTokens = get_option(self::OPTION, array());
		return self::verify_list($sPresented, is_array($aTokens) ? $aTokens : array());
	}

	/**
	 * Stores a new token and returns the full "{id}.{secret}" once. Secret is not stored.
	 *
	 * @param string $sName
	 * @param int    $iUserID Scanner user the token acts as.
	 * @return array{id:string,plain:string,name:string,user_id:int}
	 */
	public static function create($sName, $iUserID)
	{
		$sId     = bin2hex(random_bytes(self::ID_HEX_LEN / 2));
		$sSecret = bin2hex(random_bytes(self::SECRET_HEX_LEN / 2));
		$aTokens = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
		if(!is_array($aTokens))
		{
			$aTokens = array();
		}
		$aTokens = self::index_by_id($aTokens);
		$aTokens[$sId] = array(
			'id'      => $sId,
			'name'    => (string)$sName,
			'hash'    => self::hash($sSecret),
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
			'plain'   => $sId.'.'.$sSecret,
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
		$sId = is_string($sId) ? strtolower($sId) : '';
		if(!self::is_token_id($sId))
		{
			return false;
		}
		$aTokens = get_option(self::OPTION, array());
		if(!is_array($aTokens))
		{
			return false;
		}
		$aTokens = self::index_by_id($aTokens);
		if(!isset($aTokens[$sId]))
		{
			return false;
		}
		$aTokens[$sId]['revoked'] = true;
		update_option(self::OPTION, $aTokens, false);
		return true;
	}

	/**
	 * @param array $aTokens
	 * @return array<string,array>
	 */
	public static function index_by_id($aTokens)
	{
		$aOut = array();
		foreach((array)$aTokens as $mKey => $aTok)
		{
			if(!is_array($aTok))
			{
				continue;
			}
			$sId = isset($aTok['id']) ? strtolower((string)$aTok['id']) : (is_string($mKey) ? strtolower($mKey) : '');
			if(!self::is_token_id($sId))
			{
				continue;
			}
			$aTok['id'] = $sId;
			$aOut[$sId] = $aTok;
		}
		return $aOut;
	}

	/**
	 * @param string $sSecret
	 * @param string $sHash
	 * @return bool
	 */
	private static function verify_secret($sSecret, $sHash)
	{
		self::$iSecretVerifies++;
		return password_verify($sSecret, $sHash);
	}
}
