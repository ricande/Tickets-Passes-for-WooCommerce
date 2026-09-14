<?php
use PHPUnit\Framework\TestCase;

class NamedLockTest extends TestCase
{
	public function test_only_string_or_int_one_is_acquired(): void
	{
		$this->assertTrue(TPFW_Named_Lock::is_acquired('1'));
		$this->assertTrue(TPFW_Named_Lock::is_acquired(1));
		$this->assertFalse(TPFW_Named_Lock::is_acquired('0'));
		$this->assertFalse(TPFW_Named_Lock::is_acquired(0));
		$this->assertFalse(TPFW_Named_Lock::is_acquired(null));
		$this->assertFalse(TPFW_Named_Lock::is_acquired(''));
		$this->assertFalse(TPFW_Named_Lock::is_acquired(false));
	}

	public function test_timeout_and_null_skip_callback(): void
	{
		$mysqli = TPFW_Test_Credentials::mysqli();
		if(!$mysqli)
		{
			$this->markTestSkipped('No database credentials');
		}

		$wpdb = new TPFW_Test_Wpdb($mysqli, 'tpfwtest_');
		$sName = 'tpfw_locktest_'.bin2hex(random_bytes(4));

		$holder = TPFW_Named_Lock::acquire($wpdb, $sName, 5);
		$this->assertTrue($holder->held());

		$mysqli2 = TPFW_Test_Credentials::mysqli();
		$wpdb2   = new TPFW_Test_Wpdb($mysqli2, 'tpfwtest_');
		$blocked = TPFW_Named_Lock::acquire($wpdb2, $sName, 0);
		$this->assertFalse($blocked->held());
		$this->assertSame('0', (string)$blocked->raw());

		$bRan = false;
		if($blocked->held())
		{
			$bRan = true;
		}
		$this->assertFalse($bRan);

		$holder->release();
		$mysqli2->close();
	}

	public function test_null_raw_does_not_run_body(): void
	{
		$bRan = false;
		$mRaw = null;
		if(TPFW_Named_Lock::is_acquired($mRaw))
		{
			$bRan = true;
		}
		$this->assertFalse($bRan);
	}
}
