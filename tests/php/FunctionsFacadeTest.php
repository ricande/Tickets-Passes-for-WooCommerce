<?php
use PHPUnit\Framework\TestCase;

class FunctionsFacadeTest extends TestCase
{
	public function test_support_classes_are_loaded_by_main(): void
	{
		$sMain = file_get_contents(TPFW_PLUGIN_DIR.'class--main.php');
		$this->assertNotFalse(strpos($sMain, 'inc/support/load.php'));
	}

	public function test_activation_loads_support_before_db_installer(): void
	{
		$sBoot = file_get_contents(TPFW_PLUGIN_DIR.'tickets-passes-for-woocommerce.php');
		$this->assertSame(1, preg_match(
			'/function tpfw_activate_plugin\(\)\s*\{(.*?)\n    \}/s',
			$sBoot,
			$aMatch
		));
		$sBody = $aMatch[1];
		$iLoad = strpos($sBody, "inc/support/load.php");
		$iNew  = strpos($sBody, 'new TPFW_DB_Installer');
		$this->assertNotFalse($iLoad);
		$this->assertNotFalse($iNew);
		$this->assertLessThan($iNew, $iLoad);
		$this->assertNotFalse(strpos(
			file_get_contents(TPFW_PLUGIN_DIR.'inc/support/load.php'),
			'class--guest-pass-issuer.php'
		));
		$this->assertNotFalse(strpos(
			file_get_contents(TPFW_PLUGIN_DIR.'inc/support/load.php'),
			'class--ticket-line.php'
		));
		$this->assertNotFalse(strpos(
			file_get_contents(TPFW_PLUGIN_DIR.'inc/support/load.php'),
			'class--db-read.php'
		));
		$this->assertNotFalse(strpos(
			file_get_contents(TPFW_PLUGIN_DIR.'inc/db-installer/class--db-installer.php'),
			'TPFW_Guest_Pass_Issuer::backfill_legacy_slots'
		));
	}

	public function test_named_lock_is_used_for_checkin(): void
	{
		$sFn = file_get_contents(TPFW_PLUGIN_DIR.'inc/functions/class--functions.php');
		$this->assertNotFalse(strpos($sFn, 'TPFW_Named_Lock::acquire'));
		$this->assertNotFalse(strpos($sFn, 'TPFW_Checkin_Payload::allowlist'));
	}

	public function test_qr_render_is_loaded_by_support(): void
	{
		$sLoad = file_get_contents(TPFW_PLUGIN_DIR.'inc/support/load.php');
		$this->assertNotFalse(strpos($sLoad, 'class--qr-render.php'));
		$this->assertNotFalse(strpos($sLoad, 'class--qr-rewrite.php'));
		$this->assertNotFalse(strpos($sLoad, 'class--db-read.php'));
	}
}
