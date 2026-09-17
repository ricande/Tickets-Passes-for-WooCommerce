<?php
use PHPUnit\Framework\TestCase;

require_once TPFW_PLUGIN_DIR.'inc/db-installer/class--db-installer.php';

class DbInstallerFailClosedTest extends TestCase
{
	private ?TPFW_Test_Wpdb $wpdb = null;

	protected function setUp(): void
	{
		$mysqli = TPFW_Test_Credentials::mysqli();
		if(!$mysqli)
		{
			$this->markTestSkipped('No database credentials');
		}
		$this->wpdb = new TPFW_Test_Wpdb($mysqli, 'tpfwinst_');
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

	public function test_create_table_false_does_not_record_version(): void
	{
		$wpdb = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) {
			return stripos($sSql, 'CREATE TABLE') !== false;
		});
		$GLOBALS['wpdb'] = $wpdb;
		$o = $this->installerSkippingBootInstall();
		$this->assertFalse($o->install());
		$this->assertFalse($o->maybe_install());
		$this->assertNotSame(TPFW_DB_Installer::DB_VERSION, get_option('tpfw_db_version'));
		$this->assertFalse($this->tableExists('tpfw_tickets'));
	}

	public function test_add_key_false_fails_install(): void
	{
		$wpdb = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) {
			return (bool)preg_match('/ADD KEY `created`/i', $sSql);
		});
		$GLOBALS['wpdb'] = $wpdb;
		$o = $this->installerSkippingBootInstall();
		$this->assertFalse($o->install());
		$this->assertNotSame(TPFW_DB_Installer::DB_VERSION, get_option('tpfw_db_version'));
		$this->assertTrue($this->tableExists('tpfw_tickets'));
	}

	public function test_add_column_false_fails_install(): void
	{
		$this->createLegacyPassTable();
		$wpdb = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) {
			return stripos($sSql, 'ADD COLUMN') !== false;
		});
		$GLOBALS['wpdb'] = $wpdb;
		$o = $this->installerSkippingBootInstall();
		$this->assertFalse($o->install());
		$this->assertNotSame(TPFW_DB_Installer::DB_VERSION, get_option('tpfw_db_version'));
		$this->assertSame(array(), $this->wpdb->get_results("SHOW COLUMNS FROM `{$this->wpdb->prefix}tpfw_pass` LIKE 'guest_slot'"));
	}

	public function test_unique_index_false_fails_install(): void
	{
		$this->createLegacyPassTable();
		$this->wpdb->query("ALTER TABLE `{$this->wpdb->prefix}tpfw_pass` ADD COLUMN `guest_slot` TINYINT UNSIGNED NULL DEFAULT NULL AFTER `parent_nano_id_fk`");
		$wpdb = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) {
			return stripos($sSql, 'ADD UNIQUE KEY') !== false;
		});
		$GLOBALS['wpdb'] = $wpdb;
		$o = $this->installerSkippingBootInstall();
		$this->assertFalse($o->install());
		$this->assertNotSame(TPFW_DB_Installer::DB_VERSION, get_option('tpfw_db_version'));
	}

	public function test_failed_show_index_is_error_not_missing(): void
	{
		$wpdb = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) {
			return stripos($sSql, 'SHOW INDEX') !== false;
		});
		$GLOBALS['wpdb'] = $wpdb;
		$o = $this->installerSkippingBootInstall();
		$this->assertFalse($o->install());
		$this->assertNotSame(TPFW_DB_Installer::DB_VERSION, get_option('tpfw_db_version'));
	}

	public function test_failed_show_columns_is_error_not_missing(): void
	{
		$wpdb = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) {
			return stripos($sSql, 'SHOW COLUMNS') !== false;
		});
		$GLOBALS['wpdb'] = $wpdb;
		$o = $this->installerSkippingBootInstall();
		$this->assertFalse($o->install());
		$this->assertNotSame(TPFW_DB_Installer::DB_VERSION, get_option('tpfw_db_version'));
	}

	public function test_create_if_exists_zero_is_not_failure(): void
	{
		$o = $this->installerSkippingBootInstall();
		$this->assertTrue($o->install());
		$this->assertTrue($o->install());
		update_option('tpfw_db_version', TPFW_DB_Installer::DB_VERSION);
		$this->assertTrue($o->maybe_install());
	}

	public function test_successful_install_records_version(): void
	{
		$o = $this->installerSkippingBootInstall();
		delete_option('tpfw_db_version');
		$this->assertTrue($o->maybe_install());
		$this->assertSame(TPFW_DB_Installer::DB_VERSION, get_option('tpfw_db_version'));
		foreach($this->installerTables() as $sTable)
		{
			$this->assertTrue($this->tableExists($sTable), $sTable);
		}
	}

	public function test_retry_after_partial_create_keeps_existing_rows(): void
	{
		$sPass = $this->wpdb->prefix.'tpfw_pass';
		$wpdb = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) use ($sPass) {
			return (bool)preg_match('/CREATE TABLE IF NOT EXISTS `'.preg_quote($sPass, '/').'`/i', $sSql);
		});
		$GLOBALS['wpdb'] = $wpdb;
		$o = $this->installerSkippingBootInstall();
		$this->assertFalse($o->install());
		$this->assertTrue($this->tableExists('tpfw_tickets'));
		$this->assertFalse($this->tableExists('tpfw_pass'));

		$sNano = 'keep'.bin2hex(random_bytes(6));
		$sNow  = gmdate('Y-m-d H:i:s');
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
			VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s)',
			array($this->wpdb->prefix.'tpfw_tickets', $sNano, 1, 2, 3, 4, 86400, 1, $sNow, $sNow)
		));

		$GLOBALS['wpdb'] = $this->wpdb;
		delete_option('tpfw_db_version');
		$this->assertTrue($o->maybe_install());
		$this->assertSame(TPFW_DB_Installer::DB_VERSION, get_option('tpfw_db_version'));
		$this->assertTrue($this->tableExists('tpfw_pass'));
		$oRow = $this->wpdb->get_row($this->wpdb->prepare(
			'SELECT nano_id FROM %i WHERE nano_id = %s',
			$this->wpdb->prefix.'tpfw_tickets',
			$sNano
		));
		$this->assertNotNull($oRow);
		$this->assertSame($sNano, $oRow->nano_id);
	}

	public function test_rerun_after_success_preserves_rows(): void
	{
		$o = $this->installerSkippingBootInstall();
		delete_option('tpfw_db_version');
		$this->assertTrue($o->maybe_install());
		$sNano = 'live'.bin2hex(random_bytes(6));
		$sNow  = gmdate('Y-m-d H:i:s');
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
			VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s)',
			array($this->wpdb->prefix.'tpfw_tickets', $sNano, 1, 2, 3, 4, 86400, 1, $sNow, $sNow)
		));
		$this->assertTrue($o->maybe_install());
		delete_option('tpfw_db_version');
		$this->assertTrue($o->maybe_install());
		$oRow = $this->wpdb->get_row($this->wpdb->prepare(
			'SELECT nano_id FROM %i WHERE nano_id = %s',
			$this->wpdb->prefix.'tpfw_tickets',
			$sNano
		));
		$this->assertSame($sNano, $oRow->nano_id);
	}

	public function test_failed_version_write_is_not_success(): void
	{
		$o = $this->installerSkippingBootInstall();
		$GLOBALS['tpfw_test_update_option'] = static function($sKey, $mValue) {
			return false;
		};
		$this->assertTrue($o->install());
		$this->assertFalse($o->maybe_install());
		$this->assertFalse(TPFW_DB_Installer::schema_is_current());
	}

	public function test_already_current_version_is_success_even_if_update_option_false(): void
	{
		$o = $this->installerSkippingBootInstall();
		$this->assertTrue($o->maybe_install());
		$GLOBALS['tpfw_test_update_option'] = static function($sKey, $mValue) {
			return false;
		};
		$this->assertTrue($o->maybe_install());
		$this->assertTrue(TPFW_DB_Installer::schema_is_current());
	}

	public function test_update_option_false_is_success_when_stored_value_matches(): void
	{
		$o = $this->installerSkippingBootInstall();
		$GLOBALS['tpfw_test_update_option'] = static function($sKey, $mValue) {
			$GLOBALS['tpfw_test_options'][$sKey] = $mValue;
			return false;
		};
		$this->assertTrue($o->maybe_install());
		$this->assertTrue(TPFW_DB_Installer::schema_is_current());
	}

	public function test_activation_path_failed_create_runs_once_and_is_not_current(): void
	{
		$iCreates = 0;
		$wpdb = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) use (&$iCreates) {
			if(stripos($sSql, 'CREATE TABLE') !== false)
			{
				$iCreates++;
				return true;
			}
			return false;
		});
		$GLOBALS['wpdb'] = $wpdb;
		new TPFW_DB_Installer();
		$this->assertSame(1, $iCreates);
		$this->assertFalse(TPFW_DB_Installer::schema_is_current());
	}

	public function test_activation_path_success_is_current(): void
	{
		new TPFW_DB_Installer();
		$this->assertTrue(TPFW_DB_Installer::schema_is_current());
		foreach($this->installerTables() as $sTable)
		{
			$this->assertTrue($this->tableExists($sTable), $sTable);
		}
	}

	public function test_activation_hook_uses_constructor_result_not_a_second_maybe_install(): void
	{
		$sBoot = file_get_contents(TPFW_PLUGIN_DIR.'tickets-passes-for-woocommerce.php');
		$this->assertSame(1, preg_match(
			'/function tpfw_activate_plugin\(\)\s*\{(.*?)\n    \}/s',
			$sBoot,
			$aMatch
		));
		$sBody = $aMatch[1];
		$this->assertFalse(strpos($sBody, 'maybe_install'));
		$iNew  = strpos($sBody, 'new TPFW_DB_Installer');
		$iCheck = strpos($sBody, 'schema_is_current');
		$iDie  = strrpos($sBody, 'wp_die');
		$iRewrite = strpos($sBody, "delete_option('tpfw_rewrite_version')");
		$this->assertNotFalse($iNew);
		$this->assertNotFalse($iCheck);
		$this->assertNotFalse($iDie);
		$this->assertNotFalse($iRewrite);
		$this->assertLessThan($iCheck, $iNew);
		$this->assertLessThan($iDie, $iCheck);
		$this->assertLessThan($iRewrite, $iDie);
		$this->assertNotFalse(strpos($sBody, 'deactivate_plugins'));
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
	 * Pre-1.3.0 pass table: no guest_slot, no parent_guest_slot unique key.
	 *
	 * @return void
	 */
	private function createLegacyPassTable()
	{
		$p = $this->wpdb->prefix;
		$this->wpdb->query("DROP TABLE IF EXISTS `{$p}tpfw_pass`");
		$this->wpdb->query("CREATE TABLE `{$p}tpfw_pass` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`nano_id` VARCHAR(32) NOT NULL,
			`parent_nano_id_fk` VARCHAR(32) NULL DEFAULT NULL,
			`product_id` BIGINT UNSIGNED NOT NULL,
			`user_id` BIGINT UNSIGNED NOT NULL,
			`user_payer_id` BIGINT UNSIGNED NOT NULL,
			`order_id` BIGINT UNSIGNED NOT NULL,
			`order_line_id` BIGINT UNSIGNED NOT NULL,
			`valid_duration` INT UNSIGNED NOT NULL DEFAULT 0,
			`max_uses` INT UNSIGNED NOT NULL DEFAULT 1,
			`created` DATETIME NULL DEFAULT NULL,
			`updated` DATETIME NULL DEFAULT NULL,
			`deleted` DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `nano_id` (`nano_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	}

	/**
	 * @param string $sTable Unprefixed plugin table name.
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
