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
	 * Bumped when generated QR files must be rewritten even if the product's colours/logo
	 * have not changed (logo sizing + error-correction fix). Stored beside the appearance
	 * fingerprint so an upgrade can queue a repair without a product save.
	 */
	const RENDER_VERSION = 2;

	/**
	 * CSS width of the QR <img> in the completed-order / confirmation email
	 * (`TPFW_Emails::add_ticket_and_pass_text`). Generation is 300px; this is what a mail
	 * client actually paints, so decode tests resample to it.
	 */
	const EMAIL_DISPLAY_PX = 150;

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

	/**
	 * Fingerprint of the settings that actually paint a scanner QR.
	 *
	 * @param string     $sLabelText
	 * @param string     $sBackground
	 * @param string     $sForeground
	 * @param string     $sLabelColor
	 * @param int|string $mLogoId
	 * @return string
	 */
	public static function appearance_key($sLabelText, $sBackground, $sForeground, $sLabelColor, $mLogoId)
	{
		return hash('sha256', implode("\n", array(
			'v'.self::RENDER_VERSION,
			(string) $sLabelText,
			(string) $sBackground,
			(string) $sForeground,
			(string) $sLabelColor,
			(string) (int) $mLogoId,
		)));
	}

	/**
	 * Same fingerprint write_scanner_qr will paint, including the colour fallbacks used when
	 * a field is empty.
	 *
	 * @param string     $sLabelText
	 * @param string     $sBackground
	 * @param string     $sForeground
	 * @param string     $sLabelColor
	 * @param int|string $mLogoId
	 * @return string
	 */
	public static function appearance_key_from_settings($sLabelText, $sBackground, $sForeground, $sLabelColor, $mLogoId)
	{
		$sBackground = (string) $sBackground;
		$sForeground = (string) $sForeground;
		$sLabelColor = (string) $sLabelColor;
		return self::appearance_key(
			(string) $sLabelText,
			$sBackground !== '' ? $sBackground : '#000000',
			$sForeground !== '' ? $sForeground : '#FFFFFF',
			$sLabelColor !== '' ? $sLabelColor : '#000000',
			$mLogoId
		);
	}
}
