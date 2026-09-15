<?php
use PHPUnit\Framework\TestCase;

class ScannerTokensTest extends TestCase
{
	protected function setUp(): void
	{
		TPFW_Scanner_Tokens::reset_secret_verify_count();
	}

	public function test_valid_token_id_and_secret_is_accepted(): void
	{
		$sId     = $this->hexId();
		$sSecret = $this->hexSecret();
		$aList   = array(
			$sId => array(
				'id'      => $sId,
				'hash'    => TPFW_Scanner_Tokens::hash($sSecret),
				'revoked' => false,
				'user_id' => 4,
			),
		);
		$aHit = TPFW_Scanner_Tokens::verify_list($sId.'.'.$sSecret, $aList);
		$this->assertIsArray($aHit);
		$this->assertSame(4, $aHit['user_id']);
		$this->assertSame(1, TPFW_Scanner_Tokens::secret_verify_count());
	}

	public function test_unknown_token_id_is_denied_without_secret_verify(): void
	{
		$sKnown  = $this->hexId();
		$sSecret = $this->hexSecret();
		$aList   = array();
		for($i = 0; $i < 8; $i++)
		{
			$sId = $this->hexId();
			$aList[$sId] = array(
				'id'      => $sId,
				'hash'    => TPFW_Scanner_Tokens::hash($this->hexSecret()),
				'revoked' => false,
				'user_id' => $i + 1,
			);
		}
		$aList[$sKnown] = array(
			'id'      => $sKnown,
			'hash'    => TPFW_Scanner_Tokens::hash($sSecret),
			'revoked' => false,
			'user_id' => 99,
		);
		$sUnknown = $this->hexId();
		$this->assertNull(TPFW_Scanner_Tokens::verify_list($sUnknown.'.'.$sSecret, $aList));
		$this->assertSame(0, TPFW_Scanner_Tokens::secret_verify_count());
	}

	public function test_known_id_wrong_secret_verifies_once(): void
	{
		$sId    = $this->hexId();
		$sRight = $this->hexSecret();
		$sWrong = $this->hexSecret();
		$this->assertNotSame($sRight, $sWrong);
		$aList = array(
			$sId => array(
				'id'      => $sId,
				'hash'    => TPFW_Scanner_Tokens::hash($sRight),
				'revoked' => false,
				'user_id' => 4,
			),
		);
		$this->assertNull(TPFW_Scanner_Tokens::verify_list($sId.'.'.$sWrong, $aList));
		$this->assertSame(1, TPFW_Scanner_Tokens::secret_verify_count());
	}

	public function test_revoked_token_is_denied_without_secret_verify(): void
	{
		$sId     = $this->hexId();
		$sSecret = $this->hexSecret();
		$aList   = array(
			$sId => array(
				'id'      => $sId,
				'hash'    => TPFW_Scanner_Tokens::hash($sSecret),
				'revoked' => true,
				'user_id' => 4,
			),
		);
		$this->assertNull(TPFW_Scanner_Tokens::verify_list($sId.'.'.$sSecret, $aList));
		$this->assertSame(0, TPFW_Scanner_Tokens::secret_verify_count());
	}

	public function test_two_active_tokens_work_individually(): void
	{
		$sIdA = $this->hexId();
		$sIdB = $this->hexId();
		$sSecA = $this->hexSecret();
		$sSecB = $this->hexSecret();
		$aList = array(
			$sIdA => array('id' => $sIdA, 'hash' => TPFW_Scanner_Tokens::hash($sSecA), 'revoked' => false, 'user_id' => 1),
			$sIdB => array('id' => $sIdB, 'hash' => TPFW_Scanner_Tokens::hash($sSecB), 'revoked' => false, 'user_id' => 2),
		);
		$aA = TPFW_Scanner_Tokens::verify_list($sIdA.'.'.$sSecA, $aList);
		$aB = TPFW_Scanner_Tokens::verify_list($sIdB.'.'.$sSecB, $aList);
		$this->assertSame(1, $aA['user_id']);
		$this->assertSame(2, $aB['user_id']);
		$this->assertSame(2, TPFW_Scanner_Tokens::secret_verify_count());
	}

	public function test_revoke_one_leaves_the_other_valid(): void
	{
		$sIdA = $this->hexId();
		$sIdB = $this->hexId();
		$sSecA = $this->hexSecret();
		$sSecB = $this->hexSecret();
		$aList = array(
			$sIdA => array('id' => $sIdA, 'hash' => TPFW_Scanner_Tokens::hash($sSecA), 'revoked' => true, 'user_id' => 1),
			$sIdB => array('id' => $sIdB, 'hash' => TPFW_Scanner_Tokens::hash($sSecB), 'revoked' => false, 'user_id' => 2),
		);
		$this->assertNull(TPFW_Scanner_Tokens::verify_list($sIdA.'.'.$sSecA, $aList));
		$this->assertSame(0, TPFW_Scanner_Tokens::secret_verify_count());
		$aB = TPFW_Scanner_Tokens::verify_list($sIdB.'.'.$sSecB, $aList);
		$this->assertSame(2, $aB['user_id']);
		$this->assertSame(1, TPFW_Scanner_Tokens::secret_verify_count());
	}

