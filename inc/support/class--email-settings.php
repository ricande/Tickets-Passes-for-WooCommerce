<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Resolves an email subject/body: saved option if the shop edited it, otherwise the default.
 *
 * Defaults must be built with __() at send time so the site locale wins. A stored option is
 * the shop's own wording and is returned as saved.
 */
class TPFW_Email_Settings
{
	/**
	 * @param array  $aSaved    Option array from tpfw_email_settings_options.
	 * @param string $sKey      Template key.
	 * @param string $sField    'subject' or 'message'.
	 * @param array  $aDefaults Nested defaults from get_default_email_texts().
	 * @return string
	 */
	public static function resolve($aSaved, $sKey, $sField, $aDefaults)
	{
		$sOptKey = $sKey.'_'.$sField;
		if(is_array($aSaved) && isset($aSaved[$sOptKey]) && $aSaved[$sOptKey] !== '')
		{
			return $aSaved[$sOptKey];
		}
		return $aDefaults[$sKey][$sField] ?? '';
	}
}
