<?php
defined('ABSPATH') or die('No script kiddies please!');

require_once dirname(__FILE__) . '/../dashboard/class--dashboard.php';

/**
 * Tickets admin screen: every issued ticket, with check-in, resend, reset and cancel per row.
 *
 * Everything but the table names and labels lives in TPFW_Dashboard.
 *
 * @package Tickets_Passes_For_WooCommerce
 */
class TPFW_Ticket_Dashboard extends TPFW_Dashboard
{
	protected $sSlug                    = 'tpfw-tickets';
	protected $sTableClass              = 'TPFW_Tickets_Table_Dashboard';
	protected $sActionKey               = 'ticket';
	protected $sCheckinKey              = 'ticket';
	protected $sTable                   = 'tpfw_tickets';
	protected $sResetMethod             = 'reset_ticket';
	protected $sCancelMethod            = 'cancel_ticket';
	protected $sResendTemplateKey       = 'resend_ticket_email';
	protected $sResendReplacementMethod = 'resend_ticket_email_replacement';
	protected $bSupportsTransfer        = true;

	/**
	 * @param string         $sPrefix    Option/handle prefix for the plugin ('tpfw').
	 * @param TPFW_Functions $oFunctions Shared helper instance.
	 */
	public function __construct($sPrefix, $oFunctions)
	{
		$this->sMenuLabel        = __('Tickets', 'tickets-passes-for-woocommerce');
		$this->sRowLabel         = __('Ticket', 'tickets-passes-for-woocommerce');
		$this->sPageTitle        = __('Tickets', 'tickets-passes-for-woocommerce');
		$this->sResetStatusLabel = __('Unused', 'tickets-passes-for-woocommerce');
		parent::__construct($sPrefix, $oFunctions);
	}

	/**
	 * @param object $wpdb
	 * @param string $sNanoID
	 * @param int    $iNewUserID
	 * @return array{bSuccess:bool,sMessage:string,oRow:?object,iOldUserID?:int}
	 */
	protected function transfer_live_row($wpdb, $sNanoID, $iNewUserID)
	{
		return TPFW_Ticket_Line::transfer_nano($wpdb, $sNanoID, $iNewUserID);
	}
}

/**
 * WP_List_Table rendering of the tickets table.
 */
class TPFW_Tickets_Table_Dashboard extends TPFW_Dashboard_Table
{
	protected $sTable      = 'tpfw_tickets';
	protected $sStatsTable = 'tpfw_tickets_stats';

	/**
	 * @param TPFW_Functions $oFunctions Shared helper instance.
	 * @param TPFW_Dashboard $oDashboard Screen this table belongs to.
	 */
	public function __construct($oFunctions, $oDashboard)
	{
		parent::__construct($oFunctions, $oDashboard, array('singular' => 'ticket', 'plural' => 'tickets'));
	}

	/**
	 * @return array Column id => translated heading.
	 */
	public function get_columns()
	{
		return array(
			'cb'         => '<input type="checkbox" />',
			'user_id'    => __('User ID', 'tickets-passes-for-woocommerce'),
			'nano_id'    => __('Ticket ID', 'tickets-passes-for-woocommerce'),
			'status'     => __('Status', 'tickets-passes-for-woocommerce'),
			'valid'      => __('Valid from', 'tickets-passes-for-woocommerce'),
			'checkins'   => __('Check-ins', 'tickets-passes-for-woocommerce'),
			'product_id' => __('Product', 'tickets-passes-for-woocommerce'),
			'order_id'   => __('Order', 'tickets-passes-for-woocommerce'),
			'created'    => __('Created', 'tickets-passes-for-woocommerce'),
			'actions'    => __('Actions', 'tickets-passes-for-woocommerce'),
		);
	}

	/**
	 * @return array See TPFW_Dashboard_Table::get_status_filters().
	 */
	protected function get_status_filters()
	{
		return $this->ticket_status_filters();
	}

	/**
	 * @param object $oResult Database row.
	 * @param array  $aCtx    Batched context.
	 * @return array Row data keyed by column.
	 */
	protected function build_row($oResult, $aCtx)
	{
		$iUses = (int) ($aCtx['aStatisticCounts'][$oResult->nano_id] ?? 0);
		list($sSlug, $sLabel) = $this->derive_ticket_status($oResult, $iUses, $aCtx['iNow']);
		$sStatus = $this->oFunctions->get_status_pill_html($sLabel, $sSlug === 'cancelled' ? gmdate($aCtx['sDateTimeFormat'], strtotime($oResult->deleted)) : '');

		$aRow            = $this->common_cells($oResult, $aCtx, $sStatus, $iUses);
		$aRow['actions'] = $this->standard_actions($oResult, $sSlug);
		return $aRow;
	}
}