	public function test_malformed_token_is_denied_cheaply(): void
	{
		$sId     = $this->hexId();
		$sSecret = $this->hexSecret();
		$aList   = array(
			$sId => array(
				'id'      => $sId,
				'hash'    => TPFW_Scanner_Tokens::hash($sSecret),
				'revoked' => false,
				'user_id' => 4,
			),
		);
		$aBad = array(
			'',
			$sSecret,
			$sId,
			$sId.'.',
			'.'.$sSecret,
			$sId.'.'.$sSecret.'.extra',
			$sId.'-'.$sSecret,
			'not-hex.'.$sSecret,
			$sId.'.short',
			"{$sId}.{$sSecret};DROP",
		);
		foreach($aBad as $sBad)
		{
			TPFW_Scanner_Tokens::reset_secret_verify_count();
			$this->assertNull(TPFW_Scanner_Tokens::verify_list($sBad, $aList), $sBad);
			$this->assertSame(0, TPFW_Scanner_Tokens::secret_verify_count(), $sBad);
		}
	}

	public function test_verify_never_iterates_password_verify_over_all_tokens(): void
	{
		$sSrc = file_get_contents(TPFW_PLUGIN_DIR.'inc/support/class--scanner-tokens.php');
		$this->assertNotFalse(strpos($sSrc, 'function verify_secret'));
		$this->assertSame(1, preg_match_all('/return password_verify\s*\(/', $sSrc));
		$sVerify = $this->methodBody($sSrc, 'verify_list');
		$this->assertStringNotContainsString('password_verify', $sVerify);
		$this->assertStringContainsString('find_record', $sVerify);
		$this->assertStringContainsString('verify_secret', $sVerify);

		$aList = array();
		for($i = 0; $i < 12; $i++)
		{
			$sId = $this->hexId();
			$aList[$sId] = array(
				'id'      => $sId,
				'hash'    => TPFW_Scanner_Tokens::hash($this->hexSecret()),
				'revoked' => false,
				'user_id' => $i,
			);
		}
		TPFW_Scanner_Tokens::reset_secret_verify_count();
		$this->assertNull(TPFW_Scanner_Tokens::verify_list($this->hexId().'.'.$this->hexSecret(), $aList));
		$this->assertSame(0, TPFW_Scanner_Tokens::secret_verify_count());

		$sHitId  = array_key_first($aList);
		$sWrong  = $this->hexSecret();
		TPFW_Scanner_Tokens::reset_secret_verify_count();
		$this->assertNull(TPFW_Scanner_Tokens::verify_list($sHitId.'.'.$sWrong, $aList));
		$this->assertSame(1, TPFW_Scanner_Tokens::secret_verify_count());
	}

	public function test_legacy_opaque_secret_is_rejected_without_verify(): void
	{
		$sSecret = $this->hexSecret();
		$sId     = $this->hexId();
		$aList   = array(
			$sId => array(
				'id'      => $sId,
				'hash'    => TPFW_Scanner_Tokens::hash($sSecret),
				'revoked' => false,
				'user_id' => 4,
			),
		);
		$this->assertNull(TPFW_Scanner_Tokens::verify_list($sSecret, $aList));
		$this->assertSame(0, TPFW_Scanner_Tokens::secret_verify_count());
	}

	public function test_create_returns_id_dot_secret_and_does_not_embed_plain_secret_in_record(): void
	{
		$aCreated = TPFW_Scanner_Tokens::create('door-1', 7);
		$this->assertTrue(TPFW_Scanner_Tokens::is_token_id($aCreated['id']));
		$aParsed = TPFW_Scanner_Tokens::parse($aCreated['plain']);
		$this->assertNotNull($aParsed);
		$this->assertSame($aCreated['id'], $aParsed['id']);
		$this->assertTrue(TPFW_Scanner_Tokens::is_secret($aParsed['secret']));
		$aRecord = array(
			'id'      => $aCreated['id'],
			'hash'    => TPFW_Scanner_Tokens::hash($aParsed['secret']),
			'revoked' => false,
			'user_id' => 7,
		);
		$this->assertStringNotContainsString($aParsed['secret'], json_encode($aRecord) ?: '');
	}

	public function test_parse_rejects_sql_like_token_id(): void
	{
		$this->assertNull(TPFW_Scanner_Tokens::parse("1 OR 1=1.".$this->hexSecret()));
		$this->assertFalse(TPFW_Scanner_Tokens::is_token_id("1; DROP TABLE x"));
	}

	/**
	 * @param string $sSrc
	 * @param string $sName
	 * @return string
	 */
	private function methodBody($sSrc, $sName)
	{
		$i = strpos($sSrc, 'function '.$sName);
		$this->assertNotFalse($i, $sName);
		return substr($sSrc, $i, 800);
	}

	/**
	 * @return string
	 */
	private function hexId()
	{
		return bin2hex(random_bytes(8));
	}

	/**
	 * @return string
	 */
	private function hexSecret()
	{
		return bin2hex(random_bytes(24));
	}
}
