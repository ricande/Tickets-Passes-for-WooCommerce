<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Tickets tab in WooCommerce My Account.
 *
 * Where a customer finds every ticket and timeslot ticket they hold, with the QR and a
 * printable PDF. Every query here is scoped to the logged-in user in SQL.
 *
 * @package Tickets_Passes_For_WooCommerce
 */
class TPFW_Ticket_WC_MyAccount
{
	protected $sPrefix;
	protected $oFunctions;

	/**
	 * @param string        $sPrefix        Option/handle prefix for the plugin ('tpfw').
	 * @param TPFW_Functions $oFunctions     Shared helper instance.
	 */
	public function __construct($sPrefix, $oFunctions) 
	{
		$this->sPrefix 			= $sPrefix;
		$this->oFunctions		= $oFunctions;
		$this->load_settings_dependencies();
	}

	/**
	 * Registers the endpoint, the tab, its assets and the PDF download handler.
	 *
	 * add_myaccount_ticket_endpoint() is called directly rather than re-hooked to 'init': this
	 * class is itself constructed from an 'init' callback (see tpfw_init_plugin()), so a nested
	 * add_action('init', ...) would silently never fire and the endpoint would never exist.
	 *
	 * @return void
	 */
	private function load_settings_dependencies() 
	{
		add_action('wp_enqueue_scripts', 						array($this, 'enqueue_css_ticket'));

		add_action('wp_footer', 								array($this, 'enqueue_script_ticket'));
		
		$this->add_myaccount_ticket_endpoint();

		add_action('query_vars', 								array($this, 'myaccount_ticket_query_vars'), 0);
		
		add_action('woocommerce_account_menu_items', 			array($this, 'add_new_myaccount_ticket_tab'));
		
		add_action('woocommerce_account_tpfw-tickets_endpoint', 		array($this, 'myaccount_ticket_tab_content'));

		add_action('wp_ajax_tpfw_ajax_fetch_downloabable_ticket_pdf', 				array($this, 'ajax_fetch_downloabable_ticket_pdf_callback'));
	}
	
	
	

