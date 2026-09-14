<?php
defined('ABSPATH') or die('No script kiddies please!');
/**
 * Admin-side integration with the WooCommerce order screen.
 *
 * Adds a metabox to any order holding a TPFW product, letting an administrator force the
 * issue or cancellation of its tickets and passes without moving the order's status.
 *
 * @package Tickets_Passes_For_WooCommerce
 */
class TPFW_Admin
{
	protected $sPrefix;
	protected $oFunctions;
	protected $oPassWCProduct;
	protected $oTicketWCProduct;
	protected $oTimeslotTicketWCProduct;

	/**
	 * @param string                     $sPrefix                  Option/handle prefix for the plugin ('tpfw').
	 * @param TPFW_Functions              $oFunctions               Shared helper instance.
	 * @param TPFW_Pass_WC_Product|null   $oPassWCProduct           Null when passes are disabled in settings.
	 * @param TPFW_Ticket_WC_Product|null $oTicketWCProduct         Null when tickets are disabled in settings.
	 * @param TPFW_Timeslot_Ticket_WC_Product|null $oTimeslotTicketWCProduct Null when timeslot tickets are disabled.
	 */
	public function __construct($sPrefix, $oFunctions, $oPassWCProduct, $oTicketWCProduct, $oTimeslotTicketWCProduct) 
	{
		$this->sPrefix                  = $sPrefix;
		$this->oFunctions               = $oFunctions;
		$this->oPassWCProduct           = $oPassWCProduct;
		$this->oTicketWCProduct         = $oTicketWCProduct;
		$this->oTimeslotTicketWCProduct = $oTimeslotTicketWCProduct;
		$this->load_admin_dependencies();
	}


	/**
	 * Registers the admin hooks: asset enqueues, the order metabox, and its two AJAX actions.
	 *
	 * @return void
	 */
	private function load_admin_dependencies() 
	{
		add_action('admin_enqueue_scripts', array($this, 'enqueue_script_admin'));
		add_action('add_meta_boxes', array($this, 'tpfw_order_meta_box'), 10, 2);
		add_action('wp_ajax_tpfw_ajax_admin_cancel_tpfw_order', array($this, 'ajax_admin_cancel_tpfw_order_callback'));
		add_action('wp_ajax_tpfw_ajax_admin_create_tpfw_order', array($this, 'ajax_admin_create_tpfw_order_callback'));
	}


	   
    /**
     * Enqueues the order screen script, scoped to the WooCommerce orders page only.
     *
     * @return void
     */
    public function enqueue_script_admin()
    {
		global $pagenow;
        $aParams = array
        (
            'aNonces'     => $this->oFunctions->get_ajax_nonces(array('ajax_admin_cancel_tpfw_order', 'ajax_admin_create_tpfw_order')),
            // Both buttons reload the page on success and used to do nothing at all on
            // failure, so a refused issue/cancel looked identical to one that never fired.
            'translations' => array(
                'sActionFailed' => __('The action could not be completed. Please try again.', 'tickets-passes-for-woocommerce'),
            ),
        );

		// Both order edit screens: HPOS (admin.php?page=wc-orders) and the legacy post table (post.php, shop_order).
		$oScreen = function_exists('get_current_screen') ? get_current_screen() : null;
		if($oScreen && in_array($oScreen->id, array('woocommerce_page_wc-orders', 'shop_order'), true))
    	{
	        wp_register_script($this->sPrefix.'admin-single', plugins_url('', __FILE__).'/js/admin-single.js', array('jquery'), filemtime(dirname(__FILE__).'/js/admin-single.js'), true);
	        wp_enqueue_script($this->sPrefix.'admin-single');
	        wp_localize_script($this->sPrefix.'admin-single', 'tpfwParamsAdminSingle', $aParams);
    	}
    }



