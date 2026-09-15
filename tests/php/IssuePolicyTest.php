<?php
use PHPUnit\Framework\TestCase;

class IssuePolicyTest extends TestCase
{
	public function test_processing_without_payment_complete_does_not_mint(): void
	{
		$this->assertFalse(TPFW_Issue_Policy::should_issue_for_intent(TPFW_Issue_Policy::PROCESSING));
		$this->assertFalse(TPFW_Issue_Policy::should_issue('processing'));
		$this->assertFalse(TPFW_Issue_Policy::should_issue('pending'));
		$this->assertFalse(TPFW_Issue_Policy::should_issue('on-hold'));
	}

	public function test_payment_complete_while_processing_mints(): void
	{
		$this->assertTrue(TPFW_Issue_Policy::should_issue_for_intent(TPFW_Issue_Policy::PAYMENT_COMPLETE, 'processing'));
	}

	public function test_payment_complete_then_completed_still_mints_idempotently(): void
	{
		$this->assertTrue(TPFW_Issue_Policy::should_issue_for_intent(TPFW_Issue_Policy::PAYMENT_COMPLETE, 'processing'));
		$this->assertTrue(TPFW_Issue_Policy::should_issue_for_intent(TPFW_Issue_Policy::COMPLETED, 'completed'));
		$this->assertTrue(TPFW_Issue_Policy::should_issue_for_intent(TPFW_Issue_Policy::COMPLETED, 'completed'));
	}

	public function test_completed_without_prior_payment_complete_mints(): void
	{
		$this->assertTrue(TPFW_Issue_Policy::should_issue_for_intent(TPFW_Issue_Policy::COMPLETED, 'completed'));
		$this->assertTrue(TPFW_Issue_Policy::should_issue('completed'));
		$this->assertTrue(TPFW_Issue_Policy::should_issue('wc-completed'));
	}

	public function test_processing_does_not_mint_for_any_method_including_cod(): void
	{
		$this->assertFalse(TPFW_Issue_Policy::should_issue_for_intent(TPFW_Issue_Policy::PROCESSING, 'processing'));
	}

	public function test_unknown_offline_method_processing_does_not_mint(): void
	{
		$this->assertFalse(TPFW_Issue_Policy::should_issue_for_intent(TPFW_Issue_Policy::PROCESSING, 'processing'));
		$this->assertFalse(TPFW_Issue_Policy::should_issue('processing'));
	}

	public function test_admin_force_create_mints(): void
	{
		$this->assertTrue(TPFW_Issue_Policy::should_issue_for_intent(TPFW_Issue_Policy::FORCE, 'processing'));
		$this->assertTrue(TPFW_Issue_Policy::should_issue_for_intent(TPFW_Issue_Policy::FORCE, 'on-hold'));
	}

	public function test_revoked_status_blocks_automatic_intents_but_not_admin_force(): void
	{
		$this->assertFalse(TPFW_Issue_Policy::should_issue_for_intent(TPFW_Issue_Policy::PAYMENT_COMPLETE, 'cancelled'));
		$this->assertFalse(TPFW_Issue_Policy::should_issue_for_intent(TPFW_Issue_Policy::COMPLETED, 'refunded'));
		$this->assertTrue(TPFW_Issue_Policy::should_issue_for_intent(TPFW_Issue_Policy::FORCE, 'failed'));
	}

	public function test_cancelled_refunded_failed_revoke(): void
	{
		$this->assertTrue(TPFW_Issue_Policy::should_revoke('cancelled'));
		$this->assertTrue(TPFW_Issue_Policy::should_revoke('refunded'));
		$this->assertTrue(TPFW_Issue_Policy::should_revoke('failed'));
		$this->assertTrue(TPFW_Issue_Policy::should_revoke('wc-refunded'));
		$this->assertFalse(TPFW_Issue_Policy::should_revoke('completed'));
	}

	public function test_all_three_types_share_payment_complete_and_completed_hooks(): void
	{
		$aFiles = array(
			'inc/ticket-wc-product/class--ticket-wc-product.php',
			'inc/timeslot-ticket-wc-product/class--timeslot-ticket-wc-product.php',
			'inc/pass-wc-product/class--pass-wc-product.php',
		);
		foreach($aFiles as $sRel)
		{
			$sSrc = file_get_contents(TPFW_PLUGIN_DIR.$sRel);
			$this->assertNotFalse(strpos($sSrc, "add_action('woocommerce_payment_complete'"), $sRel);
			$this->assertNotFalse(strpos($sSrc, 'order_payment_complete'), $sRel);
			$this->assertNotFalse(strpos($sSrc, "add_action('woocommerce_order_status_completed'"), $sRel);
			$this->assertNotFalse(strpos($sSrc, 'order_status_completed'), $sRel);
			$this->assertFalse(strpos($sSrc, "add_action('woocommerce_order_status_processing'"), $sRel.' must not hook processing');
		}

		$sBase = file_get_contents(TPFW_PLUGIN_DIR.'inc/product-type/class--product-type.php');
		$this->assertNotFalse(strpos($sBase, 'function order_payment_complete'));
		$this->assertNotFalse(strpos($sBase, 'function order_status_completed'));
		$this->assertNotFalse(strpos($sBase, 'function order_force_issue'));
		$this->assertNotFalse(strpos($sBase, 'function issue_order_lines'));
		$this->assertFalse(strpos($sBase, 'function order_maybe_issue'));
		$this->assertFalse(strpos($sBase, "add_action('woocommerce_order_status_processing'"));
	}

	public function test_admin_create_uses_force_path(): void
	{
		$sAdmin = file_get_contents(TPFW_PLUGIN_DIR.'inc/admin/class--admin.php');
		$this->assertNotFalse(strpos($sAdmin, '->order_completed($iOrderID)'));
		$this->assertFalse(strpos($sAdmin, '->order_payment_complete($iOrderID)'));
		$sBase = file_get_contents(TPFW_PLUGIN_DIR.'inc/product-type/class--product-type.php');
		$this->assertNotFalse(strpos($sBase, '$this->order_force_issue($order_id)'));
	}

	public function test_policy_has_no_hardcoded_gateway_ids(): void
	{
		$sSrc = file_get_contents(TPFW_PLUGIN_DIR.'inc/support/class--issue-policy.php');
		foreach(array("'cod'", '"cod"', "'bacs'", '"bacs"', "'cheque'", '"cheque"', "'stripe'", '"stripe"', "'paypal'", '"paypal"') as $sNeedle)
		{
			$this->assertFalse(strpos($sSrc, $sNeedle), 'policy must not name gateway '.$sNeedle);
		}
		$this->assertFalse(strpos($sSrc, 'OFFLINE_METHODS'));
		$this->assertFalse(strpos($sSrc, 'get_payment_method'));
		$this->assertFalse(strpos($sSrc, 'get_date_paid'));
	}
}
