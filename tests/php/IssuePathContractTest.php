<?php
use PHPUnit\Framework\TestCase;

/**
 * Guards the paid-order issue path against the class of miss that left tickets uncreated:
 * a table name that never got a value, so wpdb wrote INSERT INTO ``.
 */
class IssuePathContractTest extends TestCase
{
	public function test_scanner_flags_unassigned_upsert_table(): void
	{
		$sSrc = <<<'PHP'
<?php
class TPFW_Ticket_WC_Product {
	public function create_ticket($iOrderID, $iCustomerID, $oOrderItem) {
		global $wpdb;
		$aExistsResult = array();
		$sCurrentDatetime = 'now';
		$aSync = TPFW_Order_Line_Upsert::sync($wpdb, $sTicketTable, $aExistsResult, 1, $sCurrentDatetime, function() use ($wpdb, $sTicketTable) {
			$wpdb->query($wpdb->prepare('INSERT INTO %i (nano_id) VALUES (%s)', $sTicketTable, 'x'));
		});
	}
}
PHP;
		$a = TPFW_Php_Scope_Scan::problems($sSrc, 'fixture.php');
		$sAll = implode("\n", $a);
		$this->assertStringContainsString('closure use $sTicketTable is never assigned', $sAll);
		$this->assertStringContainsString('upsert table $sTicketTable is never assigned', $sAll);
	}

	public function test_scanner_accepts_prefixed_table_assignment(): void
	{
		$sSrc = <<<'PHP'
<?php
class TPFW_Ticket_WC_Product {
	protected $sTable = 'tpfw_tickets';
	public function create_ticket($iOrderID, $iCustomerID, $oOrderItem) {
		global $wpdb;
		$sTicketTable = $wpdb->prefix.$this->sTable;
		$aExistsResult = array();
		TPFW_Order_Line_Upsert::sync($wpdb, $sTicketTable, $aExistsResult, 1, 'now', function() use ($wpdb, $sTicketTable) {
			return 'n';
		});
	}
}
PHP;
		$this->assertSame(array(), TPFW_Php_Scope_Scan::problems($sSrc, 'ok.php'));
	}

	public function test_first_party_upsert_and_issue_closures_are_assigned(): void
	{
		$aAll = array();
		foreach(TPFW_Php_Scope_Scan::first_party_files() as $sFile)
		{
			$sSrc = file_get_contents($sFile);
			if($sSrc === false)
			{
				$this->fail('unreadable '.$sFile);
			}
			$sLabel = substr($sFile, strlen(TPFW_PLUGIN_DIR));
			$aAll   = array_merge($aAll, TPFW_Php_Scope_Scan::problems($sSrc, $sLabel));
		}
		$this->assertSame(array(), $aAll, implode("\n", $aAll));
	}

	public function test_issue_methods_exist_for_all_three_types(): void
	{
		$aNeed = array(
			'inc/ticket-wc-product/class--ticket-wc-product.php' => 'function create_ticket',
			'inc/timeslot-ticket-wc-product/class--timeslot-ticket-wc-product.php' => 'function create_timeslot_ticket',
			'inc/pass-wc-product/class--pass-wc-product.php' => 'function create_pass',
		);
		foreach($aNeed as $sRel => $sNeedle)
		{
			$sSrc = file_get_contents(TPFW_PLUGIN_DIR.$sRel);
			$this->assertNotFalse(strpos($sSrc, $sNeedle), $sRel);
			$this->assertNotFalse(strpos($sSrc, 'INSERT INTO %i'), $sRel.' must insert through %i');
		}
	}

	public function test_ticket_issue_is_fail_closed_before_qr_and_meta(): void
	{
		$sSrc = file_get_contents(TPFW_PLUGIN_DIR.'inc/ticket-wc-product/class--ticket-wc-product.php');
		$this->assertNotFalse(strpos($sSrc, 'TPFW_Db_Write::inserted_row'));
		$this->assertNotFalse(strpos($sSrc, "empty(\$aSync['ok'])"));
		$iOk = strpos($sSrc, "empty(\$aSync['ok'])");
		$iQr = strpos($sSrc, 'write_scanner_qr');
		$this->assertNotFalse($iQr);
		$this->assertLessThan($iQr, $iOk);
	}

	public function test_timeslot_issue_is_fail_closed_before_qr_and_meta(): void
	{
		$sSrc = file_get_contents(TPFW_PLUGIN_DIR.'inc/timeslot-ticket-wc-product/class--timeslot-ticket-wc-product.php');
		$this->assertNotFalse(strpos($sSrc, 'TPFW_Db_Write::inserted_row'));
		$this->assertNotFalse(strpos($sSrc, "empty(\$aSync['ok'])"));
		$iOk = strpos($sSrc, "empty(\$aSync['ok'])");
		$iQr = strpos($sSrc, 'write_scanner_qr');
		$this->assertNotFalse($iQr);
		$this->assertLessThan($iQr, $iOk);
		$iRelease = strpos($sSrc, '$oLock->release()');
		$this->assertNotFalse($iRelease);
		$this->assertLessThan($iOk, $iRelease);
	}

	public function test_pass_gift_mail_is_after_verified_insert(): void
	{
		$sSrc = file_get_contents(TPFW_PLUGIN_DIR.'inc/pass-wc-product/class--pass-wc-product.php');
		$iInsert = strpos($sSrc, 'TPFW_Db_Write::inserted_row($mInsert)');
		$iMail   = strpos($sSrc, 'tpfw_custom_enmail');
		$this->assertNotFalse($iInsert);
		$this->assertNotFalse($iMail);
		$this->assertLessThan($iMail, $iInsert);
		$this->assertNotFalse(strpos($sSrc, 'TPFW_Db_Write::failed($mRestore)'));
	}

	public function test_test_wpdb_rejects_empty_identifier(): void
	{
		$mysqli = TPFW_Test_Credentials::mysqli();
		if(!$mysqli)
		{
			$this->markTestSkipped('No database credentials');
		}
		$wpdb = new TPFW_Test_Wpdb($mysqli, 'tpfwtest_');
		$this->expectException(InvalidArgumentException::class);
		$wpdb->prepare('INSERT INTO %i (nano_id) VALUES (%s)', array('', 'x'));
	}
}
