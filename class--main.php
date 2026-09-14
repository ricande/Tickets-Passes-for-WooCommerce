<?php
defined('ABSPATH') or die('No script kiddies please!');
/**
 * The core plugin class.
 *
 * Owns the option/handle prefix and decides which subsystems to load based on the
 * site's settings - see load_dependencies().
 * Instantiated once on 'init' by tpfw_init_plugin() and kept in the $tpfw_oMain global.
 *
 * @package Tickets_Passes_For_WooCommerce
 * @since   1.0.0
 * @author  Magnus V. <magvej@hotmail.com>
 */
class TPFW_Main
{
	protected $sPrefix;
	protected $oFunctions;

	/**
	 * Boots every enabled subsystem.
	 *
	 * @return void
	 */
	public function __construct()
	{
		$this->sPrefix 			= 'tpfw';
		$this->load_dependencies();
	}

	/**
	 * Requires and instantiates the subsystems the site's settings have switched on.
	 *
	 * Everything is conditional. Nothing is kept on $this except the shared TPFW_Functions:
	 * every subsystem registers its own hooks from its constructor, and that registration is
	 * what keeps it alive - a property pointing at it as well was only ever written to.
	 *
	 * Order matters: the DB installer runs first so later classes can rely on their tables,
	 * and TPFW_Functions is shared by reference into every other class.
	 *
	 * @return void
	 */
	private function load_dependencies()
	{
		add_filter('woocommerce_order_item_get_formatted_meta_data', array($this,  'tpfw_thank_you_page_formatted_meta'), 10, 1);
		add_filter('woocommerce_order_item_display_meta_key',        array($this,  'tpfw_order_item_display_meta_key'), 10, 2);
		add_filter('woocommerce_order_item_display_meta_value',      array($this,  'tpfw_order_item_display_meta_value'), 10, 2);
		add_action('wp_enqueue_scripts', array($this, 'tpfw_cart_checkout_item_meta_styles'), 20);

		require_once dirname( __FILE__ ) . '/inc/support/load.php';

		require_once dirname( __FILE__ ) . '/inc/db-installer/class--db-installer.php';
		new TPFW_DB_Installer();

		require_once dirname( __FILE__ ) . '/inc/functions/class--functions.php';
		$this->oFunctions				= new TPFW_Functions($this->sPrefix);

		// Unconditional on purpose. Every QR image, pass photo and ticket PDF is served through
		// this class, so gating it on a settings toggle would break customers' own tickets.
		require_once dirname( __FILE__ ) . '/inc/file-access/class--file-access.php';
		new TPFW_File_Access($this->oFunctions);

		// Either toggle needs the routes: the built-in scanner page is itself a client of the
		// check-in endpoint. The API toggle is not about whether the routes exist but about who
		// may authenticate against them - get_basic_auth_user() turns outside callers away when
		// it is off.
		$bScannerEnabled = $this->oFunctions->is_scanner_enabled();
		if($this->oFunctions->is_api_enabled() || $bScannerEnabled)
		{
			require_once dirname( __FILE__ ) . '/inc/api/class--api.php';
			new TPFW_API($this->oFunctions);

			// Not loading the scanner class at all is what removes both the /check-in/ route and
			// its menu link.
			if($bScannerEnabled)
			{
				require_once dirname( __FILE__ ) . '/inc/scanner/class--scanner.php';
				new TPFW_Scanner($this->sPrefix, $this->oFunctions);
			}
		}

		require_once dirname( __FILE__ ) . '/inc/emails/class--emails.php';
		new TPFW_Emails($this->sPrefix, $this->oFunctions);
		
		
		// TPFW_Admin is the one thing that reaches across to the product classes, and it only
		// gets them when their type is switched on - so these stay nulls it has to check.
		$oPassWCProduct = $oTicketWCProduct = $oTimeslotTicketWCProduct = null;

		$aGeneralSettings = get_option('tpfw_general_settings_options');				
		if(isset($aGeneralSettings['bEnablePassProduct']) && $aGeneralSettings['bEnablePassProduct'] == 1)
		{
			require_once dirname( __FILE__ ) . '/inc/pass-wc-product/class--pass-wc-product.php';
			$oPassWCProduct		= new TPFW_Pass_WC_Product($this->sPrefix, $this->oFunctions);
								
			require_once dirname( __FILE__ ) . '/inc/pass-wc-myaccount/class--pass-wc-myaccount.php';
			new TPFW_Pass_WC_MyAccount($this->sPrefix, $this->oFunctions);			
		}
		
		
		if(isset($aGeneralSettings['bEnableTicketProduct']) && $aGeneralSettings['bEnableTicketProduct'] == 1)
		{		
			require_once dirname( __FILE__ ) . '/inc/ticket-wc-product/class--ticket-wc-product.php';
			$oTicketWCProduct			= new TPFW_Ticket_WC_Product($this->sPrefix, $this->oFunctions);		
		}
		

		if(isset($aGeneralSettings['bEnableTimeslotProduct']) && $aGeneralSettings['bEnableTimeslotProduct'] == 1)
		{
			require_once dirname( __FILE__ ) . '/inc/timeslot-ticket-wc-product/class--timeslot-ticket-wc-product.php';
			$oTimeslotTicketWCProduct	= new TPFW_Timeslot_Ticket_WC_Product($this->sPrefix, $this->oFunctions);
		}
		

		if((isset($aGeneralSettings['bEnableTimeslotProduct']) && $aGeneralSettings['bEnableTimeslotProduct'] == 1) || (isset($aGeneralSettings['bEnableTicketProduct']) && $aGeneralSettings['bEnableTicketProduct'] == 1))
		{						
			require_once dirname( __FILE__ ) . '/inc/ticket-wc-myaccount/class--ticket-wc-myaccount.php';
			new TPFW_Ticket_WC_MyAccount($this->sPrefix, $this->oFunctions);					
			
			require_once dirname( __FILE__ ) . '/inc/cronjobs/class--cronjobs.php';
			new TPFW_Cronjobs($this->oFunctions);
		}		

		if(is_admin())
		{
		

			require_once dirname( __FILE__ ) . '/inc/settings/class--settings.php';
			new TPFW_Settings($this->sPrefix, $this->oFunctions);
			

			if(isset($aGeneralSettings['bEnableAnalytics']) && $aGeneralSettings['bEnableAnalytics'] == 1)
			{		
				require_once dirname( __FILE__ ) . '/inc/analytics-dashboard/class--analytics-dashboard.php';
				new TPFW_Analytics_Dashboard($this->sPrefix, $this->oFunctions);		
			}

			require_once dirname( __FILE__ ) . '/inc/api/class--api-settings.php';
			new TPFW_API_Settings($this->sPrefix, $this->oFunctions);

			require_once dirname( __FILE__ ) . '/inc/pass-settings/class--pass-settings.php';
			new TPFW_Pass_Settings($this->sPrefix, $this->oFunctions);

			require_once dirname( __FILE__ ) . '/inc/ticket-settings/class--ticket-settings.php';
			new TPFW_Ticket_Settings($this->sPrefix, $this->oFunctions);

			require_once dirname( __FILE__ ) . '/inc/timeslot-ticket-settings/class--timeslot-ticket-settings.php';
			new TPFW_Timeslot_Ticket_Settings($this->sPrefix, $this->oFunctions);


			if(isset($aGeneralSettings['bEnableTicketProduct']) && $aGeneralSettings['bEnableTicketProduct'] == 1)
			{
				require_once dirname( __FILE__ ) . '/inc/ticket-dashboard/class--ticket-dashboard.php';
				new TPFW_Ticket_Dashboard($this->sPrefix, $this->oFunctions);
			}
			if(isset($aGeneralSettings['bEnableTimeslotProduct']) && $aGeneralSettings['bEnableTimeslotProduct'] == 1)
			{
				require_once dirname( __FILE__ ) . '/inc/timeslot-ticket-dashboard/class--timeslot-ticket-dashboard.php';
				new TPFW_Timeslot_Ticket_Dashboard($this->sPrefix, $this->oFunctions);
			}

			if(isset($aGeneralSettings['bEnablePassProduct']) && $aGeneralSettings['bEnablePassProduct'] == 1)
			{
				require_once dirname( __FILE__ ) . '/inc/pass-dashboard/class--pass-dashboard.php';
				new TPFW_Pass_Dashboard($this->sPrefix, $this->oFunctions);
			}
						
			require_once dirname( __FILE__ ) . '/inc/admin/class--admin.php';
			new TPFW_Admin($this->sPrefix, $this->oFunctions, $oPassWCProduct, $oTicketWCProduct, $oTimeslotTicketWCProduct);	
		}
	}

