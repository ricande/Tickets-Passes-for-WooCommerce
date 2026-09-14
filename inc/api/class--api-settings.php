<?php
defined('ABSPATH') or die('No script kiddies please!');

require_once dirname(__FILE__) . '/../settings/class--settings-tab.php';

/**
 * Scanner / API settings tab: check-in behaviour, scanner access and the status colours.
 *
 * Stores its own tpfw_api_settings_options option, which TPFW_API and the scanner page both
 * read. Toggling the scanner here changes the rewrite rules, so saving schedules a flush -
 * see ajax_save_tpfw_api_settings_callback(). The page shell lives in TPFW_Settings_Tab.
 *
 * @package Tickets_Passes_For_WooCommerce
 */
class TPFW_API_Settings extends TPFW_Settings_Tab
{
	protected $sSlug           = 'tpfw-api-settings';
	protected $sTabKey         = 'api';
	protected $sTemplate       = 'setting-pages/api-page-content.php';
	protected $sScriptFile     = 'js/api-settings.js';
	protected $sStyleFile      = 'css/api-settings.css';
	protected $sParamsName     = 'tpfwParamsApiSettings';
	protected $sSaveAction     = 'ajax_save_tpfw_api_settings';
	protected $sOptionName     = 'tpfw_api_settings_options';
	protected $sSanitizeMethod = 'sanitize_api_settings';

	/**
	 * @param string         $sPrefix    Option/handle prefix for the plugin ('tpfw').
	 * @param TPFW_Functions $oFunctions Shared helper instance.
	 */
	public function __construct($sPrefix, $oFunctions)
	{
		$this->sMenuLabel = __('Scanner / API Settings', 'tickets-passes-for-woocommerce');
		parent::__construct($sPrefix, $oFunctions);
	}

	/** @return string */
	protected function get_dir() { return dirname(__FILE__); }

	/**
	 * The template reads the merged (defaults back-filled) options rather than the raw row.
	 *
	 * @return array
	 */
	protected function get_template_options()
	{
		return $this->oFunctions->get_api_settings_options();
	}

	/**
	 * @return void
	 */
	public function ajax_save_callback()
	{
		$this->ajax_save_tpfw_api_settings_callback();
	}

	/**
	 * Saves the API settings tab.
	 *
	 * Requires the admin nonce and manage_woocommerce. The two toggles are stored as 0/1 and the
	 * three status colours as validated #rrggbb values (a blank field stores the shipped
	 * default). The option is written whole: this tab owns every key in
	 * tpfw_api_settings_options, so there is nothing from other tabs to merge.
	 *
	 * @return void
	 */
	public function ajax_save_tpfw_api_settings_callback()
	{
		$response = array();
		check_ajax_referer('tpfw_ajax_save_tpfw_api_settings', 'security');
		if(!$this->oFunctions->user_can_manage())
		{
			$response['sMessage'] = __('Current user does not have admin privileges', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}
			
		$bEnableAPI = 0;
		if(isset($_POST['bEnableAPI']) && !empty($_POST['bEnableAPI']))
		{
			$bEnableAPI = 1;
		}

		$bEnableScanner = 0;
		if(isset($_POST['bEnableScanner']) && !empty($_POST['bEnableScanner']))
		{
			$bEnableScanner = 1;
		}

		// One loop for the three colours: the copy-pasted blocks this replaces named "406" in
		// all three error messages. A blank field falls back to the shipped default rather than
		// storing '' - the scanner and the REST refusals paint with whatever is stored, and ''
		// left the result banner with no background at all.
		$aDefaults     = $this->oFunctions->get_api_settings_options(true);
		$aStatusColors = array();
		foreach(array('status_406', 'status_202', 'status_200') as $sStatusKey)
		{
			$sRaw = sanitize_text_field(wp_unslash($_POST[$sStatusKey] ?? ''));
			if($sRaw === '')
			{
				$aStatusColors[$sStatusKey] = $aDefaults[$sStatusKey];
				continue;
			}
			$sHex = sanitize_hex_color(strpos($sRaw, '#') === 0 ? $sRaw : '#'.$sRaw);
			if(!$sHex)
			{
				/* translators: %s: HTTP status the colour is for, e.g. 202 */
				$response['sMessage'] = sprintf(__('Status %s color is not a valid HEX format color', 'tickets-passes-for-woocommerce'), substr($sStatusKey, 7));
				wp_send_json_error($response);
			}
			$aStatusColors[$sStatusKey] = $sHex;
		}

		$aTPFWSettings = array_merge(array(
			'bEnableAPI'     => $bEnableAPI,
			'bEnableScanner' => $bEnableScanner,
		), $aStatusColors);
		update_option('tpfw_api_settings_options', $aTPFWSettings);

		// Turning the scanner on or off adds or removes the /check-in/ rewrite rule, and
		// that rule set is cached until something flushes it. Clearing the version option makes
		// tpfw_maybe_flush_rewrites() do the flush on the very next request, once 'init' has run
		// and the rules have actually been registered.
		delete_option('tpfw_rewrite_version');

		$response['sMessage']              = __('Scanner / API Settings have successfully been updated', 'tickets-passes-for-woocommerce');
		wp_send_json_success($response);
	}
}