	/**
	 * Adds the TPFW actions metabox to any order containing at least one ticket, timeslot
	 * ticket or pass line item.
	 *
	 * Accepts either a WP_Post or a WC_Order as $post so the callback works under both the
	 * legacy post table (screen 'shop_order', WP_Post) and HPOS (screen
	 * 'woocommerce_page_wc-orders', WC_Order).
	 *
	 * @param string           $post_type Current screen id.
	 * @param WP_Post|WC_Order $post      The order being edited.
	 * @return void
	 */
	public function tpfw_order_meta_box($post_type, $post)
	{
		if(!in_array($post_type, array('woocommerce_page_wc-orders', 'shop_order'), true)) { return; }
		
		if(is_a($post, 'WP_Post'))
		{
			$oOrder 				= wc_get_order($post->ID);
		}
		else
		{
			$oOrder 				= $post;		
		}
		
		if(empty($oOrder)) 					{ return; }	
		if(empty($oOrder->get_items())) 	{ return; }	
		
		$bTPFWOrder 	= false;				
		foreach($oOrder->get_items() as $iOrderItemKey => $oOrderItem)
		{		
			$oProduct = wc_get_product($oOrderItem->get_product_id());
			if(!$oProduct) continue;
			if(is_a($oProduct, 'TPFW_Product_Ticket') || is_a($oProduct, 'TPFW_Product_Pass') || is_a($oProduct, 'TPFW_Product_Timeslot_Ticket'))
			{
				$bTPFWOrder = true;				
				break;				                			
			}	
		}

		if($bTPFWOrder)
		{
			add_meta_box( 
				'tpfw_custom_action_meta_box',
				__( 'TPFW Product Actions', 'tickets-passes-for-woocommerce'),
				array($this, 'tpfw_meta_box_html'),
				$post_type,
				'side',
				'high',				
			);		
		}
	}
	/**
	 * Renders the metabox body: a Create and a Cancel button carrying the order id.
	 *
	 * @param WP_Post|WC_Order $post          The order being edited (WP_Post on the legacy screen).
	 * @param array            $callback_args Metabox args (unused).
	 * @return void
	 */
	public function tpfw_meta_box_html($post, $callback_args)
	{
		$iOrderID = is_a($post, 'WP_Post') ? $post->ID : $post->get_id();
		?>
			<div class="order-tpfw-attribution-metabox">
				<div class="woocommerce-tpfw-order-attribution-pass-container">
					<h4><?php echo esc_html__('Tickets, Timeslot Tickets & Pass\'s', 'tickets-passes-for-woocommerce'); ?></h4>
					<input data-attr-orderid="<?php echo esc_attr($iOrderID); ?>" style="width: 49%;" type="button" class="button create-admin-order-tpfw button-primary" value="<?php echo esc_attr__('Create', 'tickets-passes-for-woocommerce'); ?>">
					<input data-attr-orderid="<?php echo esc_attr($iOrderID); ?>" style="width: 49%;" type="button" class="button cancel-admin-order-tpfw button-primary" value="<?php echo esc_attr__('Cancel', 'tickets-passes-for-woocommerce'); ?>">
				</div>
			</div>
		<?php
	}

	/**
	 * AJAX: force-cancels every ticket, timeslot ticket and pass issued for an order,
	 * without requiring an order status change.
	 *
	 * Requires the action's own nonce and manage_woocommerce.
	 *
	 * @return void
	 */
	public function ajax_admin_cancel_tpfw_order_callback()
	{
		$response = array();
		check_ajax_referer('tpfw_ajax_admin_cancel_tpfw_order', 'security');
		if(!$this->oFunctions->user_can_manage())
		{
			$response['sMessage'] = __('Current user does not have admin privileges', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}

		if(!isset($_POST['order_id']) || empty($_POST['order_id']))
		{
			$response['sMessage'] = __('Order ID seems to be missing', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}

		$iOrderID = absint(wp_unslash($_POST['order_id'] ?? ''));

		// Each of these three handles is null whenever its product type is switched off in
		// the general settings (see TPFW_Main::load_dependencies()), while this metabox shows
		// for an order containing ANY of the three - so calling them unguarded fataled the
		// whole request the moment a site had, say, only tickets enabled.
		if($this->oPassWCProduct)            { $this->oPassWCProduct->order_cancelled($iOrderID); }
		if($this->oTicketWCProduct)          { $this->oTicketWCProduct->order_cancelled($iOrderID); }
		if($this->oTimeslotTicketWCProduct)  { $this->oTimeslotTicketWCProduct->order_cancelled($iOrderID); }

		$response['sMessage'] = __('Pass\'s cancelled for order', 'tickets-passes-for-woocommerce');
		wp_send_json_success($response);
	}
	/**
	 * AJAX: force-creates every ticket, timeslot ticket and pass for an order, without
	 * requiring an order status change.
	 *
	 * Requires the action's own nonce and manage_woocommerce.
	 *
	 * @return void
	 */
	public function ajax_admin_create_tpfw_order_callback()
	{
		$response = array();
		check_ajax_referer('tpfw_ajax_admin_create_tpfw_order', 'security');
		if(!$this->oFunctions->user_can_manage())
		{
			$response['sMessage'] = __('Current user does not have admin privileges', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}

		if(!isset($_POST['order_id']) || empty($_POST['order_id']))
		{
			$response['sMessage'] = __('Order ID seems to be missing', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}
		
		$iOrderID = absint(wp_unslash($_POST['order_id'] ?? ''));

		// Null-guarded for the same reason as the cancel handler above.
		if($this->oPassWCProduct)            { $this->oPassWCProduct->order_completed($iOrderID); }
		if($this->oTicketWCProduct)          { $this->oTicketWCProduct->order_completed($iOrderID); }
		if($this->oTimeslotTicketWCProduct)  { $this->oTimeslotTicketWCProduct->order_completed($iOrderID); }
		
		$response['sMessage'] = __('Tickets, Timeslot Tickets & Pass\'s created for order', 'tickets-passes-for-woocommerce');
		wp_send_json_success($response);
	}
}