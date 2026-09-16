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
		$sSlug = '/tmp/tpfw-fixture-uploads/tpfw-a3f9c81b04/';
		$sDir  = TPFW_File_Paths::profile_dir($sSlug);
		$this->assertTrue(TPFW_File_Paths::is_under_slug($sDir, $sSlug));
		$this->assertFalse(TPFW_File_Paths::is_under_slug('/tmp/tpfw-fixture-uploads/custom-photos/', $sSlug));
	}

	public function test_nginx_snippet_exists_in_the_repository(): void
	{
		$sConf = $this->nginxSnippet();
		$this->assertNotSame('', $sConf);
		$this->assertFileExists(TPFW_PLUGIN_DIR.'docs/server-config/nginx-deny-tpfw-uploads.conf');
		$this->assertFileExists(TPFW_PLUGIN_DIR.'docs/server-config/README.md');
	}

	public function test_nginx_snippet_denies_tpfw_uploads_only(): void
	{
		$sConf = $this->nginxSnippet();
		$this->assertNotFalse(preg_match('/location\s+\^~\s+\/wp-content\/uploads\/tpfw-/', $sConf));
		$this->assertNotFalse(strpos($sConf, 'deny all'));
		$this->assertSame(0, preg_match('/location\s+\^~\s+\/wp-content\/uploads\/\s*\{/', $sConf));
		$this->assertStringNotContainsString('location /wp-content/uploads/', $sConf);
	}

	public function test_docs_say_nginx_needs_a_server_rule(): void
	{
		$sReadme = file_get_contents(TPFW_PLUGIN_DIR.'readme.txt');
		$sFiles  = file_get_contents(TPFW_PLUGIN_DIR.'docs/files-and-access.md');
		$sInst   = file_get_contents(TPFW_PLUGIN_DIR.'docs/install.md');
		foreach(array($sReadme, $sFiles, $sInst) as $sDoc)
		{
			$this->assertNotFalse(stripos($sDoc, 'nginx'));
			$this->assertNotFalse(strpos($sDoc, 'tpfw-'));
		}
		$this->assertNotFalse(strpos($sReadme, 'docs/server-config/nginx-deny-tpfw-uploads.conf'));
		$this->assertNotFalse(stripos($sFiles, 'Ignores `.htaccess`'));
		$this->assertNotFalse(stripos($sInst, 'ignored'));
	}

	public function test_docs_do_not_claim_htaccess_protects_nginx(): void
	{
		$aDocs = array(
			TPFW_PLUGIN_DIR.'readme.txt',
			TPFW_PLUGIN_DIR.'docs/files-and-access.md',
			TPFW_PLUGIN_DIR.'docs/install.md',
			TPFW_PLUGIN_DIR.'docs/architecture.md',
			TPFW_PLUGIN_DIR.'docs/server-config/README.md',
		);
		foreach($aDocs as $sPath)
		{
			$s = file_get_contents($sPath);
			$this->assertStringNotContainsString('Nothing to configure', $s, $sPath);
			$this->assertStringNotContainsString('behaves the same on Apache, nginx and IIS', $s, $sPath);
			$this->assertStringNotContainsString('works the same on Apache, nginx and IIS', $s, $sPath);
			$this->assertStringNotContainsString('without asking a shop owner to edit a server config', $s, $sPath);
		}
	}

	public function test_rewritable_qr_and_pdf_are_not_immutable(): void
	{
		require_once TPFW_PLUGIN_DIR.'inc/file-access/class--file-access.php';
		foreach(array('qr', 'guest', 'pdf', 'preview', 'profile') as $sType)
		{
			$this->assertSame('no-cache, max-age=0', TPFW_File_Access::cache_control_freshness($sType), $sType);
			$this->assertContains($sType, TPFW_File_Access::REWRITABLE_TYPES);
		}
		$this->assertStringNotContainsString('immutable', TPFW_File_Access::cache_control_freshness('qr'));
	}

	public function test_version_query_is_unsigned_and_does_not_widen_hmac(): void
	{
		require_once TPFW_PLUGIN_DIR.'inc/file-access/class--file-access.php';
		$sSecret = 'test-secret-must-be-at-least-32-chars!!';
		$sTok    = TPFW_File_Token::sign('qr', 'V1StGXR8Z5jdHi6BmyTxq', 0, $sSecret);
		$sVer    = 'a1b2c3d4e5f67890';
		$aArgs   = TPFW_File_Access::file_query_args('qr', 'V1StGXR8Z5jdHi6BmyTxq', 'webp', $sVer, $sTok, 0);
		$this->assertSame($sVer, $aArgs['v']);
		$this->assertSame($sTok, $aArgs['t']);
		$this->assertTrue(TPFW_File_Token::verify('qr', 'V1StGXR8Z5jdHi6BmyTxq', 0, $aArgs['t'], $sSecret));
		$this->assertFalse(TPFW_File_Token::verify('guest', 'V1StGXR8Z5jdHi6BmyTxq', 0, $aArgs['t'], $sSecret));
		$aUnsigned = TPFW_File_Access::file_query_args('qr', 'V1StGXR8Z5jdHi6BmyTxq', 'webp', $sVer);
		$this->assertArrayNotHasKey('t', $aUnsigned);
		$this->assertSame($sVer, $aUnsigned['v']);
	}

	public function test_content_revision_differs_when_size_and_mtime_match(): void
	{
		require_once TPFW_PLUGIN_DIR.'inc/file-access/class--file-access.php';
		$sDir = sys_get_temp_dir().'/tpfw-etag-'.bin2hex(random_bytes(4)).'/';
		mkdir($sDir, 0777, true);
		$sA = $sDir.'a.webp';
		$sB = $sDir.'b.webp';
		$sPayA = str_repeat('A', 64);
		$sPayB = str_repeat('B', 64);
		file_put_contents($sA, $sPayA);
		file_put_contents($sB, $sPayB);
		$iTime = 1_700_000_100;
		touch($sA, $iTime);
		touch($sB, $iTime);
		clearstatcache(true, $sA);
		clearstatcache(true, $sB);
		$this->assertSame(strlen($sPayA), strlen($sPayB));
		$this->assertSame($iTime, filemtime($sA));
		$this->assertSame($iTime, filemtime($sB));
		$sRevA = TPFW_File_Access::content_revision($sA);
		$sRevB = TPFW_File_Access::content_revision($sB);
		$this->assertNotSame('', $sRevA);
		$this->assertNotSame($sRevA, $sRevB);
		$this->assertNotSame(
			TPFW_File_Access::etag_for_file($sA, 'same.webp'),
			TPFW_File_Access::etag_for_file($sB, 'same.webp')
		);
		$this->assertNotSame(
			TPFW_File_Access::url_version('qr', $sRevA),
			TPFW_File_Access::url_version('qr', $sRevB)
		);
		$sPdfA = TPFW_File_Access::url_version('pdf', 'pdfrev', $sRevA);
		$sPdfB = TPFW_File_Access::url_version('pdf', 'pdfrev', $sRevB);
		$this->assertNotSame($sPdfA, $sPdfB);
		@unlink($sA);
		@unlink($sB);
		@rmdir($sDir);
	}

	/**
	 * @return string
	 */
	private function nginxSnippet()
	{
		$s = file_get_contents(TPFW_PLUGIN_DIR.'docs/server-config/nginx-deny-tpfw-uploads.conf');
		$this->assertNotFalse($s);
		return $s;
	}
}
