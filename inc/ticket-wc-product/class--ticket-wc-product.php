<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * The "Ticket" WooCommerce product type.
 *
 * Owns everything specific to single-use (or limited-use) tickets: the product type
 * registration, the two admin panels on the product editor, the QR code artwork, issuing and
 * revoking tickets as the order status changes, and the AJAX check-in endpoint shared by the
 * admin dashboard and My Account.
 *
 * Ticket rows live in {prefix}tickets and their check-ins in {prefix}tickets_stats; both are
 * soft deleted (a non-null `deleted` timestamp) rather than removed, so cancelling and
 * reinstating an order keeps the customer's original QR codes working.
 */
require_once dirname(__FILE__) . '/../product-type/class--product-type.php';

class TPFW_Ticket_WC_Product extends TPFW_Product_Type
{
	protected $sType         = 'ticket';
	protected $sTable        = 'tpfw_tickets';
	protected $sStatsTable   = 'tpfw_tickets_stats';
	protected $sMetaPrefix   = '_tpfw_ticket_';
	protected $sProductClass = 'TPFW_Product_Ticket';

	/**
	 * Registers every WordPress/WooCommerce hook this class owns.
	 *
	 * Called once from the constructor; nothing else in the class registers hooks, so this
	 * is the single place to look for what the Ticket product type reacts to.
	 *
	 * @return void
	 */
	protected function load_settings_dependencies()
	{
		add_action('admin_enqueue_scripts',                         array($this, 'enqueue_script_admin'));

		add_filter('woocommerce_get_item_data',                     array($this, 'tpfw_cart_display_ticket_meta_data'),                 10, 2);

		add_action('woocommerce_order_status_processing',            array($this, 'order_maybe_issue'), 10, 1);
		add_action('woocommerce_order_status_completed',            array($this, 'order_maybe_issue'), 10, 1);

		// Cancelled / refunded / failed must all revoke the ticket, otherwise a refunded
		// customer keeps a scannable QR code. cancel_ticket() is idempotent.
		add_action('woocommerce_order_status_cancelled',            array($this, 'order_cancelled'),   10, 1);
		add_action('woocommerce_order_status_refunded',             array($this, 'order_cancelled'),   10, 1);
		add_action('woocommerce_order_status_failed',               array($this, 'order_cancelled'),   10, 1);

		// Product type registration and the admin product-data UI.
		add_filter('product_type_selector',                         array($this, 'tpfw_add_ticket_tpfw_product_type'));
		add_filter('woocommerce_product_class',                     array($this, 'tpfw_woocommerce_ticket_product_class'),   10, 3);
		// Priority 9999 so the show_if_/hide_if_ classes are appended after every other
		// extension has finished adding its own tabs.
		add_filter('woocommerce_product_data_tabs',                 array($this, 'tpfw_woocommerce_ticket_data_tabs'), 9999);
		add_filter('woocommerce_product_data_tabs',                 array($this, 'tpfw_ticket_product_tab'));
		add_action('woocommerce_tpfw-ticket_add_to_cart',                array($this, 'tpfw_ticket_add_to_cart_html'));
		add_action('woocommerce_before_add_to_cart_button',              array($this, 'tpfw_ticket_product_html'));

		// Cart and order line: validate the customer-chosen start date, carry it along, store it.
		add_filter('woocommerce_add_to_cart_validation',                 array($this, 'tpfw_ticket_add_to_cart_validation'),      10, 3);
		add_filter('woocommerce_add_cart_item_data',                     array($this, 'tpfw_ticket_add_to_cart_action'),          10, 4);
		add_action('woocommerce_checkout_create_order_line_item',        array($this, 'tpfw_add_ticket_meta_to_order_line'),      10, 4);
		add_action('woocommerce_product_data_panels',               array($this, 'tpfw_wc_ticket_settings_tab_content'));
		add_action('woocommerce_product_data_panels',               array($this, 'tpfw_wc_ticket_qr_tab_content'));
		add_action('woocommerce_process_product_meta_tpfw-ticket',       array($this, 'tpfw_wc_ticket_settings_tab_save'));
		add_action('woocommerce_process_product_meta_tpfw-ticket',       array($this, 'tpfw_wc_ticket_qr_tab_save'));

		add_action('wp_ajax_tpfw_ajax_settings_preview_ticket_qr',       array($this, 'ajax_settings_preview_ticket_qr_callback'));
		add_action('wp_ajax_tpfw_ajax_checkin_ticket',                   array($this, 'ajax_checkin_ticket_callback'));
	}


