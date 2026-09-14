<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Bundled Composer library versions, read from installed.json rather than hand-maintained.
 */
class TPFW_Bundled_Libs
{
	/**
	 * @param string $sPluginDir Plugin root.
	 * @return array<string,string> package name => version (leading v stripped).
	 */
	public static function versions_from_installed($sPluginDir)
	{
		$aOut = array();
		$aFiles = glob(rtrim($sPluginDir, '/').'/inc/functions/lib/*/composer/installed.json');
		if(!is_array($aFiles))
		{
			return $aOut;
		}
		foreach($aFiles as $sFile)
		{
			$aJson = json_decode((string)file_get_contents($sFile), true);
			if(empty($aJson['packages']) || !is_array($aJson['packages']))
			{
				continue;
			}
			foreach($aJson['packages'] as $aPkg)
			{
				if(empty($aPkg['name']) || empty($aPkg['version']))
				{
					continue;
				}
				$aOut[$aPkg['name']] = ltrim((string)$aPkg['version'], 'v');
			}
		}
		return $aOut;
	}

	/**
	 * Package versions claimed in readme.txt bundled-library lines.
	 *
	 * @param string $sReadme
	 * @return array<string,string>
	 */
	public static function versions_from_readme($sReadme)
	{
		$aOut = array();
		if(!preg_match('/== Source Code and Third-Party Libraries ==(.*?)== Changelog ==/s', $sReadme, $aMatch))
		{
			return $aOut;
		}
		$sBlock = $aMatch[1];
		if(preg_match_all('/\b((?:dompdf\/)?(?:php-font-lib|php-svg-lib)|dompdf|endroid\/qr-code|bacon\/bacon-qr-code|dasprid\/enum|masterminds\/html5|sabberworm\/php-css-parser|thecodingmachine\/safe)\s+v?([0-9]+\.[0-9]+\.[0-9]+)/', $sBlock, $aHits, PREG_SET_ORDER))
		{
			foreach($aHits as $aHit)
			{
				$sName = $aHit[1];
				if($sName === 'dompdf')
				{
					$sName = 'dompdf/dompdf';
				}
				elseif($sName === 'php-font-lib' || $sName === 'dompdf/php-font-lib')
				{
					$sName = 'dompdf/php-font-lib';
				}
				elseif($sName === 'php-svg-lib' || $sName === 'dompdf/php-svg-lib')
				{
					$sName = 'dompdf/php-svg-lib';
				}
				$aOut[$sName] = $aHit[2];
			}
		}
		return $aOut;
	}
}
