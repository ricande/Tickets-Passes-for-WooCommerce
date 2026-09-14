<?php
defined('ABSPATH') or die('No script kiddies please!');

require_once dirname(__FILE__) . '/class--settings-tab.php';

/**
 * General settings tab: the plugin's top-level admin menu and its site-wide options.
 *
 * Owns the 'tpfw' menu every other settings tab and dashboard hangs off, and stores the
 * keys shared across product types (analytics toggle, date format) in
 * tpfw_general_settings_options. The page shell lives in TPFW_Settings_Tab.
 *
 * @package Tickets_Passes_For_WooCommerce
 */
class TPFW_Settings extends TPFW_Settings_Tab
{
	protected $sSlug           = 'tpfw-settings';
	protected $sTabKey         = 'general';
	protected $sParent         = 'tpfw';
	protected $sTemplate       = 'setting-pages/page-content.php';
	protected $sSaveAction     = 'ajax_save_tpfw_general_settings';
	protected $sSanitizeMethod = 'sanitize_general_settings';

	/**
	 * @param string         $sPrefix    Option/handle prefix for the plugin ('tpfw').
	 * @param TPFW_Functions $oFunctions Shared helper instance.
	 */
	public function __construct($sPrefix, $oFunctions)
	{
		$this->sMenuLabel = __('Settings', 'tickets-passes-for-woocommerce');
		parent::__construct($sPrefix, $oFunctions);
	}

	/** @return string */
	protected function get_dir() { return dirname(__FILE__); }

	/**
	 * Hides the auto-generated duplicate of the parent menu from the submenu.
	 *
	 * add_menu_page() always mirrors the top-level item as its own first submenu entry. The
	 * parent has no callback here - the first real submenu is the landing screen - so that
	 * mirror would be a dead link.
	 *
	 * @return void
	 */
	protected function register_extra()
	{
		add_filter('submenu_file', function($submenu_file)
		{
			remove_submenu_page('tpfw', 'tpfw');
			return $submenu_file;
		});
	}

	/**
	 * Adds the plugin's top-level menu, then this tab as its first entry.
	 *
	 * @return void
	 */
	public function add_settings_page()
	{
		add_menu_page(
			__('Ticket & Passes', 'tickets-passes-for-woocommerce'),
			__('Ticket & Passes', 'tickets-passes-for-woocommerce'),
			'manage_woocommerce',
			'tpfw',
			'',
			'dashicons-menu-alt3',
			58
		);
		parent::add_settings_page();
	}

	/**
	 * Fields the shared settings-tab.js posts for this screen.
	 *
	 * @return array
	 */
	protected function get_script_params()
	{
		return array_merge(parent::get_script_params(), array(
			'sSaveButton' => '.tpfw-admin-general-settings-save',
			'aFields'     => array(
				array('sKey' => 'bEnableAnalytics', 'sSelector' => '.tpfw-general-enable-analytics', 'sType' => 'checkbox'),
				array('sKey' => 'sDateTimeformat',  'sSelector' => '.tpfw-general-datetime-format',  'sType' => 'text'),
			),
		));
	}

	/**
	 * Saves the analytics toggle and the display date format.
	 *
	 * @return void
	 */
	public function ajax_save_callback()
	{
		$response = $this->guard_ajax();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_ajax() verified the nonce.
		$bEnableAnalytics = !empty($_POST['bEnableAnalytics']) ? 1 : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$sDateTimeFormat = sanitize_text_field(wp_unslash($_POST['sDateTimeformat'] ?? ''));
		if($sDateTimeFormat === '') $sDateTimeFormat = 'Y-m-d H:i:s';

		$this->merge_general_options(array(
			'bEnableAnalytics' => $bEnableAnalytics,
			'sDateTimeformat'  => $sDateTimeFormat,
		));

		$response['sMessage'] = __('General settings have successfully been updated', 'tickets-passes-for-woocommerce');
		wp_send_json_success($response);
	}
}
