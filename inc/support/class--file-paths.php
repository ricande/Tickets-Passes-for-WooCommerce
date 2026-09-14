<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Upload layout: every plugin file lives under the random tpfw-{slug}/ folder.
 *
 * A custom profile path used to leave wp-content/uploads/, which on nginx (no .htaccess)
 * made the photo a static URL. Profile images use the same slug as QR and PDF files.
 */
class TPFW_File_Paths
{
	/**
	 * @param string $sSlugBase Trailing-slashed random-slug directory.
	 * @return string Trailing-slashed profile directory.
	 */
	public static function profile_dir($sSlugBase)
	{
		return rtrim(str_replace('\\', '/', $sSlugBase), '/').'/profile-images/';
	}

	/**
	 * @param string $sPath    Candidate directory.
	 * @param string $sSlugBase Random-slug base.
	 * @return bool
	 */
	public static function is_under_slug($sPath, $sSlugBase)
	{
		$sPath     = rtrim(str_replace('\\', '/', $sPath), '/').'/';
		$sSlugBase = rtrim(str_replace('\\', '/', $sSlugBase), '/').'/';
		if($sSlugBase === '/')
		{
			return false;
		}
		return str_starts_with($sPath, $sSlugBase);
	}
}