	/**
	 * Forces one line per item meta row in the Cart/Checkout blocks.
	 *
	 * The blocks render every woocommerce_get_item_data() entry as an inline <span> joined
	 * with " / ". That reads fine for a single variation attribute, but pass, ticket and
	 * timeslot products each contribute four or five rows (names, ids, start/end, cooldowns)
	 * and they run together into one unreadable line.
	 *
	 * Attached to the two blocks' own stylesheets rather than to 'woocommerce-general': themes
	 * like Storefront drop WooCommerce's general stylesheet altogether, which silently dropped
	 * this rule with it, and the block handles only print on the page that renders the block.
	 *
	 * @return void
	 */
	public function tpfw_cart_checkout_item_meta_styles()
	{
		$sCSS = '.wc-block-components-product-details>span{display:block}.wc-block-components-product-details>span [aria-hidden="true"]{display:none}';
		wp_add_inline_style('wc-blocks-style-cart', $sCSS);
		wp_add_inline_style('wc-blocks-style-checkout', $sCSS);
	}

	/**
	 * Gives this plugin's order line meta readable labels wherever WooCommerce prints it.
	 *
	 * The keys carry the plugin prefix so they cannot collide with another plugin's, which is
	 * right for storage but not something a shop manager should have to read on the order screen.
	 *
	 * @param string $sDisplayKey Label WooCommerce is about to print.
	 * @param object $oMeta       The meta being displayed.
	 * @return string
	 */
	public function tpfw_order_item_display_meta_key($sDisplayKey, $oMeta)
	{
		if(!isset($oMeta->key))
		{
			return $sDisplayKey;
		}

		$aLabels = array(
			'tpfw_firstname'      => __('First Name', 'tickets-passes-for-woocommerce'),
			'tpfw_lastname'       => __('Last Name', 'tickets-passes-for-woocommerce'),
			'tpfw_email'          => __('Email', 'tickets-passes-for-woocommerce'),
			// Both start keys are the near end of the same window and tpfw_valid_to is its far
			// end, so the order line reads like the cart and checkout it came from.
			'tpfw_start_date'     => __('Valid from', 'tickets-passes-for-woocommerce'),
			'tpfw_predefined_date' => __('Valid from', 'tickets-passes-for-woocommerce'),
			'tpfw_valid_to'       => __('Valid to', 'tickets-passes-for-woocommerce'),
			'tpfw_timeslot_start' => __('Start', 'tickets-passes-for-woocommerce'),
			'tpfw_timeslot_end'   => __('End', 'tickets-passes-for-woocommerce'),
		);
		if(isset($aLabels[$oMeta->key]))
		{
			return $aLabels[$oMeta->key];
		}

		if(preg_match('/^tpfw_pass_id_\d+$/', $oMeta->key))
		{
			return __('Pass ID', 'tickets-passes-for-woocommerce');
		}
		if(preg_match('/^tpfw_(?:timeslot_)?ticket_id_(\d+)$/', $oMeta->key, $aMatch))
		{
			/* translators: %d: running number of the ticket within the order, e.g. "Ticket ID #2". */
			return sprintf(__('Ticket ID #%d', 'tickets-passes-for-woocommerce'), $aMatch[1]);
		}

		return $sDisplayKey;
	}

