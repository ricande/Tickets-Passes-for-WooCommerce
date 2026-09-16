<?php
use PHPUnit\Framework\TestCase;

class QrRenderTest extends TestCase
{
	public function test_missing_path_is_not_a_logo(): void
	{
		$this->assertFalse(TPFW_Qr_Render::has_logo(''));
		$this->assertFalse(TPFW_Qr_Render::has_logo(null));
		$this->assertFalse(TPFW_Qr_Render::has_logo('/no/such/file.png'));
		$this->assertNull(TPFW_Qr_Render::logo_box(300, ''));
		$this->assertNull(TPFW_Qr_Render::logo_box(300, '/no/such/file.png'));
	}

	public function test_portrait_photo_is_capped_on_height_not_width_only(): void
	{
		$sPath = $this->writePng(200, 800);
		$aBox  = TPFW_Qr_Render::logo_box(300, $sPath);
		unlink($sPath);

		$this->assertNotNull($aBox);
		$iMax = (int) round(300 * TPFW_Qr_Render::LOGO_MAX_FRACTION);
		$this->assertSame($iMax, $aBox[1]);
		$this->assertSame((int) round(200 * $iMax / 800), $aBox[0]);
		$this->assertLessThan($iMax, $aBox[0]);
		// The old size/4-by-width rule would have been 75 x 300 on a 300px code.
		$this->assertLessThan(75, $aBox[0]);
		$this->assertLessThan(150, $aBox[1]);
	}

	public function test_landscape_photo_is_capped_on_width(): void
	{
		$sPath = $this->writePng(800, 200);
		$aBox  = TPFW_Qr_Render::logo_box(300, $sPath);
		unlink($sPath);

		$this->assertNotNull($aBox);
		$iMax = (int) round(300 * TPFW_Qr_Render::LOGO_MAX_FRACTION);
		$this->assertSame($iMax, $aBox[0]);
		$this->assertSame((int) round(200 * $iMax / 800), $aBox[1]);
	}

	public function test_writer_uses_high_ecc_only_when_a_logo_is_present(): void
	{
		$sSrc = file_get_contents(TPFW_PLUGIN_DIR.'inc/functions/class--functions.php');
		$this->assertNotFalse(strpos($sSrc, 'ErrorCorrectionLevelHigh'));
		$this->assertNotFalse(strpos($sSrc, 'ErrorCorrectionLevelLow'));
		$this->assertNotFalse(strpos($sSrc, 'TPFW_Qr_Render::logo_box'));
		$this->assertFalse((bool) preg_match('/resizeToWidth\s*:\s*\(\s*\$iSize\s*\/\s*4\s*\)/', $sSrc));
		$this->assertNotFalse(strpos($sSrc, 'rewrite_issued_qr_images'));
	}

	private function writePng(int $iWidth, int $iHeight): string
	{
		$sPath = tempnam(sys_get_temp_dir(), 'tpfw-qr-');
		$im    = imagecreatetruecolor($iWidth, $iHeight);
		imagefilledrectangle($im, 0, 0, $iWidth, $iHeight, imagecolorallocate($im, 0, 0, 0));
		imagepng($im, $sPath);
		imagedestroy($im);
		return $sPath;
	}
}
