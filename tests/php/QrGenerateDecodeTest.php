<?php
use PHPUnit\Framework\TestCase;

class QrGenerateDecodeTest extends TestCase
{
	private string $sDir = '';

	protected function setUp(): void
	{
		if(!function_exists('imagecreatefromwebp'))
		{
			$this->markTestSkipped('GD webp support required to decode production QR files');
		}
		$this->sDir = sys_get_temp_dir().'/tpfw-qr-'.bin2hex(random_bytes(4)).'/';
		mkdir($this->sDir, 0777, true);
	}

	protected function tearDown(): void
	{
		if($this->sDir !== '' && is_dir($this->sDir))
		{
			foreach(glob($this->sDir.'*') ?: array() as $sFile)
			{
				@unlink($sFile);
			}
			@rmdir($this->sDir);
		}
	}

	public function test_ticket_payload_decodes_without_logo_at_email_size(): void
	{
		$sNano    = 'V1StGXR8Z5jdHi6BmyTxq';
		$sPayload = TPFW_Test_Qr_Generate::scanner_url($sNano, false);
		$sPath    = TPFW_Test_Qr_Generate::write($sPayload, $this->sDir, $sNano);
		$this->assertSame($sPayload, TPFW_Test_Qr_Generate::decode($sPath));
		$this->assertSame($sPayload, TPFW_Test_Qr_Generate::decode($sPath, TPFW_Qr_Render::EMAIL_DISPLAY_PX));
		$this->assertSame(150, TPFW_Qr_Render::EMAIL_DISPLAY_PX);
	}

	public function test_guest_pass_payload_uses_guest_suffix(): void
	{
		$sNano    = 'GuestPassNanoId0000001';
		$sPayload = TPFW_Test_Qr_Generate::scanner_url($sNano, true);
		$this->assertStringEndsWith('/'.$sNano.'/guest', $sPayload);
		$sPath = TPFW_Test_Qr_Generate::write($sPayload, $this->sDir, $sNano);
		$this->assertSame($sPayload, TPFW_Test_Qr_Generate::decode($sPath));
		$this->assertSame($sPayload, TPFW_Test_Qr_Generate::decode($sPath, TPFW_Qr_Render::EMAIL_DISPLAY_PX));
	}

	public function test_portrait_landscape_square_logos_keep_exact_payload(): void
	{
		$sNano    = 'LogoPayloadNano0000001';
		$sPayload = TPFW_Test_Qr_Generate::scanner_url($sNano, false);
		$aLogos   = array(
			'portrait'  => TPFW_Test_Qr_Generate::write_png(200, 800),
			'landscape' => TPFW_Test_Qr_Generate::write_png(800, 200),
			'square'    => TPFW_Test_Qr_Generate::write_png(400, 400),
		);
		try
		{
			foreach($aLogos as $sKind => $sLogo)
			{
				$sPath = TPFW_Test_Qr_Generate::write($sPayload, $this->sDir, $sNano.$sKind, $sLogo);
				$this->assertSame($sPayload, TPFW_Test_Qr_Generate::decode($sPath), $sKind);
				$this->assertSame(
					$sPayload,
					TPFW_Test_Qr_Generate::decode($sPath, TPFW_Qr_Render::EMAIL_DISPLAY_PX),
					$sKind.' email size'
				);
			}
		}
		finally
		{
			foreach($aLogos as $sLogo)
			{
				@unlink($sLogo);
			}
		}
	}

