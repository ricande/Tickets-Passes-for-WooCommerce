<?php
use PHPUnit\Framework\TestCase;

require_once TPFW_PLUGIN_DIR.'inc/db-installer/class--db-installer.php';

class DbInstallerMigrate105Test extends TestCase
{
	private const OLD_VERSION = '1.0.4';

	private ?TPFW_Test_Wpdb $wpdb = null;

	protected function setUp(): void
	{
		$mysqli = TPFW_Test_Credentials::mysqli();
		if(!$mysqli)
		{
			$this->markTestSkipped('No database credentials');
		}
		$this->wpdb = new TPFW_Test_Wpdb($mysqli, 'tpfwmig_');
		$GLOBALS['wpdb'] = $this->wpdb;
		$GLOBALS['tpfw_test_options'] = array();
		unset($GLOBALS['tpfw_test_update_option']);
		$this->dropInstallerTables();
	}

	protected function tearDown(): void
	{
		if($this->wpdb)
		{
			$this->dropInstallerTables();
			$this->wpdb->mysqli()->close();
		}
		unset($GLOBALS['wpdb']);
		$GLOBALS['tpfw_test_options'] = array();
		unset($GLOBALS['tpfw_test_update_option']);
	}

	public function test_healthy_1_0_4_upgrades_and_preserves_rows(): void
	{
		$o = $this->installerSkippingBootInstall();
		$this->assertTrue($o->maybe_install());
		$aSeed = $this->seedRepresentativeRows();
		update_option('tpfw_db_version', self::OLD_VERSION);

		$iCreates = 0;
		$GLOBALS['wpdb'] = $this->countingWpdb($iCreates);
		$this->assertTrue($o->maybe_install());
		$this->assertSame(TPFW_DB_Installer::DB_VERSION, get_option('tpfw_db_version'));
		$this->assertSame('1.0.6', get_option('tpfw_db_version'));
		$this->assertTrue($this->columnExists('tpfw_tickets', 'manual_cancelled_at'));
		$this->assertGreaterThan(0, $iCreates);
		$this->assertRepresentativeRows($aSeed);
		$this->assertTrue($this->indexExists('tpfw_tickets', 'created'));
		$this->assertTrue($this->columnExists('tpfw_pass', 'guest_slot'));

		$iCreates = 0;
		$GLOBALS['wpdb'] = $this->countingWpdb($iCreates);
		$this->assertTrue($o->maybe_install());
		$this->assertSame(0, $iCreates);
		$this->assertTrue(TPFW_DB_Installer::schema_is_current());
		$this->assertRepresentativeRows($aSeed);
	}

