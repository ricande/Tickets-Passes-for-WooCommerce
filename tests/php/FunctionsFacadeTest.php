<?php
use PHPUnit\Framework\TestCase;

class FunctionsFacadeTest extends TestCase
{
	public function test_support_classes_are_loaded_by_main(): void
	{
		$sMain = file_get_contents(TPFW_PLUGIN_DIR.'class--main.php');
		$this->assertNotFalse(strpos($sMain, 'inc/support/load.php'));
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
	}
}