	/**
	 * Whether the current request is the Tickets tab itself, rather than any other My Account page.
	 *
	 * Not is_wc_endpoint_url(): that only knows WooCommerce's own endpoints, and this one is
	 * registered straight through add_rewrite_endpoint().
	 *
	 * @return bool
	 */
	private function is_tickets_tab()
	{
		global $wp;
		return is_account_page() && isset($wp->query_vars['tpfw-tickets']);
	}
	/**
	 * Loads the tab's stylesheet on the Tickets tab, and just its menu icon on the other
	 * My Account pages.
	 *
	 * @return void
	 */
	public function enqueue_css_ticket()
    {
		if(!is_account_page()) return;

		$this->oFunctions->add_front_inline_style('.woocommerce-MyAccount-navigation-link--tpfw-tickets>a::before{content:"";display:inline-block;width:1.05em;height:1.05em;margin-right:.5em;vertical-align:-.18em;background-color:currentColor;-webkit-mask:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'black\' stroke-width=\'1.8\'%3E%3Cpath d=\'M3 8.5A1.5 1.5 0 0 1 4.5 7h15A1.5 1.5 0 0 1 21 8.5v1.75a1.75 1.75 0 0 0 0 3.5v1.75a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 15.5v-1.75a1.75 1.75 0 0 0 0-3.5V8.5Z\'/%3E%3Cpath d=\'M9.5 7v10\' stroke-dasharray=\'2.5 2.5\'/%3E%3C/svg%3E") no-repeat center/contain;mask:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'black\' stroke-width=\'1.8\'%3E%3Cpath d=\'M3 8.5A1.5 1.5 0 0 1 4.5 7h15A1.5 1.5 0 0 1 21 8.5v1.75a1.75 1.75 0 0 0 0 3.5v1.75a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 15.5v-1.75a1.75 1.75 0 0 0 0-3.5V8.5Z\'/%3E%3Cpath d=\'M9.5 7v10\' stroke-dasharray=\'2.5 2.5\'/%3E%3C/svg%3E") no-repeat center/contain}');

		if($this->is_tickets_tab())
    	{
	        wp_enqueue_style($this->sPrefix.'ticket-myaccount', plugins_url('', __FILE__).'/css/ticket-myaccount.css', array(), filemtime(dirname(__FILE__).'/css/ticket-myaccount.css'));
		}
	}
	/**
	 * Loads the tab's script on the Tickets tab.
	 *
	 * Hooked to 'wp_footer' rather than 'wp_enqueue_scripts' so it lands after the markup the
	 * script binds to.
	 *
	 * @return void
	 */
	public function enqueue_script_ticket()
    {
		if(!$this->is_tickets_tab()) return;

        $aParams = array
        (
			'aNonces'     => $this->oFunctions->get_ajax_nonces(array('ajax_fetch_downloabable_ticket_pdf')),
			'ajaxurl'      => admin_url('admin-ajax.php'),
			'translations' => array
			(
				// A download that never came back used to say nothing at all, so the customer
				// could not tell it apart from one that worked.
				'sDownloadFailed' => __('The ticket could not be downloaded. Please try again.', 'tickets-passes-for-woocommerce'),
			),
        );
        wp_register_script($this->sPrefix.'ticket-wc-myaccount', plugins_url('', __FILE__).'/js/ticket-wc-myaccount.js', array('jquery'), filemtime(dirname(__FILE__).'/js/ticket-wc-myaccount.js'), true);
        wp_enqueue_script($this->sPrefix.'ticket-wc-myaccount');
        wp_localize_script($this->sPrefix.'ticket-wc-myaccount', 'tpfwParamsTicketWcMyaccount', $aParams);
    }
	/**
	 * Registers the /tpfw-tickets My Account endpoint.
	 *
	 * The rewrite flush this needs is deferred to tpfw_maybe_flush_rewrites() on 'wp_loaded'.
	 *
	 * @return void
	 */
	public function add_myaccount_ticket_endpoint() 
	{
		add_rewrite_endpoint('tpfw-tickets', EP_ROOT | EP_PAGES);
	}
	/**
	 * Whitelists the endpoint's query var.
	 *
	 * @param array $vars Public query vars.
	 * @return array
	 */
	public function myaccount_ticket_query_vars($vars) 
	{
		$vars[] = 'tpfw-tickets';
		return $vars;
	}
	/**
	 * Inserts the Tickets tab into the My Account menu.
	 *
	 * Log out is pulled out and re-appended so it stays last - WooCommerce core has already
	 * added it by the time this filter runs, so a plain append would push it above the new tab.
	 *
	 * @param array $items Menu items, slug => label.
	 * @return array
	 */
	public function add_new_myaccount_ticket_tab($items)
	{
		// Keep "Log out" last - it's already in $items by this point (WooCommerce core
		// adds it before this filter runs), so appending here would otherwise push it
		// above the tab being added.
		$logout = $items['customer-logout'] ?? null;
		unset($items['customer-logout']);
		$items['tpfw-tickets']          = __('Tickets', 'tickets-passes-for-woocommerce');
		if($logout !== null) $items['customer-logout'] = $logout;
		return $items;
	}
	/**
	 * Renders the Tickets tab: the customer's tickets and timeslot tickets.
	 *
	 * @return void
	 */
	public function myaccount_ticket_tab_content()
	{
		$aUserTickets 			= $this->get_user_ticket_orders(get_current_user_id());
		$aUserTimeslotTickets 	= $this->get_user_timeslot_ticket_orders(get_current_user_id());

		$aGeneralSettings          = get_option('tpfw_general_settings_options');
		$sTimeslotColorAccent      = !empty($aGeneralSettings['sTimeslotColorAccent']) ? $aGeneralSettings['sTimeslotColorAccent'] : '#000000';
		$sTimeslotColorAccentSoft  = $this->oFunctions->mix_hex_colors($sTimeslotColorAccent, '#ffffff', 0.85);

		$sTicketColorAccent        = !empty($aGeneralSettings['sTicketColorAccent']) ? $aGeneralSettings['sTicketColorAccent'] : '#000000';
		$sTicketColorAccentSoft    = $this->oFunctions->mix_hex_colors($sTicketColorAccent, '#ffffff', 0.85);

		include('template/page-content.php');
	}
	/**
	 * Reads a user's tickets, newest first.
	 *
	 * Scoped to the user id in SQL rather than filtered afterwards, so this cannot return
	 * somebody else's tickets. Cancelled ones are excluded.
	 *
	 * @param int $iUserID Owner to read for.
	 * @param int $iLimit  Rows per page.
	 * @param int $iOffset Rows to skip.
	 * @return array
	 */
	public function get_user_ticket_orders($iUserID, $iLimit = 200, $iOffset = 0)
	{
		global $wpdb;
		$sTicketTableName = $wpdb->prefix . "tpfw_tickets";
		$sUserTicketSQL   = $wpdb->prepare('SELECT * FROM %i WHERE user_id = %d AND deleted IS NULL ORDER BY id DESC LIMIT %d OFFSET %d', $sTicketTableName, $iUserID, $iLimit, $iOffset);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sUserTicketSQL is the return value of $wpdb->prepare() above.
        return $wpdb->get_results($sUserTicketSQL);		
	}
	/**
	 * Reads a user's timeslot tickets, newest first.
	 *
	 * Scoped to the user id in SQL rather than filtered afterwards, so this cannot return
	 * somebody else's tickets. Cancelled ones are excluded.
	 *
	 * @param int $iUserID Owner to read for.
	 * @param int $iLimit  Rows per page.
	 * @param int $iOffset Rows to skip.
	 * @return array
	 */
	public function get_user_timeslot_ticket_orders($iUserID, $iLimit = 200, $iOffset = 0)
	{
		global $wpdb;
		$sTicketTableName = $wpdb->prefix . "tpfw_timeslot_tickets";
		$sUserTicketSQL   = $wpdb->prepare('SELECT * FROM %i WHERE user_id = %d AND deleted IS NULL ORDER BY id DESC LIMIT %d OFFSET %d', $sTicketTableName, $iUserID, $iLimit, $iOffset);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sUserTicketSQL is the return value of $wpdb->prepare() above.
        return $wpdb->get_results($sUserTicketSQL);		
	}

