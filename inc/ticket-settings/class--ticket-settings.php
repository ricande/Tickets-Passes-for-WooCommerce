<?php
defined('ABSPATH') or die('No script kiddies please!');

require_once dirname(__FILE__) . '/../settings/class--settings-tab.php';

/**
 * Ticket settings tab: defaults for the Ticket product type.
 *
 * Its keys live in the shared tpfw_general_settings_options option alongside the other tabs'.
 * Everything but the keys and defaults lives in TPFW_Type_Settings_Tab.
 *
 * @package Tickets_Passes_For_WooCommerce
 */
class TPFW_Ticket_Settings extends TPFW_Type_Settings_Tab
{
	protected $sSlug             = 'tpfw-ticket-settings';
	protected $sTabKey           = 'ticket';
	protected $sTemplate         = 'setting-pages/ticket-page-content.php';
	protected $sSaveAction       = 'ajax_save_tpfw_ticket_settings';
	protected $sTypeKey          = 'ticket';
	protected $sEnableKey        = 'bEnableTicketProduct';
	protected $sHintKey          = 'sTicketHintText';
	protected $sExtraRowSelector = '.tpfw-ticket-extra-row';
	protected $aColorDefaults    = array(
		'sTicketColorAccent'     => '#000000',
		'sTicketColorText'       => '#000000',
		'sTicketColorBorder'     => '#c3c4c7',
		'sTicketColorBackground' => '#ffffff',
		'sTicketColorHint'       => '#646970',
		'sTicketColorDayName'    => '#646970',
		'sTicketColorNavTitle'   => '#000000',
	);

	/**
	 * @param string         $sPrefix    Option/handle prefix for the plugin ('tpfw').
	 * @param TPFW_Functions $oFunctions Shared helper instance.
	 */
	public function __construct($sPrefix, $oFunctions)
	{
		$this->sMenuLabel    = __('Ticket Settings', 'tickets-passes-for-woocommerce');
		$this->sHintDefault  = __('Pick the date this ticket should be valid from.', 'tickets-passes-for-woocommerce');
		$this->sSavedMessage = __('Ticket settings have successfully been updated', 'tickets-passes-for-woocommerce');
		$this->sColorError   = __('One of the ticket colors is not a valid HEX format color', 'tickets-passes-for-woocommerce');
		parent::__construct($sPrefix, $oFunctions);
	}

	/** @return string */
	protected function get_dir() { return dirname(__FILE__); }
}
