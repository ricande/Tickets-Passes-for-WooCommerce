<?php
use PHPUnit\Framework\TestCase;

class ScannerAuthTest extends TestCase
{
	private function user($iId)
	{
		$o = new stdClass();
		$o->ID = $iId;
		return $o;
	}

	private function resolve(array $aExtra)
	{
		$aCan = array();
		$a = array_merge(array(
			'sScannerToken'   => '',
			'bTokenValid'     => false,
			'oTokenUser'      => false,
			'bLoggedIn'       => false,
			'oCurrentUser'    => false,
			'bHasBasicHeader' => false,
			'bApiEnabled'     => true,
			'fnCanScan'       => static function($oUser) use (&$aCan) {
				return is_object($oUser) && !empty($oUser->ID) && !empty($aCan[(int)$oUser->ID]);
			},
		), $aExtra);
		if(isset($aExtra['aCanScanIds']))
		{
			foreach((array)$aExtra['aCanScanIds'] as $iId)
			{
				$aCan[(int)$iId] = true;
			}
			unset($a['aCanScanIds']);
		}
		return TPFW_Scanner_Auth::resolve($a);
	}

	public function test_cookie_logged_in_scanner_is_accepted(): void
	{
		$oUser = $this->user(4);
		$oHit  = $this->resolve(array(
			'bLoggedIn'    => true,
			'oCurrentUser' => $oUser,
			'bApiEnabled'  => false,
			'aCanScanIds'  => array(4),
		));
		$this->assertSame($oUser, $oHit);
	}

	public function test_wordpress_authenticated_application_password_scanner_is_accepted(): void
	{
		$oUser = $this->user(8);
		$oHit  = $this->resolve(array(
			'bLoggedIn'       => true,
			'oCurrentUser'    => $oUser,
			'bHasBasicHeader' => true,
			'bApiEnabled'     => true,
			'aCanScanIds'     => array(8),
		));
		$this->assertSame($oUser, $oHit);
	}

	public function test_wordpress_authenticated_user_without_scan_capability_is_denied(): void
	{
		$this->assertFalse($this->resolve(array(
			'bLoggedIn'       => true,
			'oCurrentUser'    => $this->user(9),
			'bHasBasicHeader' => true,
			'bApiEnabled'     => true,
			'aCanScanIds'     => array(),
		)));
		$this->assertFalse($this->resolve(array(
			'bLoggedIn'    => true,
			'oCurrentUser' => $this->user(9),
			'aCanScanIds'  => array(),
		)));
	}

	public function test_unauthenticated_request_is_denied(): void
	{
		$this->assertFalse($this->resolve(array(
			'bLoggedIn'       => false,
			'bHasBasicHeader' => true,
			'bApiEnabled'     => true,
			'aCanScanIds'     => array(1),
		)));
	}

	public function test_valid_scanner_token_is_accepted(): void
	{
		$oUser = $this->user(3);
		$oHit  = $this->resolve(array(
			'sScannerToken' => 'aabbccddeeff0011.'.str_repeat('ab', 24),
			'bTokenValid'   => true,
			'oTokenUser'    => $oUser,
			'bApiEnabled'   => true,
			'aCanScanIds'   => array(3),
		));
		$this->assertSame($oUser, $oHit);
	}

	public function test_invalid_or_revoked_scanner_token_is_denied(): void
	{
		$this->assertFalse($this->resolve(array(
			'sScannerToken' => 'aabbccddeeff0011.'.str_repeat('ab', 24),
			'bTokenValid'   => false,
			'oTokenUser'    => $this->user(3),
			'bApiEnabled'   => true,
			'aCanScanIds'   => array(3),
		)));
	}

	public function test_basic_header_does_not_authenticate_without_current_user(): void
	{
		$this->assertFalse($this->resolve(array(
			'bLoggedIn'       => false,
			'bHasBasicHeader' => true,
			'bApiEnabled'     => true,
			'sPassword'       => 'account-password',
			'aCanScanIds'     => array(1),
		)));
	}

	public function test_application_password_requires_enable_api(): void
	{
		$this->assertFalse($this->resolve(array(
			'bLoggedIn'       => true,
			'oCurrentUser'    => $this->user(8),
			'bHasBasicHeader' => true,
			'bApiEnabled'     => false,
			'aCanScanIds'     => array(8),
		)));
	}

	public function test_token_does_not_use_current_user_when_token_is_invalid(): void
	{
		$this->assertFalse($this->resolve(array(
			'sScannerToken' => 'deadbeefdeadbeef.'.str_repeat('cd', 24),
			'bTokenValid'   => false,
			'bLoggedIn'     => true,
			'oCurrentUser'  => $this->user(4),
			'bApiEnabled'   => true,
			'aCanScanIds'   => array(4),
		)));
	}

	public function test_get_basic_auth_user_does_not_call_wp_authenticate(): void
	{
		$sFn = file_get_contents(TPFW_PLUGIN_DIR.'inc/functions/class--functions.php');
		$i   = strpos($sFn, 'function get_basic_auth_user');
		$this->assertNotFalse($i);
		$sBody = substr($sFn, $i, 2200);
		$this->assertStringNotContainsString('wp_authenticate(', $sBody);
		$this->assertStringNotContainsString('PHP_AUTH_PW', $sBody);
		$this->assertStringNotContainsString('base64_decode', $sBody);
		$this->assertStringContainsString('TPFW_Scanner_Auth::resolve', $sBody);
		$this->assertStringContainsString('wp_get_current_user', $sBody);
	}

	public function test_scanner_auth_has_no_password_fallback(): void
	{
		$sSrc = file_get_contents(TPFW_PLUGIN_DIR.'inc/support/class--scanner-auth.php');
		$sCode = preg_replace('!/\*.*?\*/!s', '', $sSrc);
		$this->assertStringNotContainsString('wp_authenticate', $sCode);
		$this->assertStringNotContainsString('wp_check_password', $sCode);
		$this->assertStringNotContainsString('password_verify', $sCode);
		$sApi = file_get_contents(TPFW_PLUGIN_DIR.'inc/api/class--api.php');
		$this->assertStringContainsString('get_basic_auth_user', $sApi);
		$this->assertStringNotContainsString('wp_authenticate(', $sApi);
	}
}
