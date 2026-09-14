<?php
use PHPUnit\Framework\TestCase;

class GuestPassActivationTest extends TestCase
{
	public function test_guest_refused_before_parent_scan(): void
	{
		$oGuest = (object)array('valid_from' => null, 'valid_to' => null);
		$this->assertSame('parent_not_checked_in', TPFW_Guest_Pass_Issuer::may_checkin($oGuest, true, 0));
	}

	public function test_guest_ok_after_parent_scan_and_window(): void
	{
		$oGuest = (object)array(
			'valid_from' => '2026-09-14 10:00:00',
			'valid_to'   => '2026-09-15 10:00:00',
		);
		$this->assertSame('ok', TPFW_Guest_Pass_Issuer::may_checkin($oGuest, true, 1));
	}

	public function test_cancelled_parent_is_refused(): void
	{
		$oGuest = (object)array(
			'valid_from' => '2026-09-14 10:00:00',
			'valid_to'   => '2026-09-15 10:00:00',
		);
		$this->assertSame('no_parent', TPFW_Guest_Pass_Issuer::may_checkin($oGuest, false, 1));
	}

	public function test_parent_stats_without_window_still_inactive(): void
	{
		$oGuest = (object)array('valid_from' => null, 'valid_to' => null);
		$this->assertSame('inactive', TPFW_Guest_Pass_Issuer::may_checkin($oGuest, true, 1));
	}
}