	public function test_incomplete_1_0_4_repairs_managed_schema_not_dropped_rows(): void
	{
		$o = $this->installerSkippingBootInstall();
		$this->assertTrue($o->maybe_install());
		$aSeed = $this->seedRepresentativeRows();
		$sStats = $this->wpdb->prefix.'tpfw_tickets_stats';
		$sNow = gmdate('Y-m-d H:i:s');
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id_fk, user_id, created, updated) VALUES (%s, %d, %s, %s)',
			array($sStats, $aSeed['ticket'], 9, $sNow, $sNow)
		));
		$this->assertSame(1, (int)$this->wpdb->get_var('SELECT COUNT(*) FROM `'.$sStats.'`'));

		$this->wpdb->query('DROP TABLE `'.$sStats.'`');
		$this->wpdb->query('ALTER TABLE `'.$this->wpdb->prefix.'tpfw_tickets` DROP INDEX `created`');
		$this->wpdb->query('ALTER TABLE `'.$this->wpdb->prefix.'tpfw_pass` DROP INDEX `parent_guest_slot`');
		$this->wpdb->query('ALTER TABLE `'.$this->wpdb->prefix.'tpfw_pass` DROP COLUMN `guest_slot`');
		update_option('tpfw_db_version', self::OLD_VERSION);

		$this->assertFalse($this->tableExists('tpfw_tickets_stats'));
		$this->assertFalse($this->indexExists('tpfw_tickets', 'created'));
		$this->assertFalse($this->columnExists('tpfw_pass', 'guest_slot'));

		$GLOBALS['wpdb'] = $this->wpdb;
		$this->assertTrue($o->maybe_install());
		$this->assertSame('1.0.6', get_option('tpfw_db_version'));
		$this->assertTrue($this->tableExists('tpfw_tickets_stats'));
		$this->assertTrue($this->indexExists('tpfw_tickets', 'created'));
		$this->assertTrue($this->indexExists('tpfw_tickets_stats', 'user_created'));
		$this->assertTrue($this->columnExists('tpfw_pass', 'guest_slot'));
		$this->assertTrue($this->columnExists('tpfw_tickets', 'manual_cancelled_at'));
		$this->assertTrue($this->indexExists('tpfw_pass', 'parent_guest_slot'));
		$this->assertSame(0, (int)$this->wpdb->get_var('SELECT COUNT(*) FROM `'.$sStats.'`'));
		$oTicket = $this->wpdb->get_row($this->wpdb->prepare(
			'SELECT nano_id, valid_from, valid_to, max_uses FROM %i WHERE nano_id = %s',
			$this->wpdb->prefix.'tpfw_tickets',
			$aSeed['ticket']
		));
		$this->assertNotNull($oTicket);
		$this->assertSame($aSeed['valid_from'], $oTicket->valid_from);
		$this->assertSame($aSeed['valid_to'], $oTicket->valid_to);
		$this->assertSame(2, (int)$oTicket->max_uses);
		$oGuest = $this->wpdb->get_row($this->wpdb->prepare(
			'SELECT nano_id, parent_nano_id_fk, guest_slot FROM %i WHERE nano_id = %s',
			$this->wpdb->prefix.'tpfw_pass',
			$aSeed['guest']
		));
		$this->assertSame($aSeed['parent'], $oGuest->parent_nano_id_fk);
		$this->assertGreaterThan(0, (int)$oGuest->guest_slot);
	}

	public function test_failed_1_0_4_repair_stays_on_old_marker_then_retries(): void
	{
		$o = $this->installerSkippingBootInstall();
		$this->assertTrue($o->maybe_install());
		$aSeed = $this->seedRepresentativeRows();
		$sStats = $this->wpdb->prefix.'tpfw_tickets_stats';
		$this->wpdb->query('DROP TABLE `'.$sStats.'`');
		update_option('tpfw_db_version', self::OLD_VERSION);

		$iCreates = 0;
		$GLOBALS['wpdb'] = $this->countingWpdb($iCreates, static function($sSql) use ($sStats) {
			return (bool)preg_match('/CREATE TABLE IF NOT EXISTS `'.preg_quote($sStats, '/').'`/i', $sSql);
		});
		$this->assertFalse($o->maybe_install());
		$this->assertSame(self::OLD_VERSION, get_option('tpfw_db_version'));
		$this->assertFalse($this->tableExists('tpfw_tickets_stats'));
		$this->assertRepresentativeRows($aSeed);

		$GLOBALS['wpdb'] = $this->wpdb;
		$this->assertTrue($o->maybe_install());
		$this->assertSame('1.0.6', get_option('tpfw_db_version'));
		$this->assertTrue($this->tableExists('tpfw_tickets_stats'));
		$this->assertRepresentativeRows($aSeed);
		$this->assertSame(1, (int)$this->wpdb->get_var(
			$this->wpdb->prepare('SELECT COUNT(*) FROM %i WHERE nano_id = %s', $this->wpdb->prefix.'tpfw_tickets', $aSeed['ticket'])
		));
	}

	public function test_clean_install_creates_schema_and_marks_1_0_6(): void
	{
		$this->assertFalse($this->tableExists('tpfw_tickets'));
		$this->assertFalse(get_option('tpfw_db_version'));
		new TPFW_DB_Installer();
		$this->assertSame('1.0.6', get_option('tpfw_db_version'));
		$this->assertTrue(TPFW_DB_Installer::schema_is_current());
		foreach($this->installerTables() as $sTable)
		{
			$this->assertTrue($this->tableExists($sTable), $sTable);
		}
		$this->assertTrue($this->columnExists('tpfw_tickets', 'manual_cancelled_at'));
		$this->assertTrue($this->columnExists('tpfw_pass', 'guest_slot'));
		$this->assertTrue($this->indexExists('tpfw_pass', 'parent_guest_slot'));
		$this->assertTrue($this->indexExists('tpfw_tickets', 'created'));
		$this->assertTrue($this->indexExists('tpfw_timeslots', 'product_start'));
		$this->assertTrue($this->indexExists('tpfw_tickets_stats', 'user_created'));
	}

	public function test_healthy_1_0_5_adds_manual_cancelled_at_without_backfill(): void
	{
		$o = $this->installerSkippingBootInstall();
		$this->assertTrue($o->maybe_install());
		$aSeed = $this->seedRepresentativeRows();
		$sNow  = gmdate('Y-m-d H:i:s');
		$this->wpdb->query($this->wpdb->prepare(
			'UPDATE %i SET deleted = %s, updated = %s WHERE nano_id = %s',
			array($this->wpdb->prefix.'tpfw_tickets', $sNow, $sNow, $aSeed['ticket'])
		));
		$this->wpdb->query('ALTER TABLE `'.$this->wpdb->prefix.'tpfw_tickets` DROP COLUMN `manual_cancelled_at`');
		$this->assertFalse($this->columnExists('tpfw_tickets', 'manual_cancelled_at'));
		update_option('tpfw_db_version', '1.0.5');

		$GLOBALS['wpdb'] = $this->wpdb;
		$this->assertTrue($o->maybe_install());
		$this->assertSame('1.0.6', get_option('tpfw_db_version'));
		$this->assertTrue($this->columnExists('tpfw_tickets', 'manual_cancelled_at'));
		$this->assertRepresentativeRows($aSeed);
		$oTicket = $this->wpdb->get_row($this->wpdb->prepare(
			'SELECT deleted, manual_cancelled_at FROM %i WHERE nano_id = %s',
			$this->wpdb->prefix.'tpfw_tickets',
			$aSeed['ticket']
		));
		$this->assertNotNull($oTicket->deleted);
		$this->assertNull($oTicket->manual_cancelled_at);

		$iCreates = 0;
		$GLOBALS['wpdb'] = $this->countingWpdb($iCreates);
		$this->assertTrue($o->maybe_install());
		$this->assertSame(0, $iCreates);
		$this->assertSame('1.0.6', get_option('tpfw_db_version'));
	}

	/**
	 * @return TPFW_Failing_Wpdb
	 */
	private function countingWpdb(&$iCreates, $fnFail = null)
	{
		return new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) use (&$iCreates, $fnFail) {
			if(stripos($sSql, 'CREATE TABLE') !== false)
			{
				$iCreates++;
			}
			return $fnFail ? (bool)call_user_func($fnFail, $sSql) : false;
		});
	}

	/**
	 * @return array{ticket:string,parent:string,guest:string,valid_from:string,valid_to:string}
	 */
	private function seedRepresentativeRows()
	{
		$sFrom = '2026-09-01 10:00:00';
		$sTo   = '2026-09-30 18:00:00';
		$sNow  = gmdate('Y-m-d H:i:s');
		$sTicket = 't'.bin2hex(random_bytes(8));
		$sParent = 'p'.bin2hex(random_bytes(8));
		$sGuest  = 'g'.bin2hex(random_bytes(8));
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, valid_from, valid_to, max_uses, created, updated)
			VALUES (%s, %d, %d, %d, %d, %d, %s, %s, %d, %s, %s)',
			array($this->wpdb->prefix.'tpfw_tickets', $sTicket, 11, 7, 100, 200, 86400, $sFrom, $sTo, 2, $sNow, $sNow)
		));
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, user_payer_id, order_id, order_line_id, valid_duration, valid_from, valid_to, max_uses, created, updated)
			VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s, %d, %s, %s)',
			array($this->wpdb->prefix.'tpfw_pass', $sParent, 19, 7, 7, 100, 200, 86400, $sFrom, $sTo, 3, $sNow, $sNow)
		));
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, parent_nano_id_fk, guest_slot, product_id, user_id, user_payer_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
			VALUES (%s, %s, %d, %d, %d, %d, %d, %d, %d, %d, %s, %s)',
			array($this->wpdb->prefix.'tpfw_pass', $sGuest, $sParent, 1, 19, 8, 7, 100, 201, 86400, 1, $sNow, $sNow)
		));
		return array(
			'ticket'     => $sTicket,
			'parent'     => $sParent,
			'guest'      => $sGuest,
			'valid_from' => $sFrom,
			'valid_to'   => $sTo,
		);
	}

	/**
	 * @param array{ticket:string,parent:string,guest:string,valid_from:string,valid_to:string} $aSeed
	 * @return void
	 */
	private function assertRepresentativeRows(array $aSeed)
	{
		$oTicket = $this->wpdb->get_row($this->wpdb->prepare(
			'SELECT nano_id, valid_from, valid_to, max_uses FROM %i WHERE nano_id = %s',
			$this->wpdb->prefix.'tpfw_tickets',
			$aSeed['ticket']
		));
		$this->assertNotNull($oTicket);
		$this->assertSame($aSeed['valid_from'], $oTicket->valid_from);
		$this->assertSame($aSeed['valid_to'], $oTicket->valid_to);
		$this->assertSame(2, (int)$oTicket->max_uses);
		$oParent = $this->wpdb->get_row($this->wpdb->prepare(
			'SELECT nano_id FROM %i WHERE nano_id = %s AND parent_nano_id_fk IS NULL',
			$this->wpdb->prefix.'tpfw_pass',
			$aSeed['parent']
		));
		$this->assertNotNull($oParent);
		$oGuest = $this->wpdb->get_row($this->wpdb->prepare(
			'SELECT nano_id, parent_nano_id_fk, guest_slot FROM %i WHERE nano_id = %s',
			$this->wpdb->prefix.'tpfw_pass',
			$aSeed['guest']
		));
		$this->assertNotNull($oGuest);
		$this->assertSame($aSeed['parent'], $oGuest->parent_nano_id_fk);
		$this->assertSame(1, (int)$oGuest->guest_slot);
	}

	/**
	 * @return TPFW_DB_Installer
	 */
	private function installerSkippingBootInstall()
	{
		update_option('tpfw_db_version', TPFW_DB_Installer::DB_VERSION);
		$o = new TPFW_DB_Installer();
		delete_option('tpfw_db_version');
		return $o;
	}

	/**
	 * @param string $sTable
	 * @param string $sIndex
	 * @return bool
	 */
	private function indexExists($sTable, $sIndex)
	{
		$a = $this->wpdb->get_results("SHOW INDEX FROM `{$this->wpdb->prefix}{$sTable}` WHERE Key_name = '{$sIndex}'");
		return is_array($a) && $a !== array();
	}

	/**
	 * @param string $sTable
	 * @param string $sColumn
	 * @return bool
	 */
	private function columnExists($sTable, $sColumn)
	{
		$a = $this->wpdb->get_results("SHOW COLUMNS FROM `{$this->wpdb->prefix}{$sTable}` LIKE '{$sColumn}'");
		return is_array($a) && $a !== array();
	}

	/**
	 * @param string $sTable
	 * @return bool
	 */
	private function tableExists($sTable)
	{
		$sFull = $this->wpdb->prefix.$sTable;
		$s = $this->wpdb->get_var("SHOW TABLES LIKE '".$this->wpdb->mysqli()->real_escape_string($sFull)."'");
		return $s === $sFull;
	}

	/**
	 * @return void
	 */
	private function dropInstallerTables()
	{
		$p = $this->wpdb->prefix;
		foreach(array_reverse($this->installerTables()) as $sTable)
		{
			$this->wpdb->query("DROP TABLE IF EXISTS `{$p}{$sTable}`");
		}
	}

	/**
	 * @return string[]
	 */
	private function installerTables()
	{
		return array(
			'tpfw_tickets',
			'tpfw_tickets_stats',
			'tpfw_timeslot_tickets',
			'tpfw_timeslot_tickets_stats',
			'tpfw_timeslots',
			'tpfw_timeslots_recurring',
			'tpfw_timeslot_reservations',
			'tpfw_pass',
			'tpfw_pass_stats',
		);
	}
}