    /**
     * Loads the product-edit assets, but only on the WooCommerce product editor.
     *
     * The screen check keeps the QR uploader and colour pickers off every other admin page.
     * filemtime() is used as the version so a changed file busts the browser cache.
     *
     * @return void
     */
    public function enqueue_script_admin()
    {
        global $pagenow;        
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of the current admin screen to decide whether to enqueue assets; no state is changed.
		// Only the product editor: the old $pagenow + $_GET test also matched every post and page edit screen.
		$oScreen = function_exists('get_current_screen') ? get_current_screen() : null;
		if($oScreen && $oScreen->base === 'post' && $oScreen->post_type === 'product')
    	{		
            $aParams = array
            (
                'aNonces'                  => $this->oFunctions->get_ajax_nonces(array('ajax_settings_preview_ticket_qr')),
                'sTicketQRPlaceholderLogo'  => TPFW_PLUGIN_URL . 'images/qr-logo-placeholder.webp',
                'aTranslations'             => array
                (
                    'sInsertQRLogo'   => __('Upload/Insert Logo', 'tickets-passes-for-woocommerce'),
                    'sUseSelected'    => __('Use Selected', 'tickets-passes-for-woocommerce'),
                    // A failed render used to leave the previous preview image on screen, so the
                    // shop owner would go on tweaking colours against a stale QR code.
                    'sPreviewFailed'  => __('The preview could not be generated. Please try again.', 'tickets-passes-for-woocommerce'),
                ),
            );

            wp_register_script($this->sPrefix.'ticket-wc-product', plugins_url('', __FILE__).'/js/ticket-wc-product.js', array('jquery', $this->sPrefix.'functions-admin'), filemtime(dirname(__FILE__).'/js/ticket-wc-product.js'), true);
	        wp_enqueue_script($this->sPrefix.'ticket-wc-product');
	        wp_localize_script($this->sPrefix.'ticket-wc-product', 'tpfwParamsTicketWcProduct', $aParams);

            // Shared QR-tab stylesheet (also used by Timeslot Ticket/Pass) - enqueued here too since
            // the Pass class (which otherwise owns this file) may not be loaded if that product type is disabled.
            wp_enqueue_style($this->sPrefix.'pass.module', plugins_url('../pass-wc-product/css/pass.module.css', __FILE__), array(), filemtime(dirname(__FILE__).'/../pass-wc-product/css/pass.module.css'));
        }
    }

    /**
     * Adds "Ticket Product" to the product type dropdown in the product data box.
     *
     * @param array $types Existing product types, keyed by slug.
     * @return array Types with 'tpfw-ticket' added.
     */
    public function tpfw_add_ticket_tpfw_product_type($types)
    {
        $types[ 'tpfw-ticket' ]     = __('Ticket Product', 'tickets-passes-for-woocommerce');
        return $types;
    }
    

    /**
     * Maps the 'tpfw-ticket' product type onto the TPFW_Product_Ticket class.
     *
     * @param string $classname         Class WooCommerce resolved for this product.
     * @param string $product_type      Product type slug.
     * @param string $product_variation Post type ('product' or 'product_variation').
     * @return string Class name to instantiate.
     */
    public function tpfw_woocommerce_ticket_product_class( $classname, $product_type, $product_variation ) 
    {      
        // WooCommerce guesses the class from the type slug ('tpfw-ticket' -> WC_Product_Tpfw_Ticket),
        // finds nothing and falls back to WC_Product_Simple, which reports its type as 'simple' -
        // and with that every show_if_tpfw-ticket panel and the add-to-cart hook stop matching.
        // Naming it back is out: an unprefixed WC_Product_Ticket in the global namespace is the
        // very collision the prefix rule exists to prevent. So the mapping is stated here instead.
        if($product_type == 'tpfw-ticket' && $product_variation == 'product')
        {
            return 'TPFW_Product_Ticket';
        }
        return $classname;
    }


    