	public function test_rewrite_changes_url_version_and_keeps_hmac_scope(): void
	{
		$sNano = 'CacheBustNano000000001';
		$sA    = TPFW_Test_Qr_Generate::scanner_url($sNano, false);
		$sB    = TPFW_Test_Qr_Generate::scanner_url($sNano, true);
		$sPath = TPFW_Test_Qr_Generate::write($sA, $this->sDir, $sNano);
		$iOld  = (int) filemtime($sPath);
		$sOld  = file_get_contents($sPath);
		touch($sPath, $iOld);
		$iVerA = TPFW_File_Access::url_version('qr', $iOld);
		$aUrlA = TPFW_File_Access::file_query_args('qr', $sNano, 'webp', $iVerA, 'token', 0);
		$this->assertSame((string) $iOld, $aUrlA['v']);
		$this->assertSame('qr', $aUrlA['tpfw_file']);
		$this->assertSame($sNano, $aUrlA['id']);

		clearstatcache(true, $sPath);
		TPFW_Test_Qr_Generate::write($sB, $this->sDir, $sNano);
		touch($sPath, $iOld + 30);
		clearstatcache(true, $sPath);
		$iNew = (int) filemtime($sPath);
		$this->assertNotSame($sOld, file_get_contents($sPath));
		$this->assertSame($sB, TPFW_Test_Qr_Generate::decode($sPath));
		$iVerB = TPFW_File_Access::url_version('qr', $iNew);
		$this->assertNotSame($iVerA, $iVerB);
		$aUrlB = TPFW_File_Access::file_query_args('qr', $sNano, 'webp', $iVerB, 'token', 0);
		$this->assertSame((string) $iNew, $aUrlB['v']);
		$this->assertSame($aUrlA['t'], $aUrlB['t']);
		$this->assertSame($aUrlA['exp'], $aUrlB['exp']);

		$sSecret = 'test-secret-must-be-at-least-32-chars!!';
		$sTok    = TPFW_File_Token::sign('qr', $sNano, 0, $sSecret);
		$this->assertTrue(TPFW_File_Token::verify('qr', $sNano, 0, $sTok, $sSecret));
		$this->assertFalse(TPFW_File_Token::verify('guest', $sNano, 0, $sTok, $sSecret));
		$this->assertFalse(TPFW_File_Token::verify('qr', $sNano.'x', 0, $sTok, $sSecret));
		$sTokWithVersionShape = TPFW_File_Token::sign('qr', $sNano.'|'.$iVerB, 0, $sSecret);
		$this->assertNotSame($sTok, $sTokWithVersionShape);
		$this->assertFalse(TPFW_File_Token::verify('qr', $sNano, 0, $sTokWithVersionShape, $sSecret));
	}

	public function test_pdf_url_version_follows_rewritten_qr(): void
	{
		$sNano = 'PdfEmbedNano0000000001';
		$sQr   = $this->sDir.$sNano.'.webp';
		$sPdf  = $this->sDir.$sNano.'.pdf';
		TPFW_Test_Qr_Generate::write(TPFW_Test_Qr_Generate::scanner_url($sNano), $this->sDir, $sNano);
		file_put_contents($sPdf, '%PDF-1.4 stale');
		$iQr  = 1_700_000_100;
		$iPdf = 1_700_000_050;
		$this->assertSame($iQr, TPFW_File_Access::url_version('pdf', $iPdf, $iQr));
		$sBefore = TPFW_Test_Qr_Generate::decode($sQr);
		TPFW_Test_Qr_Generate::write(TPFW_Test_Qr_Generate::scanner_url($sNano, true), $this->sDir, $sNano);
		$sAfter = TPFW_Test_Qr_Generate::decode($sQr);
		$this->assertNotSame($sBefore, $sAfter);
		$this->assertSame(TPFW_Test_Qr_Generate::scanner_url($sNano, true), $sAfter);
		$this->assertSame('%PDF-1.4 stale', file_get_contents($sPdf));
		$aPdfArgs = TPFW_File_Access::file_query_args('pdf', $sNano, 'pdf', TPFW_File_Access::url_version('pdf', $iPdf, $iQr + 40));
		$this->assertSame((string) ($iQr + 40), $aPdfArgs['v']);
		$this->assertArrayNotHasKey('t', $aPdfArgs);
	}
}
