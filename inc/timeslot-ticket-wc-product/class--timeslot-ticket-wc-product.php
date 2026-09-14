<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * The "Timeslot Ticket" WooCommerce product type.
 *
 * A timeslot ticket is a ticket bound to one bookable slot. Slots live in {prefix}timeslots,
 * each with its own start/end and available_slots capacity, and can be generated in series
 * from a row in {prefix}timeslots_recurring. The customer picks a slot on the product page
 * before adding to the cart; each paid line produces one row per quantity in
 * {prefix}timeslot_tickets with its own QR code, and check-ins land in
 * {prefix}timeslot_tickets_stats.
 *
 * Products may optionally use reservations: picking a slot writes a time-limited row in
 * {prefix}timeslot_reservations that counts against the slot's capacity, so two customers
 * cannot hold the last seat at once. Expired reservations are swept out of the cart on every
 * path that leads to checkout - see load_settings_dependencies().
 *
 * All of these tables are soft deleted (a non-null `deleted` timestamp) rather than purged,
 * so cancelling and reinstating an order keeps the customer's original QR codes working.
 */
require_once dirname(__FILE__) . '/../product-type/class--product-type.php';
require_once dirname(__FILE__) . '/class--timeslot-ticket-checkout.php';

class TPFW_Timeslot_Ticket_WC_Product extends TPFW_Product_Type
{
	protected $sType         = 'timeslot';
	protected $sTable        = 'tpfw_timeslot_tickets';
	protected $sStatsTable   = 'tpfw_timeslot_tickets_stats';
	protected $sMetaPrefix   = '_tpfw_timeslot_';
	protected $sProductClass = 'TPFW_Product_Timeslot_Ticket';

	/** @var string Datepicker init JS built by the add-to-cart template, attached on 'wp_footer'. */
	protected $sTimeslotDatepickerJS = '';

	/** Remaining places at or below which a timeslot is flagged as nearly full on the product page. */
	const LOW_SPOTS_THRESHOLD = 3;

	/** @var TPFW_Timeslot_Ticket_Checkout */
	protected $oCheckout;

	/**
	 * @return TPFW_Timeslot_Ticket_Checkout
	 */
	protected function checkout()
	{
		if($this->oCheckout === null)
		{
			$this->oCheckout = new TPFW_Timeslot_Ticket_Checkout($this->oFunctions);
		}
		return $this->oCheckout;
	}

	/**
	 * Registers every WordPress/WooCommerce hook this class owns.
	 *
	 * Called once from the constructor; nothing else in the class registers hooks, so this
	 * is the single place to look for what the Timeslot Ticket product type reacts to.
	 *
	 * @return void
	 */
	protected function load_settings_dependencies()
	{
		add_action('admin_enqueue_scripts',                                         array($this, 'enqueue_script_admin'));
        add_action('wp_enqueue_scripts',                                            array($this, 'tpfw_enqueue_timeslot_datepicker'));

        // Product type registration and the product editor panels.
        add_filter('product_type_selector',                                         array($this, 'tpfw_add_timeslot_ticket_tpfw_product_type'));
        add_filter('woocommerce_product_class',                                     array($this, 'tpfw_woocommerce_timeslot_ticket_product_class'),      10, 3);
        // Priority 9999 so the show_if_/hide_if_ classes are appended after every other
        // extension has finished adding its own tabs.
        add_filter('woocommerce_product_data_tabs',                                 array($this, 'tpfw_woocommerce_timeslot_ticket_data_tabs'),          9999);
        add_filter('woocommerce_product_data_tabs',                                 array($this, 'tpfw_timeslot_ticket_product_tab'));
        add_action('woocommerce_product_data_panels',                               array($this, 'tpfw_wc_timeslot_ticket_settings_general_tab_content'));
        add_action('woocommerce_product_data_panels',                               array($this, 'tpfw_wc_timeslot_ticket_settings_qr_tab_content'));
        add_action('woocommerce_process_product_meta_tpfw-timeslot-ticket',              array($this, 'tpfw_wc_timeslot_settings_tab_save'));
        add_action('woocommerce_process_product_meta_tpfw-timeslot-ticket',              array($this, 'tpfw_wc_timeslot_qr_tab_save'));

        // Product page: the timeslot picker and the add-to-cart form for this type.
        add_action("woocommerce_tpfw-timeslot-ticket_add_to_cart",                       array($this, "tpfw_woocommerce_timeslot_ticket_add_to_cart"));
        add_filter('woocommerce_before_add_to_cart_button',                         array($this, 'tpfw_timeslot_ticket_product_html'));

        // Cart and order line: validate, carry the chosen timeslot along, display it.
        add_filter('woocommerce_add_to_cart_validation',                            array($this, 'tpfw_timeslot_ticket_add_to_cart_validation'),         10, 3);
        add_filter('woocommerce_add_cart_item_data',                                array($this, 'tpfw_timeslot_ticket_add_to_cart_action'),             10, 4);
        add_filter('woocommerce_get_item_data',                                     array($this, 'tpfw_display_timeslot_ticket_meta_data'),              10, 2);
        add_action('woocommerce_checkout_create_order_line_item',                   array($this, 'tpfw_add_timeslot_ticket_meta_to_order_line'),         10, 4);
        // Stored reservation_time meta stays a raw timestamp; this only formats it for display.
        add_filter('woocommerce_order_item_display_meta_value',                     array($this, 'tpfw_format_reservation_time_order_item_meta'),        10, 2);

        // Issuing and revoking. Cancelled / refunded / failed must all revoke the ticket,
        // otherwise a refunded customer keeps a scannable QR code. cancel_timeslot_ticket()
        // is idempotent.
		add_action('woocommerce_order_status_processing',                            array($this, 'order_maybe_issue'), 10, 1);
		add_action('woocommerce_order_status_completed',                            array($this, 'order_maybe_issue'), 10, 1);
		add_action('woocommerce_order_status_cancelled',                            array($this, 'order_cancelled'),   10, 1);
		add_action('woocommerce_order_status_refunded',                             array($this, 'order_cancelled'),   10, 1);
		add_action('woocommerce_order_status_failed',                               array($this, 'order_cancelled'),   10, 1);

        // Reservations: sweep expired ones out of the cart on every path that can reach checkout.
        add_action('woocommerce_checkout_init',                                     array($this, 'tpfw_remove_expired_timeslot_ticket_reservations'));
        add_action('woocommerce_cart_updated',                                      array($this, 'tpfw_remove_expired_timeslot_ticket_reservations'));
        // WC_Cart::check_cart_items() is what both classic checkout (WC_Checkout::process_checkout())
        // and the Store API/Blocks checkout run right before creating the order - a wc_add_notice('error')
        // raised here (same function the cart/checkout page render already uses) stops the order the same
        // way an out-of-stock notice would, instead of only catching expiry on the render before it.
        add_action('woocommerce_check_cart_items',                                  array($this, 'tpfw_remove_expired_timeslot_ticket_reservations'));

        // Release a reservation the moment its cart item goes, rather than waiting for expiry.
        add_action('woocommerce_cart_item_removed',                                 array($this, 'tpfw_delete_reservation_on_cart_item_removed'),        10, 2);
        // Extend the reservation once an order exists - one hook per checkout flavour.
        add_action('woocommerce_store_api_checkout_order_processed',                array($this, 'tpfw_reservation_time_added_woocommerce_new_order'),   10, 1); //Block style WC
        add_action('woocommerce_checkout_order_created',                            array($this, 'tpfw_reservation_time_added_woocommerce_new_order'),   10, 1); //Classic WC

        // Lock the quantity in the cart. The first filter only reaches the classic Cart
        // template; the Mini Cart drawer and Cart/Checkout blocks go through the Store API,
        // which respects is_sold_individually() instead.
        add_filter('woocommerce_cart_item_quantity',                                array($this, 'tpfw_timeslot_disable_cart_item_quantity'),            10, 3);
        add_filter('woocommerce_is_sold_individually',                              array($this, 'tpfw_timeslot_disable_quantity_editing_in_cart'),      10, 2);

        // AJAX. All admin-only except the check-in, which a customer may also run against
        // their own ticket from My Account.
        add_action('wp_ajax_tpfw_ajax_get_recurring_timeslot_children',                  array($this, 'ajax_get_recurring_timeslot_children_callback'));
        add_action('wp_ajax_tpfw_ajax_force_recurring_timeslot_children',                array($this, 'ajax_force_recurring_timeslot_children_callback'));
        add_action('wp_ajax_tpfw_ajax_delete_timeslot',                                  array($this, 'ajax_delete_timeslot_callback'));
        add_action('wp_ajax_tpfw_ajax_delete_recurring_timeslot_children',               array($this, 'ajax_delete_recurring_timeslot_children_callback'));
		add_action('wp_ajax_tpfw_ajax_settings_preview_timeslot_qr',                     array($this, 'ajax_settings_preview_timeslot_qr_callback'));
        add_action('wp_ajax_tpfw_ajax_checkin_timeslot',                                 array($this, 'ajax_checkin_timeslot_callback'));
    }

