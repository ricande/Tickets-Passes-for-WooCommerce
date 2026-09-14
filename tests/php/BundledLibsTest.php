<?php
use PHPUnit\Framework\TestCase;

class BundledLibsTest extends TestCase
{
	public function test_readme_versions_match_installed_json(): void
	{
		$aInstalled = TPFW_Bundled_Libs::versions_from_installed(rtrim(TPFW_PLUGIN_DIR, '/'));
		$sReadme    = file_get_contents(TPFW_PLUGIN_DIR.'readme.txt');
		$aReadme    = TPFW_Bundled_Libs::versions_from_readme($sReadme);

		$this->assertNotEmpty($aInstalled);
		$this->assertArrayHasKey('endroid/qr-code', $aReadme);
		$this->assertSame($aInstalled['endroid/qr-code'], $aReadme['endroid/qr-code']);
		$this->assertSame($aInstalled['bacon/bacon-qr-code'], $aReadme['bacon/bacon-qr-code']);
		$this->assertSame($aInstalled['dasprid/enum'], $aReadme['dasprid/enum']);
		$this->assertSame($aInstalled['dompdf/dompdf'], $aReadme['dompdf/dompdf']);
		$this->assertSame($aInstalled['thecodingmachine/safe'], $aReadme['thecodingmachine/safe']);
	}
}
