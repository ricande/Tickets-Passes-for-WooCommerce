<?php
use PHPUnit\Framework\TestCase;

class GuestPassQuotaTest extends TestCase
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
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, user_payer_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
			VALUES (%s, %d, %d, %d, %d, %d, %d, %d, %s, %s)',
			array($this->wpdb->prefix.'tpfw_pass', $sNano, 19, 7, 7, 100, 200, 86400, 3, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'))
		));
		return $this->wpdb->get_row($this->wpdb->prepare('SELECT * FROM %i WHERE nano_id = %s', $this->wpdb->prefix.'tpfw_pass', $sNano));
	}

	public function test_parallel_mints_stop_at_quota(): void
	{
		$oParent = $this->insertParent();
		$sWorker = TPFW_TEST_ROOT.'/bin/guest-quota-worker.php';
		$sPrefix = $this->wpdb->prefix;

		$aSpecs = array(
			array(PHP_BINARY, $sWorker, $oParent->nano_id, '2', $sPrefix),
			array(PHP_BINARY, $sWorker, $oParent->nano_id, '2', $sPrefix),
		);
		$aPipes = array();
		$aProcs = array();
		foreach($aSpecs as $i => $aCmd)
		{
			$aPipes[$i] = array();
			$aProcs[$i] = proc_open($aCmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $aPipes[$i], TPFW_PLUGIN_DIR);
			$this->assertIsResource($aProcs[$i]);
		}
		foreach($aProcs as $i => $mProc)
		{
			stream_get_contents($aPipes[$i][1]);
			stream_get_contents($aPipes[$i][2]);
			fclose($aPipes[$i][1]);
			fclose($aPipes[$i][2]);
			proc_close($mProc);
		}

		$iCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE parent_nano_id_fk = %s AND deleted IS NULL',
			$this->wpdb->prefix.'tpfw_pass', $oParent->nano_id
		));
		$this->assertSame(2, $iCount);
	}

	public function test_already_at_quota_inserts_nothing(): void
	{
		$oParent = $this->insertParent();
		$issuer  = new TPFW_Guest_Pass_Issuer();
		$issuer->ensure_quota($this->wpdb, $oParent, 2, 86400, 3);
		$issuer->ensure_quota($this->wpdb, $oParent, 2, 86400, 3);

		$iCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE parent_nano_id_fk = %s AND deleted IS NULL',
			$this->wpdb->prefix.'tpfw_pass', $oParent->nano_id
		));
		$this->assertSame(2, $iCount);
	}

	public function test_minted_guests_have_null_window(): void
	{
		$oParent = $this->insertParent();
		$aGuests = (new TPFW_Guest_Pass_Issuer())->ensure_quota($this->wpdb, $oParent, 1, 86400, 3);
		$this->assertCount(1, $aGuests);
		$this->assertNull($aGuests[0]->valid_from);
		$this->assertNull($aGuests[0]->valid_to);
	}

	public function test_guests_minted_after_parent_checkin_get_a_window(): void
	{
		$oParent = $this->insertParent();
		$sNow    = gmdate('Y-m-d H:i:s');
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id_fk, user_id, created, updated) VALUES (%s, %d, %s, %s)',
			array($this->wpdb->prefix.'tpfw_pass_stats', $oParent->nano_id, 1, $sNow, $sNow)
		));
		$aGuests = (new TPFW_Guest_Pass_Issuer())->ensure_quota($this->wpdb, $oParent, 1, 86400, 3);
		$this->assertNotNull($aGuests[0]->valid_from);
		$this->assertNotNull($aGuests[0]->valid_to);
	}
}