    /**
     * Loads the product-edit assets, but only on the WooCommerce product editor.
     *
     * The screen check keeps the timeslot table, QR uploader and colour pickers off every other
     * admin page. filemtime() is used as the version so a changed file busts the browser cache.
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
            // air-datepicker's own default locale isn't English, so this is passed through
            // explicitly (reusing WordPress core's own already-translated month/weekday
            // names via $wp_locale, the same source WP's own admin date pickers use) rather
            // than hardcoding English or duplicating translation strings this plugin doesn't
            // otherwise need.
            global $wp_locale;
            $aDatepickerDays      = array();
            $aDatepickerDaysShort = array();
            $aDatepickerDaysMin   = array();
            foreach($wp_locale->weekday as $sDayName)
            {
                $aDatepickerDays[]      = $sDayName;
                $aDatepickerDaysShort[] = $wp_locale->weekday_abbrev[$sDayName];
                $aDatepickerDaysMin[]   = $wp_locale->weekday_initial[$sDayName];
            }

            $aParams = array
            (
                'aNonces'                          => $this->oFunctions->get_ajax_nonces(array('ajax_delete_recurring_timeslot_children', 'ajax_delete_timeslot', 'ajax_force_recurring_timeslot_children', 'ajax_get_recurring_timeslot_children', 'ajax_settings_preview_timeslot_qr')),
                'sTimeslotTicketQRPlaceholderLogo'  => TPFW_PLUGIN_URL . 'images/qr-logo-placeholder.webp',
                'sSiteURL'                          => site_url(),
                'aDatepickerLocale'             => array
                (
                                                        'days'        => $aDatepickerDays,
                                                        'daysShort'   => $aDatepickerDaysShort,
                                                        'daysMin'     => $aDatepickerDaysMin,
                                                        'months'      => array_values($wp_locale->month),
                                                        'monthsShort' => array_values($wp_locale->month_abbrev),
                                                        'today'       => __('Today', 'tickets-passes-for-woocommerce'),
                                                        'clear'       => __('Clear', 'tickets-passes-for-woocommerce'),
                ),
                'aTranslations'                => array
                (
                    'sInsertQRLogo'                   => __('Upload/Insert Logo', 'tickets-passes-for-woocommerce'),
                    'sUseSelected'                    => __('Use Selected', 'tickets-passes-for-woocommerce'),
                    'sConfirmCancelRecurringTimeslot' => __('Accepting, will also cancel the recurring timeslot and all its timeslots + cancel all the connected timeslot tickets', 'tickets-passes-for-woocommerce'),
                    'sConfirmCancelTimeslot'          => __('Accepting, will cancel the timeslot and also cancel all its connected tickets', 'tickets-passes-for-woocommerce'),
                    // Deleting a timeslot cancels its tickets, so a delete that quietly did
                    // nothing was the worst possible outcome: the row vanished from the screen on
                    // some paths and the shop owner had no reason to check.
                    'sActionFailed'                   => __('The action could not be completed. Please try again.', 'tickets-passes-for-woocommerce'),
                    'sPreviewFailed'                  => __('The preview could not be generated. Please try again.', 'tickets-passes-for-woocommerce'),
                    // The timeslot list rewrites its own labels as rows are added, dated and filtered,
                    // so the sentences it builds have to come from PHP to stay translatable.
                    'sOnSale'                         => __('On sale', 'tickets-passes-for-woocommerce'),
                    'sSoldOut'                        => __('Sold out', 'tickets-passes-for-woocommerce'),
                    'sPast'                           => __('Past', 'tickets-passes-for-woocommerce'),
                    /* translators: %d: number of places still available. */
                    'sPlacesLeft'                     => __('%d left', 'tickets-passes-for-woocommerce'),
                    /* translators: %d: number of timeslots. */
                    'sTimeslotCount'                  => __('%d timeslot', 'tickets-passes-for-woocommerce'),
                    /* translators: %d: number of timeslots. */
                    'sTimeslotCountPlural'            => __('%d timeslots', 'tickets-passes-for-woocommerce'),
                    /* translators: %d: number of recurring rules. */
                    'sSeriesCount'                    => __('%d recurring timeslot', 'tickets-passes-for-woocommerce'),
                    /* translators: %d: number of recurring rules. */
                    'sSeriesCountPlural'              => __('%d recurring timeslots', 'tickets-passes-for-woocommerce'),
                    'sNewTimeslot'                    => __('No date yet', 'tickets-passes-for-woocommerce'),
                    /* translators: %d: number of timeslots the current filter is hiding. */
                    'sHiddenByFilter'                 => __('%d hidden by filter', 'tickets-passes-for-woocommerce'),
                    /* translators: 1: "3 timeslots". 2: tickets sold that day. 3: total places that day. */
                    'sDayMeta'                        => __('%1$s · %2$d of %3$d sold', 'tickets-passes-for-woocommerce'),
                    /* translators: 1: tickets sold. 2: total places. */
                    'sSoldSummary'                    => __('%1$d of %2$d places sold', 'tickets-passes-for-woocommerce'),
                    /* translators: 1: weekday name. 2: start time. 3: end time. 4: first week number. 5: last week number. 6: places per timeslot. */
                    /* translators: 1: weekday. 2: start time. 3: end time. */
                    'sSeriesRule'                     => __('Every %1$s, %2$s–%3$s', 'tickets-passes-for-woocommerce'),
                    /* translators: 1: first week number. 2: last week number. 3: places per timeslot. */
                    'sSeriesRuleMeta'                 => __('· weeks %1$s–%2$s · %3$s places each', 'tickets-passes-for-woocommerce'),
                ),
            );
            
            // Same air-datepicker bundle the frontend single-product picker uses (see
            // tpfw_enqueue_timeslot_datepicker() below) - reused here so the admin Start/End
            // fields get a themeable popup with a real timepicker instead of relying on each
            // browser's own unstylable native datetime-local control.
            wp_enqueue_style($this->sPrefix.'air-datepicker',  TPFW_PLUGIN_URL . 'lib/air-datepicker/css/air-datepicker.min.css', array(), filemtime(TPFW_PLUGIN_DIR . 'lib/air-datepicker/css/air-datepicker.min.css'));
            wp_register_script($this->sPrefix.'air-datepicker', TPFW_PLUGIN_URL . 'lib/air-datepicker/js/air-datepicker.min.js', array(), filemtime(TPFW_PLUGIN_DIR . 'lib/air-datepicker/js/air-datepicker.min.js'), true);
            wp_enqueue_script($this->sPrefix.'air-datepicker');

            wp_register_script($this->sPrefix.'timeslot-ticket-wc-product', plugins_url('', __FILE__).'/js/timeslot-ticket-wc-product.js', array('jquery', $this->sPrefix.'air-datepicker', $this->sPrefix.'functions-admin'), filemtime(dirname(__FILE__).'/js/timeslot-ticket-wc-product.js'), true);
            wp_enqueue_script($this->sPrefix.'timeslot-ticket-wc-product');
            wp_localize_script($this->sPrefix.'timeslot-ticket-wc-product', 'tpfwParamsTimeslotTicketWcProduct', $aParams);

            wp_enqueue_style($this->sPrefix.'timeslot.module', plugins_url('', __FILE__).'/css/timeslot.module.css', array(), filemtime(dirname(__FILE__).'/css/timeslot.module.css'));

            // Shared QR-tab stylesheet (also used by Ticket/Pass) - enqueued here too since
            // the Pass class (which otherwise owns this file) may not be loaded if that product type is disabled.
            wp_enqueue_style($this->sPrefix.'pass.module', plugins_url('../pass-wc-product/css/pass.module.css', __FILE__), array(), filemtime(dirname(__FILE__).'/../pass-wc-product/css/pass.module.css'));
        }
    }



    /**
     * Loads the bundled air-datepicker and the front end timeslot picker on timeslot product pages.
     *
     * The datepicker is localized from WP core's $wp_locale so month and weekday names follow the
     * site language rather than shipping a separate locale file per language.
     *
     * @return void
     */
    public function tpfw_enqueue_timeslot_datepicker()
    {
        global $post;
        if (is_product() && is_singular('product'))
        {
            $oProduct = wc_get_product($post->ID);
            if (is_a($oProduct, 'TPFW_Product_Timeslot_Ticket'))
            {
                wp_enqueue_style($this->sPrefix.'air-datepicker',  TPFW_PLUGIN_URL . 'lib/air-datepicker/css/air-datepicker.min.css', false, filemtime(TPFW_PLUGIN_DIR . 'lib/air-datepicker/css/air-datepicker.min.css'));

                wp_register_script($this->sPrefix.'air-datepicker', TPFW_PLUGIN_URL . 'lib/air-datepicker/js/air-datepicker.min.js', false, filemtime(TPFW_PLUGIN_DIR . 'lib/air-datepicker/js/air-datepicker.min.js'), true);
                wp_enqueue_script($this->sPrefix.'air-datepicker');

                wp_register_script($this->sPrefix.'timeslot-ticket-wc-product-frontend', plugins_url('', __FILE__).'/js/timeslot-ticket-wc-product-frontend.js', array('jquery', $this->sPrefix.'air-datepicker'), filemtime(dirname(__FILE__).'/js/timeslot-ticket-wc-product-frontend.js'), true);
                wp_enqueue_script($this->sPrefix.'timeslot-ticket-wc-product-frontend');

                wp_enqueue_style($this->sPrefix.'timeslot.front', plugins_url('', __FILE__).'/css/timeslot.front.css', array(), filemtime(dirname(__FILE__).'/css/timeslot.front.css'));
            }
        }
    }

    /**
     * Attaches the datepicker init built by the add-to-cart template to the front end script.
     *
     * Runs on 'wp_footer' at priority 5 - after the template render and after the enqueue, but
     * before wp_print_footer_scripts() at priority 20 prints the handle it is attached to.
     *
     * @return void
     */
    public function tpfw_add_timeslot_datepicker_inline_script()
    {
        if($this->sTimeslotDatepickerJS === '')
        {
            return;
        }

        wp_add_inline_script($this->sPrefix.'timeslot-ticket-wc-product-frontend', $this->sTimeslotDatepickerJS);
        $this->sTimeslotDatepickerJS = '';
    }

    /**
     * Tags the stock WooCommerce product tabs as shown or hidden for the Timeslot Ticket type.
     *
     * @param array $tabs Product data tabs.
     * @return array Tabs with show_if_tpfw-timeslot-ticket / hide_if_tpfw-timeslot-ticket classes appended.
     */
    public function tpfw_woocommerce_timeslot_ticket_data_tabs($tabs) 
    {
        $tabs['inventory']['class'][]               = 'hide_if_tpfw-timeslot-ticket';
        if(isset($tabs['marketplace-suggestions'])) { $tabs['marketplace-suggestions']['class'][] = 'hide_if_tpfw-timeslot-ticket'; }
        $tabs['shipping']['class'][]                = 'hide_if_tpfw-timeslot-ticket';
        $tabs['linked_product']['class'][]          = 'hide_if_tpfw-timeslot-ticket';
        $tabs['attribute']['class'][]               = 'hide_if_tpfw-timeslot-ticket';
        $tabs['variations']['class'][]              = 'hide_if_tpfw-timeslot-ticket';
        $tabs['advanced']['class'][]                = 'hide_if_tpfw-timeslot-ticket';
        return $tabs;
    }



    /**
     * Adds the plugin's "Timeslot Settings" and "Timeslot QR" panels to the product data box.
     *
     * @param array $tabs Product data tabs.
     * @return array Tabs with the two timeslot panels added.
     */
    public function tpfw_timeslot_ticket_product_tab($tabs) 
    { 
        $tabs['timeslot-ticket-settings-general'] = array
        (
            'label'  => __('Timeslot Settings', 'tickets-passes-for-woocommerce'),
            'target' => 'timeslot-ticket_product_settings_general',
            'class'  => 'show_if_tpfw-timeslot-ticket',
        );
        $tabs['timeslot-ticket-settings-qr'] = array
        (
            'label'  => __('Timeslot QR', 'tickets-passes-for-woocommerce'),
            'target' => 'timeslot-ticket_product_settings_qr',
            'class'  => 'show_if_tpfw-timeslot-ticket',
        );

        return $tabs;
    }






    /**
     * Renders the "Timeslot QR" panel: label text, the three colours, logo upload and a live preview.
     *
     * Each value falls back to a sensible default when the meta key has never been saved.
     *
     * @return void
     */
    public function tpfw_wc_timeslot_ticket_settings_qr_tab_content()
    {
        $this->oFunctions->render_qr_tab('timeslot', 'timeslot-ticket_product_settings_qr', 'timeslot', __('Timeslot Ticket', 'tickets-passes-for-woocommerce'));
    }
    

    /**
     * Persists the "Timeslot QR" panel and regenerates the preview image.
     *
     * Empty fields delete their meta rather than storing an empty string, so the defaults in
     * tpfw_wc_timeslot_ticket_settings_qr_tab_content() apply again. Also saves the sales window,
     * which is rendered on the settings panel - harmless, because both save handlers fire on the
     * same woocommerce_process_product_meta_tpfw-timeslot-ticket hook and see the whole $_POST.
     *
     * @param int $post_id Product id being saved.
     * @return void
     */
    public function tpfw_wc_timeslot_qr_tab_save($post_id)
    {
        $this->oFunctions->save_qr_tab('timeslot', $post_id);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
        $_timeslot_sales_timespan_enable   = sanitize_text_field(wp_unslash($_POST['_tpfw_timeslot_sales_timespan_enable'] ?? ''));
        if($_timeslot_sales_timespan_enable == 'yes')
        {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
            $_timeslot_sales_timespan_start       = sanitize_text_field(wp_unslash($_POST['_tpfw_timeslot_sales_timespan_start'] ?? ''));
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
            $_timeslot_sales_timespan_end         = sanitize_text_field(wp_unslash($_POST['_tpfw_timeslot_sales_timespan_end'] ?? ''));
            $_timeslot_sales_timespan_end = $this->oFunctions->clamp_sales_timespan_end($_timeslot_sales_timespan_start, $_timeslot_sales_timespan_end);
            update_post_meta($post_id, '_tpfw_timeslot_sales_timespan_start', $_timeslot_sales_timespan_start);
            update_post_meta($post_id, '_tpfw_timeslot_sales_timespan_end', $_timeslot_sales_timespan_end);
            update_post_meta($post_id, '_tpfw_timeslot_sales_timespan_enable', $_timeslot_sales_timespan_enable);
        }
        else
        {
            delete_post_meta($post_id, '_tpfw_timeslot_sales_timespan_start');
            delete_post_meta($post_id, '_tpfw_timeslot_sales_timespan_end');
            delete_post_meta($post_id, '_tpfw_timeslot_sales_timespan_enable');
        }

        $this->oFunctions->save_checkbox_meta($post_id, '_tpfw_timeslot_show_max_uses');
        // Without a sales window there are no dates to publish.
        $this->oFunctions->save_checkbox_meta($post_id, '_tpfw_timeslot_show_sales_window', $_timeslot_sales_timespan_enable == 'yes');
    }



    /**
     * Markup the capacity sentence is allowed to carry.
     *
     * "3 of [8] sold" is one translatable sentence with an editable field inside it, so the
     * placeholders are markup rather than plain text and the result has to go through wp_kses()
     * instead of esc_html().
     *
     * @return array Tag and attribute allowlist for wp_kses().
     */
    private function get_timeslot_capacity_kses()
    {
        return array
        (
            'span'  => array('class' => array()),
            'input' => array('type' => array(), 'class' => array(), 'name' => array(), 'value' => array(), 'min' => array(), 'disabled' => array(), 'aria-label' => array()),
        );
    }

    /**
     * Renders one timeslot row of the Timeslots card.
     *
     * The hidden row template, the saved rows on the product screen and the rows the "expand a
     * series" AJAX returns were three near-identical copies of this markup, kept in step by hand.
     * They now differ only in what they pass in.
     *
     * The row leads with its own data - when it runs, how full it is, whether it has already been -
     * rather than with a labelled field per value: the date is carried by the day heading the admin
     * script groups these under, and the times are edited in place.
     *
     * @param array $aArgs {
     *     @type int    $index        1-based position, which is what the posted field names key on.
     *     @type string $id           Timeslot id. Empty for a row that has never been saved.
     *     @type string $start        Stored 'Y-m-d H:i:s' start, or an empty string.
     *     @type string $end          Stored 'Y-m-d H:i:s' end, or an empty string.
     *     @type int    $qty          Places on the timeslot.
     *     @type int    $used         Tickets already issued against it.
     *     @type string $recurring_id Series the row belongs to. Empty for a standalone timeslot.
     *     @type string $parent_id    Series id, as the data attribute the delete handler matches on.
     *     @type string $classes      Classes after "row". The hidden template deliberately drops
     *                                "timeslot-row" so the live-row selectors never reach it.
     *     @type bool   $disabled     True for the hidden template, whose fields must not post.
     * }
     * @return void Echoes the row.
     */
    private function render_timeslot_row($aArgs)
    {
        $aArgs = array_merge(array('index' => 1, 'id' => '', 'start' => '', 'end' => '', 'qty' => 1, 'used' => 0, 'recurring_id' => '', 'parent_id' => '', 'classes' => 'timeslot-row', 'disabled' => false), $aArgs);

        $sName     = 'timeslots[' . (int)$aArgs['index'] . ']';
        $sDisabled = $aArgs['disabled'] ? ' disabled' : '';
        // Escaped once here rather than at each of the four places it is interpolated below.
        $sUsedHTML = '<span class="timeslot-current-used">' . esc_html((string)(int)$aArgs['used']) . '</span>';
        $sQtyHTML  = '<input type="number" class="timeslot-qty" name="' . esc_attr($sName . '[qty]') . '" value="' . esc_attr((string)(int)$aArgs['qty']) . '" min="1" aria-label="' . esc_attr__('Places', 'tickets-passes-for-woocommerce') . '"' . $sDisabled . '/>';
        ?>
        <div class="row <?php echo esc_attr($aArgs['classes']); ?>"<?php if($aArgs['parent_id'] !== '') { echo ' data-attr-reccuring-parent-id="' . esc_attr($aArgs['parent_id']) . '"'; } ?>>
            <span class="timeslot-flag" aria-hidden="true"></span>

            <div class="timeslot-when">
                <input readonly type="text" class="timeslot-start-picker" aria-label="<?php echo esc_attr__('Start', 'tickets-passes-for-woocommerce'); ?>" placeholder="<?php echo esc_attr__('Start', 'tickets-passes-for-woocommerce'); ?>"<?php echo esc_attr($sDisabled); ?>/>
                <span class="timeslot-when-dash" aria-hidden="true">&ndash;</span>
                <input readonly type="text" class="timeslot-end-picker" aria-label="<?php echo esc_attr__('End', 'tickets-passes-for-woocommerce'); ?>" placeholder="<?php echo esc_attr__('End', 'tickets-passes-for-woocommerce'); ?>"<?php echo esc_attr($sDisabled); ?>/>
                <span class="timeslot-duration"></span>
                <input<?php echo esc_attr($sDisabled); ?> type="hidden" class="timeslot-start" name="<?php echo esc_attr($sName . '[start]'); ?>" value="<?php echo esc_attr($aArgs['start']); ?>"/>
                <input<?php echo esc_attr($sDisabled); ?> type="hidden" class="timeslot-end" name="<?php echo esc_attr($sName . '[end]'); ?>" value="<?php echo esc_attr($aArgs['end']); ?>"/>
            </div>

            <div class="timeslot-cap">
                <span class="timeslot-cap-line">
                    <?php
                        /* translators: 1: number of places already sold. 2: input field holding the total number of places. */
                        echo wp_kses(sprintf(__('%1$s of %2$s sold', 'tickets-passes-for-woocommerce'), $sUsedHTML, $sQtyHTML), $this->get_timeslot_capacity_kses());
                    ?>
                </span>
                <span class="timeslot-meter" aria-hidden="true"><i></i></span>
            </div>

            <div class="timeslot-state"><b></b><span></span></div>

            <input<?php echo esc_attr($sDisabled); ?> type="hidden" class="timeslot-id" name="<?php echo esc_attr($sName . '[id]'); ?>" value="<?php echo esc_attr($aArgs['id']); ?>"/>
            <input disabled type="hidden" class="timeslot-recurring-id" name="<?php echo esc_attr($sName . '[recurring-id]'); ?>" value="<?php echo esc_attr($aArgs['recurring_id']); ?>"/>

            <div class="timeslot-row-actions">
                <button type="button" class="timeslot-action-see-tickets<?php echo esc_attr($aArgs['id'] === '' ? ' hide' : ''); ?>" title="<?php echo esc_attr__('See timeslot tickets', 'tickets-passes-for-woocommerce'); ?>" aria-label="<?php echo esc_attr__('See timeslot tickets', 'tickets-passes-for-woocommerce'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z"/><circle cx="12" cy="12" r="2.5"/></svg></button>
                <button type="button" class="timeslot-action-trash" title="<?php echo esc_attr__('Cancel timeslot', 'tickets-passes-for-woocommerce'); ?>" aria-label="<?php echo esc_attr__('Cancel timeslot', 'tickets-passes-for-woocommerce'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13"/></svg></button>
            </div>
        </div>
        <?php
    }

    /**
     * Renders one recurring series of the Timeslots card.
     *
     * A series is a single decision - "every Monday at ten" - so the row leads with that decision
     * written out (the admin script keeps the sentence in step with the fields) and folds the six
     * fields encoding it into a native <details> disclosure, which is also what loads and shows the
     * slots the series has generated.
     *
     * @param array $aArgs {
     *     @type int    $index      1-based position, which is what the posted field names key on.
     *     @type string $id         Series id. Empty for a series that has never been saved.
     *     @type int    $weekday    1 (Monday) to 7 (Sunday).
     *     @type int    $week_start First week number of the run.
     *     @type int    $week_end   Last week number of the run.
     *     @type string $time_start 'HH:MM' each slot starts at.
     *     @type string $time_end   'HH:MM' each slot ends at.
     *     @type int    $qty        Places on each generated slot.
     *     @type int    $slots      Slots generated so far. -1 when not counted (the template).
     *     @type int    $places     Places across those slots.
     *     @type int    $used       Tickets issued across those slots.
     *     @type string $classes    Classes after "row". The hidden template deliberately drops
     *                              "timeslot-row-recurring" so the live-row selectors miss it.
     *     @type bool   $disabled   True for the hidden template, whose fields must not post.
     * }
     * @return void Echoes the series.
     */
    private function render_timeslot_series_row($aArgs)
    {
        $aArgs = array_merge(array('index' => 1, 'id' => '', 'weekday' => 1, 'week_start' => (int)gmdate('W', current_time('timestamp')), 'week_end' => (int)gmdate('W', strtotime('+5 weeks', current_time('timestamp'))), 'time_start' => '00:00', 'time_end' => '01:00', 'qty' => 1, 'slots' => -1, 'places' => 0, 'used' => 0, 'classes' => 'timeslot-row-recurring parent', 'disabled' => false), $aArgs);

        $sName     = 'timeslots[' . (int)$aArgs['index'] . ']';
        $sDisabled = $aArgs['disabled'] ? ' disabled' : '';
        $aWeekdays = array
        (
            1 => __('Monday', 'tickets-passes-for-woocommerce'),
            2 => __('Tuesday', 'tickets-passes-for-woocommerce'),
            3 => __('Wednesday', 'tickets-passes-for-woocommerce'),
            4 => __('Thursday', 'tickets-passes-for-woocommerce'),
            5 => __('Friday', 'tickets-passes-for-woocommerce'),
            6 => __('Saturday', 'tickets-passes-for-woocommerce'),
            7 => __('Sunday', 'tickets-passes-for-woocommerce'),
        );
        ?>
        <details class="row <?php echo esc_attr($aArgs['classes']); ?>">
            <summary class="timeslot-series-head">
                <span class="timeslot-flag" aria-hidden="true"></span>
                <span class="timeslot-series-toggle" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg></span>
                <span class="timeslot-series-rule"></span>
                <span class="timeslot-series-count">
                    <?php
                        if((int)$aArgs['slots'] >= 0)
                        {
                            /* translators: 1: how many timeslots the series has generated. 2: tickets sold across them. 3: total places across them. */
                            echo esc_html(sprintf(_n('%1$d timeslot · %2$d of %3$d sold', '%1$d timeslots · %2$d of %3$d sold', (int)$aArgs['slots'], 'tickets-passes-for-woocommerce'), (int)$aArgs['slots'], (int)$aArgs['used'], (int)$aArgs['places']));
                        }
                    ?>
                </span>
                <span class="timeslot-row-actions timeslot-series-actions">
                    <button type="button" class="timeslot-action-force-create<?php echo esc_attr($aArgs['id'] === '' ? ' hide' : ''); ?>" title="<?php echo esc_attr__('Force create timeslots', 'tickets-passes-for-woocommerce'); ?>" aria-label="<?php echo esc_attr__('Force create timeslots', 'tickets-passes-for-woocommerce'); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.3-5.7M20 4v4h-4"/></svg></button>
                    <button type="button" class="timeslot-action-trash timeslot-action-trash--text" title="<?php echo esc_attr__('Cancel recurring timeslot', 'tickets-passes-for-woocommerce'); ?>"><?php echo esc_html__('Cancel Series', 'tickets-passes-for-woocommerce'); ?></button>
                </span>
            </summary>

            <div class="timeslot-row-fields">
                <div class="timeslot-field">
                    <label><?php echo esc_html__('Weekday', 'tickets-passes-for-woocommerce'); ?></label>
                    <select<?php echo esc_attr($sDisabled); ?> class="timeslot-recurring-weekday" name="<?php echo esc_attr($sName . '[weekday]'); ?>">
                        <?php foreach($aWeekdays as $iWeekday => $sWeekday) { ?>
                            <option <?php selected((int)$aArgs['weekday'], $iWeekday); ?> value="<?php echo esc_attr((string)$iWeekday); ?>"><?php echo esc_html($sWeekday); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="timeslot-field">
                    <label><?php echo esc_html__('Week Start', 'tickets-passes-for-woocommerce'); ?></label>
                    <input<?php echo esc_attr($sDisabled); ?> type="number" class="timeslot-recurring-week-number-start" name="<?php echo esc_attr($sName . '[week-number-start]'); ?>" value="<?php echo esc_attr((string)(int)$aArgs['week_start']); ?>" min="1" max="52"/>
                </div>
                <div class="timeslot-field">
                    <label><?php echo esc_html__('Week End', 'tickets-passes-for-woocommerce'); ?></label>
                    <input<?php echo esc_attr($sDisabled); ?> type="number" class="timeslot-recurring-week-number-end" name="<?php echo esc_attr($sName . '[week-number-end]'); ?>" value="<?php echo esc_attr((string)(int)$aArgs['week_end']); ?>" min="1" max="52"/>
                </div>
                <div class="timeslot-field">
                    <label><?php echo esc_html__('Time Start', 'tickets-passes-for-woocommerce'); ?></label>
                    <input<?php echo esc_attr($sDisabled); ?> type="time" class="timeslot-recurring-time-start" name="<?php echo esc_attr($sName . '[time-start]'); ?>" value="<?php echo esc_attr($aArgs['time_start']); ?>"/>
                </div>
                <div class="timeslot-field">
                    <label><?php echo esc_html__('Time End', 'tickets-passes-for-woocommerce'); ?></label>
                    <input<?php echo esc_attr($sDisabled); ?> type="time" class="timeslot-recurring-time-end" name="<?php echo esc_attr($sName . '[time-end]'); ?>" value="<?php echo esc_attr($aArgs['time_end']); ?>"/>
                </div>
                <div class="timeslot-field">
                    <label><?php echo esc_html__('Quantity', 'tickets-passes-for-woocommerce'); ?></label>
                    <input<?php echo esc_attr($sDisabled); ?> type="number" class="timeslot-recurring-qty" name="<?php echo esc_attr($sName . '[qty]'); ?>" value="<?php echo esc_attr((string)(int)$aArgs['qty']); ?>" min="1"/>
                </div>
            </div>

            <input<?php echo esc_attr($sDisabled); ?> type="hidden" class="timeslot-recurring-id" name="<?php echo esc_attr($sName . '[id]'); ?>" value="<?php echo esc_attr($aArgs['id']); ?>"/>
        </details>
        <?php
    }

    /**
     * Renders the "Timeslot Settings" panel.
     *
     * Covers the per-ticket rules (max uses, how long before the slot a ticket may be scanned,
     * check-in cooldown), the optional reservation system, and the timeslot editor itself - both
     * the one-off slots and the recurring series, each series listing the slots it has generated.
     *
     * @return void
     */
    public function tpfw_wc_timeslot_ticket_settings_general_tab_content()
    {
        global $post;
        ?>
            <div id='timeslot-ticket_product_settings_general' class='panel woocommerce_options_panel'>
                <div class="tpfw-cards tpfw-cards--grid">
                    <div class="tpfw-card tpfw-card--timeslot tpfw-card--wide">
                        <div class="tpfw-card-header">
                            <svg class="tpfw-card-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h11l3 3v13H5z"/><path d="M8 10h8M8 14h5"/></svg>
                            <h3 class="tpfw-card-title"><?php echo esc_html__('Product Note', 'tickets-passes-for-woocommerce'); ?></h3>
                            <span class="tpfw-card-tag"><?php echo esc_html__('Max 350 characters', 'tickets-passes-for-woocommerce'); ?></span>
                        </div>
                        <div class="tpfw-card-body">
                            <?php
                                woocommerce_wp_textarea_input(
                                    array(
                                        'id'                => '_tpfw_timeslot_ticket_note',
                                        'label'             => __('Note', 'tickets-passes-for-woocommerce'),
                                        'value'             => get_post_meta($post->ID, '_tpfw_timeslot_ticket_note', true),
                                        'description'       => __('A short note shown on the ticket PDF, in the customer emails, on the order view and in My Account. Max 350 characters.', 'tickets-passes-for-woocommerce'),
                                        'custom_attributes' => array(
                                                            'maxlength' => '350',
                                        )
                                    )
                                );
                            ?>
                        </div>
                    </div>

                    <?php
                        $_timeslot_ticket_max_usage = get_post_meta($post->ID, '_tpfw_timeslot_ticket_max_usage', true);
                        if(empty($_timeslot_ticket_max_usage))
                        {
                            $value = 1;
                        }
                        else
                        {
                            $value = $_timeslot_ticket_max_usage;
                        }
                    ?>
                    <div class="tpfw-card tpfw-card--timeslot">
                        <div class="tpfw-card-header">
                            <svg class="tpfw-card-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l8 4v6c0 4-3.4 7.2-8 8-4.6-.8-8-4-8-8V7z"/><path d="M9 12l2 2 4-4"/></svg>
                            <h3 class="tpfw-card-title"><?php echo esc_html__('Access & Usage', 'tickets-passes-for-woocommerce'); ?></h3>
                        </div>
                        <div class="tpfw-card-body">
                            <?php
                                woocommerce_wp_text_input(
                                    array(
                                        'id'                => '_tpfw_timeslot_ticket_max_usage',
                                        'label'             => __('Max Uses', 'tickets-passes-for-woocommerce'),
                                        'type'              => 'number',
                                        'value'             => $value,
                                        'description'       => __('The maximum number of times a timeslot ticket can be checked in before further check-ins are rejected.', 'tickets-passes-for-woocommerce'),
                                        'custom_attributes' => array(
                                                            'step' => 'any',
                                                            'min'  => '1',
                                                            'max'  => '999999999999',
                                        )
                                    )
                                );

                                woocommerce_wp_checkbox( array(
                                    'id'          => '_tpfw_timeslot_show_max_uses',
                                    'label'       => __('Show On Product Page', 'tickets-passes-for-woocommerce'),
                                    'description' => __('Enable this to list the maximum number of uses in the details table on the product page.', 'tickets-passes-for-woocommerce'),
                                    'value'       => get_post_meta($post->ID, '_tpfw_timeslot_show_max_uses', true),
                                ));
                            ?>
                        </div>
                    </div>

                    <?php
                        $_timeslot_ticket_before_checkin_duration = get_post_meta($post->ID, '_tpfw_timeslot_ticket_before_checkin_duration', true);
                        if(empty($_timeslot_ticket_before_checkin_duration))
                        {
                            $iBeforeCheckin = 1;
                        }
                        else
                        {
                            $iBeforeCheckin = (int)$_timeslot_ticket_before_checkin_duration;
                        }

                        $_timeslot_ticket_cooldown_sec = get_post_meta($post->ID, '_tpfw_timeslot_ticket_cooldown_sec', true);
                        if(empty($_timeslot_ticket_cooldown_sec))
                        {
                            $iCooldown = 1;
                        }
                        else
                        {
                            $iCooldown = (int)$_timeslot_ticket_cooldown_sec;
                        }
                    ?>
                    <div class="tpfw-card tpfw-card--timeslot">
                        <div class="tpfw-card-header">
                            <svg class="tpfw-card-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/></svg>
                            <h3 class="tpfw-card-title"><?php echo esc_html__('Check-in Rules', 'tickets-passes-for-woocommerce'); ?></h3>
                        </div>
                        <div class="tpfw-card-body">
                            <?php
                                $this->oFunctions->render_duration_field(
                                    '_tpfw_timeslot_ticket_before_checkin_duration',
                                    __('Before Checkin Duration', 'tickets-passes-for-woocommerce'),
                                    __('How long before the timeslot\'s start time a ticket can be checked in.', 'tickets-passes-for-woocommerce'),
                                    $iBeforeCheckin
                                );

                                $this->oFunctions->render_duration_field(
                                    '_tpfw_timeslot_ticket_cooldown_sec',
                                    __('Timeslot Ticket Cooldown', 'tickets-passes-for-woocommerce'),
                                    __('The minimum time required between check-ins on a timeslot ticket that allows multiple uses.', 'tickets-passes-for-woocommerce'),
                                    $iCooldown
                                );
                            ?>
                        </div>
                    </div>

                    <?php
                        $_timeslot_sales_timespan_enable = get_post_meta($post->ID, '_tpfw_timeslot_sales_timespan_enable', true);
                        $_timeslot_sales_timespan_start = get_post_meta($post->ID, '_tpfw_timeslot_sales_timespan_start', true);
                        if(empty($_timeslot_sales_timespan_start))
                        {
                            $sStartValue = gmdate('Y-m-d', current_time('timestamp'));
                        }
                        else
                        {
                            $sStartValue = $_timeslot_sales_timespan_start;
                        }

                        $_timeslot_sales_timespan_end = get_post_meta($post->ID, '_tpfw_timeslot_sales_timespan_end', true);
                        if(empty($_timeslot_sales_timespan_end))
                        {
                            $sEndValue = gmdate('Y-m-d', current_time('timestamp'));
                        }
                        else
                        {
                            $sEndValue = $_timeslot_sales_timespan_end;
                    }
                    if($sEndValue < $sStartValue)
                    {
                        $sEndValue = $sStartValue;
                        }
                    ?>
                    <div class="tpfw-card tpfw-card--timeslot">
                        <div class="tpfw-card-header">
                            <svg class="tpfw-card-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8l2-4h12l2 4"/><path d="M4 8h16v12H4z"/><path d="M9 12h6"/></svg>
                            <h3 class="tpfw-card-title"><?php echo esc_html__('Sales Window', 'tickets-passes-for-woocommerce'); ?></h3>
                        </div>
                        <div class="tpfw-card-body">
                            <?php
                                woocommerce_wp_checkbox( array(
                                    'id'          => '_tpfw_timeslot_sales_timespan_enable',
                                    'label'       => __('Sales Timespan', 'tickets-passes-for-woocommerce'),
                                    'description' => __('Enable this to restrict purchasing to a specific date range - the product can only be bought while today\'s date falls within it.', 'tickets-passes-for-woocommerce'),
                                    'value'       => $_timeslot_sales_timespan_enable,
                                ));

                                woocommerce_wp_text_input(
                                    array(
                                            'id'            => '_tpfw_timeslot_sales_timespan_start',
                                            'label'         => __('Sales Timespan, Start Date', 'tickets-passes-for-woocommerce'),
                                            'description'   => __('The date from which this product can be purchased.', 'tickets-passes-for-woocommerce'),
                                            'value'         => $sStartValue,
                                            'wrapper_class' => ($_timeslot_sales_timespan_enable == 'yes' ) ? '' : 'hide',
                                            'type'          => 'date',
                                    )
                                );

                                woocommerce_wp_text_input(
                                    array(
                                            'id'            => '_tpfw_timeslot_sales_timespan_end',
                                            'label'         => __('Sales Timespan, End Date', 'tickets-passes-for-woocommerce'),
                                            'description'   => __('The date after which this product can no longer be purchased.', 'tickets-passes-for-woocommerce'),
                                            'value'         => $sEndValue,
                                            'wrapper_class' => ($_timeslot_sales_timespan_enable == 'yes' ) ? '' : 'hide',
                                            'type'          => 'date',
                                            'custom_attributes' => array('min' => $sStartValue),
                                    )
                                );

                                    woocommerce_wp_checkbox( array(
                                        'id'            => '_tpfw_timeslot_show_sales_window',
                                        'label'         => __('Show On Product Page', 'tickets-passes-for-woocommerce'),
                                        'description'   => __('Enable this to list the dates between which the ticket can be bought in the details table on the product page.', 'tickets-passes-for-woocommerce'),
                                        'value'         => get_post_meta($post->ID, '_tpfw_timeslot_show_sales_window', true),
                                        'wrapper_class' => ($_timeslot_sales_timespan_enable == 'yes' ) ? '' : 'hide',
                                    ));
                            ?>
                        </div>
                    </div>

                    <?php
                        $_timeslot_ticket_reservation_enable = get_post_meta($post->ID, '_tpfw_timeslot_ticket_reservation_enable', true);
                        $sReservationHideClass = ($_timeslot_ticket_reservation_enable == 'yes' ) ? '' : 'hide';

                        $_timeslot_ticket_reservation_duration = get_post_meta($post->ID, '_tpfw_timeslot_ticket_reservation_duration', true);
                        if(empty($_timeslot_ticket_reservation_duration))
                        {
                            $iReservationDuration = 600;
                        }
                        else
                        {
                            $iReservationDuration = (int)$_timeslot_ticket_reservation_duration;
                        }

                        $_timeslot_ticket_reservation_order_duration = get_post_meta($post->ID, '_tpfw_timeslot_ticket_reservation_order_duration', true);
                        if(empty($_timeslot_ticket_reservation_order_duration))
                        {
                            $iReservationOrderDuration = 1;
                        }
                        else
                        {
                            $iReservationOrderDuration = (int)$_timeslot_ticket_reservation_order_duration;
                        }

                        $_timeslot_ticket_reservation_status_delete = get_post_meta($post->ID, '_tpfw_timeslot_ticket_reservation_status_delete', true);
                        if(empty($_timeslot_ticket_reservation_status_delete) || !is_array($_timeslot_ticket_reservation_status_delete))
                        {
                            $aReservationStatusValue = array('on-hold', 'cancelled', 'failed', 'checkout-draft', 'pending', 'processing');
                        }
                        else
                        {
                            $aReservationStatusValue = $_timeslot_ticket_reservation_status_delete;
                        }
                        $aReservationStatusDelete = array(
                            'pending'        => __('Pending', 'tickets-passes-for-woocommerce'),
                            'processing'     => __('Processing', 'tickets-passes-for-woocommerce'),
                            'on-hold'        => __('On-Hold', 'tickets-passes-for-woocommerce'),
                            'completed'      => __('Completed', 'tickets-passes-for-woocommerce'),
                            'cancelled'      => __('Cancelled', 'tickets-passes-for-woocommerce'),
                            'refunded'       => __('Refunded', 'tickets-passes-for-woocommerce'),
                            'failed'         => __('Failed', 'tickets-passes-for-woocommerce'),
                            'checkout-draft' => __('Checkout-draft', 'tickets-passes-for-woocommerce'),
                        );

                        $_timeslot_ticket_orderline_status_delete = get_post_meta($post->ID, '_tpfw_timeslot_ticket_orderline_status_delete', true);
                        if(empty($_timeslot_ticket_orderline_status_delete) || !is_array($_timeslot_ticket_orderline_status_delete))
                        {
                            $aOrderlineStatusValue = array('on-hold', 'cancelled', 'failed', 'checkout-draft', 'pending', 'processing');
                        }
                        else
                        {
                            $aOrderlineStatusValue = $_timeslot_ticket_orderline_status_delete;
                        }
                        $aOrderlineStatusDelete = array(
                            'pending'        => __('Pending', 'tickets-passes-for-woocommerce'),
                            'processing'     => __('Processing', 'tickets-passes-for-woocommerce'),
                            'on-hold'        => __('On-Hold', 'tickets-passes-for-woocommerce'),
                            'completed'      => __('Completed', 'tickets-passes-for-woocommerce'),
                            'cancelled'      => __('Cancelled', 'tickets-passes-for-woocommerce'),
                            'refunded'       => __('Refunded', 'tickets-passes-for-woocommerce'),
                            'failed'         => __('Failed', 'tickets-passes-for-woocommerce'),
                            'checkout-draft' => __('Checkout-draft', 'tickets-passes-for-woocommerce'),
                        );
                    ?>
                    <div class="tpfw-card tpfw-card--timeslot">
                        <div class="tpfw-card-header">
                            <svg class="tpfw-card-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 4h12v16l-6-4-6 4z"/></svg>
                            <h3 class="tpfw-card-title"><?php echo esc_html__('Reservations', 'tickets-passes-for-woocommerce'); ?></h3>
                        </div>
                        <div class="tpfw-card-body">
                            <?php
                                woocommerce_wp_checkbox( array(
                                    'id'          => '_tpfw_timeslot_ticket_reservation_enable',
                                    'label'       => __('Enable Timeslot Ticket Reservations', 'tickets-passes-for-woocommerce'),
                                    'description' => __('Enabling this will temporarily reserve a timeslot\'s available spot for a customer once added to their cart, so it can\'t be sold to someone else while they checkout.', 'tickets-passes-for-woocommerce'),
                                    'value'       => $_timeslot_ticket_reservation_enable,
                                ));

                                $this->oFunctions->render_duration_field(
                                    '_tpfw_timeslot_ticket_reservation_duration',
                                    __('Reservation Duration', 'tickets-passes-for-woocommerce'),
                                    __('How long a timeslot stays reserved for a customer after being added to their cart.', 'tickets-passes-for-woocommerce'),
                                    $iReservationDuration,
                                    $sReservationHideClass
                                );

                                $this->oFunctions->render_duration_field(
                                    '_tpfw_timeslot_ticket_reservation_order_duration',
                                    __('Order Created, Reservation Duration Added', 'tickets-passes-for-woocommerce'),
                                    __('Extra time added to the reservation once an order is created at checkout but not yet paid, to allow time to complete payment.', 'tickets-passes-for-woocommerce'),
                                    $iReservationOrderDuration,
                                    $sReservationHideClass
                                );

                                woocommerce_wp_select(
                                    array(
                                        'id'                => '_tpfw_timeslot_ticket_reservation_status_delete',
                                        'name'              => '_tpfw_timeslot_ticket_reservation_status_delete[]',
                                        'label'             => __('Delete Reservation, WC Statuses', 'tickets-passes-for-woocommerce'),
                                        'description'       => __('Which order statuses cause the reservation to be deleted once its reservation time has passed.', 'tickets-passes-for-woocommerce'),
                                        'value'             => $aReservationStatusValue,
                                        'options'           => $aReservationStatusDelete,
                                        'wrapper_class'     => $sReservationHideClass,
                                        'custom_attributes' => array('multiple' => 'multiple'),
                                    )
                                );

                                woocommerce_wp_select(
                                    array(
                                        'id'                => '_tpfw_timeslot_ticket_orderline_status_delete',
                                        'name'              => '_tpfw_timeslot_ticket_orderline_status_delete[]',
                                        'label'             => __('Delete Orderline, WC Statuses', 'tickets-passes-for-woocommerce'),
                                        'description'       => __('Which order statuses cause the timeslot ticket to be removed from the order once its reservation time has passed.', 'tickets-passes-for-woocommerce'),
                                        'value'             => $aOrderlineStatusValue,
                                        'options'           => $aOrderlineStatusDelete,
                                        'wrapper_class'     => $sReservationHideClass,
                                        'custom_attributes' => array('multiple' => 'multiple'),
                                    )
                                );
                            ?>
                        </div>
                    </div>

                    <?php
                        $_timeslot_ticket_recurring_enable = get_post_meta($post->ID, '_tpfw_timeslot_ticket_recurring_enable', true);

                        $_timeslot_ticket_recurring_future = get_post_meta($post->ID, '_tpfw_timeslot_ticket_recurring_future', true);
                        if(empty($_timeslot_ticket_recurring_future))
                        {
                            $value = 2;
                        }
                        else
                        {
                            $value = $_timeslot_ticket_recurring_future;
                        }
                    ?>
                    <div class="tpfw-card tpfw-card--timeslot">
                        <div class="tpfw-card-header">
                            <svg class="tpfw-card-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 0 1 13.6-5.7L20 8"/><path d="M20 4v4h-4"/><path d="M20 12a8 8 0 0 1-13.6 5.7L4 16"/><path d="M4 20v-4h4"/></svg>
                            <h3 class="tpfw-card-title"><?php echo esc_html__('Recurring', 'tickets-passes-for-woocommerce'); ?></h3>
                        </div>
                        <div class="tpfw-card-body">
                            <?php
                                woocommerce_wp_checkbox( array(
                                    'id'            => '_tpfw_timeslot_ticket_recurring_enable',
                                    'label'         => __('Enable Recurring Timeslot Tickets', 'tickets-passes-for-woocommerce'),
                                    'description'   => __('Enabling this lets you define a weekly recurring pattern instead of adding individual timeslots one by one - future timeslots are then generated automatically from that pattern.', 'tickets-passes-for-woocommerce'),
                                    'value'         => $_timeslot_ticket_recurring_enable,
                                ));

                                woocommerce_wp_text_input(
                                    array(
                                        'id'                => '_tpfw_timeslot_ticket_recurring_future',
                                        'label'             => __('Recurring Future', 'tickets-passes-for-woocommerce'),
                                        'type'              => 'number',
                                        'description'       => __('How many weeks ahead of today timeslots should automatically be generated for.', 'tickets-passes-for-woocommerce'),
                                        'value'             => $value,
                                        'wrapper_class'     => ($_timeslot_ticket_recurring_enable == 'yes' ) ? '' : 'hide',
                                        'custom_attributes' => array(
                                                                        'step' => 'any',
                                                                        'min'  => '2',
                                                                        'max'  => '999999999',
                                                                    )
                                    )
                                );
                            ?>
                        </div>
                    </div>

                    <?php
                        // Depends only on the recurring toggle, not on the row query below - computed
                        // here (rather than inside the query branches further down) so the "Add" buttons
                        // can render in the card header, above the row list that used to set these.
                        $sTimeslotCSS       = ($_timeslot_ticket_recurring_enable == 'yes') ? 'display: none;' : 'display: flex;';
                        $sTimeslotRecurrCSS = ($_timeslot_ticket_recurring_enable == 'yes') ? 'display: flex;' : 'display: none;';
                    ?>
                    <div class="tpfw-card tpfw-card--timeslot tpfw-card--wide">
                        <div class="tpfw-card-header">
                            <svg class="tpfw-card-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h10M4 18h6"/><circle cx="18" cy="17" r="3.5"/><path d="M18 15.3V17l1.2.8"/></svg>
                            <h3 class="tpfw-card-title"><?php echo esc_html__('Timeslots', 'tickets-passes-for-woocommerce'); ?></h3>
                            <span class="tpfw-card-tag timeslot-card-tag" hidden></span>
                            <input type="button" class="timeslot-action-add button button-primary" style="<?php echo esc_attr($sTimeslotCSS); ?>" value="<?php echo esc_attr__('Add Timeslot', 'tickets-passes-for-woocommerce'); ?>">
                            <input type="button" class="timeslot-recurring-action-add button button-primary" style="<?php echo esc_attr($sTimeslotRecurrCSS); ?>" value="<?php echo esc_attr__('Add Recurring Timeslot', 'tickets-passes-for-woocommerce'); ?>">
                        </div>

                        <?php
                            // Only the one-off list is a schedule long enough to need filtering: a
                            // recurring product holds a handful of rules, not a season of dates.
                            if($_timeslot_ticket_recurring_enable != 'yes')
                            {
                        ?>
                            <div class="timeslot-toolbar">
                                <span class="timeslot-filter">
                                    <button type="button" class="timeslot-filter-btn" data-timeslot-filter="past" aria-pressed="false"><?php echo esc_html__('Past', 'tickets-passes-for-woocommerce'); ?></button>
                                    <button type="button" class="timeslot-filter-btn" data-timeslot-filter="upcoming" aria-pressed="true"><?php echo esc_html__('Upcoming', 'tickets-passes-for-woocommerce'); ?></button>
                                    <button type="button" class="timeslot-filter-btn" data-timeslot-filter="all" aria-pressed="false"><?php echo esc_html__('All', 'tickets-passes-for-woocommerce'); ?></button>
                                </span>
                                <span class="timeslot-toolbar-count"></span>
                            </div>
                        <?php
                            }
                        ?>

                        <div class="timeslot-template-row-wrapper hide">
                            <?php
                                $this->render_timeslot_row(array('classes' => 'timeslot-row-template recurring-timeslot-row-child template', 'disabled' => true));
                                $this->render_timeslot_series_row(array('classes' => 'timeslot-row-recurring-template template parent', 'disabled' => true));
                            ?>
                        </div>

                        <div class="timeslot-row-wrapper">
                        <?php
                            global $wpdb;
                            if($_timeslot_ticket_recurring_enable == 'yes')
                            {
                                $sRecurringTimeslotsTableName = $wpdb->prefix.'tpfw_timeslots_recurring';
                                $sRecurringTimeslotsPrepared  = $wpdb->prepare(
                                    'SELECT * FROM %i WHERE product_id = %s AND deleted is NULL ORDER BY start ASC;', $sRecurringTimeslotsTableName,
                                    $post->ID
                                );
                                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sRecurringTimeslotsPrepared is the return value of $wpdb->prepare() above.
                                $aRecurringTimeslots = $wpdb->get_results($sRecurringTimeslotsPrepared);

                                if(isset($aRecurringTimeslots) && !empty($aRecurringTimeslots) && is_array($aRecurringTimeslots))
                                {
                                    // Each series' header reads back what it has produced so far, which is the
                                    // one thing the six fields cannot tell the shop owner. Batched into two
                                    // grouped queries rather than two per series.
                                    $aRecurringIds     = wp_list_pluck($aRecurringTimeslots, 'id');
                                    $aSeriesSlotCounts = array();
                                    $aSeriesUsedCounts = array();
                                    if(!empty($aRecurringIds))
                                    {
                                        $sRecurringIdsPlaceholders = implode(', ', array_fill(0, count($aRecurringIds), '%s'));

                                        $sSeriesSlotsPrepared = $wpdb->prepare(
                                            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sRecurringIdsPlaceholders is a "%s, %s, ..." scaffold, not user data; every value is bound via the array_merge() below.
                                            "SELECT timeslot_recurring_id_fk, COUNT(*) as slot_count, SUM(available_slots) as place_count FROM %i WHERE deleted is NULL AND timeslot_recurring_id_fk IN ($sRecurringIdsPlaceholders) GROUP BY timeslot_recurring_id_fk;",
                                            array_merge(array($wpdb->prefix . 'tpfw_timeslots'), $aRecurringIds)
                                        );
                                        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sSeriesSlotsPrepared is the return value of $wpdb->prepare() above.
                                        $aSeriesSlotCounts = $wpdb->get_results($sSeriesSlotsPrepared, OBJECT_K);

                                        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the replacements are one array_merge() call, which the sniff cannot count through, so it only sees the two %i and none of the ids; the count is right at runtime.
                                        $sSeriesUsedPrepared = $wpdb->prepare(
                                            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sRecurringIdsPlaceholders is a "%s, %s, ..." scaffold, not user data; every value is bound via the array_merge() below.
                                            "SELECT s.timeslot_recurring_id_fk as rid, COUNT(*) as used_count FROM %i t INNER JOIN %i s ON s.id = t.timeslot_id WHERE t.deleted is NULL AND s.deleted is NULL AND s.timeslot_recurring_id_fk IN ($sRecurringIdsPlaceholders) GROUP BY s.timeslot_recurring_id_fk;",
                                            array_merge(array($wpdb->prefix . 'tpfw_timeslot_tickets', $wpdb->prefix . 'tpfw_timeslots'), $aRecurringIds)
                                        );
                                        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sSeriesUsedPrepared is the return value of $wpdb->prepare() above.
                                        $aSeriesUsedCounts = wp_list_pluck($wpdb->get_results($sSeriesUsedPrepared), 'used_count', 'rid');
                                    }

                                    foreach($aRecurringTimeslots as $iRecTimeslotKey => $oRecTimeslot)
                                    {
                                        if(!isset($oRecTimeslot->id) || empty($oRecTimeslot->id) || $oRecTimeslot->id == "") continue;

                                        $oSeriesSlots = isset($aSeriesSlotCounts[$oRecTimeslot->id]) ? $aSeriesSlotCounts[$oRecTimeslot->id] : null;

                                        $this->render_timeslot_series_row(array
                                        (
                                            'index'      => (int)$iRecTimeslotKey + 1,
                                            'id'         => $oRecTimeslot->id,
                                            'weekday'    => (int)$oRecTimeslot->weekday,
                                            'week_start' => (int)$oRecTimeslot->week_number_start,
                                            'week_end'   => (int)$oRecTimeslot->week_number_end,
                                            'time_start' => $oRecTimeslot->slot_start,
                                            'time_end'   => $oRecTimeslot->slot_end,
                                            'qty'        => (int)$oRecTimeslot->available_slots,
                                            'slots'      => ($oSeriesSlots !== null) ? (int)$oSeriesSlots->slot_count : 0,
                                            'places'     => ($oSeriesSlots !== null) ? (int)$oSeriesSlots->place_count : 0,
                                            'used'       => isset($aSeriesUsedCounts[$oRecTimeslot->id]) ? (int)$aSeriesUsedCounts[$oRecTimeslot->id] : 0,
                                        ));
                                    }
                                }
                            }
                            else
                            {
                                $sTimeslotsPrepared = $wpdb->prepare(
                                    'SELECT * FROM %i WHERE product_id = %s AND deleted is NULL AND timeslot_recurring_id_fk IS NULL ORDER BY start ASC;', $wpdb->prefix . 'tpfw_timeslots',
                                    $post->ID
                                );

                                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sTimeslotsPrepared is the return value of $wpdb->prepare() above.
                                $aTimeslots         = $wpdb->get_results($sTimeslotsPrepared);
                                if(isset($aTimeslots) && !empty($aTimeslots) && is_array($aTimeslots))
                                {
                                    // Batch the per-timeslot "used" count into one grouped query instead of
                                    // one SELECT per row - avoids N+1 queries on product-edit pages with many timeslots.
                                    $aTimeslotIds       = wp_list_pluck($aTimeslots, 'id');
                                    $aTimeslotUsedCounts = array();
                                    if(!empty($aTimeslotIds))
                                    {
                                        $sTimeslotIdsPlaceholders = implode(', ', array_fill(0, count($aTimeslotIds), '%s'));
                                        $sTimeslotUsedCountsPrepared = $wpdb->prepare(
                                            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sTimeslotIdsPlaceholders is a "%s, %s, ..." scaffold, not user data; every value is bound via the array_merge() below.
                                            "SELECT timeslot_id, COUNT(*) as used_count FROM %i WHERE deleted is NULL AND timeslot_id IN ($sTimeslotIdsPlaceholders) GROUP BY timeslot_id;",
                                            array_merge(array($wpdb->prefix . 'tpfw_timeslot_tickets'), $aTimeslotIds)
                                        );
                                        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sTimeslotUsedCountsPrepared is the return value of $wpdb->prepare() above.
                                        $aTimeslotUsedCounts = wp_list_pluck($wpdb->get_results($sTimeslotUsedCountsPrepared), 'used_count', 'timeslot_id');
                                    }

                                    foreach($aTimeslots as $iTimeslotKey => $oTimeslot)
                                    {
                                        if(!isset($oTimeslot->id) || empty($oTimeslot->id) || $oTimeslot->id == "") continue;

                                        $this->render_timeslot_row(array
                                        (
                                            'index' => (int)$iTimeslotKey + 1,
                                            'id'    => $oTimeslot->id,
                                            'start' => $oTimeslot->start,
                                            'end'   => $oTimeslot->end,
                                            'qty'   => (int)$oTimeslot->available_slots,
                                            'used'  => isset($aTimeslotUsedCounts[$oTimeslot->id]) ? (int)$aTimeslotUsedCounts[$oTimeslot->id] : 0,
                                        ));
                                    }
                                }
                            }
                        ?>
                        </div>

                        <?php
                            // Shown and hidden by the admin script, which is the only side that knows
                            // whether a row has just been added or the filter has emptied the list.
                        ?>
                        <div class="timeslot-empty" hidden>
                            <p class="timeslot-empty-title"><?php echo esc_html__('No timeslots yet', 'tickets-passes-for-woocommerce'); ?></p>
                            <p class="timeslot-empty-text">
                                <?php
                                    if($_timeslot_ticket_recurring_enable == 'yes')
                                    {
                                        echo esc_html__('Add a recurring timeslot to generate a weekly pattern of timeslots.', 'tickets-passes-for-woocommerce');
                                    }
                                    else
                                    {
                                        echo esc_html__('Add a timeslot, or turn on "Enable Recurring Timeslot Tickets" above to generate a weekly pattern instead.', 'tickets-passes-for-woocommerce');
                                    }
                                ?>
                            </p>
                        </div>

                        <div class="timeslot-foot" hidden>
                            <span class="timeslot-foot-summary"></span>
                            <span class="timeslot-foot-note"></span>
                        </div>
                    </div>
                </div>
            </div>
        <?php
    }
 

    /**
     * Persists the "Timeslot Settings" panel, including the whole timeslot table.
     *
     * Every posted row is upserted (ON DUPLICATE KEY UPDATE) so editing an existing slot keeps its
     * id - and therefore the tickets already issued against it. New rows get a fresh nano id.
     * Rows are skipped rather than rejected when they fail a sanity check: zero quantity, an end
     * before the start, or - for new rows only - a start already in the past, mirroring the min-date
     * the admin JS enforces for anyone posting directly.
     *
     * @param int $post_id Product id being saved.
     * @return void
     */
    public function tpfw_wc_timeslot_settings_tab_save($post_id)
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
        $_timeslots                          = map_deep(wp_unslash($_POST['timeslots'] ?? array()), 'sanitize_text_field');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
        $_timeslot_ticket_note               = mb_substr(sanitize_textarea_field(wp_unslash($_POST['_tpfw_timeslot_ticket_note'] ?? '')), 0, 350);
        if($_timeslot_ticket_note != '')
        {
            update_post_meta($post_id, '_tpfw_timeslot_ticket_note', $_timeslot_ticket_note);
        }
        else
        {
            delete_post_meta($post_id, '_tpfw_timeslot_ticket_note');
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
        $_timeslot_ticket_recurring_enable   = sanitize_text_field(wp_unslash($_POST['_tpfw_timeslot_ticket_recurring_enable'] ?? ''));        
        
        global $wpdb;
        $sCurrentDatetime             = current_time('mysql');        
        $sTimeslotTableName           = $wpdb->prefix.'tpfw_timeslots';
        $sRecurringTimeslotsTableName = $wpdb->prefix.'tpfw_timeslots_recurring';

        if(!empty($_timeslots) && is_array($_timeslots)) 
        {
            foreach($_timeslots as $iTimeslotKey => $oTimeslot)
            {                                                        
                if((int)$oTimeslot['qty'] <= 0) { continue; }
                $bIsNewTimeslot = (!isset($oTimeslot['id']) || empty($oTimeslot['id']) || $oTimeslot['id'] == "");
                if($bIsNewTimeslot)
                {
                    $sTempTimeslotID                 = $this->oFunctions->generateNanoId();
                    $_timeslots[$iTimeslotKey]['id'] = $sTempTimeslotID;
                    $oTimeslot['id']                 = $sTempTimeslotID;
                }
                
                if($_timeslot_ticket_recurring_enable == 'yes' && isset($oTimeslot['week-number-start']) && isset($oTimeslot['week-number-end'])) 
                {   
                    $iRecTimeslotWeekStart = $oTimeslot['week-number-start'];
                    $iRecTimeslotWeekEnd   = $oTimeslot['week-number-end'];
                    $iWeekDay              = (int) $oTimeslot['weekday'];
                    // Week numbers are ISO-8601 weeks of the current year (what every calendar
                    // shows), resolved with setISODate() so "week 34, Monday" lands on the
                    // Monday of ISO week 34. The old "Jan 1 + N*7 + weekday - 3 days" arithmetic
                    // was off by up to three days depending on which weekday the year started.
                    $fnWeekdayOfWeek       = function($iWeekNumber, $sYear) use ($iWeekDay)
                    {
                        $oDate = new DateTime('now', wp_timezone());
                        $oDate->setISODate((int) $sYear, (int) $iWeekNumber, max(1, min(7, $iWeekDay)));
                        return $oDate->format('Y-m-d');
                    };

                    $sCurrentYear          = gmdate('Y', current_time('timestamp'));
                    $sRecStart             = $fnWeekdayOfWeek($iRecTimeslotWeekStart, $sCurrentYear) . ' ' . $oTimeslot['time-start'];
                    $sRecEnd               = $fnWeekdayOfWeek($iRecTimeslotWeekEnd, $sCurrentYear) . ' ' . $oTimeslot['time-start'];
                    // A run is only stale once its LAST week has gone. Rolling as soon as the
                    // first week had passed threw a series that began earlier this month a full
                    // year forward, so every week of it still to come vanished off the calendar.
                    if(strtotime($sRecEnd) < strtotime(current_time('mysql')))
                    {
                        $sCurrentYear = (string)((int)$sCurrentYear + 1);
                        $sRecStart    = $fnWeekdayOfWeek($iRecTimeslotWeekStart, $sCurrentYear) . ' ' . $oTimeslot['time-start'];
                        $sRecEnd      = $fnWeekdayOfWeek($iRecTimeslotWeekEnd, $sCurrentYear) . ' ' . $oTimeslot['time-start'];
                    }
                    $sTimeStart            = $oTimeslot['time-start'];
                    $sTimeEnd              = $oTimeslot['time-end'];

                    if(!isset($iRecTimeslotWeekStart) || empty($iRecTimeslotWeekStart)) continue;
                    if(!isset($iRecTimeslotWeekEnd) || empty($iRecTimeslotWeekEnd)) continue;
                    // Equal weeks are a one-week series and must be allowed; only a reversed range is rejected.
                    if((int) $iRecTimeslotWeekStart > (int) $iRecTimeslotWeekEnd) continue;
                    if(!isset($iWeekDay) || empty($iWeekDay)) continue;
                    // Compared a timestamp against the raw date string, which PHP 8 evaluates as a
                    // string comparison - an end date before the start date passed straight through.
                    if(strtotime($sRecStart) >= strtotime($sRecEnd)) continue;
                    if(str_replace(':', '', $sTimeStart) >= str_replace(':', '', $sTimeEnd)) continue;

                    // %i for the table name and unquoted placeholders, like every other query in
                    // this file - prepare() adds its own quoting, and the interpolated table name
                    // plus "%s" pairs made WordPress emit a _doing_it_wrong on every save.
                    $sSQL   = '     INSERT INTO %i (id, start, end, slot_start, slot_end, weekday, week_number_start, week_number_end, available_slots, product_id, user_id, created, updated)
                                    VALUES (%s, %s, %s, %s, %s, %d, %d, %d, %d, %d, %d, %s, %s)
                                    ON DUPLICATE KEY UPDATE
                                    start=%s, end=%s, slot_start=%s, slot_end=%s, weekday=%d, week_number_start=%d, week_number_end=%d, available_slots=%d, updated=%s;';

                    $sCreateRecTimeslotPrepared = $wpdb->prepare(
                        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query text is assembled from literal fragments only; every value goes through prepare().
                        $sSQL,
                        array(
                            $sRecurringTimeslotsTableName,
                            //insert
                            $oTimeslot['id'],
                            $sRecStart,
                            $sRecEnd,
                            $sTimeStart,
                            $sTimeEnd,
                            $iWeekDay,
                            $iRecTimeslotWeekStart,  
                            $iRecTimeslotWeekEnd,
                            $oTimeslot['qty'],
                            $post_id,
                            get_current_user_id(),                            
                            $sCurrentDatetime, 
                            $sCurrentDatetime,
    
                            //update
                            $sRecStart,
                            $sRecEnd,
                            $sTimeStart,
                            $sTimeEnd,
                            $iWeekDay,
                            $iRecTimeslotWeekStart,  
                            $iRecTimeslotWeekEnd,
                            $oTimeslot['qty'],                            
                            $sCurrentDatetime,                
                        )            
                    );        
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sCreateRecTimeslotPrepared is the return value of $wpdb->prepare() above.
                    $wpdb->query($sCreateRecTimeslotPrepared);                                                                                                           
                } 
                else if(isset($oTimeslot['start']) && !empty($oTimeslot['start']))
                {
                    if(strtotime($oTimeslot['start']) > strtotime($oTimeslot['end'])) { continue; }
                    // Mirrors the admin JS's min-date restriction server-side, for anyone bypassing
                    // it via devtools/direct POST - only applies to genuinely new rows, so existing
                    // timeslots that are now in the past (already happened) still save unchanged.
                    if($bIsNewTimeslot && strtotime($oTimeslot['start']) < strtotime($sCurrentDatetime)) { continue; }
                    if(isset($oTimeslot['recurring-id']) && !empty($oTimeslot['recurring-id']) && $oTimeslot['recurring-id'] != "")
                    {
                        $sCreateTimeslotPrepared = $wpdb->prepare(
                            '     INSERT INTO %i (id, product_id, user_id, start, end, available_slots, manual, timeslot_recurring_id_fk, created, updated)                     
                                                         VALUES (%s, %d, %d, %s, %s, %d, 1, %s, %s, %s)
                                                         ON DUPLICATE KEY UPDATE
                                                         start=%s, end=%s, available_slots=%d, updated=%s;',
                            array(
				$sTimeslotTableName, 
                                //insert
                                $oTimeslot['id'],
                                $post_id,
                                get_current_user_id(),  
                                $oTimeslot['start'], 
                                $oTimeslot['end'],
                                $oTimeslot['qty'],
                                $oTimeslot['recurring-id'],
                                $sCurrentDatetime, 
                                $sCurrentDatetime,
        
                                //update
                                $oTimeslot['start'], 
                                $oTimeslot['end'],
                                $oTimeslot['qty'],                        
                                $sCurrentDatetime,                
                            )            
                        );        
                        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sCreateTimeslotPrepared is the return value of $wpdb->prepare() above.
                        $wpdb->query($sCreateTimeslotPrepared); 
                    }                              
                    else
                    {
                        $sCreateTimeslotPrepared = $wpdb->prepare(
                            '     INSERT INTO %i (id, product_id, user_id, start, end, available_slots, manual, created, updated)                     
                                                         VALUES (%s, %d, %d, %s, %s, %d, 1, %s, %s)
                                                         ON DUPLICATE KEY UPDATE
                                                         start=%s, end=%s, available_slots=%d, updated=%s;',
                            array(
				$sTimeslotTableName, 
                                //insert
                                $oTimeslot['id'],
                                $post_id,
                                get_current_user_id(),  
                                $oTimeslot['start'], 
                                $oTimeslot['end'],
                                $oTimeslot['qty'],
                                $sCurrentDatetime, 
                                $sCurrentDatetime,
        
                                //update
                                $oTimeslot['start'], 
                                $oTimeslot['end'],
                                $oTimeslot['qty'],                        
                                $sCurrentDatetime,                
                            )            
                        );        
                        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sCreateTimeslotPrepared is the return value of $wpdb->prepare() above.
                        $wpdb->query($sCreateTimeslotPrepared);        
                    }        
                }                
            }               
        }        

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
        $_timeslot_ticket_before_checkin_duration = sanitize_text_field(wp_unslash($_POST['_tpfw_timeslot_ticket_before_checkin_duration'] ?? ''));
        if(!empty($_timeslot_ticket_before_checkin_duration)) 
        {
            update_post_meta($post_id, '_tpfw_timeslot_ticket_before_checkin_duration', $_timeslot_ticket_before_checkin_duration);
        }
        
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
        $_timeslot_ticket_cooldown_sec = sanitize_text_field(wp_unslash($_POST['_tpfw_timeslot_ticket_cooldown_sec'] ?? ''));
        if(!empty($_timeslot_ticket_cooldown_sec)) 
        {
            update_post_meta($post_id, '_tpfw_timeslot_ticket_cooldown_sec', $_timeslot_ticket_cooldown_sec);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
        $_timeslot_ticket_max_usage = sanitize_text_field(wp_unslash($_POST['_tpfw_timeslot_ticket_max_usage'] ?? ''));
        // Guarded on the cooldown field, not on itself - max usage silently refused to save on
        // any product where the cooldown had been left blank.
        if(!empty($_timeslot_ticket_max_usage))
        {
            update_post_meta($post_id, '_tpfw_timeslot_ticket_max_usage', $_timeslot_ticket_max_usage);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
        $_timeslot_ticket_reservation_enable = sanitize_text_field(wp_unslash($_POST['_tpfw_timeslot_ticket_reservation_enable'] ?? ''));
        update_post_meta($post_id, '_tpfw_timeslot_ticket_reservation_enable', $_timeslot_ticket_reservation_enable);
        if($_timeslot_ticket_reservation_enable == 'yes')
        {            
            
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
            $_timeslot_ticket_reservation_order_duration = sanitize_text_field(wp_unslash($_POST['_tpfw_timeslot_ticket_reservation_order_duration'] ?? ''));
            // Both selects post an array (name="...[]"). Each entry is validated against the
            // statuses the select offers; sanitize_text_field() on the array used to return ''
            // so the admin's choice was never stored and the cron always fell back to defaults.
            $aAllowedStatuses = array('pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft');
            $fnStatusList     = function($mRaw) use ($aAllowedStatuses)
            {
                $aRaw = array_map('sanitize_key', (array) wp_unslash($mRaw));
                return array_values(array_intersect($aRaw, $aAllowedStatuses));
            };
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce verifies woocommerce_meta_nonce before firing this hook; $fnStatusList() unslashes, sanitizes and safelists every entry.
            $_timeslot_ticket_orderline_status_delete    = $fnStatusList($_POST['_tpfw_timeslot_ticket_orderline_status_delete'] ?? array());
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above; $fnStatusList() unslashes, sanitizes and safelists every entry.
            $_timeslot_ticket_reservation_status_delete  = $fnStatusList($_POST['_tpfw_timeslot_ticket_reservation_status_delete'] ?? array());
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
            $_timeslot_ticket_reservation_duration       = sanitize_text_field(wp_unslash($_POST['_tpfw_timeslot_ticket_reservation_duration'] ?? ''));
            if(!empty($_timeslot_ticket_reservation_duration))
            {
                update_post_meta($post_id, '_tpfw_timeslot_ticket_reservation_duration', $_timeslot_ticket_reservation_duration);
                update_post_meta($post_id, '_tpfw_timeslot_ticket_reservation_order_duration', $_timeslot_ticket_reservation_order_duration);
                update_post_meta($post_id, '_tpfw_timeslot_ticket_orderline_status_delete', $_timeslot_ticket_orderline_status_delete);
                update_post_meta($post_id, '_tpfw_timeslot_ticket_reservation_status_delete', $_timeslot_ticket_reservation_status_delete);
            }            
        }
        else
        {                                
            update_post_meta($post_id, '_tpfw_timeslot_ticket_orderline_status_delete', array());
            update_post_meta($post_id, '_tpfw_timeslot_ticket_reservation_status_delete', array());
            delete_post_meta($post_id, '_tpfw_timeslot_ticket_reservation_duration');
            delete_post_meta($post_id, '_tpfw_timeslot_ticket_reservation_order_duration');
        }

        update_post_meta($post_id, '_tpfw_timeslot_ticket_recurring_enable', $_timeslot_ticket_recurring_enable);
        if($_timeslot_ticket_recurring_enable == 'yes')
        {                
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
            $_timeslot_ticket_recurring_future  = sanitize_text_field(wp_unslash($_POST['_tpfw_timeslot_ticket_recurring_future'] ?? ''));            
            update_post_meta($post_id, '_tpfw_timeslot_ticket_recurring_future', $_timeslot_ticket_recurring_future);
        }
        else
        {            
            delete_post_meta($post_id, '_tpfw_timeslot_ticket_recurring_future');             
        }
    }



    /**
     * Maps the 'tpfw-timeslot-ticket' product type onto the TPFW_Product_Timeslot_Ticket class.
     *
     * @param string $classname         Class WooCommerce resolved for this product.
     * @param string $product_type      Product type slug.
     * @param string $product_variation Post type ('product' or 'product_variation').
     * @return string Class name to instantiate.
     */
    public function tpfw_woocommerce_timeslot_ticket_product_class( $classname, $product_type, $product_variation ) 
    {      
        // See the note in TPFW_Ticket_WC_Product: WooCommerce cannot derive a prefixed class name
        // from the type slug, so without this the product loads as WC_Product_Simple.
        if($product_type == 'tpfw-timeslot-ticket' && $product_variation == 'product')
        {
            return 'TPFW_Product_Timeslot_Ticket';
        }
        return $classname;
    }
    

    
    /**
     * Adds "Timeslot Ticket Product" to the product type dropdown in the product data box.
     *
     * @param array $types Existing product types, keyed by slug.
     * @return array Types with 'tpfw-timeslot-ticket' added.
     */
    public function tpfw_add_timeslot_ticket_tpfw_product_type($types)
    {
        $types['tpfw-timeslot-ticket']     = __('Timeslot Ticket Product', 'tickets-passes-for-woocommerce');
        return $types;
    }

    /**
     * Renders the add-to-cart form for a timeslot ticket, honouring the optional sales window.
     *
     * The customer picks a date and then a slot; only slots with capacity left after subtracting
     * issued tickets and live reservations are offered. Returning early outside the sales window
     * is what removes the buy button - the window is also enforced in the add-to-cart validation.
     *
     * @return void
     */
    public function tpfw_woocommerce_timeslot_ticket_add_to_cart() 
    {
        global $post;
        if (is_product() && is_singular('product')) 
        {            
            $oProduct = wc_get_product($post->ID);
            if (is_a($oProduct, 'TPFW_Product_Timeslot_Ticket')) 
            {
                global $wpdb;
                // $_timeslots     = get_post_meta($oProduct->get_id(), '_tpfw_timeslots', true);
                
                $sTimeslotsPrepared = $wpdb->prepare(
                    'SELECT * FROM %i WHERE product_id = %s AND deleted is NULL AND start >= %s ORDER BY start ASC;',
                    array(
				$wpdb->prefix . 'tpfw_timeslots',                 
                        $post->ID,
                        current_time('mysql'),
                    )            
                );
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sTimeslotsPrepared is the return value of $wpdb->prepare() above.
                $aValidTimeslots = $wpdb->get_results($sTimeslotsPrepared);
                if(empty($aValidTimeslots))
                {
                    // Say so: with no picker and no buy button the page otherwise just looks broken.
                    echo '<p class="tpfw-timeslot-empty">' . esc_html__('There are no upcoming timeslots for this product right now. Please check back later.', 'tickets-passes-for-woocommerce') . '</p>';
                    return;
                }

                $bShowAddToCart = false;
                // Populated below, per timeslot, only once its remaining quantity is known -
                // otherwise a date whose slots are all sold out would still be pickable.
                $availableDates    = [];
                $sDateTimeFormat   = $this->oFunctions->get_datetime_format('date');
                $sDateTimeFormatJS = $this->oFunctions->get_datepicker_format();

                $aGeneralSettings = get_option('tpfw_general_settings_options');
                $sColorAccent     = !empty($aGeneralSettings['sTimeslotColorAccent'])     ? $aGeneralSettings['sTimeslotColorAccent']     : '#000000';
                $sColorText       = !empty($aGeneralSettings['sTimeslotColorText'])       ? $aGeneralSettings['sTimeslotColorText']       : '#000000';
                $sColorBorder     = !empty($aGeneralSettings['sTimeslotColorBorder'])     ? $aGeneralSettings['sTimeslotColorBorder']     : '#000000';
                $sColorBackground = !empty($aGeneralSettings['sTimeslotColorBackground']) ? $aGeneralSettings['sTimeslotColorBackground'] : '#ffffff';
                $sColorHint       = !empty($aGeneralSettings['sTimeslotColorHint'])       ? $aGeneralSettings['sTimeslotColorHint']       : '#646970';
                $sColorDayName    = !empty($aGeneralSettings['sTimeslotColorDayName'])    ? $aGeneralSettings['sTimeslotColorDayName']    : '#b45309';
                $sColorNavTitle   = !empty($aGeneralSettings['sTimeslotColorNavTitle'])   ? $aGeneralSettings['sTimeslotColorNavTitle']   : '#000000';

                $sTimeslotHintText = __('Pick an available date below, then choose a time.', 'tickets-passes-for-woocommerce');
                if(!empty($aGeneralSettings['sTimeslotHintText']))
                {
                    $sTimeslotHintText = $aGeneralSettings['sTimeslotHintText'];
                }

                // Toward the card's own background, not white: on a dark card a white-mixed
                // tint read as a pale slab behind the selected time.
                $sColorAccentSoft    = $this->oFunctions->mix_hex_colors($sColorAccent, $sColorBackground, 0.85);
                $sColorAccentHover   = $this->oFunctions->mix_hex_colors($sColorAccent, '#000000', 0.15);
                $sColorDayNameHover  = $this->oFunctions->mix_hex_colors($sColorDayName, '#000000', 0.15);
                $sColorAccentFg      = $this->oFunctions->get_contrast_text_color($sColorAccent);
                $sColorStyle = sprintf(
                    '--tpfw-accent:%1$s;--tpfw-ink:%2$s;--tpfw-border:%3$s;--tpfw-bg:%4$s;--tpfw-hint:%5$s;--tpfw-accent-soft:%6$s;--tpfw-timeslot-accent:%1$s;--tpfw-timeslot-accent-hover:%7$s;--tpfw-timeslot-dayname:%8$s;--tpfw-timeslot-dayname-hover:%9$s;--tpfw-timeslot-navtitle:%10$s;--tpfw-timeslot-accent-fg:%11$s;',
                    esc_attr($sColorAccent),
                    esc_attr($sColorText),
                    esc_attr($sColorBorder),
                    esc_attr($sColorBackground),
                    esc_attr($sColorHint),
                    esc_attr($sColorAccentSoft),
                    esc_attr($sColorAccentHover),
                    esc_attr($sColorDayName),
                    esc_attr($sColorDayNameHover),
                    esc_attr($sColorNavTitle),
                    esc_attr($sColorAccentFg)
                );
                ?>

                <div class="tpfw-timeslot-picker" style="<?php echo esc_attr($sColorStyle); ?>">
                    <div class="tpfw-timeslot-picker-head">
                        <div class="tpfw-timeslot-picker-label">
                            <label for="timeslot-date-picker"><?php echo esc_html__('Select a Date', 'tickets-passes-for-woocommerce'); ?></label>
                            <p class="tpfw-timeslot-picker-hint"><?php echo esc_html($sTimeslotHintText); ?></p>
                        </div>
                        <?php
                        // Mirrors WooCommerce's own quantity input rather than replacing it - that field
                        // is still what add-to-cart posts, this just puts a control where the customer is
                        // already looking. Starts hidden and the frontend script only reveals it once it
                        // has found a quantity field to drive, so a "sold individually" product or a theme
                        // without that field never shows a stepper that does nothing.
                        ?>
                        <div class="tpfw-timeslot-stepper" hidden>
                            <button type="button" data-tpfw-step="-1" aria-label="<?php echo esc_attr__('One fewer ticket', 'tickets-passes-for-woocommerce'); ?>">&minus;</button>
                            <span class="tpfw-timeslot-stepper-value" aria-live="polite" data-tpfw-one="<?php /* translators: %d: number of tickets. */ echo esc_attr__('%d ticket', 'tickets-passes-for-woocommerce'); ?>" data-tpfw-many="<?php /* translators: %d: number of tickets. */ echo esc_attr__('%d tickets', 'tickets-passes-for-woocommerce'); ?>"></span>
                            <button type="button" data-tpfw-step="1" aria-label="<?php echo esc_attr__('One more ticket', 'tickets-passes-for-woocommerce'); ?>">+</button>
                        </div>
                    </div>

                    <div class="tpfw-timeslot-calendar">
                        <input type="text" id="timeslot-date-picker" readonly placeholder="<?php echo esc_attr__('Select a date', 'tickets-passes-for-woocommerce'); ?>">
                    </div>

                    <div class="tpfw-timeslot-times timeslot-wrapper">
                        <div class="tpfw-timeslot-times-seam">
                            <span class="tpfw-timeslot-times-label"><?php echo esc_html__('Available Times', 'tickets-passes-for-woocommerce') ?></span>
                            <span class="tpfw-timeslot-times-day is-empty"><?php echo esc_html__('No date selected', 'tickets-passes-for-woocommerce'); ?></span>
                        </div>
                        <div>
                        <?php
                        // These used to be two queries per timeslot, run while rendering the
                        // grid - a product with a season's worth of slots opened hundreds of
                        // them on every page view, and read every ticket row back just to count
                        // it. One grouped count and one grouped sum answer the whole grid.
                        $aTimeslotIds     = array_filter(wp_list_pluck($aValidTimeslots, 'id'));
                        $aSoldPerTimeslot = array();
                        $aHeldPerTimeslot = array();

                        $_timeslot_ticket_reservation_enable   = get_post_meta($oProduct->get_id(), '_tpfw_timeslot_ticket_reservation_enable', true);
                        $_timeslot_ticket_reservation_duration = (int)get_post_meta($oProduct->get_id(), '_tpfw_timeslot_ticket_reservation_duration', true);
                        $bReservationsOn                       = ($_timeslot_ticket_reservation_enable == "yes" && $_timeslot_ticket_reservation_duration > 0);

                        if(!empty($aTimeslotIds))
                        {
                            $sTimeslotIdsPlaceholders = implode(', ', array_fill(0, count($aTimeslotIds), '%s'));

                            $sSoldSQL = $wpdb->prepare(
                                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sTimeslotIdsPlaceholders is a "%s, %s, ..." scaffold, not user data; every value is bound via the array_merge() below.
                                "SELECT timeslot_id, COUNT(*) AS used_count FROM %i WHERE deleted IS NULL AND timeslot_id IN ($sTimeslotIdsPlaceholders) GROUP BY timeslot_id;",
                                array_merge(array($wpdb->prefix . 'tpfw_timeslot_tickets'), $aTimeslotIds)
                            );
                            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sSoldSQL is the return value of $wpdb->prepare() above.
                            $aSoldPerTimeslot = wp_list_pluck($wpdb->get_results($sSoldSQL), 'used_count', 'timeslot_id');

                            if($bReservationsOn)
                            {
                                // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the sniff cannot count the placeholders inside $sTimeslotIdsPlaceholders, so the replacement array looks longer than the format string.
                                $sHeldSQL = $wpdb->prepare(
                                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sTimeslotIdsPlaceholders is a "%s, %s, ..." scaffold, not user data; every value is bound via the array_merge() below.
                                    "SELECT timeslot_id, SUM(quantity) AS held FROM %i WHERE deleted IS NULL AND valid_to > %s AND timeslot_id IN ($sTimeslotIdsPlaceholders) GROUP BY timeslot_id;",
                                    array_merge(array($wpdb->prefix . 'tpfw_timeslot_reservations', current_time('mysql')), $aTimeslotIds)
                                );
                                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sHeldSQL is the return value of $wpdb->prepare() above.
                                $aHeldPerTimeslot = wp_list_pluck($wpdb->get_results($sHeldSQL), 'held', 'timeslot_id');
                            }
                        }

                        foreach ($aValidTimeslots as $oTimeslot)
                        {
                            if (!isset($oTimeslot->start) || empty($oTimeslot->start))  continue;
                            if (!isset($oTimeslot->end)   || empty($oTimeslot->end))    continue;
                            if (!isset($oTimeslot->id)    || empty($oTimeslot->id))     continue;
                            if (!isset($oTimeslot->available_slots)   || empty($oTimeslot->available_slots))
                            {
                                $iQuantity = 0;
                            }
                            else
                            {
                                $iQuantity = $oTimeslot->available_slots;
                            }

                            $bShowAddToCart = true;

                            $sStartDate  = gmdate('Y-m-d',        strtotime($oTimeslot->start));
                            $sStartTime  = gmdate('H:i',          strtotime($oTimeslot->start));
                            $sEndDate    = gmdate('Y-m-d',        strtotime($oTimeslot->end));
                            $sEndTime    = gmdate('H:i',          strtotime($oTimeslot->end));                            

                            $iSold = isset($aSoldPerTimeslot[$oTimeslot->id]) ? (int)$aSoldPerTimeslot[$oTimeslot->id] : 0;
                            if($iSold > 0)
                            {
                                $iQuantity = max(0, $iQuantity-$iSold);
                            }

                            if($bReservationsOn)
                            {
                                $iHeld = isset($aHeldPerTimeslot[$oTimeslot->id]) ? (int)$aHeldPerTimeslot[$oTimeslot->id] : 0;
                                $iQuantity = max(0, $iQuantity-$iHeld);
                            }
                            $bSoldOut = ($iQuantity <= 0);
                            if (!$bSoldOut)
                            {
                                $availableDates[] = gmdate('Y-m-d', strtotime($oTimeslot->start));
                            }

                            // "3 spots left" reads exactly like "30 spots left" in a list of otherwise
                            // identical rows - the low marker is what makes a nearly-full slot stand out
                            // while there is still time to pick a different one.
                            $bLowOnSpots   = (!$bSoldOut && $iQuantity <= self::LOW_SPOTS_THRESHOLD);
                            $iDurationMins = (int)round((strtotime($oTimeslot->end) - strtotime($oTimeslot->start)) / 60);

                            if ($sEndDate === $sStartDate)
                            {
                                /* translators: 1: time the timeslot ends, e.g. 14:45. 2: how long it lasts, in minutes. */
                                $sTimeslotSubline = sprintf(__('until %1$s · %2$d min', 'tickets-passes-for-woocommerce'), $sEndTime, $iDurationMins);
                            }
                            else
                            {
                                /* translators: 1: date the timeslot ends. 2: time it ends, e.g. 14:45. */
                                $sTimeslotSubline = sprintf(__('until %1$s at %2$s', 'tickets-passes-for-woocommerce'), gmdate($sDateTimeFormat, strtotime($sEndDate)), $sEndTime);
                            }
                            ?>
                            <div class="tpfw-timeslot-option single-product-timeslot<?php echo esc_attr($bSoldOut ? ' tpfw-timeslot-option--soldout' : ''); ?>"
                                data-date-match="<?php echo esc_attr(gmdate($sDateTimeFormat, strtotime($sStartDate))); ?>"
                                data-date="<?php echo esc_attr($sStartDate); ?>"
                                data-start-time="<?php echo esc_attr($sStartTime); ?>"
                                data-end-date="<?php echo esc_attr($sEndDate); ?>"
                                data-end-time="<?php echo esc_attr($sEndTime); ?>"
                                data-timeslot-id="<?php echo esc_attr($oTimeslot->id); ?>"
                                data-timeslot-qty="<?php echo esc_attr($iQuantity); ?>">
                                <span class="tpfw-timeslot-option-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 1.8"/></svg>
                                </span>
                                <span class="tpfw-timeslot-option-lines">
                                    <span class="tpfw-timeslot-option-time"><?php echo esc_html($sStartTime); ?></span>
                                    <span class="tpfw-timeslot-option-sub"><?php echo esc_html($sTimeslotSubline); ?></span>
                                </span>
                                <span class="tpfw-timeslot-option-spots<?php echo esc_attr($bLowOnSpots ? ' tpfw-timeslot-option-spots--low' : ''); ?>">
                                    <?php
                                        if($bSoldOut)
                                        {
                                            echo esc_html__('Sold out', 'tickets-passes-for-woocommerce');
                                        }
                                        else
                                        {
                                            /* translators: %d: number of places still available in this timeslot. */
                                            echo esc_html(sprintf(_n('%d spot left', '%d spots left', $iQuantity, 'tickets-passes-for-woocommerce'), $iQuantity));
                                        }
                                    ?>
                                </span>
                            </div>
                            <?php
                        }
                        ?>
                        </div>
                    </div>

                    <div class="tpfw-timeslot-picker-foot">
                        <span class="tpfw-timeslot-picker-summary"><?php echo esc_html__('Pick a date to see available times', 'tickets-passes-for-woocommerce'); ?></span>
                        <span class="tpfw-timeslot-picker-meter"><i></i></span>
                    </div>
                </div>

                <?php
                $availableDatesJson = wp_json_encode(array_values(array_unique($availableDates)));
                ob_start();
                ?>
                jQuery(document).ready(function($)
                {
                    const availableDates = <?php echo $availableDatesJson; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() output is already a safe JS literal; escaping it would break the syntax. ?>;
                    new AirDatepicker('#timeslot-date-picker', {
                        dateFormat: "<?php echo esc_js($sDateTimeFormatJS); ?>",
                        inline    : true,
                        locale: {
                            days       : 
                            [
                                "<?php echo esc_js(__('Sunday', 'tickets-passes-for-woocommerce')); ?>", 
                                "<?php echo esc_js(__('Monday', 'tickets-passes-for-woocommerce')); ?>", 
                                "<?php echo esc_js(__('Tuesday', 'tickets-passes-for-woocommerce')); ?>", 
                                "<?php echo esc_js(__('Wednesday', 'tickets-passes-for-woocommerce')); ?>", 
                                "<?php echo esc_js(__('Thursday', 'tickets-passes-for-woocommerce')); ?>", 
                                "<?php echo esc_js(__('Friday', 'tickets-passes-for-woocommerce')); ?>", 
                                "<?php echo esc_js(__('Saturday', 'tickets-passes-for-woocommerce')); ?>", 
                            ],
                            daysShort  : 
                            [
                                "<?php echo esc_js(__('Sun', 'tickets-passes-for-woocommerce')); ?>", 
                                "<?php echo esc_js(__('Mon', 'tickets-passes-for-woocommerce')); ?>", 
                                "<?php echo esc_js(__('Tue', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('Wed', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('Thu', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('Fri', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('Sat', 'tickets-passes-for-woocommerce')); ?>", 
                                
                            ],
                            daysMin    : 
                            [
                                "<?php echo esc_js(__('Su', 'tickets-passes-for-woocommerce')); ?>", 
                                "<?php echo esc_js(__('Mo', 'tickets-passes-for-woocommerce')); ?>", 
                                "<?php echo esc_js(__('Tu', 'tickets-passes-for-woocommerce')); ?>", 
                                "<?php echo esc_js(__('We', 'tickets-passes-for-woocommerce')); ?>", 
                                "<?php echo esc_js(__('Th', 'tickets-passes-for-woocommerce')); ?>", 
                                "<?php echo esc_js(__('Fr', 'tickets-passes-for-woocommerce')); ?>", 
                                "<?php echo esc_js(__('Sa', 'tickets-passes-for-woocommerce')); ?>",                                 
                            ],
                            months     : 
                            [
                                "<?php echo esc_js(__('January', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('February', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('March', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('April', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('May', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('June', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('July', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('August', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('September', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('October', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('November', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('December', 'tickets-passes-for-woocommerce')); ?>",                                                                 
                            ],
                            monthsShort: 
                            [
                                "<?php echo esc_js(__('Jan', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('Feb', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('Mar', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('Apr', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('May', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('Jun', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('Jul', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('Aug', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('Sep', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('Oct', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('Nov', 'tickets-passes-for-woocommerce')); ?>",                                 
                                "<?php echo esc_js(__('Dec', 'tickets-passes-for-woocommerce')); ?>",                                                                 
                            ],                                       
                            today      : "<?php echo esc_js(__('Today', 'tickets-passes-for-woocommerce')); ?>",
                            clear      : "<?php echo esc_js(__('Clear', 'tickets-passes-for-woocommerce')); ?>",                            
                        },
                        timepicker: false,
                        minDate   : [new Date()],
                        // Without this the calendar always opens on the current month - fine
                        // when slots start soon, but a recurring rule's first occurrence can be
                        // months out, and every day in the current month shows disabled with no
                        // hint that clicking "next" repeatedly would eventually find one.
                        startDate : availableDates.length ? new Date(availableDates[0]) : new Date(),
                        onRenderCell({date, cellType }) 
                        {                               
                            let offset 			= date.getTimezoneOffset()*60000; 							
							let dateWithOffset 	= new Date(date.getTime() - offset); 
                            let dateString 		= dateWithOffset.toISOString().split('T')[0];                                         
                            if(!availableDates.includes(dateString))
                            {                                
                                return {
                                    disabled: true,
                                    classes: 'disabled-class'
                                }                          
                            }
                        }, 
                        onSelect({ date, formattedDate, datepicker })
                        {
                            // Same local-to-ISO shift as onRenderCell above: toISOString() is UTC,
                            // so a local midnight west of Greenwich would otherwise read as the
                            // previous day (and the old "+1 day" only worked east of it).
                            let _date      = new Date(date.getTime() - date.getTimezoneOffset()*60000);
                            let dateString = _date.toISOString().split('T')[0];
                            // .show()/.hide() would restore the browser's default "block" display for
                            // a <div>, not the "grid" the row layout needs to line icon, time and
                            // spots-left up into columns - set the display explicitly instead.
                            jQuery('.single-product-timeslot').css('display', 'none');
                            jQuery('.single-product-timeslot[data-date="'+dateString+'"]').css('display', 'grid');
                            jQuery('.tpfw-timeslot-times-day').removeClass('is-empty').text(formattedDate);
                            if(jQuery('.single-product-timeslot[data-date="'+dateString+'"]').length >= 1)
                            {
                                jQuery(".timeslot-wrapper").show();   
                            }
                            else
                            {
                                jQuery(".timeslot-wrapper").hide();   
                            }
                        },
                    })

                });
                <?php
                // Stashed rather than attached here: with a block theme WordPress renders the
                // product template before 'wp_enqueue_scripts' fires, so the frontend handle is
                // not registered yet and wp_add_inline_script() would silently drop the script.
                // See tpfw_add_timeslot_datepicker_inline_script(), on 'wp_footer'.
                $this->sTimeslotDatepickerJS = ob_get_clean();
                add_action('wp_footer', array($this, 'tpfw_add_timeslot_datepicker_inline_script'), 5);
                ?>

                <?php
                if(get_post_meta($post->ID, '_tpfw_timeslot_sales_timespan_enable', true) == 'yes')
                {
                    if(!$this->oFunctions->is_within_sales_window(get_post_meta($post->ID, '_tpfw_timeslot_sales_timespan_start', true), get_post_meta($post->ID, '_tpfw_timeslot_sales_timespan_end', true))) $bShowAddToCart = false;
                }
                                if ($bShowAddToCart)
                {
                    $this->oFunctions->render_product_info_table($post->ID, array(
                        'show_max_uses' => '_tpfw_timeslot_show_max_uses',
                        'max_uses'      => '_tpfw_timeslot_ticket_max_usage',
                        'show_sales'    => '_tpfw_timeslot_show_sales_window',
                        'sales_enable'  => '_tpfw_timeslot_sales_timespan_enable',
                        'sales_start'   => '_tpfw_timeslot_sales_timespan_start',
                        'sales_end'     => '_tpfw_timeslot_sales_timespan_end',
                    ), $this->oFunctions->get_front_color_style('sTimeslotColor'));

                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook, fired here so the stock add-to-cart template renders for this product type.
                    do_action('woocommerce_simple_add_to_cart');
                }
            }
        }
    }

    /**
     * Issues (or reinstates) the timeslot tickets for one paid order line.
     *
     * Idempotent by design: existing rows for the same timeslot/product/order/line are un-deleted up
     * to the current quantity, surplus rows are soft deleted, and only the shortfall is inserted, so
     * a completed -> refunded -> completed cycle keeps the customer's original nano ids and QR codes.
     * Refuses the line rather than issuing anything when the slot is full, the product is missing a
     * max-usage / check-in-duration / cooldown setting, or reservations are on and the line carries no
     * reservation id. On success the line's reservation is closed out, since the ticket now holds the seat.
     *
     * @param int           $iOrderID    Order id.
     * @param int           $iCustomerID Customer user id the tickets belong to.
     * @param WC_Order_Item $oOrderItem  The line item being fulfilled.
     * @return array{sMessage:string,bStatus:bool} Result suitable for an order note.
     */
    public function create_timeslot_ticket($iOrderID, $iCustomerID, $oOrderItem)
    {
        $iProductID = $oOrderItem->get_product_id();    
        global $wpdb;                
        $sTimeslotsPrepared = $wpdb->prepare(
            'SELECT * FROM %i WHERE product_id = %s AND deleted is NULL AND start >= %s;',
            array(
				$wpdb->prefix . 'tpfw_timeslots',                 
                $iProductID,
                current_time('mysql'),
            )            
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sTimeslotsPrepared is the return value of $wpdb->prepare() above.
        $aValidTimeslots = $wpdb->get_results($sTimeslotsPrepared);                                                               
                
        if(empty($aValidTimeslots) || !isset($aValidTimeslots))
        {
            return array(
                'sMessage' => __('Timeslot product, seems to have no timeslots set', 'tickets-passes-for-woocommerce'),
                'bStatus' => false,
            );   
        }
        
        
        $_timeslot_ticket_reservation_enable   = get_post_meta($oOrderItem->get_product_id(), '_tpfw_timeslot_ticket_reservation_enable', true);        
        if($_timeslot_ticket_reservation_enable == "yes" && ($oOrderItem->get_meta('tpfw_reservation_id', true) === null || empty($oOrderItem->get_meta('tpfw_reservation_id', true)) || $oOrderItem->get_meta('tpfw_reservation_id', true) == ""))
        { 
            return array(
                'sMessage' => __('This Timeslot product Requires a reservation ID to be created, but seems to have no reservation ID', 'tickets-passes-for-woocommerce'),
                'bStatus' => false,
            );   
        }


        if($oOrderItem->get_meta('tpfw_timeslot_id', true) == null || $oOrderItem->get_meta('tpfw_timeslot_id', true) == false || $oOrderItem->get_meta('tpfw_timeslot_id', true) == "")
        {
            return array(
                'sMessage' => __('Timeslot ID does not seem to be set', 'tickets-passes-for-woocommerce'),
                'bStatus' => false,
            );    
        }
        $sTimeslotID = $oOrderItem->get_meta('tpfw_timeslot_id', true);        

        $oCurrentTimeslot = null;
        foreach($aValidTimeslots as $iTimeslotKey => $oTimeslot)
        {
            if($sTimeslotID == $oTimeslot->id)
            {
                $oCurrentTimeslot = $oTimeslot;
                break;
            }            
        }
        if($oCurrentTimeslot == null)
        {
            return array(
                'sMessage' => __('Unable to find a matching timeslot', 'tickets-passes-for-woocommerce'),
                'bStatus' => false,
            );    
        }
        
        $oParentProduct           = wc_get_product($oOrderItem->get_product_id());
        $iTimeslotTicketMaxUses   = get_post_meta($oParentProduct->get_id(), '_tpfw_timeslot_ticket_max_usage', true);              
        if($iTimeslotTicketMaxUses === null || $iTimeslotTicketMaxUses <= 0 || $iTimeslotTicketMaxUses === false || $iTimeslotTicketMaxUses === "")
        {
            return array(
                'sMessage' => __('Timeslot Ticket max usage does not seem to be set', 'tickets-passes-for-woocommerce'),
                'bStatus' => false,
            );
        }
 

        $iProductBeforeCheckinDuration  = get_post_meta($oParentProduct->get_id(), '_tpfw_timeslot_ticket_before_checkin_duration', true);            
        if($iProductBeforeCheckinDuration === null || $iProductBeforeCheckinDuration <= 0 || $iProductBeforeCheckinDuration === false || $iProductBeforeCheckinDuration === "")
        {
                return array(
                'sMessage' => $oParentProduct->get_name().' (#'.$oParentProduct->get_id() . ') - ' . __('is missing a valid before check-in duration value', 'tickets-passes-for-woocommerce'),
                'bStatus'  => false,
            );                    
        }
        

        $iTimeslotTicketCooldown  = get_post_meta($oParentProduct->get_id(), '_tpfw_timeslot_ticket_cooldown_sec', true);            
        if($iTimeslotTicketCooldown === null || $iTimeslotTicketCooldown <= 0 || $iTimeslotTicketCooldown === false || $iTimeslotTicketCooldown === "")
        {
                return array(
                'sMessage' => $oParentProduct->get_name().' (#'.$oParentProduct->get_id() . ') - ' . __('is missing a valid cooldown duration value', 'tickets-passes-for-woocommerce'),
                'bStatus'  => false,
            );                    
        }

        $sCurrentDatetime = current_time('mysql');
        $sTable           = $wpdb->prefix.'tpfw_timeslot_tickets';
        $oLock            = TPFW_Timeslot_Capacity::acquire_lock($wpdb, $sTimeslotID);
        if(!$oLock->held())
        {
            return array(
                'sMessage' => __('Could not issue this timeslot ticket because the slot is busy. No extra tickets were created.', 'tickets-passes-for-woocommerce'),
                'bStatus'  => false,
            );
        }

        try
        {
            $iTimeslotTicketsSold = (int)$wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE timeslot_id = %s AND deleted IS NULL AND NOT (order_id = %d AND order_line_id = %d);',
                $sTable, $sTimeslotID, $iOrderID, $oOrderItem->get_id()
            ));

            if(!TPFW_Timeslot_Capacity::quantity_fits($iTimeslotTicketsSold, (int)$oOrderItem->get_quantity(), (int)$oCurrentTimeslot->available_slots))
            {
                return array(
                    'sMessage' => __('Seems like the maximum number of tickets already created for the following timeslot', 'tickets-passes-for-woocommerce').': '.$sTimeslotID,
                    'bStatus'  => false,
                );
            }

            $oTimeslotExistsPrepared = $wpdb->prepare(
                'SELECT * FROM %i WHERE timeslot_id = %s AND product_id = %d AND order_id = %d AND order_line_id = %d ORDER BY -deleted;',
                array($sTable, $sTimeslotID, $oOrderItem->get_product_id(), $iOrderID, $oOrderItem->get_id())
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oTimeslotExistsPrepared is the return value of $wpdb->prepare() above.
            $aTimeslotExistsResult = $wpdb->get_results($oTimeslotExistsPrepared);

            $aSync = TPFW_Order_Line_Upsert::sync($wpdb, $sTable, $aTimeslotExistsResult, (int)$oOrderItem->get_quantity(), $sCurrentDatetime, function() use ($wpdb, $sTable, $sTimeslotID, $oOrderItem, $iCustomerID, $iOrderID, $iProductBeforeCheckinDuration, $oCurrentTimeslot, $iTimeslotTicketMaxUses, $sCurrentDatetime) {
                $sGeneratedNanoID = $this->oFunctions->generateNanoId();
                $wpdb->query($wpdb->prepare(
                    'INSERT INTO %i (timeslot_id, nano_id, product_id, user_id, order_id, order_line_id, before_checkin_duration, valid_from, valid_to, max_uses, created, updated)
                    VALUES (%s, %s, %d, %d, %d, %d, %d, %s, %s, %d, %s, %s);',
                    array(
                        $sTable,
                        $sTimeslotID,
                        $sGeneratedNanoID,
                        $oOrderItem->get_product_id(),
                        $iCustomerID,
                        $iOrderID,
                        $oOrderItem->get_id(),
                        $iProductBeforeCheckinDuration,
                        gmdate('Y-m-d H:i:s', (strtotime($oCurrentTimeslot->start)-$iProductBeforeCheckinDuration)),
                        gmdate('Y-m-d H:i:s', strtotime($oCurrentTimeslot->end)),
                        $iTimeslotTicketMaxUses,
                        $sCurrentDatetime,
                        $sCurrentDatetime,
                    )
                ));
                return $sGeneratedNanoID;
            });
        }
        finally
        {
            $oLock->release();
        }

        $aTempTicketReset = $aSync['keep'];
        
        if(!empty($oOrderItem->get_meta_data()))
        {
            foreach($oOrderItem->get_meta_data() as $iMetaKey => $aMetaData)
            {
                if(str_contains($aMetaData->key, 'tpfw_timeslot_ticket_id_')) 
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
                $oOrderItem->add_meta_data('tpfw_timeslot_ticket_id_'.((int)$iTempTicketKey+1), $sTicketNanoID); 

                $iProductID = $oOrderItem->get_product_id();
                $this->oFunctions->write_scanner_qr($iProductID, 'timeslot', $sTicketNanoID);
                                                                       
            }
            $oOrderItem->save();
        }
        
        if($_timeslot_ticket_reservation_enable == "yes" && !empty($oOrderItem->get_meta('tpfw_reservation_id', true)))
        {                
            $sCurrentDatetime                       = current_time('mysql');
            $sReservationTableName                  = $wpdb->prefix . "tpfw_timeslot_reservations";
            $sUpdateTimeslotReservationSQL          = $wpdb->prepare('	UPDATE %i
                                                                        SET updated = %s, deleted = %s
                                                                        WHERE reservation_id = %s', array(
				$sReservationTableName,$sCurrentDatetime, $sCurrentDatetime, $oOrderItem->get_meta('tpfw_reservation_id', true)));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sUpdateTimeslotReservationSQL is the return value of $wpdb->prepare() above.
            $wpdb->get_results($sUpdateTimeslotReservationSQL);                
        }

        return array(
            'sMessage' => __('Timeslot Ticket for order line created', 'tickets-passes-for-woocommerce'),
            'bStatus' => true,
        );
    }

	/**
	 * Soft deletes every timeslot ticket and check-in record on one order line and removes its QR files.
	 *
	 * Safe to call repeatedly - an already-cancelled line matches nothing and returns early. The
	 * line's reservation, if any, is released too.
	 *
	 * @param int           $iOrderID    Order id.
	 * @param int           $iCustomerID Customer user id the tickets belong to.
	 * @param WC_Order_Item $oOrderItem  The line item being cancelled.
	 * @return array{sMessage:string,bStatus:bool} Result suitable for an order note.
	 */
	public function cancel_timeslot_ticket($iOrderID, $iCustomerID, $oOrderItem)
    {        
        global $wpdb;
        $sTimeslotID     = $oOrderItem->get_meta('tpfw_timeslot_id', true);
        $oTimeslotTicketExistsPrepared = $wpdb->prepare(
            'SELECT * FROM %i WHERE timeslot_id = %s AND product_id = %d AND order_id = %d AND order_line_id = %d AND user_id = %d AND deleted IS NULL;',
            array(
				$wpdb->prefix . 'tpfw_timeslot_tickets',                 
                $sTimeslotID, 
                $oOrderItem->get_product_id(), 
                $iOrderID, 
                $oOrderItem->get_id(),
                $iCustomerID                
            )            
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oTimeslotTicketExistsPrepared is the return value of $wpdb->prepare() above.
        $oTimeslotTicketExistsResult = $wpdb->get_results($oTimeslotTicketExistsPrepared);
        if(empty($oTimeslotTicketExistsResult))
        {
            return array(
                'sMessage' => __('Timeslot Ticket for this order line already seems to be cancelled', 'tickets-passes-for-woocommerce'),
                'bStatus' => false,
            );
        }

        $iQuantity                 = 1;
        $oOrder                    = wc_get_order($iOrderID);
        $sTicketTableName          = $wpdb->prefix . "tpfw_timeslot_tickets";
        $sTicketStatisticTableName = $wpdb->prefix . "tpfw_timeslot_tickets_stats";
        $sCurrentDatetime          = current_time('mysql');
        foreach($oTimeslotTicketExistsResult as $iExistResultKey => $oExistResult)
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
            $oOrderItem->delete_meta_data('tpfw_timeslot_ticket_id_'.$iQuantity);
			$oOrderItem->save();
			$oOrder->add_order_note(__('Timeslot Ticket with ID', 'tickets-passes-for-woocommerce') . ': ' .$oExistResult->nano_id . ' ' . __('cancelled', 'tickets-passes-for-woocommerce'));
			$oOrder->save();	
            $iQuantity++;		            			
        }

        $_timeslot_ticket_reservation_enable   = get_post_meta($oOrderItem->get_product_id(), '_tpfw_timeslot_ticket_reservation_enable', true);        
        if($_timeslot_ticket_reservation_enable == "yes" && !empty($oOrderItem->get_meta('tpfw_reservation_id', true)))
        {                
            $sCurrentDatetime                       = current_time('mysql');
            $sReservationTableName                  = $wpdb->prefix . "tpfw_timeslot_reservations";
            $sUpdateTimeslotReservationSQL          = $wpdb->prepare('	UPDATE %i
                                                                        SET updated = %s, deleted = %s
                                                                        WHERE reservation_id = %s', array(
				$sReservationTableName,$sCurrentDatetime, $sCurrentDatetime, $oOrderItem->get_meta('tpfw_reservation_id', true)));
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sUpdateTimeslotReservationSQL is the return value of $wpdb->prepare() above.
            $wpdb->get_results($sUpdateTimeslotReservationSQL);                
        }

        return array(
            'sMessage' => __('All related tickets have been cancelled', 'tickets-passes-for-woocommerce'),
            'bStatus' => false,
        );
    }

    /**
     * Replaces the quantity stepper on the classic Cart page with a fixed value.
     *
     * The reservation and the slot's capacity were both taken for the quantity that was added, so
     * editing it in the cart would silently oversell the slot.
     *
     * @param string $product_quantity Quantity HTML WooCommerce would render.
     * @param string $cart_item_key    Cart item key.
     * @param array  $cart_item        The cart item.
     * @return string Fixed quantity markup for timeslot tickets, otherwise the incoming HTML.
     */
    function tpfw_timeslot_disable_cart_item_quantity($product_quantity, $cart_item_key, $cart_item) 
    {        
        $oProduct = wc_get_product($cart_item['product_id']);
        if(is_cart() && is_a($oProduct, 'TPFW_Product_Timeslot_Ticket')) 
        {
            $product_quantity = sprintf( '<strong>%s</strong><input type="hidden" name="cart[%s][qty]" value="%s" />', esc_html($cart_item['quantity']), esc_attr($cart_item_key), esc_attr($cart_item['quantity']) );
        }
        return $product_quantity;
    }

    // The filter above only reaches the classic Cart page template - the Mini Cart drawer
    // and Cart/Checkout blocks read cart data through the Store API instead, and those
    // respect is_sold_individually() rather than that template filter. Scoped to cart
    // contexts only (not the single product page) since a timeslot ticket can legitimately
    // be bought in quantity > 1 there - this only stops the quantity from being changed
    // again afterwards, which would desync it from the specific timeslot's remaining spots.
    /**
     * Forces timeslot tickets to be sold individually in cart contexts.
     *
     * Covers the Mini Cart drawer and the Cart/Checkout blocks, which read cart data through the
     * Store API and never see the woocommerce_cart_item_quantity filter above. Scoped to cart
     * contexts only, so the product page can still offer a quantity of more than one.
     *
     * @param bool       $return  Whether WooCommerce already considers the product sold individually.
     * @param WC_Product $product Product being tested.
     * @return bool True for timeslot tickets in a cart context, otherwise the incoming value.
     */
    function tpfw_timeslot_disable_quantity_editing_in_cart($return, $product)
    {
        if($this->oFunctions->is_wc_cart_request() && is_a($product, 'TPFW_Product_Timeslot_Ticket')) { return true; }
        return $return;
    }

    /**
     * Extends each line's reservation once the order exists, by the product's order-duration setting.
     *
     * Without this the reservation would expire while the customer is still paying - the order is
     * created before payment completes on both the classic and the Store API checkout.
     *
     * @param WC_Order $order The order just created.
     * @return void
     */
    function tpfw_reservation_time_added_woocommerce_new_order($oOrder) 
    {
        // $oOrder = wc_get_order($order_id);
        if(empty($oOrder)) return;
        if(empty($oOrder->get_items())) return;
        global $wpdb;
        $sCurrentDatetime = current_time('mysql');
        foreach($oOrder->get_items() as $iOrderItemKey => $oOrderItem)
        {
            $oProduct = wc_get_product($oOrderItem->get_product_id());
            if(!is_a($oProduct, 'TPFW_Product_Timeslot_Ticket')) continue;
            if($oOrderItem->get_meta('tpfw_reservation_id', true) == null || $oOrderItem->get_meta('tpfw_reservation_id', true) == "") continue;

            $_timeslot_ticket_reservation_enable         = get_post_meta($oProduct->get_id(), '_tpfw_timeslot_ticket_reservation_enable', true);
            $_timeslot_ticket_reservation_duration       = (int)get_post_meta($oProduct->get_id(), '_tpfw_timeslot_ticket_reservation_duration', true);
            $_timeslot_ticket_reservation_order_duration = get_post_meta($oProduct->get_id(), '_tpfw_timeslot_ticket_reservation_order_duration', true);
            if($_timeslot_ticket_reservation_enable == "yes" && $_timeslot_ticket_reservation_duration > 0 && $_timeslot_ticket_reservation_order_duration > 0)
            {
                $sNewReservationValidTo = gmdate('Y-m-d H:i:s', ($oOrderItem->get_meta('tpfw_reservation_time', true)+$_timeslot_ticket_reservation_duration+$_timeslot_ticket_reservation_order_duration));

                $sReservationTableName                  = $wpdb->prefix . "tpfw_timeslot_reservations";
                $sUpdateTimeslotReservationSQL          = $wpdb->prepare('	UPDATE %i
                                                                SET updated = %s, valid_to = %s, order_id=%d, order_line_id=%d
                                                                WHERE deleted IS null AND reservation_id = %s', array(
				$sReservationTableName,$sCurrentDatetime, $sNewReservationValidTo, $oOrder->get_id(), $oOrderItem->get_id(), $oOrderItem->get_meta('tpfw_reservation_id', true)));
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sUpdateTimeslotReservationSQL is the return value of $wpdb->prepare() above.
                $wpdb->get_results($sUpdateTimeslotReservationSQL);
            }
        }                    
    }   

    /**
     * Removes cart items whose reservation has run out, and tells the customer why.
     *
     * Runs on cart render, on checkout init and on woocommerce_check_cart_items - the last of which
     * is what both the classic and the Store API checkout call right before creating the order, so an
     * error notice raised here blocks the order the same way an out-of-stock notice would.
     *
     * @return void
     */
    public function tpfw_remove_expired_timeslot_ticket_reservations() 
    {
        if(is_admin()) return;
        // WC()->cart is null before woocommerce_load_cart_from_session has run, and on Store API
        // requests that never build a cart at all - both would fatal on ->get_cart().
        if(is_null(WC()->cart)) return;
        $aCartContent = WC()->cart->get_cart();
        if(empty($aCartContent))
        {
            return;
        }

        global $wpdb;
        foreach($aCartContent as $sCartContentKey => $oCartItem)
        {                
            if(!isset($oCartItem['reservation_id']) || empty($oCartItem['reservation_id']) || $oCartItem['reservation_id'] == "")
            {
                continue;
            }

            $_timeslot_ticket_reservation_duration = (int)get_post_meta($oCartItem['product_id'], '_tpfw_timeslot_ticket_reservation_duration', true);                
            if(($oCartItem['reservation_time']+$_timeslot_ticket_reservation_duration)-current_time('timestamp') <= 0)
            {
                $oProduct              = wc_get_product($oCartItem['product_id']);
                $sReservationTableName = $wpdb->prefix . "tpfw_timeslot_reservations";
                $sCurrentDatetime      = current_time('mysql');
                $sUpdateReservationSQL = $wpdb->prepare('	UPDATE %i
                                                                SET   deleted        = %s, updated = %s
                                                                WHERE reservation_id = %s', $sReservationTableName, $sCurrentDatetime, $sCurrentDatetime, $oCartItem['reservation_id']);
                
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sUpdateReservationSQL is the return value of $wpdb->prepare() above.
                $wpdb->get_results($sUpdateReservationSQL);
                WC()->cart->remove_cart_item($sCartContentKey);
                // The product can have been deleted while the item sat in the cart, in which case
                // wc_get_product() is false - the reservation still has to be released and the
                // customer still has to be told, so fall back to a generic name instead of fatalling.
                $sRemovedName = $oProduct ? $oProduct->get_name() : __('the reserved timeslot', 'tickets-passes-for-woocommerce');
                wc_add_notice(__('The following product was removed because the reservation was no longer valid:', 'tickets-passes-for-woocommerce') . ' ' . $sRemovedName, 'error');
            }
        }        
    }

    /**
     * Releases a reservation as soon as its cart item is removed, instead of waiting for expiry.
     *
     * @param string  $cart_item_key Key of the removed item.
     * @param WC_Cart $cart          The cart it was removed from.
     * @return void
     */
    public function tpfw_delete_reservation_on_cart_item_removed($sCartItemKey, $oCart)
    {
        // WC_Cart::remove_cart_item() moves the item into removed_cart_contents (keyed the
        // same way) right before firing this hook - that's the only place the reservation_id
        // is still available, the item is already gone from get_cart() by this point.
        $aCartItem = $oCart->removed_cart_contents[$sCartItemKey] ?? null;
        if(empty($aCartItem) || empty($aCartItem['reservation_id'])) return;

        global $wpdb;
        $sReservationTableName = $wpdb->prefix . "tpfw_timeslot_reservations";
        $sCurrentDatetime      = current_time('mysql');
        $sDeleteReservationSQL = $wpdb->prepare('	UPDATE %i
                                                    SET deleted = %s, updated = %s
                                                    WHERE deleted IS NULL AND reservation_id = %s', $sReservationTableName, $sCurrentDatetime, $sCurrentDatetime, $aCartItem['reservation_id']);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sDeleteReservationSQL is the return value of $wpdb->prepare() above.
        $wpdb->query($sDeleteReservationSQL);
    }

    /**
     * Blocks the add-to-cart when no slot was picked or the chosen slot has no capacity left.
     *
     * Remaining capacity is the slot's available_slots minus every live reservation on it. The
     * quantity is taken from WooCommerce's own $request_quantity rather than $_POST, because the
     * Store API, wc_add_to_cart() and the REST API never populate that field.
     *
     * @param bool $passed           Whether validation has passed so far.
     * @param int  $product_id       Product being added.
     * @param int  $request_quantity Quantity WooCommerce intends to add.
     * @return bool False to block the add-to-cart, otherwise the incoming value.
     */
    public function tpfw_timeslot_ticket_add_to_cart_validation($passed, $product_id, $request_quantity)
    {
        return $this->checkout()->validate($passed, $product_id, $request_quantity);
    }

    /**
     * Copies the chosen timeslot onto the cart item and, when enabled, opens a reservation for it.
     *
     * The reservation id doubles as the cart line's unique_key, so two bookings of the same slot stay
     * separate lines rather than being merged into one by WooCommerce.
     *
     * @param array $cart_item_data Custom cart item data.
     * @param int   $product_id     Product being added.
     * @param int   $variation_id   Variation id (unused for timeslot tickets).
     * @param int   $quantity       Quantity being added; also the size of the reservation.
     * @return array The cart item data with the timeslot and reservation details added.
     */
    public function tpfw_timeslot_ticket_add_to_cart_action($cart_item_data, $product_id, $variation_id, $quantity)
    {
        return $this->checkout()->cart_item_data($cart_item_data, $product_id, $variation_id, $quantity);
    }

    /**
     * Shows the chosen timeslot, and the time left on its reservation, as line item meta in the cart.
     *
     * @param array $item_data      Meta rows already queued for display.
     * @param array $cart_item_data The cart item.
     * @return array Item data with the timeslot details appended for timeslot products.
     */
    public function tpfw_display_timeslot_ticket_meta_data($item_data, $cart_item_data) 
    {            
        if(isset($cart_item_data['product_id']))
        {
            $oProduct     = wc_get_product($cart_item_data['product_id']);    
        }
        if(!empty($oProduct) && $oProduct != null && is_a($oProduct, 'TPFW_Product_Timeslot_Ticket'))
        {
            if(isset($cart_item_data['timeslot_id']) && $this->oFunctions->user_can_manage())
            {
                $item_data[] = array(
                    'key'   => __('Admin only - Timeslot ID', 'tickets-passes-for-woocommerce'),
                    'value' => wc_clean($cart_item_data['timeslot_id']),
                );
            }     
            
            if($this->oFunctions->user_can_manage() && isset($cart_item_data['reservation_id']))
            {
                $item_data[] = array(
                    'key'   => __('Admin only - Reservation ID', 'tickets-passes-for-woocommerce'),
                    'value' => wc_clean($cart_item_data['reservation_id']),
                );                            
            }   
            
            if(!empty($cart_item_data['timeslot_start']) && !empty($cart_item_data['timeslot_end']))
            {
                $sDateTimeFormat = $this->oFunctions->get_datetime_format('datetime');
                $item_data[] = array(
                    'key'   => __('Start', 'tickets-passes-for-woocommerce'),
                    'value' => wc_clean(gmdate($sDateTimeFormat, strtotime($cart_item_data['timeslot_start']))),
                );

                $item_data[] = array(
                    'key'   => __('End', 'tickets-passes-for-woocommerce'),
                    'value' => wc_clean(gmdate($sDateTimeFormat, strtotime($cart_item_data['timeslot_end']))),
                );
            }          
            
            if(isset($cart_item_data['reservation_id'], $cart_item_data['reservation_time']))
            {
                $_timeslot_ticket_reservation_duration = (int)get_post_meta($cart_item_data['product_id'], '_tpfw_timeslot_ticket_reservation_duration', true);
                // Floored at zero and shown as "5 mins" rather than a raw (possibly negative) second count.
                $iSecondsLeft = max(0, ((int) wc_clean($cart_item_data['reservation_time']) + $_timeslot_ticket_reservation_duration) - current_time('timestamp'));
                $item_data[] = array(
                    'key'   => __('Reservation Time Left', 'tickets-passes-for-woocommerce'),
                    'value' => $iSecondsLeft > 0 ? human_time_diff(current_time('timestamp'), current_time('timestamp') + $iSecondsLeft) : __('Expired', 'tickets-passes-for-woocommerce'),
                );
            }
        }
        return $item_data;        
    }

	/**
	 * Renders the reservation_time line meta as a readable date on order screens.
	 *
	 * The stored value stays a raw timestamp, because the expiry sweep does arithmetic on it.
	 *
	 * @param string $display_value Value WooCommerce is about to display.
	 * @param object $meta          The meta object being displayed.
	 * @return string A formatted date for reservation_time, otherwise the incoming value.
	 */
	public function tpfw_format_reservation_time_order_item_meta($sDisplayValue, $oMeta)
	{
		if(!isset($oMeta->key) || $oMeta->key != 'tpfw_reservation_time' || empty($oMeta->value)) return $sDisplayValue;
		return gmdate($this->oFunctions->get_datetime_format('datetime'), (int)$oMeta->value);
	}



	/**
	 * Copies the chosen timeslot and reservation from the cart item onto the order line item.
	 *
	 * This is what survives into the order, and what create_timeslot_ticket() later reads to decide
	 * which slot the ticket belongs to.
	 *
	 * @param WC_Order_Item_Product $item          Order line being built.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart item data.
	 * @param WC_Order              $order         Order being created.
	 * @return void
	 */
	public function tpfw_add_timeslot_ticket_meta_to_order_line($item, $cart_item_key, $values, $order) 
	{
        $oProduct     = wc_get_product($item->get_product_id());        
        if(is_a($oProduct, 'TPFW_Product_Timeslot_Ticket'))
        {
            if(!empty($values['timeslot_id']) && isset($values['timeslot_id']) && $values['timeslot_id'] != "") 
			{
                $item->update_meta_data('tpfw_timeslot_id', sanitize_text_field($values['timeslot_id']));                
			}
            
            if(!empty($values['timeslot_start']) && isset($values['timeslot_start']) && $values['timeslot_start'] != "") 
			{
				$item->update_meta_data('tpfw_timeslot_start', sanitize_text_field($values['timeslot_start']));                
			}
            
            if(!empty($values['timeslot_end']) && isset($values['timeslot_end']) && $values['timeslot_end'] != "") 
			{
				$item->update_meta_data('tpfw_timeslot_end', sanitize_text_field($values['timeslot_end']));                
			}
            
            if(!empty($values['reservation_id']) && isset($values['reservation_id']) && $values['reservation_id'] != "") 
			{
				$item->update_meta_data('tpfw_reservation_id', sanitize_text_field($values['reservation_id']));                
			}

            if(!empty($values['reservation_time']) && isset($values['reservation_time']) && $values['reservation_time'] != "") 
			{
				$item->update_meta_data('tpfw_reservation_time', sanitize_text_field($values['reservation_time']));                
			}
        }                
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
        return $this->create_timeslot_ticket($iOrderID, $iCustomerID, $oOrderItem);
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
        return $this->cancel_timeslot_ticket($iOrderID, $iCustomerID, $oOrderItem);
    }

    /**
     * Renders the timeslot picker above the add-to-cart button on a timeslot product page.
     *
     * @return void
     */
    public function tpfw_timeslot_ticket_product_html()
    {
        global $post;
        // $post is null on a shortcode-rendered add-to-cart form outside a singular product
        // view, which made ->ID a fatal - the same is_product() guard the other product types use.
        if(!is_product() || !is_singular('product') || empty($post)) return;
        $oProduct = wc_get_product($post->ID);
        if(!empty($oProduct) && is_a($oProduct, 'TPFW_Product_Timeslot_Ticket'))
        {
            ?>
                <input type="text" style="display:none;" value="" name="timeslot-id" class="input-single-product-timeslot-id">
                <input type="text" style="display:none;"value="" name="timeslot-start" class="input-single-product-timeslot-start">
                <input type="text" style="display:none;"value="" name="timeslot-end" class="input-single-product-timeslot-end">                
            <?php
        }
    }

    /**
     * AJAX: checks a timeslot ticket in from the admin dashboard or the customer's My Account page.
     *
     * Either the current user manages the plugin, or the ticket belongs to them - anything else is
     * rejected. A refusal from the shared check-in helper (cooldown, max uses, lock contention) is
     * reported back as a failure rather than being swallowed. Returns the refreshed status pill and
     * usage counter so the calling table row can be updated without a reload.
     *
     * @return void Sends a JSON envelope through wp_send_json_success()/wp_send_json_error() and exits.
     */
    public function ajax_checkin_timeslot_callback()
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
	public function ajax_settings_preview_timeslot_qr_callback()
	{
		$this->oFunctions->render_qr_preview('timeslot');
	}

    /**
     * AJAX: cancels one timeslot and every ticket issued against it.
     *
     * Admin only. Both the slot and its tickets are soft deleted, so the history stays reportable.
     *
     * @return void Sends a JSON envelope through wp_send_json_success()/wp_send_json_error() and exits.
     */
    function ajax_delete_timeslot_callback()
    {
        $response = array();
        // The JS already posts sParam.security - without this the endpoint was CSRF-able.
        check_ajax_referer('tpfw_ajax_delete_timeslot', 'security');
        if(!$this->oFunctions->user_can_manage())
        {
            $response['sMessage'] = __('Current user does not have admin privileges', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
        }

        if(!isset($_POST['timeslot_id']) || empty($_POST['timeslot_id']))
        {
            $response['sMessage'] = __('Missing timeslot parameter', 'tickets-passes-for-woocommerce');            
            wp_send_json_error($response);
        }
        $sTimeslotID        = sanitize_text_field(wp_unslash($_POST['timeslot_id'] ?? ''));

        global $wpdb;
        $sCurrentDatetime           = current_time('mysql');
        $sTimeslotTableName         = $wpdb->prefix . "tpfw_timeslots";
        $sTimeslotTicketTableName   = $wpdb->prefix . "tpfw_timeslot_tickets";

        $sFetchTimeslotsTicketSQL   = $wpdb->prepare('	SELECT * FROM %i                
                                                        WHERE timeslot_id=%s', $sTimeslotTicketTableName, $sTimeslotID);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sFetchTimeslotsTicketSQL is the return value of $wpdb->prepare() above.
        $aTimeslotTicketResult      = $wpdb->get_results($sFetchTimeslotsTicketSQL);
        if(!empty($aTimeslotTicketResult))
        {
            foreach($aTimeslotTicketResult as $iTimeslotTicketKey => $oTimeslotTicket)
            {
                $this->oFunctions->cancel_timeslot_ticket($oTimeslotTicket->nano_id);                
            }
        }
        $sDeleteTimeslotsSQL        = $wpdb->prepare('	UPDATE %i
                                                        SET   deleted        = %s, updated = %s
                                                        WHERE deleted IS NULL AND id=%s', $sTimeslotTableName, $sCurrentDatetime, $sCurrentDatetime, $sTimeslotID);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sDeleteTimeslotsSQL is the return value of $wpdb->prepare() above.
        $wpdb->get_results($sDeleteTimeslotsSQL);

        $response['sMessage']   = __('Timeslot and connected tickets cancelled', 'tickets-passes-for-woocommerce');            
        wp_send_json_success($response);
    }

    /**
     * AJAX: cancels a recurring series, every slot it generated and every ticket on those slots.
     *
     * Admin only.
     *
     * @return void Sends a JSON envelope through wp_send_json_success()/wp_send_json_error() and exits.
     */
    function ajax_delete_recurring_timeslot_children_callback()
    {
        $response = array();
        // The JS already posts sParam.security - without this the endpoint was CSRF-able.
        check_ajax_referer('tpfw_ajax_delete_recurring_timeslot_children', 'security');
        if(!$this->oFunctions->user_can_manage())
        {
            $response['sMessage'] = __('Current user does not have admin privileges', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
        }

        if(!isset($_POST['recurring_timeslot_id']) || empty($_POST['recurring_timeslot_id']))
        {
            $response['sMessage'] = __('Missing timeslot parameter', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
        }
        $sRecurringTimeslotID        = sanitize_text_field(wp_unslash($_POST['recurring_timeslot_id'] ?? ''));

        global $wpdb;
        $sCurrentDatetime               = current_time('mysql');
        $sTimeslotTableName             = $wpdb->prefix . 'tpfw_timeslots';
        $sTimeslotTicketTableName       = $wpdb->prefix . "tpfw_timeslot_tickets";
        $sRecurringTimeslotsTableName   = $wpdb->prefix . 'tpfw_timeslots_recurring';
        
        $sFetchRecTimeslotsSQL = $wpdb->prepare('	SELECT * FROM %i
                                                    
                                                    WHERE timeslot_recurring_id_fk=%s', $sTimeslotTableName, $sRecurringTimeslotID);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sFetchRecTimeslotsSQL is the return value of $wpdb->prepare() above.
        $aFetchRecTimeslotsResult = $wpdb->get_results($sFetchRecTimeslotsSQL);
        
        if(!empty($aFetchRecTimeslotsResult))
        {
            foreach($aFetchRecTimeslotsResult as $iFetchRecTimeslotsKey => $oFetchRecTimeslots)
            {
                $sFetchTimeslotsTicketSQL   = $wpdb->prepare('	SELECT * FROM %i                
                                                                WHERE timeslot_id=%s', $sTimeslotTicketTableName, $oFetchRecTimeslots->id);
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sFetchTimeslotsTicketSQL is the return value of $wpdb->prepare() above.
                $aTimeslotTicketResult      = $wpdb->get_results($sFetchTimeslotsTicketSQL);
                if(!empty($aTimeslotTicketResult))
                    {
                    foreach($aTimeslotTicketResult as $iTimeslotTicketKey => $oTimeslotTicket)
                    {
                        $this->oFunctions->cancel_timeslot_ticket($oTimeslotTicket->nano_id);                
                    }
                }
            }
        }
       
        $sDeleteTimeslotsSQL            = $wpdb->prepare('	UPDATE %i
                                                            SET   deleted        = %s, updated = %s
                                                            WHERE deleted IS NULL AND timeslot_recurring_id_fk=%s', $sTimeslotTableName, $sCurrentDatetime, $sCurrentDatetime, $sRecurringTimeslotID);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sDeleteTimeslotsSQL is the return value of $wpdb->prepare() above.
        $wpdb->get_results($sDeleteTimeslotsSQL);
        
        $sDeleteRecTimeslotsSQL = $wpdb->prepare('	UPDATE %i
                                                    SET   deleted        = %s, updated = %s
                                                    WHERE deleted IS NULL AND id=%s', $sRecurringTimeslotsTableName, $sCurrentDatetime, $sCurrentDatetime, $sRecurringTimeslotID);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sDeleteRecTimeslotsSQL is the return value of $wpdb->prepare() above.
        $wpdb->get_results($sDeleteRecTimeslotsSQL);

        $response['sMessage']   = __('Recurring Timeslot and connected timeslot&tickets cancelled', 'tickets-passes-for-woocommerce');            
        wp_send_json_success($response);
    }

    /**
     * AJAX: generates a recurring series' slots immediately instead of waiting for the cron run.
     *
     * Admin only. Slot creation is idempotent, so running it early cannot duplicate anything.
     *
     * @return void Sends a JSON envelope through wp_send_json_success()/wp_send_json_error() and exits.
     */
    function ajax_force_recurring_timeslot_children_callback()
    {
        $response = array();
        // The JS already posts sParam.security - without this the endpoint was CSRF-able.
        check_ajax_referer('tpfw_ajax_force_recurring_timeslot_children', 'security');
        if(!$this->oFunctions->user_can_manage())
        {
            $response['sMessage'] = __('Current user does not have admin privileges', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
        }

        if(!isset($_POST['recurring_timeslot_id']) || empty($_POST['recurring_timeslot_id']))
        {
            $response['sMessage'] = __('Missing timeslot parameter', 'tickets-passes-for-woocommerce');            
            wp_send_json_error($response);
        }
        $sReccuringTimeslotID = sanitize_text_field(wp_unslash($_POST['recurring_timeslot_id'] ?? ''));
        
        $this->oFunctions->create_recurring_timeslots($sReccuringTimeslotID);

        $response['sMessage']     = __('Recurring timeslot children force created', 'tickets-passes-for-woocommerce');        
        wp_send_json_success($response);
    }

    /**
     * AJAX: returns the rendered rows for the slots a recurring series has generated.
     *
     * Admin only. Used by the "Show Timeslots" button on the settings panel, so the series' children
     * are only fetched when an admin actually asks to see them.
     *
     * @return void Sends a JSON envelope through wp_send_json_success()/wp_send_json_error() and exits.
     */
    function ajax_get_recurring_timeslot_children_callback()
    {
        $response = array();
        // The JS already posts sParam.security - without this the endpoint was CSRF-able.
        check_ajax_referer('tpfw_ajax_get_recurring_timeslot_children', 'security');
        if(!$this->oFunctions->user_can_manage())
        {
            $response['sMessage'] = __('Current user does not have admin privileges', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
        }

        if(!isset($_POST['recurring_timeslot_id']) || empty($_POST['recurring_timeslot_id']))
        {
            $response['sMessage'] = __('Missing timeslot parameter', 'tickets-passes-for-woocommerce');            
            wp_send_json_error($response);
        }
        $sReccuringTimeslotID = sanitize_text_field(wp_unslash($_POST['recurring_timeslot_id'] ?? ''));
        
        global $wpdb;
        $sTimeslotTableName           = $wpdb->prefix . "tpfw_timeslots";
        $sRecurringChildTimeslotsSQL  = $wpdb->prepare('	SELECT * FROM %i				
                                                            WHERE deleted IS NULL AND timeslot_recurring_id_fk = %s
                                                            ORDER BY start ASC', $sTimeslotTableName, 
                                                        $sReccuringTimeslotID
        );       
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sRecurringChildTimeslotsSQL is the return value of $wpdb->prepare() above.
        $sRecurringChildTimeslotsResult = $wpdb->get_results($sRecurringChildTimeslotsSQL);
        
        ob_start();
            echo '<div class="recurring-timeslot-children-wrapper">';        
            if(!empty($sRecurringChildTimeslotsResult))
            {
                // One grouped count for the whole series rather than one query per child row.
                $aChildIds   = wp_list_pluck($sRecurringChildTimeslotsResult, 'id');
                $aUsedCounts = array();
                if(!empty($aChildIds))
                {
                    $sChildIdsPlaceholders = implode(', ', array_fill(0, count($aChildIds), '%s'));
                    $sChildUsedPrepared    = $wpdb->prepare(
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sChildIdsPlaceholders is a "%s, %s, ..." scaffold, not user data; every value is bound via the array_merge() below.
                        "SELECT timeslot_id, COUNT(*) as used_count FROM %i WHERE deleted is NULL AND timeslot_id IN ($sChildIdsPlaceholders) GROUP BY timeslot_id;",
                        array_merge(array($wpdb->prefix . 'tpfw_timeslot_tickets'), $aChildIds)
                    );
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sChildUsedPrepared is the return value of $wpdb->prepare() above.
                    $aUsedCounts = wp_list_pluck($wpdb->get_results($sChildUsedPrepared), 'used_count', 'timeslot_id');

                    // Caption over the occurrences, so an open rule reads as rule-then-output.
                    echo '<div class="recurring-timeslot-children-head">' . esc_html__('Generated timeslots', 'tickets-passes-for-woocommerce') . '</div>';
                }

                foreach($sRecurringChildTimeslotsResult as $iRecurringChildKey => $oRecurringChild)
                {
                    $this->render_timeslot_row(array
                    (
                        'index'        => (int)$iRecurringChildKey + 1,
                        'id'           => $oRecurringChild->id,
                        'start'        => $oRecurringChild->start,
                        'end'          => $oRecurringChild->end,
                        'qty'          => (int)$oRecurringChild->available_slots,
                        'used'         => isset($aUsedCounts[$oRecurringChild->id]) ? (int)$aUsedCounts[$oRecurringChild->id] : 0,
                        'recurring_id' => $sReccuringTimeslotID,
                        'parent_id'    => $sReccuringTimeslotID,
                        'classes'      => 'timeslot-row recurring-timeslot-row-child',
                    ));
                }
            }
            else
            {
                 ?>
                 <div class="recurring-timeslot-children-text">
                    <?php echo '<h2><strong>'.esc_html__('No Timeslots Found', 'tickets-passes-for-woocommerce') . '</strong></h2>'; ?>
                    <?php echo '<p style="margin-left: 4px;">'.esc_html__('This recurring timeslot have no connected timeslots yet.', 'tickets-passes-for-woocommerce') . '</p>'; ?>
                    <?php echo '<p style="margin-left: 4px;">'.esc_html__('Timeslots are automaticly created/updated every hour, if the recurring timeslot have valid settings. You can wait for the next time timeslots are created/updated, or you can force timeslot creation by pressing the "Force Create" button next to the timeslot.', 'tickets-passes-for-woocommerce') . '</p>'; ?>
                </div>
                 <?php
            }
                ?>
                <input data-attr-reccuring-parent-id="<?php echo esc_attr($sReccuringTimeslotID); ?>" type="button" class="timeslot-action-manual-add-timeslot-tickets" value="<?php echo esc_attr__('+ Add Manual Timeslot', 'tickets-passes-for-woocommerce'); ?>">
                
                <?php    
            echo '</div>';
        $sHTMLContent = ob_get_clean();

        $response['sMessage']     = __('timeslot children fetched', 'tickets-passes-for-woocommerce');
        $response['sHTMLContent'] = $sHTMLContent;
        wp_send_json_success($response);
    }
}

    // Declared at file scope, outside TPFW_Timeslot_Ticket_WC_Product: WooCommerce instantiates
    // this by name from the woocommerce_product_class filter, so it has to exist even when the
    // admin class above is never constructed. The class_exists() guard keeps a double include
    // harmless.
    if(!class_exists('TPFW_Product_Timeslot_Ticket'))
    {
        /**
         * WooCommerce product class backing the 'tpfw-timeslot-ticket' product type.
         *
         * Behaviourally a simple product - all the timeslot logic lives in
         * TPFW_Timeslot_Ticket_WC_Product and in the {prefix}timeslot* tables, so only the type
         * slug needs overriding here.
         */
        class TPFW_Product_Timeslot_Ticket extends WC_Product
        {
            /**
             * @return string The WooCommerce product type slug.
             */
            public function get_type()
            {
                return 'tpfw-timeslot-ticket';
            }
        }
    }