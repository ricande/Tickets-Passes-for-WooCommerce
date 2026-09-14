<?php
/**
 * Disposable tables used by the DB tests. Prefixed tpfwtest_ so shop data is never touched.
 */
class TPFW_Test_Schema
{
	/**
	 * @param TPFW_Test_Wpdb $wpdb
	 * @return void
	 */
	public static function install($wpdb)
	{
		$p = $wpdb->prefix;
		$wpdb->query("DROP TABLE IF EXISTS `{$p}tpfw_pass_stats`");
		$wpdb->query("DROP TABLE IF EXISTS `{$p}tpfw_pass`");
		$wpdb->query("DROP TABLE IF EXISTS `{$p}tpfw_timeslot_reservations`");
		$wpdb->query("DROP TABLE IF EXISTS `{$p}tpfw_timeslot_tickets`");
		$wpdb->query("DROP TABLE IF EXISTS `{$p}tpfw_timeslots`");
		$wpdb->query("DROP TABLE IF EXISTS `{$p}tpfw_tickets`");

		$wpdb->query("CREATE TABLE `{$p}tpfw_pass` (
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
			UNIQUE KEY `parent_guest_slot` (`parent_nano_id_fk`, `guest_slot`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$wpdb->query("CREATE TABLE `{$p}tpfw_pass_stats` (
			`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`nano_id_fk` VARCHAR(32) NOT NULL,
			`user_id` BIGINT UNSIGNED NOT NULL,
			`created` DATETIME NULL DEFAULT NULL,
			`updated` DATETIME NULL DEFAULT NULL,
			`deleted` DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (`id`),
			KEY `nano_id_fk` (`nano_id_fk`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$wpdb->query("CREATE TABLE `{$p}tpfw_timeslots` (
			`id` VARCHAR(32) NOT NULL,
			`product_id` BIGINT UNSIGNED NOT NULL,
			`user_id` BIGINT UNSIGNED NOT NULL,
			`start` DATETIME NULL DEFAULT NULL,
			`end` DATETIME NULL DEFAULT NULL,
			`available_slots` INT UNSIGNED NOT NULL DEFAULT 0,
			`created` DATETIME NULL DEFAULT NULL,
			`updated` DATETIME NULL DEFAULT NULL,
			`deleted` DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (`id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$wpdb->query("CREATE TABLE `{$p}tpfw_timeslot_tickets` (
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
			UNIQUE KEY `nano_id` (`nano_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$wpdb->query("CREATE TABLE `{$p}tpfw_timeslot_reservations` (
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
			PRIMARY KEY (`id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$wpdb->query("CREATE TABLE `{$p}tpfw_tickets` (
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
			UNIQUE KEY `nano_id` (`nano_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	}

	/**
	 * @param TPFW_Test_Wpdb $wpdb
	 * @return void
	 */
	public static function drop($wpdb)
	{
		$p = $wpdb->prefix;
		foreach(array('tpfw_pass_stats', 'tpfw_pass', 'tpfw_timeslot_reservations', 'tpfw_timeslot_tickets', 'tpfw_timeslots', 'tpfw_tickets') as $sTable)
		{
			$wpdb->query("DROP TABLE IF EXISTS `{$p}{$sTable}`");
		}
	}
}
