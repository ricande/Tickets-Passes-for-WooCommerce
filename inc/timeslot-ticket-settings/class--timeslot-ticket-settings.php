<?php
defined('ABSPATH') or die('No script kiddies please!');

require_once dirname(__FILE__) . '/../settings/class--settings-tab.php';

/**
 * Timeslot Ticket settings tab: defaults for the Timeslot Ticket product type.
 *
 * Its keys live in the shared tpfw_general_settings_options option alongside the other tabs'.
 * Everything but the keys and defaults lives in TPFW_Type_Settings_Tab.
 *
 * @package Tickets_Passes_For_WooCommerce
 */
class TPFW_Timeslot_Ticket_Settings extends TPFW_Type_Settings_Tab
{
	protected $sSlug             = 'tpfw-timeslot-ticket-settings';
	protected $sTabKey           = 'timeslot';
	protected $sTemplate         = 'setting-pages/timeslot-ticket-page-content.php';
	protected $sSaveAction       = 'ajax_save_tpfw_timeslot_ticket_settings';
	protected $sTypeKey          = 'timeslot';
	protected $sEnableKey        = 'bEnableTimeslotProduct';
	protected $sHintKey          = 'sTimeslotHintText';
	protected $sExtraRowSelector = '.tpfw-timeslot-ticket-extra-row';
	protected $aColorDefaults    = array(
		'sTimeslotColorAccent'     => '#000000',
		'sTimeslotColorText'       => '#000000',
		'sTimeslotColorBorder'     => '#000000',
		'sTimeslotColorBackground' => '#ffffff',
		'sTimeslotColorHint'       => '#646970',
		'sTimeslotColorDayName'    => '#b45309',
		'sTimeslotColorNavTitle'   => '#000000',
	);

	/**
	 * @param string         $sPrefix    Option/handle prefix for the plugin ('tpfw').
	 * @param TPFW_Functions $oFunctions Shared helper instance.
	 */
	public function __construct($sPrefix, $oFunctions)
	{
		$this->sMenuLabel    = __('Timeslot Ticket Settings', 'tickets-passes-for-woocommerce');
		$this->sHintDefault  = __('Pick an available date below, then choose a time.', 'tickets-passes-for-woocommerce');
		$this->sSavedMessage = __('Timeslot Ticket settings have successfully been updated', 'tickets-passes-for-woocommerce');
		$this->sColorError   = __('One of the timeslot ticket colors is not a valid HEX format color', 'tickets-passes-for-woocommerce');
		parent::__construct($sPrefix, $oFunctions);
	}

	/** @return string */
	protected function get_dir() { return dirname(__FILE__); }
}
