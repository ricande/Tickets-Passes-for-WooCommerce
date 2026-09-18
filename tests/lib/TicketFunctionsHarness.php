<?php
/**
 * Test-only TPFW_Functions for dashboard nano-path races.
 *
 * Skips WordPress hook registration. QR writes are no-ops so cancel/reset exercise
 * their database statements without touching the uploads directory.
 */
if(!class_exists('WP_REST_Response'))
{
	class WP_REST_Response
	{
		private $data;
		private $status;

		public function __construct($data = null, $status = 200)
		{
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data()
		{
			return $this->data;
		}

		public function get_status()
		{
			return $this->status;
		}
	}
}

if(!class_exists('WC_Product'))
{
	class WC_Product
	{
	}
}

if(!class_exists('TPFW_Product_Ticket'))
{
	class TPFW_Product_Ticket extends WC_Product
	{
	}
}

if(!function_exists('get_post_meta'))
{
	function get_post_meta($iId, $sKey, $bSingle = false)
	{
		unset($bSingle);
		if(isset($GLOBALS['tpfw_test_post_meta'][(int)$iId][$sKey]))
		{
			return $GLOBALS['tpfw_test_post_meta'][(int)$iId][$sKey];
		}
		return '';
	}
}

if(!function_exists('wc_get_product'))
{
	function wc_get_product($iId)
	{
		if(isset($GLOBALS['tpfw_test_products'][(int)$iId]))
		{
			return $GLOBALS['tpfw_test_products'][(int)$iId];
		}
		return new TPFW_Product_Ticket();
	}
}

if(!function_exists('add_action'))
{
	function add_action($sHook, $mCb, $iPrio = 10, $iArgs = 1)
	{
		unset($sHook, $mCb, $iPrio, $iArgs);
	}
}

require_once TPFW_PLUGIN_DIR.'inc/functions/class--functions.php';

class TPFW_Test_Ticket_Functions extends TPFW_Functions
{
	public function __construct()
	{
		$this->sPrefix = 'tpfw';
	}

	public function write_scanner_qr($iProductID, $sType, $sNanoID)
	{
		unset($iProductID, $sType, $sNanoID);
		return true;
	}

	public function delete_qr_code($sQRCode)
	{
		unset($sQRCode);
		return false;
	}
}

/**
 * @param TPFW_Test_Wpdb $wpdb
 * @return void
 */
function tpfw_test_install_ticket_stats($wpdb)
{
	$p = $wpdb->prefix;
	$wpdb->query("CREATE TABLE IF NOT EXISTS `{$p}tpfw_tickets_stats` (
		`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		`nano_id_fk` VARCHAR(32) NOT NULL,
		`user_id` BIGINT UNSIGNED NOT NULL,
		`created` DATETIME NULL DEFAULT NULL,
		`updated` DATETIME NULL DEFAULT NULL,
		`deleted` DATETIME NULL DEFAULT NULL,
		PRIMARY KEY (`id`),
		KEY `nano_id_fk` (`nano_id_fk`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * @param TPFW_Test_Wpdb $wpdb
 * @return void
 */
function tpfw_test_drop_ticket_stats($wpdb)
{
	$wpdb->query('DROP TABLE IF EXISTS `'.$wpdb->prefix.'tpfw_tickets_stats`');
}
