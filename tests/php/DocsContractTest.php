<?php
use PHPUnit\Framework\TestCase;

/**
 * Guards first-party docs against stale 1.2.3 / early-1.3.0 claims.
 * Asserts presence of key terms, not exact marketing prose.
 */
class DocsContractTest extends TestCase
{
	public function test_authorship_is_preserved(): void
	{
		$sMain   = file_get_contents(TPFW_PLUGIN_DIR.'tickets-passes-for-woocommerce.php');
		$sReadme = file_get_contents(TPFW_PLUGIN_DIR.'readme.txt');
		$sDev    = file_get_contents(TPFW_PLUGIN_DIR.'README.md');
		$this->assertMatchesRegularExpression('/^\s*\*\s*Author:\s*Magnus V\.\s*$/m', $sMain);
		$this->assertMatchesRegularExpression('/^Contributors:\s*macvej\s*$/m', $sReadme);
		$this->assertNotFalse(strpos($sDev, 'Magnus V.'));
		$this->assertNotFalse(strpos($sDev, 'macvej'));
		$this->assertNotFalse(strpos($sDev, '1.2.3'));
		$this->assertNotFalse(strpos($sReadme, 'GPLv2 or later'));
	}

	public function test_issue_docs_are_not_completed_only_or_processing_paid(): void
	{
		$aDocs = array(
			TPFW_PLUGIN_DIR.'docs/architecture.md',
			TPFW_PLUGIN_DIR.'docs/product-types.md',
			TPFW_PLUGIN_DIR.'docs/install.md',
			TPFW_PLUGIN_DIR.'readme.txt',
		);
		foreach($aDocs as $sPath)
		{
			$s = file_get_contents($sPath);
			$this->assertNotFalse(stripos($s, 'payment_complete') || stripos($s, 'payment complete'), $sPath);
			$this->assertStringNotContainsString('processing==paid', $s, $sPath);
			$this->assertStringNotContainsString('processing is paid', $s, $sPath);
			$this->assertStringNotContainsString('status processing or completed', $s, $sPath);
			$this->assertStringNotContainsString('A processing or completed order issues', $s, $sPath);
		}
	}

	public function test_scanner_docs_do_not_recommend_account_password(): void
	{
		$aDocs = array(
			TPFW_PLUGIN_DIR.'docs/check-in.md',
			TPFW_PLUGIN_DIR.'docs/install.md',
			TPFW_PLUGIN_DIR.'readme.txt',
			TPFW_PLUGIN_DIR.'inc/api/setting-pages/api-page-content.php',
		);
		foreach($aDocs as $sPath)
		{
			$s = file_get_contents($sPath);
			$this->assertNotFalse(stripos($s, 'Application Password'), $sPath);
			$this->assertNotFalse(strpos($s, 'X-TPFW-Scanner-Token') || strpos($s, 'token_id.secret') || strpos($s, '{token_id}.{secret}'), $sPath);
			$this->assertStringNotContainsString('use the account login password', strtolower($s), $sPath);
			$this->assertStringNotContainsString('send the WordPress password', $s, $sPath);
		}
		$sCheckin = file_get_contents(TPFW_PLUGIN_DIR.'docs/check-in.md');
		$this->assertNotFalse(strpos($sCheckin, 'token_id}.{secret') || strpos($sCheckin, 'token_id.secret'));
		$this->assertNotFalse(strpos($sCheckin, 'does not accept the account login password') || strpos($sCheckin, 'does not accept'));
	}

	public function test_release_and_test_commands_are_documented(): void
	{
		$sRel  = file_get_contents(TPFW_PLUGIN_DIR.'docs/release.md');
		$sInst = file_get_contents(TPFW_PLUGIN_DIR.'docs/install.md');
		foreach(array($sRel, $sInst) as $s)
		{
			$this->assertNotFalse(strpos($s, 'bash tests/run.sh'));
			$this->assertNotFalse(strpos($s, 'ensure-phpunit.sh'));
			$this->assertNotFalse(strpos($s, 'bash scripts/build-plugin-zip.sh'));
			$this->assertNotFalse(strpos($s, 'dist/tickets-passes-for-woocommerce-1.3.0.zip'));
		}
	}

	public function test_version_headers_match_changelog(): void
	{
		$sMain = file_get_contents(TPFW_PLUGIN_DIR.'tickets-passes-for-woocommerce.php');
		$sRead = file_get_contents(TPFW_PLUGIN_DIR.'readme.txt');
		$sLog  = file_get_contents(TPFW_PLUGIN_DIR.'changelog.txt');
		$this->assertMatchesRegularExpression('/^\s*\*\s*Version:\s*1\.3\.0\s*$/m', $sMain);
		$this->assertNotFalse(strpos($sMain, "define('TPFW_VERSION', '1.3.0')"));
		$this->assertNotFalse(strpos($sRead, 'Stable tag: 1.3.0'));
		$this->assertMatchesRegularExpression('/^= 1\.3\.0 =/m', $sLog);
		$this->assertMatchesRegularExpression('/^= 1\.3\.0 =/m', $sRead);
	}
}
