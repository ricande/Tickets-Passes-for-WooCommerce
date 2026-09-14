<?php
defined('ABSPATH') or die('No script kiddies please!');

require_once dirname(__FILE__) . '/../settings/class--settings-tab.php';

/**
 * Pass settings tab: defaults for the Pass product type.
 *
 * Its keys live in the shared tpfw_general_settings_options option alongside the other tabs'.
 * Everything but the keys and defaults lives in TPFW_Type_Settings_Tab.
 *
 * @package Tickets_Passes_For_WooCommerce
 */
class TPFW_Pass_Settings extends TPFW_Type_Settings_Tab
{
	protected $sSlug             = 'tpfw-pass-settings';
	protected $sTabKey           = 'pass';
	protected $sTemplate         = 'setting-pages/pass-page-content.php';
	protected $sSaveAction       = 'ajax_save_tpfw_pass_settings';
	protected $sTypeKey          = 'pass';
	protected $sEnableKey        = 'bEnablePassProduct';
	protected $sHintKey          = 'sPassHintText';
	protected $sExtraRowSelector = '.tpfw-pass-extra-row';
	protected $aColorDefaults    = array(
		'sPassColorAccent'     => '#000000',
		'sPassColorText'       => '#000000',
		'sPassColorBorder'     => '#000000',
		'sPassColorBackground' => '#ffffff',
		'sPassColorHint'       => '#646970',
	);

	/**
	 * @param string         $sPrefix    Option/handle prefix for the plugin ('tpfw').
	 * @param TPFW_Functions $oFunctions Shared helper instance.
	 */
	public function __construct($sPrefix, $oFunctions)
	{
		$this->sMenuLabel   = __('Pass Settings', 'tickets-passes-for-woocommerce');
		$this->sHintDefault = __('Add a name for each person this pass covers. You can optionally email someone their own copy of the pass.', 'tickets-passes-for-woocommerce');
		$this->sSavedMessage = __('Pass settings have successfully been updated', 'tickets-passes-for-woocommerce');
		$this->sColorError   = __('One of the pass colors is not a valid HEX format color', 'tickets-passes-for-woocommerce');
		parent::__construct($sPrefix, $oFunctions);
	}

	/** @return string */
	protected function get_dir() { return dirname(__FILE__); }
}
