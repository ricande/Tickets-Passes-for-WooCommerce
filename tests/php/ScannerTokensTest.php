<?php
use PHPUnit\Framework\TestCase;

class ScannerTokensTest extends TestCase
{
	public function test_valid_token_verifies(): void
	{
		$sPlain = 'device-token-plain';
		$aList  = array(
			array('id' => 'a', 'hash' => TPFW_Scanner_Tokens::hash($sPlain), 'revoked' => false, 'user_id' => 4),
		);
		$aHit = TPFW_Scanner_Tokens::verify_list($sPlain, $aList);
		$this->assertIsArray($aHit);
		$this->assertSame(4, $aHit['user_id']);
	}

	public function test_revoked_token_is_rejected(): void
	{
		$sPlain = 'device-token-plain';
		$aList  = array(
			array('id' => 'a', 'hash' => TPFW_Scanner_Tokens::hash($sPlain), 'revoked' => true, 'user_id' => 4),
		);
		$this->assertNull(TPFW_Scanner_Tokens::verify_list($sPlain, $aList));
	}

	public function test_wrong_secret_is_rejected(): void
	{
		$aList = array(
			array('id' => 'a', 'hash' => TPFW_Scanner_Tokens::hash('right'), 'revoked' => false, 'user_id' => 4),
		);
		$this->assertNull(TPFW_Scanner_Tokens::verify_list('wrong', $aList));
	}
}