	/**
	 * Prints the customer-picked start date in the shop's own date format.
	 *
	 * It is stored as Y-m-d because create_ticket() and create_pass() parse it back, but it now
	 * sits next to a "Valid to" that was formatted when it was written - so the pair would
	 * otherwise be shown in two different formats on the same order line.
	 *
	 * @param string $sDisplayValue Value WooCommerce is about to print.
	 * @param object $oMeta         The meta being displayed.
	 * @return string
	 */
	public function tpfw_order_item_display_meta_value($sDisplayValue, $oMeta)
	{
		if(!isset($oMeta->key) || $oMeta->key != 'tpfw_start_date') return $sDisplayValue;

		return gmdate($this->oFunctions->get_datetime_format('date'), strtotime($oMeta->value));
	}

	/**
	 * Hides the internal reservation bookkeeping meta on the thank-you page and in My Account.
	 *
	 * Labels are not touched here: WC_Order_Item::get_formatted_meta_data() has already run
	 * display_key through woocommerce_order_item_display_meta_key by the time this filter
	 * fires. Admin screens are left untouched so the raw keys stay searchable there.
	 *
	 * @param array $aFormattedMeta Meta objects keyed by meta id.
	 * @return array The filtered set.
	 */
	public function tpfw_thank_you_page_formatted_meta($aFormattedMeta)
	{
		if(is_admin()) return $aFormattedMeta;

		// isset() && in_array(), not ||: a meta object without a key belongs to some other
		// plugin's filter and is none of this one's business - dropping it hid rows this
		// plugin never wrote.
		$aHidden = array('tpfw_reservation_id', 'tpfw_reservation_time', 'tpfw_timeslot_id');
		foreach($aFormattedMeta as $sKey => $oMeta)
		{
			if(isset($oMeta->key) && in_array($oMeta->key, $aHidden, true))
			{
				unset($aFormattedMeta[$sKey]);
			}
		}

		return $aFormattedMeta;
	}
}