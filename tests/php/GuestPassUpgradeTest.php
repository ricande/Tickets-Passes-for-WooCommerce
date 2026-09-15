<?php
use PHPUnit\Framework\TestCase;

class GuestPassUpgradeTest extends TestCase
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

	private function insertParent(): object
	{
		$sNano = 'parent'.bin2hex(random_bytes(6));
		$sNow  = gmdate('Y-m-d H:i:s');
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, user_payer_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
			VALUES (%s, %d, %d, %d, %d, %d, %d, %d, %s, %s)',
			array($this->wpdb->prefix.'tpfw_pass', $sNano, 19, 7, 7, 100, 200, 86400, 3, $sNow, $sNow)
		));
		return $this->wpdb->get_row($this->wpdb->prepare('SELECT * FROM %i WHERE nano_id = %s', $this->wpdb->prefix.'tpfw_pass', $sNano));
	}

	/**
	 * 1.2.3-shaped guest: parent set, guest_slot NULL.
	 */
	private function insertLegacyGuest(object $oParent, $bDeleted = false): string
	{
		$sNano = 'g'.bin2hex(random_bytes(8));
		$sNow  = gmdate('Y-m-d H:i:s');
		$aArgs = array(
			$this->wpdb->prefix.'tpfw_pass',
			$sNano,
			$oParent->nano_id,
			(int)$oParent->product_id,
			(int)$oParent->user_id,
			(int)$oParent->user_payer_id,
			(int)$oParent->order_id,
			(int)$oParent->order_line_id,
			86400,
			3,
			$sNow,
			$sNow,
		);
		$sSql = 'INSERT INTO %i (nano_id, parent_nano_id_fk, guest_slot, product_id, user_id, user_payer_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
			VALUES (%s, %s, NULL, %d, %d, %d, %d, %d, %d, %d, %s, %s)';
		if($bDeleted)
		{
			$sSql = 'INSERT INTO %i (nano_id, parent_nano_id_fk, guest_slot, product_id, user_id, user_payer_id, order_id, order_line_id, valid_duration, max_uses, created, updated, deleted)
				VALUES (%s, %s, NULL, %d, %d, %d, %d, %d, %d, %d, %s, %s, %s)';
			$aArgs[] = $sNow;
		}
		$this->wpdb->query($this->wpdb->prepare($sSql, $aArgs));
		return $sNano;
	}

	private function liveGuests(string $sParent): array
	{
		return $this->wpdb->get_results($this->wpdb->prepare(
			'SELECT * FROM %i WHERE parent_nano_id_fk = %s AND deleted IS NULL ORDER BY guest_slot ASC, id ASC',
			$this->wpdb->prefix.'tpfw_pass',
			$sParent
		));
	}

	private function allGuests(string $sParent): array
	{
		return $this->wpdb->get_results($this->wpdb->prepare(
			'SELECT * FROM %i WHERE parent_nano_id_fk = %s ORDER BY id ASC',
			$this->wpdb->prefix.'tpfw_pass',
			$sParent
		));
	}

	private function assertActiveQuota(array $aLive, int $iQuota): void
	{
		$this->assertCount($iQuota, $aLive);
		$aSlots = array();
		foreach($aLive as $oRow)
		{
			$iSlot = (int)$oRow->guest_slot;
			$this->assertGreaterThanOrEqual(1, $iSlot);
			$this->assertLessThanOrEqual($iQuota, $iSlot);
			$this->assertFalse(isset($aSlots[$iSlot]), 'duplicate live slot '.$iSlot);
			$aSlots[$iSlot] = true;
		}
	}

	public function test_case1_soft_deleted_null_slots_restore_to_quota(): void
	{
		$oParent = $this->insertParent();
		$sA = $this->insertLegacyGuest($oParent, true);
		$sB = $this->insertLegacyGuest($oParent, true);

		$this->assertTrue(TPFW_Guest_Pass_Issuer::backfill_legacy_slots($this->wpdb));
		$aAll = $this->allGuests($oParent->nano_id);
		$this->assertSame(1, (int)$aAll[0]->guest_slot);
		$this->assertSame(2, (int)$aAll[1]->guest_slot);
		$this->assertNotNull($aAll[0]->deleted);
		$this->assertNotNull($aAll[1]->deleted);

		$aLive = (new TPFW_Guest_Pass_Issuer())->ensure_quota($this->wpdb, $oParent, 2, 86400, 3);
		$this->assertActiveQuota($aLive, 2);
		$this->assertSame(array($sA, $sB), array_map(static function($o) { return $o->nano_id; }, $aLive));
		$this->assertSame(2, count($this->allGuests($oParent->nano_id)));
	}

	public function test_case2_active_null_slots_get_one_and_two(): void
	{
		$oParent = $this->insertParent();
		$sA = $this->insertLegacyGuest($oParent, false);
		$sB = $this->insertLegacyGuest($oParent, false);

		$this->assertTrue(TPFW_Guest_Pass_Issuer::backfill_legacy_slots($this->wpdb));
		$aLive = $this->liveGuests($oParent->nano_id);
		$this->assertActiveQuota($aLive, 2);
		$this->assertSame(array($sA, $sB), array_map(static function($o) { return $o->nano_id; }, $aLive));
	}

	public function test_case3_legacy_overflow_keeps_history_activates_quota(): void
	{
		$oParent = $this->insertParent();
		$aNanos = array();
		for($i = 0; $i < 4; $i++)
		{
			$aNanos[] = $this->insertLegacyGuest($oParent, true);
		}

		$this->assertTrue(TPFW_Guest_Pass_Issuer::backfill_legacy_slots($this->wpdb));
		$aAll = $this->allGuests($oParent->nano_id);
		$this->assertCount(4, $aAll);
		$this->assertSame(array(1, 2, 3, 4), array_map(static function($o) { return (int)$o->guest_slot; }, $aAll));

		$aLive = (new TPFW_Guest_Pass_Issuer())->ensure_quota($this->wpdb, $oParent, 2, 86400, 3);
		$this->assertActiveQuota($aLive, 2);
		$this->assertSame(array($aNanos[0], $aNanos[1]), array_map(static function($o) { return $o->nano_id; }, $aLive));
		$aDead = array_filter($this->allGuests($oParent->nano_id), static function($o) { return $o->deleted !== null; });
		$this->assertCount(2, $aDead);
		foreach($aDead as $oDead)
		{
			$this->assertGreaterThan(2, (int)$oDead->guest_slot);
		}
	}

	public function test_case4_quota_increase_reuses_legacy_nanos(): void
	{
		$oParent = $this->insertParent();
		$sA = $this->insertLegacyGuest($oParent, true);
		$sB = $this->insertLegacyGuest($oParent, true);

		$this->assertTrue(TPFW_Guest_Pass_Issuer::backfill_legacy_slots($this->wpdb));
		$aLive = (new TPFW_Guest_Pass_Issuer())->ensure_quota($this->wpdb, $oParent, 4, 86400, 3);
		$this->assertActiveQuota($aLive, 4);
		$this->assertSame($sA, $aLive[0]->nano_id);
		$this->assertSame($sB, $aLive[1]->nano_id);
		$this->assertSame(array(1, 2, 3, 4), array_map(static function($o) { return (int)$o->guest_slot; }, $aLive));
	}

	public function test_case5_quota_zero_restores_parent_only(): void
	{
		$oParent = $this->insertParent();
		$this->insertLegacyGuest($oParent, true);
		$this->insertLegacyGuest($oParent, true);
		$this->assertTrue(TPFW_Guest_Pass_Issuer::backfill_legacy_slots($this->wpdb));

		$aLive = (new TPFW_Guest_Pass_Issuer())->ensure_quota($this->wpdb, $oParent, 0, 86400, 3);
		$this->assertSame(array(), $aLive);
		$this->assertSame(0, count($this->liveGuests($oParent->nano_id)));
		$this->assertSame(2, count($this->allGuests($oParent->nano_id)));
		$oParentRow = $this->wpdb->get_row($this->wpdb->prepare(
			'SELECT * FROM %i WHERE nano_id = %s',
			$this->wpdb->prefix.'tpfw_pass',
			$oParent->nano_id
		));
		$this->assertNull($oParentRow->deleted);
	}

	public function test_case6_migration_is_idempotent(): void
	{
		$oParent = $this->insertParent();
		$this->insertLegacyGuest($oParent, true);
		$this->insertLegacyGuest($oParent, false);

		$this->assertTrue(TPFW_Guest_Pass_Issuer::backfill_legacy_slots($this->wpdb));
		$aFirst = $this->allGuests($oParent->nano_id);
		$aSnap  = array_map(static function($o) {
			return array($o->id, $o->nano_id, (int)$o->guest_slot, $o->deleted);
		}, $aFirst);

		$this->assertTrue(TPFW_Guest_Pass_Issuer::backfill_legacy_slots($this->wpdb));
		$aSecond = $this->allGuests($oParent->nano_id);
		$aSnap2  = array_map(static function($o) {
			return array($o->id, $o->nano_id, (int)$o->guest_slot, $o->deleted);
		}, $aSecond);
		$this->assertSame($aSnap, $aSnap2);
		$this->assertCount(2, $aSecond);
	}

	public function test_case7_restore_ensure_is_idempotent(): void
	{
		$oParent = $this->insertParent();
		$this->insertLegacyGuest($oParent, true);
		$this->insertLegacyGuest($oParent, true);
		$this->insertLegacyGuest($oParent, true);
		$this->insertLegacyGuest($oParent, true);
		$this->assertTrue(TPFW_Guest_Pass_Issuer::backfill_legacy_slots($this->wpdb));

		$issuer = new TPFW_Guest_Pass_Issuer();
		$aOne   = $issuer->ensure_quota($this->wpdb, $oParent, 2, 86400, 3);
		$aTwo   = $issuer->ensure_quota($this->wpdb, $oParent, 2, 86400, 3);
		$this->assertActiveQuota($aOne, 2);
		$this->assertActiveQuota($aTwo, 2);
		$this->assertSame(
			array_map(static function($o) { return $o->nano_id; }, $aOne),
			array_map(static function($o) { return $o->nano_id; }, $aTwo)
		);
		$this->assertSame(4, count($this->allGuests($oParent->nano_id)));
	}

	public function test_quota_decrease_from_four_to_two(): void
	{
		$oParent = $this->insertParent();
		for($i = 0; $i < 4; $i++)
		{
			$this->insertLegacyGuest($oParent, false);
		}
		$this->assertTrue(TPFW_Guest_Pass_Issuer::backfill_legacy_slots($this->wpdb));
		$aLive = (new TPFW_Guest_Pass_Issuer())->ensure_quota($this->wpdb, $oParent, 2, 86400, 3);
		$this->assertActiveQuota($aLive, 2);
		$this->assertSame(4, count($this->allGuests($oParent->nano_id)));
	}

	public function test_restore_does_not_undelete_all_children_in_create_pass(): void
	{
		$sSrc = file_get_contents(TPFW_PLUGIN_DIR.'inc/pass-wc-product/class--pass-wc-product.php');
		$this->assertFalse(strpos($sSrc, "SET deleted = null, updated = %s\n                                                                    WHERE nano_id = %s OR parent_nano_id_fk = %s"));
		$this->assertNotFalse(strpos($sSrc, 'WHERE nano_id = %s', 0));
		$this->assertNotFalse(strpos($sSrc, 'ensure_quota'));
		$sIssuer = file_get_contents(TPFW_PLUGIN_DIR.'inc/support/class--guest-pass-issuer.php');
		$this->assertFalse(strpos($sIssuer, 'AND deleted IS NULL AND (guest_slot IS NULL'));
	}

	public function test_backfill_failure_is_reported(): void
	{
		$oBroken = new class {
			public $prefix = 'tpfwtest_';
			public $last_error = '';
			public function prepare($q, $a = null) { return $q; }
			public function get_results($q) { $this->last_error = 'simulated failure'; return array(); }
			public function query($q) { return false; }
		};
		$this->assertFalse(TPFW_Guest_Pass_Issuer::backfill_legacy_slots($oBroken));
	}
}
