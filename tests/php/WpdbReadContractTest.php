<?php
use PHPUnit\Framework\TestCase;

class WpdbReadContractTest extends TestCase
{
	private ?TPFW_Test_Wpdb $wpdb = null;

	protected function setUp(): void
	{
		$mysqli = TPFW_Test_Credentials::mysqli();
		if(!$mysqli)
		{
			$this->markTestSkipped('No database credentials');
		}
		$this->wpdb = new TPFW_Test_Wpdb($mysqli, 'tpfwrdrd_');
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

	public function test_successful_empty_get_results_is_not_a_failure(): void
	{
		$a = $this->wpdb->get_results('SELECT * FROM `'.$this->wpdb->prefix.'tpfw_tickets` WHERE 1=0');
		$this->assertSame(array(), $a);
		$this->assertSame('', $this->wpdb->last_error);
		$this->assertFalse(TPFW_Db_Read::results_failed($this->wpdb, $a));
	}

	public function test_sql_error_get_results_is_empty_array_with_last_error(): void
	{
		$a = $this->wpdb->get_results('SELECT `tpfw_inject_missing_col` FROM `'.$this->wpdb->prefix.'tpfw_tickets` WHERE 1=0');
		$this->assertIsArray($a);
		$this->assertSame(array(), $a);
		$this->assertNotFalse($a);
		$this->assertNotSame('', $this->wpdb->last_error);
		$this->assertTrue(TPFW_Db_Read::results_failed($this->wpdb, $a));
	}

	public function test_failing_wpdb_matches_wordpress_read_contract(): void
	{
		$oFail = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) {
			return str_contains($sSql, 'tpfw_tickets');
		});
		$a = $oFail->get_results('SELECT * FROM `'.$this->wpdb->prefix.'tpfw_tickets`');
		$this->assertSame(array(), $a);
		$this->assertSame('injected failure', $oFail->last_error);
		$this->assertTrue(TPFW_Db_Read::results_failed($oFail, $a));

		$mRow = $oFail->get_row('SELECT * FROM `'.$this->wpdb->prefix.'tpfw_tickets` LIMIT 1');
		$this->assertNull($mRow);
		$this->assertTrue(TPFW_Db_Read::row_failed($oFail, $mRow));

		$mVar = $oFail->get_var('SELECT COUNT(*) FROM `'.$this->wpdb->prefix.'tpfw_tickets`');
		$this->assertNull($mVar);
		$this->assertTrue(TPFW_Db_Read::var_failed($oFail, $mVar));
		$this->assertSame(0, (int)$mVar);
	}

	public function test_count_zero_is_a_legitimate_value(): void
	{
		$m = $this->wpdb->get_var('SELECT COUNT(*) FROM `'.$this->wpdb->prefix.'tpfw_tickets`');
		$this->assertFalse(TPFW_Db_Read::var_failed($this->wpdb, $m));
		$this->assertSame(0, (int)$m);
		$this->assertSame('', $this->wpdb->last_error);
	}
}
