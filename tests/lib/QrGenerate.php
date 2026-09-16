<?php
use Endroid\QrCode\Color\Color;

/**
 * Generates a QR through the production writer and decodes it with the scanner's jsQR build.
 */
class TPFW_Test_Qr_Generate
{
	/**
	 * @return void
	 */
	public static function bootstrap()
	{
		if(!function_exists('wp_mkdir_p'))
		{
			function wp_mkdir_p($sDir)
			{
				return is_dir($sDir) || mkdir($sDir, 0777, true);
			}
		}
		require_once TPFW_PLUGIN_DIR.'inc/functions/lib/qrcodegen/autoload.php';
		require_once TPFW_PLUGIN_DIR.'inc/functions/class--functions.php';
		require_once TPFW_PLUGIN_DIR.'inc/file-access/class--file-access.php';
	}

	/**
	 * REST check-in URL matching TPFW_Functions::write_scanner_qr().
	 *
	 * @param string $sNanoID
	 * @param bool   $bGuest
	 * @param string $sRestBase Trailing-slashed get_rest_url() equivalent.
	 * @return string
	 */
	public static function scanner_url($sNanoID, $bGuest = false, $sRestBase = 'https://woocommerce.local/wp-json/')
	{
		return $sRestBase.'tpfw/v1/scanner/checkin/'.$sNanoID.($bGuest ? '/guest' : '');
	}

	/**
	 * @param string      $sPayload
	 * @param string      $sDestDir Trailing slash optional.
	 * @param string      $sFileName Without extension.
	 * @param string|null $sLogoPath
	 * @param string      $sLabel
	 * @return string Absolute path of the written .webp.
	 */
	public static function write($sPayload, $sDestDir, $sFileName, $sLogoPath = null, $sLabel = '')
	{
		self::bootstrap();
		$sDestDir = rtrim($sDestDir, '/').'/';
		if(!is_dir($sDestDir) && !mkdir($sDestDir, 0777, true) && !is_dir($sDestDir))
		{
			throw new RuntimeException('qr_test_dir');
		}
		$bOk = tpfw_create_qr_code_function(
			$sPayload,
			300,
			10,
			$sLabel,
			$sLogoPath,
			new Color(255, 255, 255),
			new Color(0, 0, 0),
			new Color(0, 0, 0),
			$sDestDir,
			$sFileName
		);
		$sPath = $sDestDir.$sFileName.'.webp';
		if($bOk !== true || !is_file($sPath))
		{
			throw new RuntimeException('qr_test_write');
		}
		return $sPath;
	}

	/**
	 * @param string $sWebpPath
	 * @param int    $iDisplayPx 0 = native size; email uses TPFW_Qr_Render::EMAIL_DISPLAY_PX.
	 * @return string Decoded payload.
	 */
	public static function decode($sWebpPath, $iDisplayPx = 0)
	{
		if(!function_exists('imagecreatefromwebp'))
		{
			throw new RuntimeException('gd_webp_missing');
		}
		$oSrc = @imagecreatefromwebp($sWebpPath);
		if($oSrc === false)
		{
			throw new RuntimeException('webp_unreadable');
		}
		$iSrcW = imagesx($oSrc);
		$iSrcH = imagesy($oSrc);
		$oIm   = $oSrc;
		$iW    = $iSrcW;
		$iH    = $iSrcH;
		if($iDisplayPx > 0 && $iSrcW > 0)
		{
			$iW = (int) $iDisplayPx;
			$iH = max(1, (int) round($iSrcH * $iDisplayPx / $iSrcW));
			$oIm = imagecreatetruecolor($iW, $iH);
			imagecopyresampled($oIm, $oSrc, 0, 0, 0, 0, $iW, $iH, $iSrcW, $iSrcH);
		}

		$sRgba = '';
		for($iY = 0; $iY < $iH; $iY++)
		{
			for($iX = 0; $iX < $iW; $iX++)
			{
				$a = imagecolorsforindex($oIm, imagecolorat($oIm, $iX, $iY));
				$sRgba .= chr((int) $a['red']).chr((int) $a['green']).chr((int) $a['blue']).chr(255);
			}
		}

		$sRaw = tempnam(sys_get_temp_dir(), 'tpfw-rgba-');
		file_put_contents($sRaw, $sRgba);
		$sJs = TPFW_PLUGIN_DIR.'tests/js/decode-qr-rgba.js';
		$sCmd = 'node '.escapeshellarg($sJs).' '.escapeshellarg($sRaw).' '.$iW.' '.$iH;
		$sOut = array();
		$iCode = 0;
		exec($sCmd.' 2>/dev/null', $sOut, $iCode);
		@unlink($sRaw);
		if($iCode !== 0)
		{
			throw new RuntimeException('jsqr_failed');
		}
		return implode('', $sOut);
	}

	/**
	 * @param int $iWidth
	 * @param int $iHeight
	 * @return string PNG path.
	 */
	public static function write_png($iWidth, $iHeight)
	{
		$sPath = tempnam(sys_get_temp_dir(), 'tpfw-logo-');
		$oIm   = imagecreatetruecolor($iWidth, $iHeight);
		imagefilledrectangle($oIm, 0, 0, $iWidth, $iHeight, imagecolorallocate($oIm, 0, 80, 160));
		imagepng($oIm, $sPath);
		return $sPath;
	}
}
