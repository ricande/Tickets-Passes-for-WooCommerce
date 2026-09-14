<?php
use PHPUnit\Framework\TestCase;

class IssuePolicyTest extends TestCase
{
	public function test_processing_and_completed_mint(): void
	{
		$this->assertTrue(TPFW_Issue_Policy::should_issue('processing'));
		$this->assertTrue(TPFW_Issue_Policy::should_issue('completed'));
		$this->assertFalse(TPFW_Issue_Policy::should_issue('pending'));
		$this->assertFalse(TPFW_Issue_Policy::should_issue('on-hold'));
	}

	public function test_cancelled_refunded_failed_revoke(): void
	{
		$this->assertTrue(TPFW_Issue_Policy::should_revoke('cancelled'));
		$this->assertTrue(TPFW_Issue_Policy::should_revoke('refunded'));
		$this->assertTrue(TPFW_Issue_Policy::should_revoke('failed'));
		$this->assertFalse(TPFW_Issue_Policy::should_revoke('completed'));
	}
}
