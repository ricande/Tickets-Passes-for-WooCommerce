<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * QR centre-logo box. The encoder also needs a higher error-correction level when a logo is
 * present; that choice lives next to the Endroid call so this helper stays free of the vendor.
 *
 * A media-library photo used as a "logo" is often portrait and much taller than it is wide.
 * Sizing only by width (a quarter of the QR) then lets the height cover the finder patterns
 * and the payload. Cap the longer side, keep the aspect ratio.
 */
class TPFW_Qr_Render
{
	const LOGO_MAX_FRACTION = 0.20;

	/**
	 * @param mixed $sPath Filesystem path to a centre image, or empty for none.
	 * @return bool
	 */
	public static function has_logo($sPath)
	{
		return is_string($sPath) && $sPath !== '' && is_file($sPath);
	}

	/**
	 * Pixel width and height for the centre logo, or null when there is no usable image.
	 *
	 * @param int    $iQrSize QR size in pixels (the Endroid `size`, before margin).
	 * @param mixed  $sPath   Filesystem path, or empty for none.
	 * @return array{0:int,1:int}|null
	 */
	public static function logo_box($iQrSize, $sPath)
	{
		$iQrSize = (int) $iQrSize;
		if($iQrSize < 1 || !self::has_logo($sPath))
		{
			return null;
		}

		$iMax  = max(24, (int) round($iQrSize * self::LOGO_MAX_FRACTION));
		$aSize = @getimagesize($sPath);
		$iSrcW = (is_array($aSize) && (int) $aSize[0] > 0) ? (int) $aSize[0] : $iMax;
		$iSrcH = (is_array($aSize) && (int) $aSize[1] > 0) ? (int) $aSize[1] : $iMax;

		if($iSrcW >= $iSrcH)
		{
			return array($iMax, max(1, (int) round($iSrcH * $iMax / $iSrcW)));
		}
		return array(max(1, (int) round($iSrcW * $iMax / $iSrcH)), $iMax);
	}
}
