<?php
use PHPUnit\Framework\TestCase;

class CheckinMethodTest extends TestCase
{
	public function test_api_registers_post_and_get_does_not_mutate(): void
	{
		$sApi = file_get_contents(TPFW_PLUGIN_DIR.'inc/api/class--api.php');
		$this->assertNotFalse(strpos($sApi, "'methods'             => 'POST'"));
		$this->assertNotFalse(strpos($sApi, 'scanner_checkin_not_allowed'));
		$this->assertNotFalse(strpos($sApi, 'function scanner_checkin_not_allowed'));
	}

	public function test_scanner_js_sends_post(): void
	{
		$sJs = file_get_contents(TPFW_PLUGIN_DIR.'inc/scanner/js/scanner.js');
		$iUrl = strpos($sJs, 'var sURL = cfg.sCheckinBase');
		$this->assertNotFalse($iUrl);
		$sSlice = substr($sJs, $iUrl, 500);
		$this->assertStringContainsString("method: 'POST'", $sSlice);
		$this->assertStringNotContainsString("method: 'GET'", $sSlice);
	}
}
