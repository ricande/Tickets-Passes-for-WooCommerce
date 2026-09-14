<?php
use PHPUnit\Framework\TestCase;

class FileAccessTest extends TestCase
{
	public function test_unsigned_token_is_rejected(): void
	{
		$sSecret = 'test-secret-must-be-at-least-32-chars!!';
		$this->assertFalse(TPFW_File_Token::verify('qr', 'V1StGXR8Z5jdHi6BmyTxq', 0, '', $sSecret));
		$this->assertFalse(TPFW_File_Token::verify('qr', 'V1StGXR8Z5jdHi6BmyTxq', 0, 'deadbeef', $sSecret));
	}

	public function test_signed_token_matches(): void
	{
		$sSecret = 'test-secret-must-be-at-least-32-chars!!';
		$sTok    = TPFW_File_Token::sign('qr', 'V1StGXR8Z5jdHi6BmyTxq', 0, $sSecret);
		$this->assertTrue(TPFW_File_Token::verify('qr', 'V1StGXR8Z5jdHi6BmyTxq', 0, $sTok, $sSecret));
	}

	public function test_expired_token_is_rejected(): void
	{
		$sSecret = 'test-secret-must-be-at-least-32-chars!!';
		$iExp    = 1000;
		$sTok    = TPFW_File_Token::sign('profile', 'nano1', $iExp, $sSecret);
		$this->assertFalse(TPFW_File_Token::verify('profile', 'nano1', $iExp, $sTok, $sSecret, 2000));
	}

	public function test_profile_dir_stays_under_slug(): void
	{
		$sSlug = '/var/www/uploads/tpfw-a3f9c81b04/';
		$sDir  = TPFW_File_Paths::profile_dir($sSlug);
		$this->assertTrue(TPFW_File_Paths::is_under_slug($sDir, $sSlug));
		$this->assertFalse(TPFW_File_Paths::is_under_slug('/var/www/uploads/custom-photos/', $sSlug));
	}

	public function test_nginx_snippet_denies_tpfw_uploads(): void
	{
		$sConf = file_get_contents(dirname(TPFW_PLUGIN_DIR).'/deploy/nginx/woocommerce.local');
		$this->assertNotFalse(strpos($sConf, 'wp-content/uploads/tpfw-'));
		$this->assertNotFalse(strpos($sConf, 'deny all'));
	}
}
