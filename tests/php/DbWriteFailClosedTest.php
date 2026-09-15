<?php
use PHPUnit\Framework\TestCase;

class DbWriteFailClosedTest extends TestCase
{
	private ?TPFW_Test_Wpdb $wpdb = null;

	protected function setUp(): void
	{
		$mysqli = TPFW_Test_Credentials::mysqli();
		if(!$mysqli)
		{
			$this->markTestSkipped('No database credentials');
		}
		$this->wpdb = new TPFW_Test_Wpdb($mysqli, 'tpfwtest_');
		TPFW_Test_Schema::install($this->wpdb);
	}

	protected function tearDown(): void
	{
		if($this->wpdb)
		{
			TPFW_Test_Schema::drop($this->wpdb);
			$this->wpdb->mysqli()->close();
		}
	}

	public function test_wpdb_false_is_failure_zero_is_not(): void
	{
		$this->assertTrue(TPFW_Db_Write::failed(false));
		$this->assertFalse(TPFW_Db_Write::failed(0));
		$this->assertFalse(TPFW_Db_Write::failed(1));
		$this->assertTrue(TPFW_Db_Write::succeeded(0));
		$this->assertTrue(TPFW_Db_Write::succeeded(2));
		$this->assertFalse(TPFW_Db_Write::succeeded(false));
		$this->assertFalse(TPFW_Db_Write::inserted_row(false));
		$this->assertFalse(TPFW_Db_Write::inserted_row(0));
		$this->assertTrue(TPFW_Db_Write::inserted_row(1));
	}