    /**
     * Tags the stock WooCommerce product tabs as shown or hidden for the Ticket type.
     *
     * @param array $tabs Product data tabs.
     * @return array Tabs with show_if_tpfw-ticket / hide_if_tpfw-ticket classes appended.
     */
    public function tpfw_woocommerce_ticket_data_tabs($tabs) 
    {
        $tabs['inventory']['class'][]               = 'show_if_tpfw-ticket';
        if(isset($tabs['marketplace-suggestions'])) { $tabs['marketplace-suggestions']['class'][] = 'hide_if_tpfw-ticket'; }
        $tabs['shipping']['class'][]                = 'hide_if_tpfw-ticket';
        $tabs['linked_product']['class'][]          = 'hide_if_tpfw-ticket';
        $tabs['attribute']['class'][]               = 'hide_if_tpfw-ticket';
        $tabs['variations']['class'][]              = 'hide_if_tpfw-ticket';
        $tabs['advanced']['class'][]                = 'hide_if_tpfw-ticket';
        return $tabs;
    }
    

    /**
     * Renders the add-to-cart form, honouring the product's optional sales window.
     *
     * Returning early outside the window is what actually removes the buy button - the window
     * is also enforced server side in the add-to-cart validation.
     *
     * @return void
     */
    public function tpfw_ticket_add_to_cart_html()
    {
        global $post;

        // Shown regardless of whether the product can currently be bought - a shop owner who
        // enabled these rows wants them visible even while a sales window has not opened yet
        // or has already closed, not just while the buy button happens to be showing.
        $this->oFunctions->render_product_info_table($post->ID, array(
            'show_max_uses'   => '_tpfw_ticket_show_max_uses',
            'max_uses'        => '_tpfw_ticket_max_uses',
            'show_date'       => '_tpfw_ticket_show_predefined_date',
            'show_valid_from' => '_tpfw_ticket_show_valid_from',
            'show_valid_to'   => '_tpfw_ticket_show_valid_to',
            'start_enable'    => '_tpfw_ticket_predefined_start_date_enable',
            'start_date'      => '_tpfw_ticket_predefined_start_date',
            'duration'        => '_tpfw_ticket_valid_duration',
            'show_sales'      => '_tpfw_ticket_show_sales_window',
            'sales_enable'    => '_tpfw_ticket_sales_timespan_enable',
            'sales_start'     => '_tpfw_ticket_sales_timespan_start',
            'sales_end'       => '_tpfw_ticket_sales_timespan_end',
        ), $this->oFunctions->get_front_color_style('sTicketColor'));

        if(get_post_meta($post->ID, '_tpfw_ticket_sales_timespan_enable', true) == 'yes')
        {
            if(!$this->oFunctions->is_within_sales_window(get_post_meta($post->ID, '_tpfw_ticket_sales_timespan_start', true), get_post_meta($post->ID, '_tpfw_ticket_sales_timespan_end', true))) return;
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook, fired here so the stock add-to-cart template renders for this product type.
        do_action( 'woocommerce_simple_add_to_cart' );
    }


    /**
     * Renders the start date picker inside the add-to-cart form, when the customer picks the date.
     *
     * Hooked inside the form rather than beside the table above, so the hidden field the calendar
     * writes into is actually posted with the add-to-cart request.
     *
     * @return void
     */
    public function tpfw_ticket_product_html()
    {
        global $post;
        // $post is null on a shortcode-rendered add-to-cart form outside a singular product view.
        if(!is_product() || !is_singular('product') || empty($post)) return;

        $oProduct = wc_get_product($post->ID);
        if(!is_a($oProduct, 'TPFW_Product_Ticket')) return;
        if(get_post_meta($post->ID, '_tpfw_ticket_user_start_date_enable', true) != 'yes') return;

        $aGeneralSettings = get_option('tpfw_general_settings_options');
        $sHintText = __('Pick the date this ticket should be valid from.', 'tickets-passes-for-woocommerce');
        if(!empty($aGeneralSettings['sTicketHintText']))
        {
            $sHintText = $aGeneralSettings['sTicketHintText'];
        }

        $this->oFunctions->render_customer_start_date_picker(
            $this->oFunctions->get_front_color_style('sTicketColor'),
            __('Select a Date', 'tickets-passes-for-woocommerce'),
            $sHintText,
            get_post_meta($post->ID, '_tpfw_ticket_user_start_date_min', true),
            get_post_meta($post->ID, '_tpfw_ticket_user_start_date_max', true)
        );
    }


    /**
     * Server-side add-to-cart checks for a ticket: the sales window, and - when the customer
     * picks the start date - that a valid date inside the offered range was posted.
     *
     * The buy button is hidden outside the sales window, but a direct POST is not, so the window
     * is enforced here as well.
     *
     * @param bool $passed           Whether validation has passed so far.
     * @param int  $product_id       Product being added.
     * @param int  $request_quantity Quantity WooCommerce intends to add.
     * @return bool False to block the add-to-cart, otherwise the incoming value.
     */
    public function tpfw_ticket_add_to_cart_validation($passed, $product_id, $request_quantity)
    {
        $oProduct = wc_get_product($product_id);
        if(!is_a($oProduct, 'TPFW_Product_Ticket')) return $passed;

        if(get_post_meta($product_id, '_tpfw_ticket_sales_timespan_enable', true) == 'yes'
            && !$this->oFunctions->is_within_sales_window(get_post_meta($product_id, '_tpfw_ticket_sales_timespan_start', true), get_post_meta($product_id, '_tpfw_ticket_sales_timespan_end', true)))
        {
            wc_add_notice(__('This product is not available for purchase right now.', 'tickets-passes-for-woocommerce'), 'error');
            return false;
        }

        if(get_post_meta($product_id, '_tpfw_ticket_user_start_date_enable', true) != 'yes') return $passed;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its own add-to-cart nonce; this only reads the posted date.
        $sStartDate = sanitize_text_field(wp_unslash($_POST['tpfw-start-date'] ?? ''));
        if(!$this->oFunctions->is_valid_ymd($sStartDate))
        {
            wc_add_notice(__('Please pick a start date before adding this to the cart.', 'tickets-passes-for-woocommerce'), 'error');
            return false;
        }

        // Both ends compared as date strings rather than timestamps, so the near end does not
        // expire at midday and the far end stays inclusive of its own date.
        $sMinDate = get_post_meta($product_id, '_tpfw_ticket_user_start_date_min', true);
        $sMaxDate = get_post_meta($product_id, '_tpfw_ticket_user_start_date_max', true);
        $sToday   = gmdate('Y-m-d', current_time('timestamp'));
        if($sMinDate == '' || $sMinDate < $sToday) $sMinDate = $sToday;

        if($sStartDate < $sMinDate || ($sMaxDate != '' && $sStartDate > $sMaxDate))
        {
            wc_add_notice(__('Please pick a date within the dates offered for this ticket.', 'tickets-passes-for-woocommerce'), 'error');
            return false;
        }

        return $passed;
    }


    /**
     * Carries the customer-chosen start date from the product page into the cart item.
     *
     * @param array $cart_item_data Cart item data collected so far.
     * @param int   $product_id     Product being added.
     * @param int   $variation_id   Unused; tickets have no variations.
     * @param int   $quantity       Quantity being added.
     * @return array The cart item data with the start date added.
     */
    public function tpfw_ticket_add_to_cart_action($cart_item_data, $product_id, $variation_id, $quantity)
    {
        $oProduct = wc_get_product($product_id);
        if(!is_a($oProduct, 'TPFW_Product_Ticket')) return $cart_item_data;

        // A shop-set date is fixed at add-to-cart time so the order line keeps the date the
        // customer was actually shown, even if the product is re-dated afterwards.
        if(get_post_meta($product_id, '_tpfw_ticket_predefined_start_date_enable', true) == 'yes')
        {
            $cart_item_data['tpfw_predefined_date'] = gmdate(
                $this->oFunctions->get_datetime_format('date'),
                strtotime(get_post_meta($product_id, '_tpfw_ticket_predefined_start_date', true))
            );
        }

        if(get_post_meta($product_id, '_tpfw_ticket_user_start_date_enable', true) != 'yes') return $cart_item_data;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its own add-to-cart nonce; this only reads the posted date.
        $sStartDate = sanitize_text_field(wp_unslash($_POST['tpfw-start-date'] ?? ''));
        if($sStartDate === '') return $cart_item_data;

        $cart_item_data['tpfw_start_date'] = $sStartDate;
        // Two of the same ticket for different dates are separate lines, not a quantity of two.
        $cart_item_data['unique_key']      = md5(microtime().wp_rand());

        return $cart_item_data;
    }


    /**
     * Copies the chosen start date onto the order line, which is what create_ticket() reads.
     *
     * @param WC_Order_Item_Product $item          Order line being built.
     * @param string                $cart_item_key Cart item key.
     * @param array                 $values        Cart item data.
     * @param WC_Order              $order         Order being created.
     * @return void
     */
    public function tpfw_add_ticket_meta_to_order_line($item, $cart_item_key, $values, $order)
    {
        if(!empty($values['tpfw_predefined_date']))
        {
            $item->update_meta_data('tpfw_predefined_date', sanitize_text_field($values['tpfw_predefined_date']));
            $this->oFunctions->add_valid_to_order_item_meta($item, $values['tpfw_predefined_date'], '_tpfw_ticket_valid_duration');
        }
        if(empty($values['tpfw_start_date'])) return;
        $item->update_meta_data('tpfw_start_date', sanitize_text_field($values['tpfw_start_date']));
        $this->oFunctions->add_valid_to_order_item_meta($item, $values['tpfw_start_date'], '_tpfw_ticket_valid_duration');
    }


    /**
     * Adds the plugin's own "Ticket Settings" and "Ticket QR" tabs to the product data box.
     *
     * @param array $tabs Product data tabs.
     * @return array Tabs with the two ticket panels added.
     */
    public function tpfw_ticket_product_tab($tabs) 
    { 
        $tabs['ticket-settings-general'] = array(
            'label'  => __('Ticket Settings', 'tickets-passes-for-woocommerce'),
            'target' => 'ticket_product_settings_general',
            'class'  => 'show_if_tpfw-ticket',
        );
        $tabs['ticket-settings-qr'] = array(
            'label'  => __('Ticket QR', 'tickets-passes-for-woocommerce'),
            'target' => 'ticket_product_settings_qr',
            'class'  => 'show_if_tpfw-ticket',
        );
        return $tabs;
    }






    /**
     * Renders the "Ticket QR" panel: label text, the three colours, logo upload and a live preview.
     *
     * Each value falls back to a sensible default when the meta key has never been saved.
     *
     * @return void
     */
    public function tpfw_wc_ticket_qr_tab_content()
    {
        $this->oFunctions->render_qr_tab('ticket', 'ticket_product_settings_qr', 'ticket', __('Ticket', 'tickets-passes-for-woocommerce'));
    }
    

    /**
     * Persists the "Ticket QR" panel and regenerates the preview image.
     *
     * Empty fields delete their meta rather than storing an empty string, so the defaults in
     * tpfw_wc_ticket_qr_tab_content() apply again.
     *
     * @param int $post_id Product id being saved.
     * @return void
     */
    public function tpfw_wc_ticket_qr_tab_save($post_id)
    {
        $this->oFunctions->save_qr_tab('ticket', $post_id);
    }



    /**
     * Renders the "Ticket Settings" panel: the cards shared with passes.
     *
     * @return void
     */
    public function tpfw_wc_ticket_settings_tab_content()
    {
        ?>
        <div id='ticket_product_settings_general' class='panel woocommerce_options_panel'>
            <div class="tpfw-cards tpfw-cards--grid">
                <?php $this->render_settings_cards('ticket', __('ticket', 'tickets-passes-for-woocommerce')); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Persists the "Ticket Settings" panel - see TPFW_Product_Type::save_settings_cards().
     *
     * @param int $post_id Product id being saved.
     * @return void
     */
    public function tpfw_wc_ticket_settings_tab_save($post_id)
    {
        $this->save_settings_cards($post_id);
    }

        /**
     * Soft deletes this type's rows for one order line - see TPFW_Product_Type::order_cancelled().
     *
     * @param int           $iOrderID    Order id.
     * @param int           $iCustomerID Purchasing customer's user id.
     * @param WC_Order_Item $oOrderItem  Line item being processed.
     * @return array{sMessage:string,bStatus:bool}
     */
    protected function cancel_entry($iOrderID, $iCustomerID, $oOrderItem)
    {
        return $this->cancel_ticket($iOrderID, $iCustomerID, $oOrderItem);
    }

    /**
     * Shows the ticket's validity window as line item meta in the cart.
     *
     * @param array $item_data      Meta rows already queued for display.
     * @param array $cart_item_data The cart item.
     * @return array Item data with "Valid From"/"Valid To" appended for ticket products.
     */
    public function tpfw_cart_display_ticket_meta_data($item_data, $cart_item_data) 
    {               
        if(isset($cart_item_data['product_id']))
        {
            $oProduct     = wc_get_product($cart_item_data['product_id']);    
        }
        if(!empty($oProduct) && $oProduct != null && is_a($oProduct, 'TPFW_Product_Ticket'))
        {               
            $sDateTimeFormat    = $this->oFunctions->get_datetime_format('date');            
            $sStartDate         = gmdate($sDateTimeFormat);            
            if(get_post_meta($cart_item_data['product_id'], '_tpfw_ticket_predefined_start_date_enable', true) == 'yes')
            {                
                $sStartDate = gmdate($sDateTimeFormat, strtotime(get_post_meta($cart_item_data['product_id'], '_tpfw_ticket_predefined_start_date', true)));
            }
            // A date the customer picked wins over both defaults - it is the one they were shown.
            if(!empty($cart_item_data['tpfw_start_date']))
            {
                $sStartDate = gmdate($sDateTimeFormat, strtotime($cart_item_data['tpfw_start_date']));
            }
            $sEndDate = gmdate($sDateTimeFormat, (strtotime($sStartDate)+(int)get_post_meta($cart_item_data['product_id'], '_tpfw_ticket_valid_duration', true)));

            // Every case - shop-set date, customer-picked date or valid-from-purchase - is shown
            // as the same window, so the cart, checkout and order line all read alike.
            $item_data[] = array(
                'key'   => __('Valid from', 'tickets-passes-for-woocommerce'),
                'value' => wc_clean(trim($sStartDate)),
            );

            $item_data[] = array(
                'key'   => __('Valid to', 'tickets-passes-for-woocommerce'),
                'value' => wc_clean(trim($sEndDate)),
            );
        }
        return $item_data;        
    }



        /**
     * Creates this type's rows for one order line - see TPFW_Product_Type::order_completed().
     *
     * @param int           $iOrderID    Order id.
     * @param int           $iCustomerID Purchasing customer's user id.
     * @param WC_Order_Item $oOrderItem  Line item being processed.
     * @return array{sMessage:string,bStatus:bool}
     */
    protected function create_entry($iOrderID, $iCustomerID, $oOrderItem)
    {
        return $this->create_ticket($iOrderID, $iCustomerID, $oOrderItem);
    }

    /**
     * AJAX: checks a ticket in from the admin dashboard or the customer's My Account page.
     *
     * Either the current user manages the plugin, or the ticket belongs to them - anything else
     * is rejected. Returns the refreshed status pill and usage counter so the calling table row
     * can be updated without a reload.
     *
     * @return void Sends a JSON envelope through wp_send_json_success()/wp_send_json_error() and exits.
     */
    public function ajax_checkin_ticket_callback()
    {
        // Shared with the other product types - see TPFW_Product_Type::ajax_checkin_callback().
        $this->ajax_checkin_callback();
    }

	/**
	 * AJAX: renders a throwaway QR image so an admin can preview colour/logo changes before saving.
	 *
	 * Admin only. The image is written to the preview upload directory keyed by product id, so
	 * repeated previews overwrite rather than accumulate.
	 *
	 * @return void Sends a JSON envelope through wp_send_json_success()/wp_send_json_error() and exits.
	 */
	public function ajax_settings_preview_ticket_qr_callback()
	{
		$this->oFunctions->render_qr_preview('ticket');
	}

    /**
     * Issues (or reinstates) the tickets for one paid order line.
     *
     * Idempotent by design: existing rows for the same product/order/line are un-deleted up to the
     * current quantity, surplus rows are soft deleted, and only the shortfall is inserted. That
     * way a completed -> refunded -> completed cycle keeps the customer's original nano ids and
     * QR codes valid instead of minting new ones.
     *
     * @param int           $iOrderID    Order id.
     * @param int           $iCustomerID Customer user id the tickets belong to.
     * @param WC_Order_Item $oOrderItem  The line item being fulfilled.
     * @return array{sMessage:string,bStatus:bool} Result suitable for an order note.
     */
    public function create_ticket($iOrderID, $iCustomerID, $oOrderItem)
    {        		
        $oParentProduct   = wc_get_product($oOrderItem->get_product_id());
        $iTicketMaxUses   = get_post_meta($oParentProduct->get_id(), '_tpfw_ticket_max_uses', true);
        $sCurrentDatetime = current_time('mysql');
        if($iTicketMaxUses === null || $iTicketMaxUses <= 0 || $iTicketMaxUses === false || $iTicketMaxUses === "")
        {            
            return array(
                'sMessage' => $oParentProduct->get_name().' (#'.$oParentProduct->get_id() . ') - ' . __('is missing a max usage value', 'tickets-passes-for-woocommerce'),
                'bStatus' => false,
            );
        }

        
        $iProductValidDuration  = get_post_meta($oParentProduct->get_id(), '_tpfw_ticket_valid_duration', true);;
        if($iProductValidDuration === null || $iProductValidDuration <= 0 || $iProductValidDuration === false || $iProductValidDuration === "")
        {
            return array(
                'sMessage' => $oParentProduct->get_name().' (#'.$oParentProduct->get_id() . ') - ' . __('is missing a valid duration value', 'tickets-passes-for-woocommerce'),
                'bStatus'  => false,
            );                    
        }

        global $wpdb;
        $sTicketTable = $wpdb->prefix.$this->sTable;
        $oExistsPrepared = $wpdb->prepare(
            'SELECT * FROM %i WHERE product_id = %d AND order_id = %d AND order_line_id = %d ORDER BY -deleted;',
            array(
				$sTicketTable,
                $oOrderItem->get_product_id(), 
                $iOrderID, 
                $oOrderItem->get_id()                 
            )            
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oExistsPrepared is the return value of $wpdb->prepare() above.
        $aExistsResult = $wpdb->get_results($oExistsPrepared);
        $aSync = TPFW_Order_Line_Upsert::sync($wpdb, $sTicketTable, $aExistsResult, (int)$oOrderItem->get_quantity(), $sCurrentDatetime, function() use ($wpdb, $sTicketTable, $oOrderItem, $iCustomerID, $iOrderID, $iProductValidDuration, $iTicketMaxUses, $sCurrentDatetime, $oParentProduct) {
                $sStartDate = current_time('mysql');            
                if(get_post_meta($oParentProduct->get_id(), '_tpfw_ticket_predefined_start_date_enable', true) == 'yes')
                {                
                    $sStartDate = gmdate('Y-m-d H:i:s', strtotime(get_post_meta($oParentProduct->get_id(), '_tpfw_ticket_predefined_start_date', true)));
                }
                $sCustomerStartDate = $oOrderItem->get_meta('tpfw_start_date');
                if(!empty($sCustomerStartDate))
                {
                    $sStartDate = gmdate('Y-m-d H:i:s', strtotime($sCustomerStartDate));
                }
                $sEndDate = gmdate('Y-m-d H:i:s', (strtotime($sStartDate)+(int)$iProductValidDuration));

                $sGeneratedNanoID = $this->oFunctions->generateNanoId();
                $wpdb->query($wpdb->prepare(
                    'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, valid_from, valid_to, max_uses, created, updated)
                    VALUES (%s, %d, %d, %d, %d, %d, %s, %s, %d, %s, %s);',
                    array(
                        $sTicketTable,
                        $sGeneratedNanoID,
                        $oOrderItem->get_product_id(),
                        $iCustomerID,
                        $iOrderID,
                        $oOrderItem->get_id(),
                        $iProductValidDuration,
                        $sStartDate,
                        $sEndDate,
                        $iTicketMaxUses,
                        $sCurrentDatetime,
                        $sCurrentDatetime,
                    )
                ));
                return $sGeneratedNanoID;
        });
        $aTempTicketReset = $aSync['keep'];
        
        if(!empty($oOrderItem->get_meta_data()))
        {
            foreach($oOrderItem->get_meta_data() as $iMetaKey => $aMetaData)
            {
                if(str_contains($aMetaData->key, 'tpfw_ticket_id_')) 
                {								
                    $oOrderItem->delete_meta_data($aMetaData->key);
                }
            }
            $oOrderItem->save();
        }

        if(!empty($aTempTicketReset))
        {            
            foreach(array_unique($aTempTicketReset) as $iTempTicketKey => $sTicketNanoID)
            {
                $oOrderItem->add_meta_data('tpfw_ticket_id_'.((int)$iTempTicketKey+1), $sTicketNanoID);
                $iProductID = $oOrderItem->get_product_id();
                $this->oFunctions->write_scanner_qr($iProductID, 'ticket', $sTicketNanoID);
            }
            $oOrderItem->save();
        }
        
        return array(
            'sMessage' => __('Ticket for order line created', 'tickets-passes-for-woocommerce'),
            'bStatus' => true,
        );
    }

	/**
	 * Soft deletes every ticket and check-in record belonging to one order line and removes its QR files.
	 *
	 * Safe to call repeatedly - already-cancelled lines match nothing and return early.
	 *
	 * @param int           $iOrderID    Order id.
	 * @param int           $iCustomerID Customer user id the tickets belong to.
	 * @param WC_Order_Item $oOrderItem  The line item being cancelled.
	 * @return array{sMessage:string,bStatus:bool} Result suitable for an order note.
	 */
	public function cancel_ticket($iOrderID, $iCustomerID, $oOrderItem)
    {
        global $wpdb;
        $oExistsPrepared = $wpdb->prepare(
            'SELECT * FROM %i WHERE product_id = %d AND order_id = %d AND order_line_id = %d AND user_id = %d AND deleted IS NULL;',
            array(
				$wpdb->prefix . 'tpfw_tickets',                 
                $oOrderItem->get_product_id(), 
                $iOrderID, 
                $oOrderItem->get_id(),
                $iCustomerID                
            )            
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oExistsPrepared is the return value of $wpdb->prepare() above.
        $oExistsResult = $wpdb->get_results($oExistsPrepared);
        if(empty($oExistsResult))
        {
            return array(
                'sMessage' => __('Ticket for this order line already seems to be cancelled', 'tickets-passes-for-woocommerce'),
                'bStatus' => false,
            );
        }

        $iQuantity                 = 1;
        $oOrder                    = wc_get_order($iOrderID);
        $sTicketTableName          = $wpdb->prefix . "tpfw_tickets";
        $sTicketStatisticTableName = $wpdb->prefix . "tpfw_tickets_stats";
        $sCurrentDatetime          = current_time('mysql');
        foreach($oExistsResult as $iExistResultKey => $oExistResult)
        {            
            
            $sUpdateTicketSQL 			= $wpdb->prepare('	UPDATE %i
                                                        SET deleted = %s, updated = %s
                                                        WHERE nano_id = %s', $sTicketTableName, $sCurrentDatetime, $sCurrentDatetime, $oExistResult->nano_id);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sUpdateTicketSQL is the return value of $wpdb->prepare() above.
            $wpdb->get_results($sUpdateTicketSQL);

            $sTicketStatisticUpdateSQL 		= $wpdb->prepare('	UPDATE %i
                                                                SET deleted = %s, updated = %s
                                                                WHERE nano_id_fk = %s', $sTicketStatisticTableName, $sCurrentDatetime, $sCurrentDatetime, $oExistResult->nano_id);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sTicketStatisticUpdateSQL is the return value of $wpdb->prepare() above.
            $wpdb->get_results($sTicketStatisticUpdateSQL);

            
            $this->oFunctions->delete_qr_code($oExistResult->nano_id);
            $oOrderItem->delete_meta_data('tpfw_ticket_id_'.$iQuantity);
			$oOrderItem->save();
			$oOrder->add_order_note(__('Ticket with ID', 'tickets-passes-for-woocommerce') . ': ' .$oExistResult->nano_id . ' ' . __('cancelled', 'tickets-passes-for-woocommerce'));
			$oOrder->save();	
            $iQuantity++;		            			
        }

        return array(
            'sMessage' => __('All related tickets have been cancelled', 'tickets-passes-for-woocommerce'),
            'bStatus' => false,
        );
    }

}

    // Declared at file scope, outside TPFW_Ticket_WC_Product: WooCommerce instantiates this by
    // name from the woocommerce_product_class filter, so it has to exist even when the admin
    // class above is never constructed. The class_exists() guard keeps a double include harmless.
    if(!class_exists('TPFW_Product_Ticket'))
    {
        /**
         * WooCommerce product class backing the 'tpfw-ticket' product type.
         *
         * Behaviourally a simple product - all the ticket logic lives in TPFW_Ticket_WC_Product
         * and in the {prefix}tickets table, so only the type slug needs overriding here.
         */
        class TPFW_Product_Ticket extends WC_Product
        {
            /**
             * @return string The WooCommerce product type slug.
             */
            public function get_type()
            {
                return 'tpfw-ticket';
            }
        }
    }