    /**
     * Builds the printable PDF for one of the current user's tickets.
     *
     * The lookup carries the current user id in the WHERE clause, so a guessed row or nano id
     * belonging to someone else simply matches nothing. Both ticket tables are checked because
     * the tab lists both types under one download button.
     *
     * @return void
     */
    public function ajax_fetch_downloabable_ticket_pdf_callback()
    {
        $response = array();
        $response['sMessage'] = __('Something went wrong', 'tickets-passes-for-woocommerce');
        check_ajax_referer('tpfw_ajax_fetch_downloabable_ticket_pdf', 'security');

        $wp_current_user = wp_get_current_user();
        if(!$wp_current_user->exists())
        {
            $response['sMessage'] = __('You must be logged in to upload an image', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
        }
        
		if(!isset($_POST['nano_id']) || empty($_POST['nano_id']))
        {
			$response['sMessage'] = __('Nano ID seems to be missing', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
        }

		if(!isset($_POST['row_id']) || empty($_POST['row_id']))
        {
			$response['sMessage'] = __('Row ID seems to be missing', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
        }		

		$_ROW_ID  = (int)sanitize_text_field(wp_unslash($_POST['row_id'] ?? ''));
		$_NANO_ID = sanitize_text_field(wp_unslash($_POST['nano_id'] ?? ''));

		global $wpdb;
		$sTicketPrepared = $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d AND user_id = %d AND nano_id = %s and deleted IS NULL;',
			array(
				$wpdb->prefix . 'tpfw_tickets',                 
				$_ROW_ID, 
				get_current_user_id(),
				$_NANO_ID, 
				)            
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sTicketPrepared is the return value of $wpdb->prepare() above.
		$aTicketResult   = $wpdb->get_results($sTicketPrepared);

		$sTimeslotTicketPrepared = $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d AND user_id = %d AND nano_id = %s and deleted IS NULL;',
			array(
				$wpdb->prefix . 'tpfw_timeslot_tickets',                 
				$_ROW_ID, 
				get_current_user_id(),
				$_NANO_ID, 
				)            
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sTimeslotTicketPrepared is the return value of $wpdb->prepare() above.
		$aTimeslotTicketResult   = $wpdb->get_results($sTimeslotTicketPrepared);
		if(empty($aTicketResult) && empty($aTimeslotTicketResult))
		{			
			$response['sMessage'] = __('Failed to find ticket with the given ID', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
		}

		$aPDFDownload = $this->oFunctions->create_fetch_ticket_pdf_qr($_NANO_ID);
		// The helper reports its own outcome, so the envelope has to follow it rather than
		// assume success - a missing or unreadable QR image comes back through this same path.
		// Its bSuccess key is dropped because the envelope now carries that.
		$bSuccess = !empty($aPDFDownload['bSuccess']);
		unset($aPDFDownload['bSuccess']);

		if(!$bSuccess)
		{
			wp_send_json_error($aPDFDownload);
		}

		wp_send_json_success($aPDFDownload);
    }
}