	public function test_ticket_insert_failure_is_not_issued(): void
	{
		$wpdb = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) {
			return stripos($sSql, 'INSERT INTO') !== false;
		});
		$aMeta = array();
		$aQr   = array();
		$aOut  = $this->issueTicketLikeCreate($wpdb, 1, $aMeta, $aQr);
		$this->assertFalse($aOut['bStatus']);
		$this->assertSame(array(), $aMeta);
		$this->assertSame(array(), $aQr);
		$this->assertSame(0, $this->countLive($this->wpdb->prefix.'tpfw_tickets'));
	}

	public function test_pass_insert_failure_skips_mail_guests_and_meta(): void
	{
		$wpdb = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) {
			return stripos($sSql, 'INSERT INTO') !== false && stripos($sSql, 'parent_nano_id_fk') === false;
		});
		$iMail = 0;
		$aMeta = array();
		$iGuests = 0;
		$bOk = $this->issuePassLikeCreate($wpdb, $iMail, $aMeta, $iGuests);
		$this->assertFalse($bOk);
		$this->assertSame(0, $iMail);
		$this->assertSame(array(), $aMeta);
		$this->assertSame(0, $iGuests);
		$this->assertSame(0, $this->countLive($this->wpdb->prefix.'tpfw_pass'));
	}

	public function test_pass_restore_update_failure_is_not_restored(): void
	{
		$sTable = $this->wpdb->prefix.'tpfw_pass';
		$sNow   = gmdate('Y-m-d H:i:s');
		$sNano  = 'pr'.bin2hex(random_bytes(8));
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, user_payer_id, order_id, order_line_id, valid_duration, max_uses, created, updated, deleted)
			VALUES (%s, %d, %d, %d, %d, %d, %d, %d, %s, %s, %s)',
			array($sTable, $sNano, 19, 7, 7, 100, 55, 86400, 1, $sNow, $sNow, $sNow)
		));
		$wpdb = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) {
			return stripos($sSql, 'UPDATE') !== false && stripos($sSql, 'deleted') !== false;
		});
		$mRestore = $wpdb->query($wpdb->prepare(
			'UPDATE %i SET deleted = null, updated = %s WHERE nano_id = %s',
			$sTable,
			$sNow,
			$sNano
		));
		$bRestored = !TPFW_Db_Write::failed($mRestore);
		$this->assertFalse($bRestored);
		$sDeleted = $this->wpdb->get_var($this->wpdb->prepare(
			'SELECT deleted FROM %i WHERE nano_id = %s',
			$sTable,
			$sNano
		));
		$this->assertNotNull($sDeleted);
	}

	public function test_timeslot_insert_failure_is_not_issued(): void
	{
		$wpdb = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) {
			return stripos($sSql, 'INSERT INTO') !== false;
		});
		$aMeta = array();
		$aOut  = $this->issueTimeslotLikeCreate($wpdb, 1, $aMeta);
		$this->assertFalse($aOut['bStatus']);
		$this->assertSame(array(), $aMeta);
		$this->assertSame(0, $this->countLive($this->wpdb->prefix.'tpfw_timeslot_tickets'));
	}

	public function test_upsert_insert_callback_failure_is_not_a_row(): void
	{
		$table = $this->wpdb->prefix.'tpfw_tickets';
		$sNow  = gmdate('Y-m-d H:i:s');
		$aSync = TPFW_Order_Line_Upsert::sync($this->wpdb, $table, array(), 2, $sNow, static function() {
			return false;
		});
		$this->assertFalse($aSync['ok']);
		$this->assertSame(array(), $aSync['inserted']);
		$this->assertSame(array(), $aSync['keep']);
		$this->assertSame(0, $this->countLive($table));
	}

	public function test_shrink_db_error_is_failure_and_not_revoked(): void
	{
		$table = $this->wpdb->prefix.'tpfw_tickets';
		$sNow  = gmdate('Y-m-d H:i:s');
		$fnInsert = function() use ($table, $sNow) {
			$sNano = 'n'.bin2hex(random_bytes(8));
			$this->wpdb->query($this->wpdb->prepare(
				'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
				VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s)',
				array($table, $sNano, 1, 1, 10, 20, 86400, 1, $sNow, $sNow)
			));
			return $sNano;
		};
		$aFirst = TPFW_Order_Line_Upsert::sync($this->wpdb, $table, array(), 3, $sNow, $fnInsert);
		$this->assertTrue($aFirst['ok']);
		$aLive = $this->wpdb->get_results("SELECT * FROM `{$table}` WHERE deleted IS NULL ORDER BY id ASC");
		$wpdb = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) {
			return stripos($sSql, 'UPDATE') !== false && stripos($sSql, 'SET deleted') !== false;
		});
		$aSync = TPFW_Order_Line_Upsert::shrink($wpdb, $table, $aLive, 1, $sNow);
		$this->assertFalse($aSync['ok']);
		$this->assertSame(3, $this->countLive($table));
	}

	public function test_legitimate_update_zero_is_idempotent_success(): void
	{
		$table = $this->wpdb->prefix.'tpfw_tickets';
		$sNow  = gmdate('Y-m-d H:i:s');
		$sNano = 'z'.bin2hex(random_bytes(8));
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
			VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s)',
			array($table, $sNano, 1, 1, 10, 20, 86400, 1, $sNow, $sNow)
		));
		$aExisting = $this->wpdb->get_results($this->wpdb->prepare(
			'SELECT * FROM %i WHERE order_line_id = %d ORDER BY id ASC',
			$table,
			20
		));
		$bInsertRan = false;
		$aSync = TPFW_Order_Line_Upsert::sync($this->wpdb, $table, $aExisting, 1, $sNow, function() use (&$bInsertRan) {
			$bInsertRan = true;
			return false;
		});
		$this->assertTrue($aSync['ok']);
		$this->assertFalse($bInsertRan);
		$this->assertSame(array($sNano), $aSync['keep']);
		$this->assertSame(1, $this->countLive($table));

		$mZero = $this->wpdb->query($this->wpdb->prepare(
			'UPDATE %i SET deleted = NULL WHERE nano_id = %s AND deleted IS NULL',
			$table,
			$sNano
		));
		$this->assertSame(0, $mZero);
		$this->assertFalse(TPFW_Db_Write::failed($mZero));
		$this->assertTrue(TPFW_Db_Write::succeeded($mZero));
	}

	/**
	 * @param object $wpdb
	 * @param int    $iQty
	 * @param array  $aMeta
	 * @param array  $aQr
	 * @return array{bStatus:bool}
	 */
	private function issueTicketLikeCreate($wpdb, $iQty, array &$aMeta, array &$aQr)
	{
		$sTable = $wpdb->prefix.'tpfw_tickets';
		$sNow   = gmdate('Y-m-d H:i:s');
		$aExisting = $wpdb->get_results($wpdb->prepare(
			'SELECT * FROM %i WHERE product_id = %d AND order_id = %d AND order_line_id = %d ORDER BY (deleted IS NULL) DESC, id ASC',
			$sTable,
			1,
			10,
			20
		));
		$aSync = TPFW_Order_Line_Upsert::sync($wpdb, $sTable, $aExisting, $iQty, $sNow, function() use ($wpdb, $sTable, $sNow) {
			$sNano   = 't'.bin2hex(random_bytes(8));
			$mInsert = $wpdb->query($wpdb->prepare(
				'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, valid_from, valid_to, max_uses, created, updated)
				VALUES (%s, %d, %d, %d, %d, %d, %s, %s, %d, %s, %s)',
				array($sTable, $sNano, 1, 1, 10, 20, 86400, $sNow, $sNow, 1, $sNow, $sNow)
			));
			if(!TPFW_Db_Write::inserted_row($mInsert))
			{
				return false;
			}
			return $sNano;
		});
		if($aSync === null || empty($aSync['ok']))
		{
			return array('bStatus' => false);
		}
		foreach($aSync['keep'] as $iKey => $sNano)
		{
			$aMeta['tpfw_ticket_id_'.((int)$iKey + 1)] = $sNano;
			$aQr[] = $sNano;
		}
		return array('bStatus' => true);
	}

	/**
	 * @param object $wpdb
	 * @param int    $iMail
	 * @param array  $aMeta
	 * @param int    $iGuests
	 * @return bool
	 */
	private function issuePassLikeCreate($wpdb, &$iMail, array &$aMeta, &$iGuests)
	{
		$sTable = $wpdb->prefix.'tpfw_pass';
		$sNow   = gmdate('Y-m-d H:i:s');
		$sNano  = 'p'.bin2hex(random_bytes(8));
		$sPendingMail = 'gift-body';
		$mInsert = $wpdb->query($wpdb->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, user_payer_id, order_id, order_line_id, firstname, lastname, valid_duration, valid_from, valid_to, max_uses, created, updated)
			VALUES (%s, %d, %d, %d, %d, %d, %s, %s, %d, %s, %s, %d, %s, %s)',
			array($sTable, $sNano, 19, 7, 7, 100, 55, 'A', 'B', 86400, $sNow, $sNow, 1, $sNow, $sNow)
		));
		if(!TPFW_Db_Write::inserted_row($mInsert))
		{
			return false;
		}
		if($sPendingMail !== '')
		{
			$iMail++;
		}
		$aMeta['tpfw_pass_id_1'] = $sNano;
		$oParent = $this->wpdb->get_row($this->wpdb->prepare('SELECT * FROM %i WHERE nano_id = %s', $sTable, $sNano));
		if($oParent)
		{
			$oIssuer = new TPFW_Guest_Pass_Issuer(static function() {
				return 'g'.bin2hex(random_bytes(8));
			});
			$aGuests = $oIssuer->ensure_quota($this->wpdb, $oParent, 2, 3600, 1);
			$iGuests = count($aGuests);
		}
		return true;
	}

	/**
	 * @param object $wpdb
	 * @param int    $iQty
	 * @param array  $aMeta
	 * @return array{bStatus:bool}
	 */
	private function issueTimeslotLikeCreate($wpdb, $iQty, array &$aMeta)
	{
		$sTable = $wpdb->prefix.'tpfw_timeslot_tickets';
		$sNow   = gmdate('Y-m-d H:i:s');
		$sSlot  = 'slot'.bin2hex(random_bytes(4));
		$aExisting = $wpdb->get_results($wpdb->prepare(
			'SELECT * FROM %i WHERE timeslot_id = %s AND product_id = %d AND order_id = %d AND order_line_id = %d ORDER BY (deleted IS NULL) DESC, id ASC',
			$sTable,
			$sSlot,
			1,
			10,
			20
		));
		$aSync = TPFW_Order_Line_Upsert::sync($wpdb, $sTable, $aExisting, $iQty, $sNow, function() use ($wpdb, $sTable, $sSlot, $sNow) {
			$sNano   = 'ts'.bin2hex(random_bytes(8));
			$mInsert = $wpdb->query($wpdb->prepare(
				'INSERT INTO %i (timeslot_id, nano_id, product_id, user_id, order_id, order_line_id, before_checkin_duration, valid_from, valid_to, max_uses, created, updated)
				VALUES (%s, %s, %d, %d, %d, %d, %d, %s, %s, %d, %s, %s)',
				array($sTable, $sSlot, $sNano, 1, 1, 10, 20, 3600, $sNow, $sNow, 1, $sNow, $sNow)
			));
			if(!TPFW_Db_Write::inserted_row($mInsert))
			{
				return false;
			}
			return $sNano;
		});
		if($aSync === null || empty($aSync['ok']))
		{
			return array('bStatus' => false);
		}
		foreach($aSync['keep'] as $iKey => $sNano)
		{
			$aMeta['tpfw_timeslot_ticket_id_'.((int)$iKey + 1)] = $sNano;
		}
		return array('bStatus' => true);
	}

	/**
	 * @param string $sTable
	 * @return int
	 */
	private function countLive($sTable)
	{
		return (int)$this->wpdb->get_var("SELECT COUNT(*) FROM `{$sTable}` WHERE deleted IS NULL");
	}
}
