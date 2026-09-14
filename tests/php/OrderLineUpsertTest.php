<?php
use PHPUnit\Framework\TestCase;

class OrderLineUpsertTest extends TestCase
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

	private function insertTicket($sNano, $bDeleted = false): void
	{
		$sNow = gmdate('Y-m-d H:i:s');
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, max_uses, created, updated, deleted)
			VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s, %s)',
			array($this->wpdb->prefix.'tpfw_tickets', $sNano, 1, 1, 10, 20, 86400, 1, $sNow, $sNow, $bDeleted ? $sNow : null)
		));
	}

	public function test_qty_three_then_refund_then_complete_keeps_nano_ids(): void
	{
		$table = $this->wpdb->prefix.'tpfw_tickets';
		$sNow  = gmdate('Y-m-d H:i:s');
		$iSeq  = 0;
		$fnInsert = function() use (&$iSeq, $table, $sNow) {
			$iSeq++;
			$sNano = 'nano'.$iSeq.'_'.bin2hex(random_bytes(3));
			$this->wpdb->query($this->wpdb->prepare(
				'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
				VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s)',
				array($table, $sNano, 1, 1, 10, 20, 86400, 1, $sNow, $sNow)
			));
			return $sNano;
		};

		$aFirst = TPFW_Order_Line_Upsert::sync($this->wpdb, $table, array(), 3, $sNow, $fnInsert);
		$this->assertCount(3, $aFirst['keep']);
		$aOriginal = $aFirst['keep'];

		$aExisting = $this->wpdb->get_results($this->wpdb->prepare(
			'SELECT * FROM %i WHERE order_id = %d AND order_line_id = %d ORDER BY -deleted',
			$table, 10, 20
		));
		TPFW_Order_Line_Upsert::sync($this->wpdb, $table, $aExisting, 0, $sNow, $fnInsert);

		$iLive = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE deleted IS NULL");
		$this->assertSame(0, $iLive);

		$aExisting = $this->wpdb->get_results($this->wpdb->prepare(
			'SELECT * FROM %i WHERE order_id = %d AND order_line_id = %d ORDER BY -deleted',
			$table, 10, 20
		));
		$aAgain = TPFW_Order_Line_Upsert::sync($this->wpdb, $table, $aExisting, 3, $sNow, $fnInsert);
		$this->assertSame($aOriginal, $aAgain['keep']);
		$this->assertSame(array(), $aAgain['inserted']);
	}

	public function test_qty_three_then_one_leaves_two_deleted(): void
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

		TPFW_Order_Line_Upsert::sync($this->wpdb, $table, array(), 3, $sNow, $fnInsert);
		$aExisting = $this->wpdb->get_results($this->wpdb->prepare(
			'SELECT * FROM %i WHERE order_id = %d AND order_line_id = %d ORDER BY -deleted',
			$table, 10, 20
		));
		$aSync = TPFW_Order_Line_Upsert::sync($this->wpdb, $table, $aExisting, 1, $sNow, $fnInsert);
		$this->assertCount(1, $aSync['keep']);
		$this->assertCount(2, $aSync['deleted']);
		$iDeleted = (int)$this->wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE deleted IS NOT NULL");
		$this->assertSame(2, $iDeleted);
	}

	public function test_sync_rejects_empty_table(): void
	{
		$this->expectException(InvalidArgumentException::class);
		TPFW_Order_Line_Upsert::sync($this->wpdb, '', array(), 1, gmdate('Y-m-d H:i:s'), function() {
			$this->fail('insert must not run when the table name is empty');
		});
	}
}
