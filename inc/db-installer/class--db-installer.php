<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Creates the plugin's nine custom tables and tracks the installed schema version.
 *
 * Runs on every load rather than only on activation, because activation does not fire on
 * plugin updates - bumping DB_VERSION is what triggers the next install pass.
 */
class TPFW_DB_Installer
{
	const DB_VERSION = '1.0.4';

	/**
	 * Runs the schema check immediately - the class is only ever instantiated on plugin load.
	 */
	public function __construct()
	{
		$this->maybe_install();
	}

	/**
	 * Creates the tables once per DB_VERSION bump, then records the new version.
	 *
	 * @return void
	 */
	public function maybe_install()
	{
		if(get_option('tpfw_db_version') === self::DB_VERSION)
		{
			return;
		}

		if($this->install() === false)
		{
			if(function_exists('error_log'))
			{
				error_log('TPFW: database install/backfill failed; leaving tpfw_db_version unchanged');
			}
			return;
		}
		update_option('tpfw_db_version', self::DB_VERSION);
	}


	/**
	 * Creates any missing table.
	 *
	 * CREATE TABLE IF NOT EXISTS never alters a table that already exists, so a schema change
	 * has to go with a DB_VERSION bump and a matching ALTER.
	 *
	 * @return bool False when guest-slot backfill or the unique index cannot be applied.
	 */
	public function install()
	{
		global $wpdb;
		$sCharsetCollate = $wpdb->get_charset_collate();
		$sPrefix         = $wpdb->prefix;

		$aTables = array();

		$aTables[] = "CREATE TABLE IF NOT EXISTS `{$sPrefix}tpfw_tickets` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`nano_id` VARCHAR(32) NOT NULL,
			`product_id` BIGINT UNSIGNED NOT NULL,
			`user_id` BIGINT UNSIGNED NOT NULL,
			`order_id` BIGINT UNSIGNED NOT NULL,
			`order_line_id` BIGINT UNSIGNED NOT NULL,
			`valid_duration` INT UNSIGNED NOT NULL DEFAULT 0,
			`valid_from` DATETIME NULL DEFAULT NULL,
			`valid_to` DATETIME NULL DEFAULT NULL,
			`max_uses` INT UNSIGNED NOT NULL DEFAULT 1,
			`created` DATETIME NULL DEFAULT NULL,
			`updated` DATETIME NULL DEFAULT NULL,
			`deleted` DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `nano_id` (`nano_id`),
			KEY `product_id` (`product_id`),
			KEY `user_id` (`user_id`),
			KEY `order_id` (`order_id`)
		) {$sCharsetCollate};";

