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
