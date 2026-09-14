<?php
/**
 * Loads KEY=VALUE credentials from .wp-credentials without putting the password on argv.
 */
class TPFW_Test_Credentials
{
	/**
	 * @return array<string,string>|null
	 */
	public static function load()
	{
		$sFromEnv = getenv('TPFW_TEST_CREDENTIALS');
		$aCandidates = array();
		if(is_string($sFromEnv) && $sFromEnv !== '')
		{
			$aCandidates[] = $sFromEnv;
		}
		$aCandidates[] = dirname(TPFW_PLUGIN_DIR).'/.wp-credentials';
		$aCandidates[] = dirname(dirname(TPFW_PLUGIN_DIR)).'/.wp-credentials';

		foreach($aCandidates as $sPath)
		{
			if(!is_readable($sPath))
			{
				continue;
			}
			$aOut = array();
			foreach(file($sPath, FILE_IGNORE_NEW_LINES) as $sLine)
			{
				$sLine = trim($sLine);
				if($sLine === '' || $sLine[0] === '#' || strpos($sLine, '=') === false)
				{
					continue;
				}
				list($sKey, $sVal) = explode('=', $sLine, 2);
				$aOut[trim($sKey)] = trim($sVal);
			}
			if(!empty($aOut['DB_NAME']) && !empty($aOut['DB_USER']))
			{
				$aOut['DB_HOST'] = $aOut['DB_HOST'] ?? 'localhost';
				$aOut['DB_PASS'] = $aOut['DB_PASS'] ?? '';
				return $aOut;
			}
		}
		return null;
	}

	/**
	 * @return mysqli|null
	 */
	public static function mysqli()
	{
		$a = self::load();
		if($a === null)
		{
			return null;
		}
		mysqli_report(MYSQLI_REPORT_OFF);
		$o = @new mysqli($a['DB_HOST'], $a['DB_USER'], $a['DB_PASS'], $a['DB_NAME']);
		if($o->connect_error)
		{
			return null;
		}
		$o->set_charset('utf8mb4');
		return $o;
	}
}