		$aTables[] = "CREATE TABLE IF NOT EXISTS `{$sPrefix}tpfw_tickets_stats` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`nano_id_fk` VARCHAR(32) NOT NULL,
			`user_id` BIGINT UNSIGNED NOT NULL,
			`created` DATETIME NULL DEFAULT NULL,
			`updated` DATETIME NULL DEFAULT NULL,
			`deleted` DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `nano_id_fk` (`nano_id_fk`),
			KEY `user_created` (`user_id`, `created`)
		) {$sCharsetCollate};";

		$aTables[] = "CREATE TABLE IF NOT EXISTS `{$sPrefix}tpfw_timeslot_tickets` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`timeslot_id` VARCHAR(32) NOT NULL,
			`nano_id` VARCHAR(32) NOT NULL,
			`product_id` BIGINT UNSIGNED NOT NULL,
			`user_id` BIGINT UNSIGNED NOT NULL,
			`order_id` BIGINT UNSIGNED NOT NULL,
			`order_line_id` BIGINT UNSIGNED NOT NULL,
			`before_checkin_duration` INT NOT NULL DEFAULT 0,
			`valid_from` DATETIME NULL DEFAULT NULL,
			`valid_to` DATETIME NULL DEFAULT NULL,
			`max_uses` INT UNSIGNED NOT NULL DEFAULT 1,
			`created` DATETIME NULL DEFAULT NULL,
			`updated` DATETIME NULL DEFAULT NULL,
			`deleted` DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `nano_id` (`nano_id`),
			KEY `timeslot_id` (`timeslot_id`),
			KEY `product_id` (`product_id`),
			KEY `user_id` (`user_id`),
			KEY `order_id` (`order_id`)
		) {$sCharsetCollate};";

		$aTables[] = "CREATE TABLE IF NOT EXISTS `{$sPrefix}tpfw_timeslot_tickets_stats` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`nano_id_fk` VARCHAR(32) NOT NULL,
			`user_id` BIGINT UNSIGNED NOT NULL,
			`created` DATETIME NULL DEFAULT NULL,
			`updated` DATETIME NULL DEFAULT NULL,
			`deleted` DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `nano_id_fk` (`nano_id_fk`),
			KEY `user_created` (`user_id`, `created`)
		) {$sCharsetCollate};";

		$aTables[] = "CREATE TABLE IF NOT EXISTS `{$sPrefix}tpfw_timeslots` (
			`id` VARCHAR(32) NOT NULL,
			`product_id` BIGINT UNSIGNED NOT NULL,
			`user_id` BIGINT UNSIGNED NOT NULL,
			`start` DATETIME NULL DEFAULT NULL,
			`end` DATETIME NULL DEFAULT NULL,
			`available_slots` INT UNSIGNED NOT NULL DEFAULT 0,
			`timeslot_recurring_id_fk` VARCHAR(32) NULL DEFAULT NULL,
			`manual` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			`created` DATETIME NULL DEFAULT NULL,
			`updated` DATETIME NULL DEFAULT NULL,
			`deleted` DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `product_id` (`product_id`),
			KEY `timeslot_recurring_id_fk` (`timeslot_recurring_id_fk`)
		) {$sCharsetCollate};";

		$aTables[] = "CREATE TABLE IF NOT EXISTS `{$sPrefix}tpfw_timeslots_recurring` (
			`id` VARCHAR(32) NOT NULL,
			`start` DATETIME NULL DEFAULT NULL,
			`end` DATETIME NULL DEFAULT NULL,
			`slot_start` TIME NULL DEFAULT NULL,
			`slot_end` TIME NULL DEFAULT NULL,
			`weekday` TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`week_number_start` INT NOT NULL DEFAULT 0,
			`week_number_end` INT NOT NULL DEFAULT 0,
			`available_slots` INT UNSIGNED NOT NULL DEFAULT 0,
			`product_id` BIGINT UNSIGNED NOT NULL,
			`user_id` BIGINT UNSIGNED NOT NULL,
			`created` DATETIME NULL DEFAULT NULL,
			`updated` DATETIME NULL DEFAULT NULL,
			`deleted` DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `product_id` (`product_id`),
			KEY `end` (`end`),
			KEY `updated` (`updated`)
		) {$sCharsetCollate};";

		$aTables[] = "CREATE TABLE IF NOT EXISTS `{$sPrefix}tpfw_timeslot_reservations` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`timeslot_id` VARCHAR(32) NOT NULL,
			`reservation_id` VARCHAR(32) NOT NULL,
			`product_id` BIGINT UNSIGNED NOT NULL,
			`quantity` INT UNSIGNED NOT NULL DEFAULT 1,
			`user_id` BIGINT UNSIGNED NOT NULL,
			`order_id` BIGINT UNSIGNED NULL DEFAULT NULL,
			`order_line_id` BIGINT UNSIGNED NULL DEFAULT NULL,
			`valid_from` DATETIME NULL DEFAULT NULL,
			`valid_to` DATETIME NULL DEFAULT NULL,
			`created` DATETIME NULL DEFAULT NULL,
			`updated` DATETIME NULL DEFAULT NULL,
			`deleted` DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `reservation_id` (`reservation_id`),
			KEY `timeslot_id` (`timeslot_id`),
			KEY `order_id` (`order_id`),
			KEY `valid_to` (`valid_to`)
		) {$sCharsetCollate};";

		$aTables[] = "CREATE TABLE IF NOT EXISTS `{$sPrefix}tpfw_pass` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`nano_id` VARCHAR(32) NOT NULL,
			`parent_nano_id_fk` VARCHAR(32) NULL DEFAULT NULL,
			`guest_slot` TINYINT UNSIGNED NULL DEFAULT NULL,
			`product_id` BIGINT UNSIGNED NOT NULL,
			`user_id` BIGINT UNSIGNED NOT NULL,
			`user_payer_id` BIGINT UNSIGNED NOT NULL,
			`order_id` BIGINT UNSIGNED NOT NULL,
			`order_line_id` BIGINT UNSIGNED NOT NULL,
			`firstname` VARCHAR(255) NULL DEFAULT NULL,
			`lastname` VARCHAR(255) NULL DEFAULT NULL,
			`valid_duration` INT UNSIGNED NOT NULL DEFAULT 0,
			`valid_from` DATETIME NULL DEFAULT NULL,
			`valid_to` DATETIME NULL DEFAULT NULL,
			`max_uses` INT UNSIGNED NOT NULL DEFAULT 1,
			`profile_image_type` VARCHAR(10) NULL DEFAULT NULL,
			`created` DATETIME NULL DEFAULT NULL,
			`updated` DATETIME NULL DEFAULT NULL,
			`deleted` DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			UNIQUE KEY `nano_id` (`nano_id`),
			UNIQUE KEY `parent_guest_slot` (`parent_nano_id_fk`, `guest_slot`),
			KEY `product_id` (`product_id`),
			KEY `user_id` (`user_id`),
			KEY `order_id` (`order_id`)
		) {$sCharsetCollate};";

		$aTables[] = "CREATE TABLE IF NOT EXISTS `{$sPrefix}tpfw_pass_stats` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`nano_id_fk` VARCHAR(32) NOT NULL,
			`user_id` BIGINT UNSIGNED NOT NULL,
			`created` DATETIME NULL DEFAULT NULL,
			`updated` DATETIME NULL DEFAULT NULL,
			`deleted` DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `nano_id_fk` (`nano_id_fk`),
			KEY `user_created` (`user_id`, `created`)
		) {$sCharsetCollate};";

		foreach($aTables as $sTableSQL)
		{
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- schema DDL built from $wpdb->prefix and get_charset_collate(); identifiers cannot be placeholders.
			$wpdb->query($sTableSQL);
		}

		// CREATE TABLE IF NOT EXISTS leaves an existing table exactly as it is, so an index added
		// after a site's first install only arrives through here.
		foreach(array('tpfw_tickets_stats', 'tpfw_timeslot_tickets_stats', 'tpfw_pass_stats') as $sStatsTable)
		{
			$this->maybe_add_index($sPrefix.$sStatsTable, 'user_created', '(`user_id`, `created`)');
		}

		// The three admin dashboards page by ORDER BY created DESC, and the product page and
		// cron filter timeslots by start; neither had an index, so both were full scans.
		foreach(array('tpfw_tickets', 'tpfw_timeslot_tickets', 'tpfw_pass') as $sRowTable)
		{
			$this->maybe_add_index($sPrefix.$sRowTable, 'created', '(`created`)');
		}
		$this->maybe_add_index($sPrefix.'tpfw_timeslots', 'product_start', '(`product_id`, `start`)');

		$this->maybe_add_column($sPrefix.'tpfw_pass', 'guest_slot', 'TINYINT UNSIGNED NULL DEFAULT NULL AFTER `parent_nano_id_fk`');
		if(!TPFW_Guest_Pass_Issuer::backfill_legacy_slots($wpdb))
		{
			return false;
		}
		if(!$this->maybe_add_unique_index($sPrefix.'tpfw_pass', 'parent_guest_slot', '(`parent_nano_id_fk`, `guest_slot`)'))
		{
			return false;
		}
		return true;
	}



	/**
	 * Adds a column to an existing table, unless it is already there.
	 *
	 * @param string $sTable      Full table name, prefix included.
	 * @param string $sColumn     Column name.
	 * @param string $sDefinition Column definition after the name, e.g. 'TINYINT UNSIGNED NULL'.
	 * @return void
	 */
	private function maybe_add_column($sTable, $sColumn, $sDefinition)
	{
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sTable and $sColumn are literals from install(); identifiers cannot be placeholders.
		$aExisting = $wpdb->get_results("SHOW COLUMNS FROM `{$sTable}` LIKE '{$sColumn}'");
		if(!empty($aExisting)) return;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema DDL; see above.
		$wpdb->query("ALTER TABLE `{$sTable}` ADD COLUMN `{$sColumn}` {$sDefinition}");
	}

	/**
	 * Adds a UNIQUE index unless it is already there.
	 *
	 * @param string $sTable   Full table name, prefix included.
	 * @param string $sIndex   Index name.
	 * @param string $sColumns Column list, parentheses included.
	 * @return bool False when the ALTER fails.
	 */
	private function maybe_add_unique_index($sTable, $sIndex, $sColumns)
	{
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sTable and $sIndex are literals from install(); identifiers cannot be placeholders.
		$aExisting = $wpdb->get_results("SHOW INDEX FROM `{$sTable}` WHERE Key_name = '{$sIndex}'");
		if(!empty($aExisting))
		{
			return true;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema DDL; see above.
		$m = $wpdb->query("ALTER TABLE `{$sTable}` ADD UNIQUE KEY `{$sIndex}` {$sColumns}");
		return $m !== false;
	}



	/**
	 * Adds an index to an existing table, unless it is already there.
	 *
	 * MySQL has no ADD KEY IF NOT EXISTS, and this runs on every DB_VERSION bump, so the
	 * existence check is the idempotency.
	 *
	 * @param string $sTable   Full table name, prefix included.
	 * @param string $sIndex   Index name.
	 * @param string $sColumns Column list, parentheses included: '(`user_id`, `created`)'.
	 * @return void
	 */
	private function maybe_add_index($sTable, $sIndex, $sColumns)
	{
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sTable and $sIndex are literals from install(); identifiers cannot be placeholders.
		$aExisting = $wpdb->get_results("SHOW INDEX FROM `{$sTable}` WHERE Key_name = '{$sIndex}'");
		if(!empty($aExisting)) return;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema DDL; see above.
		$wpdb->query("ALTER TABLE `{$sTable}` ADD KEY `{$sIndex}` {$sColumns}");
	}
}
