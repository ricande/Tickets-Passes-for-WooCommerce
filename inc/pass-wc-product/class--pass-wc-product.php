<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * The "Pass" WooCommerce product type.
 *
 * A pass is a named, multi-use entitlement: the buyer enters one person per pass on the
 * product page, each becomes its own cart line, and each paid line produces one row in
 * {prefix}pass with its own QR code. A pass may optionally grant a number of guest passes,
 * stored in the same table with parent_nano_id_fk pointing back at the parent, and may be
 * gifted by supplying the recipient's email - in which case user_id holds the recipient and
 * user_payer_id the buyer.
 *
 * Check-ins live in {prefix}pass_stats. Both tables are soft deleted (a non-null `deleted`
 * timestamp) rather than purged, so cancelling and reinstating an order keeps the customer's
 * original QR codes working.
 */
require_once dirname(__FILE__) . '/../product-type/class--product-type.php';

class TPFW_Pass_WC_Product extends TPFW_Product_Type
{
	protected $sType         = 'pass';
	protected $sTable        = 'tpfw_pass';
	protected $sStatsTable   = 'tpfw_pass_stats';
	protected $sMetaPrefix   = '_tpfw_pass_';
	protected $sProductClass = 'TPFW_Product_Pass';

	/**
	 * True while tpfw_annual_pass_add_to_cart_action() is adding the per-person lines, so the
	 * add-to-cart hooks those inner calls fire can tell themselves apart from the customer's.
	 *
	 * @var bool
	 */
	protected $bSplittingCart = false;

	/**
	 * Registers every WordPress/WooCommerce hook this class owns.
	 *
	 * Called once from the constructor; nothing else in the class registers hooks, so this
	 * is the single place to look for what the Pass product type reacts to.
	 *
	 * @return void
	 */
	protected function load_settings_dependencies()
	{
		add_action('admin_enqueue_scripts',                         array($this, 'enqueue_script_admin'));
		add_action('wp_enqueue_scripts',                            array($this, 'enqueue_script_front'));

		// Front end purchase flow: the per-person fields on the product page are turned into one
		// cart line per person, then carried onto the order line as item meta.
		add_filter('woocommerce_before_add_to_cart_button',         array($this, 'tpfw_pass_product_html'));
		add_filter('woocommerce_add_to_cart_validation',            array($this, 'tpfw_pass_add_to_cart_validation'),                    10, 3);
		add_action('woocommerce_add_to_cart',                       array($this, 'tpfw_annual_pass_add_to_cart_action'),         10, 6);
		add_filter('woocommerce_get_item_data',                     array($this, 'tpfw_cart_display_meta_data'),                 10, 2);
		add_action('woocommerce_checkout_create_order_line_item',   array($this, 'tpfw_add_pass_meta_to_order_line'),            10, 4);
		// One pass per line - the quantity is expressed by the number of person rows instead.
		add_filter('woocommerce_is_sold_individually',              array($this, 'wc_remove_quantity_field_from_cart'),         10, 2);

		add_action('woocommerce_order_status_processing',            array($this, 'order_maybe_issue'), 10, 1);
		add_action('woocommerce_order_status_completed',            array($this, 'order_maybe_issue'), 10, 1);

		// Cancelled / refunded / failed must all revoke the pass, otherwise a refunded customer
		// keeps a scannable QR code. cancel_annual_pass() is idempotent.
		add_action('woocommerce_order_status_cancelled',            array($this, 'order_cancelled'),   10, 1);
		add_action('woocommerce_order_status_refunded',             array($this, 'order_cancelled'),   10, 1);
		add_action('woocommerce_order_status_failed',               array($this, 'order_cancelled'),   10, 1);

		// Product type registration and the admin product-data UI.
		add_filter('product_type_selector',                         array($this, 'tpfw_add_custom_tpfw_pass_product_type'));
		add_filter('woocommerce_product_class',                     array($this, 'tpfw_woocommerce_pass_product_class'),         10, 3);
		// Priority 9999 so the show_if_/hide_if_ classes are appended after every other
		// extension has finished adding its own tabs.
		add_filter('woocommerce_product_data_tabs',                 array($this, 'tpfw_woocommerce_pass_data_tabs'), 9999);
		add_filter('woocommerce_product_data_tabs',                 array($this, 'tpfw_pass_product_tab'));
		add_action('woocommerce_tpfw-pass_add_to_cart',                  array($this, 'tpfw_pass_add_to_cart_html'));

		// Four panels: settings and QR artwork, once for the pass itself and once for its guest passes.
		add_action('woocommerce_product_data_panels',               array($this, 'tpfw_wc_pass_settings_tab_content'));
		add_action('woocommerce_product_data_panels',               array($this, 'tpfw_wc_pass_qr_tab_content'));
		add_action('woocommerce_product_data_panels',               array($this, 'tpfw_wc_guest_settings_tab_content'));
		add_action('woocommerce_product_data_panels',               array($this, 'tpfw_wc_guest_qr_tab_content'));
		add_action('woocommerce_process_product_meta_tpfw-pass',         array($this, 'tpfw_wc_pass_settings_tab_save'));
		add_action('woocommerce_process_product_meta_tpfw-pass',         array($this, 'tpfw_wc_pass_qr_tab_save'));
		add_action('woocommerce_process_product_meta_tpfw-pass',         array($this, 'tpfw_wc_guestpass_settings_tab_save'));
		add_action('woocommerce_process_product_meta_tpfw-pass',         array($this, 'tpfw_wc_guestpass_qr_tab_save'));

		add_action('wp_ajax_tpfw_ajax_settings_preview_pass_qr',         array($this, 'ajax_settings_preview_pass_qr_callback'));
		add_action('wp_ajax_tpfw_ajax_settings_preview_guestpass_qr',    array($this, 'ajax_settings_preview_guestpass_qr_callback'));
		add_action('wp_ajax_tpfw_ajax_checkin_pass',                     array($this, 'ajax_checkin_pass_callback'));
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
                'aNonces'                     => $this->oFunctions->get_ajax_nonces(array('ajax_settings_preview_guestpass_qr', 'ajax_settings_preview_pass_qr')),
                'sGuestpassQRPlaceholderLogo'  => TPFW_PLUGIN_URL . 'images/qr-logo-placeholder.webp',
                'sPassQRPlaceholderLogo' => TPFW_PLUGIN_URL . 'images/qr-logo-placeholder.webp',
                'aTranslations'                => array
                (
                    'sInsertQRLogo'   => __('Upload/Insert Logo', 'tickets-passes-for-woocommerce'),
                    'sUseSelected'    => __('Use Selected', 'tickets-passes-for-woocommerce'),
                    // A failed render used to leave the previous preview image on screen, so the
                    // shop owner would go on tweaking colours against a stale QR code.
                    'sPreviewFailed'  => __('The preview could not be generated. Please try again.', 'tickets-passes-for-woocommerce'),
                ),
            );
            
            wp_register_script($this->sPrefix.'pass-wc-product', plugins_url('', __FILE__).'/js/pass-wc-product.js', array('jquery', $this->sPrefix.'functions-admin'), filemtime(dirname(__FILE__).'/js/pass-wc-product.js'), true);
	        wp_enqueue_script($this->sPrefix.'pass-wc-product');
	        wp_localize_script($this->sPrefix.'pass-wc-product', 'tpfwParamsPassWcProduct', $aParams);


            wp_enqueue_style($this->sPrefix.'pass.module', plugins_url('', __FILE__).'/css/pass.module.css', array(), filemtime(dirname(__FILE__).'/css/pass.module.css')); 
        }
    }

    /**
     * Loads the front end stylesheet for the per-person pass fields on the product page.
     *
     * @return void
     */
    public function enqueue_script_front()
    {
        // Both assets are only for a pass product page; the stylesheet used to load on every
        // front-end request.
        if(is_product())
        {
            $oProduct = wc_get_product(get_the_ID());
            if(is_a($oProduct, 'TPFW_Product_Pass'))
            {
                wp_enqueue_style($this->sPrefix.'pass.front', plugins_url('', __FILE__).'/css/pass.front.css', array(), filemtime(dirname(__FILE__).'/css/pass.front.css'));
                wp_register_script($this->sPrefix.'pass.front', plugins_url('', __FILE__).'/js/pass.front.js', array('jquery'), filemtime(dirname(__FILE__).'/js/pass.front.js'), true);
                wp_enqueue_script($this->sPrefix.'pass.front');
            }
        }
    }

    /**
     * Adds "Pass Product" to the product type dropdown in the product data box.
     *
     * @param array $types Existing product types, keyed by slug.
     * @return array Types with 'tpfw-pass' added.
     */
    public function tpfw_add_custom_tpfw_pass_product_type($types)
    {
        $types['tpfw-pass']     = __('Pass Product', 'tickets-passes-for-woocommerce');
        return $types;
    }



    /**
     * Maps the 'tpfw-pass' product type onto the TPFW_Product_Pass class.
     *
     * @param string $classname         Class WooCommerce resolved for this product.
     * @param string $product_type      Product type slug.
     * @param string $product_variation Post type ('product' or 'product_variation').
     * @return string Class name to instantiate.
     */
    public function tpfw_woocommerce_pass_product_class($classname, $product_type, $product_variation)
    {
        // See the note in TPFW_Ticket_WC_Product: WooCommerce cannot derive a prefixed class name
        // from the type slug, so without this the product loads as WC_Product_Simple.
        if($product_type == 'tpfw-pass' && $product_variation == 'product')
        {
            return 'TPFW_Product_Pass';
        }
        return $classname;
    }
    



    /**
     * Tags the stock WooCommerce product tabs as shown or hidden for the Pass type.
     *
     * @param array $tabs Product data tabs.
     * @return array Tabs with show_if_tpfw-pass / hide_if_tpfw-pass classes appended.
     */
    public function tpfw_woocommerce_pass_data_tabs($tabs) 
    {
        $tabs['inventory']['class'][]               = 'show_if_tpfw-pass';
        if(isset($tabs['marketplace-suggestions'])) { $tabs['marketplace-suggestions']['class'][] = 'hide_if_tpfw-pass'; }
        $tabs['shipping']['class'][]                = 'hide_if_tpfw-pass';
        $tabs['linked_product']['class'][]          = 'hide_if_tpfw-pass';
        $tabs['attribute']['class'][]               = 'hide_if_tpfw-pass';
        $tabs['variations']['class'][]              = 'hide_if_tpfw-pass';
        $tabs['advanced']['class'][]                = 'hide_if_tpfw-pass';
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
    public function tpfw_pass_add_to_cart_html() 
    {
        global $post;        
        if(get_post_meta($post->ID, '_tpfw_pass_sales_timespan_enable', true) == 'yes')
        {
            if(!$this->oFunctions->is_within_sales_window(get_post_meta($post->ID, '_tpfw_pass_sales_timespan_start', true), get_post_meta($post->ID, '_tpfw_pass_sales_timespan_end', true))) return;
        }
        
        $this->oFunctions->render_product_info_table($post->ID, array(
            'show_max_uses'   => '_tpfw_pass_show_max_uses',
            'max_uses'        => '_tpfw_pass_max_uses',
            'show_date'       => '_tpfw_pass_show_predefined_date',
            'show_valid_from' => '_tpfw_pass_show_valid_from',
            'show_valid_to'   => '_tpfw_pass_show_valid_to',
            'start_enable'    => '_tpfw_pass_predefined_start_date_enable',
            'start_date'      => '_tpfw_pass_predefined_start_date',
            'duration'        => '_tpfw_pass_valid_duration',
            'show_sales'      => '_tpfw_pass_show_sales_window',
            'sales_enable'    => '_tpfw_pass_sales_timespan_enable',
            'sales_start'     => '_tpfw_pass_sales_timespan_start',
            'sales_end'       => '_tpfw_pass_sales_timespan_end',
        ), $this->oFunctions->get_front_color_style('sPassColor'));

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook, fired here so the stock add-to-cart template renders for this product type.
        do_action( 'woocommerce_simple_add_to_cart' );
    }



    /**
     * Adds the plugin's four Pass panels to the product data box.
     *
     * Two for the pass itself (settings and QR artwork) and two for the guest passes it grants.
     *
     * @param array $tabs Product data tabs.
     * @return array Tabs with the four pass panels added.
     */
    public function tpfw_pass_product_tab($tabs) 
    {         
        global $post;        
        $tabs['pass-settings-general'] = array
        (
            'label'  => __( 'Pass Settings', 'tickets-passes-for-woocommerce'),
            'target' => 'pass_product_settings_general',
            'class'  => 'show_if_tpfw-pass',
        );

        $tabs['pass-settings-qr'] = array
        (
            'label'  => __( 'Pass QR', 'tickets-passes-for-woocommerce'),
            'target' => 'pass_product_settings_qr',
            'class'  => 'show_if_tpfw-pass',
        );

        $tabs['guestpass-settings-general'] = array
        (
            'label'  => __('Guest Pass Settings', 'tickets-passes-for-woocommerce'),
            'target' => 'guest_product_settings_general',
            'class'  => 'show_if_tpfw-pass',
        );
                                                
        $tabs['guestpass-settings-qr'] = array
        (
            'label'  => __('Guest Pass QR', 'tickets-passes-for-woocommerce'),
            'target' => 'guest_product_settings_qr',
            'class'  => 'show_if_tpfw-pass',
        );
        
        return $tabs;
    }







    /**
     * Renders the "Guest Pass QR" panel: label text, the three colours, logo upload and a live preview.
     *
     * Guest passes get their own artwork so a scanner operator can tell them apart from the
     * parent pass at a glance.
     *
     * @return void
     */
    public function tpfw_wc_guest_qr_tab_content()
    {
        $this->oFunctions->render_qr_tab('guestpass', 'guest_product_settings_qr', 'guest', __('Guest Pass', 'tickets-passes-for-woocommerce'));
    }



    /**
     * Renders the "Pass QR" panel: label text, the three colours, logo upload and a live preview.
     *
     * Each value falls back to a sensible default when the meta key has never been saved.
     *
     * @return void
     */
    public function tpfw_wc_pass_qr_tab_content() 
    {
        $this->oFunctions->render_qr_tab('pass', 'pass_product_settings_qr', 'annual', __('Pass', 'tickets-passes-for-woocommerce'));
    }

    /**
     * Renders the "Guest Pass Settings" panel: whether guest passes are granted, how many,
     * how long each is valid and the cooldown between guest check-ins.
     *
     * @return void
     */
    public function tpfw_wc_guest_settings_tab_content()
    {
        global $post;
        ?>
        <div id='guest_product_settings_general' class='panel woocommerce_options_panel'>
            <div class="tpfw-cards">
                <?php
                    $_pass_guest_pass_enable = get_post_meta($post->ID, '_tpfw_pass_guest_pass_enable', true);
                    $sHideClass = ($_pass_guest_pass_enable == 'yes' ) ? '' : 'hide';

                    $_pass_guest_pass_quantity = get_post_meta($post->ID, '_tpfw_pass_guest_pass_quantity', true);
                    if(empty($_pass_guest_pass_quantity))
                    {
                        $iQuantityValue = 1;
                    }
                    else
                    {
                        $iQuantityValue = $_pass_guest_pass_quantity;
                    }

                    $_guestpass_cooldown_sec = get_post_meta($post->ID, '_tpfw_guestpass_cooldown_sec', true);
                    if(empty($_guestpass_cooldown_sec))
                    {
                        $iCooldownValue = 1;
                    }
                    else
                    {
                        $iCooldownValue = (int)$_guestpass_cooldown_sec;
                    }

                    $_pass_guest_pass_valid_duration = get_post_meta($post->ID, '_tpfw_pass_guest_pass_valid_duration', true);
                    if(empty($_pass_guest_pass_valid_duration))
                    {
                        $iValidAfterValue = DAY_IN_SECONDS;
                    }
                    else
                    {
                        $iValidAfterValue = (int)$_pass_guest_pass_valid_duration;
                    }
                ?>
                <div class="tpfw-card tpfw-card--guest">
                    <div class="tpfw-card-header">
                        <svg class="tpfw-card-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.3 2.7-5 6-5s6 1.7 6 5"/><path d="M17 8h4M19 6v4"/></svg>
                        <h3 class="tpfw-card-title"><?php echo esc_html__('Guest Passes', 'tickets-passes-for-woocommerce'); ?></h3>
                    </div>
                    <div class="tpfw-card-body">
                        <?php
                            woocommerce_wp_checkbox( array(
                                'id'          => '_tpfw_pass_guest_pass_enable',
                                'label'       => __('Guest Pass', 'tickets-passes-for-woocommerce'),
                                'value'       => $_pass_guest_pass_enable,
                                'description' => __('Enable guest pass options for customers who purchase this product.', 'tickets-passes-for-woocommerce'),
                            ));

                            woocommerce_wp_text_input(
                                array(
                                    'id'                => '_tpfw_pass_guest_pass_quantity',
                                    'label'             => __('Number of Guest Pass\'s', 'tickets-passes-for-woocommerce'),
                                    'type'              => 'number',
                                    'value'             => $iQuantityValue,
                                    'wrapper_class'     => $sHideClass,
                                    'description'       => __('How many guest passes a customer receives when purchasing this product.', 'tickets-passes-for-woocommerce'),
                                    'custom_attributes' => array(
                                                                    'step' => 'any',
                                                                    'min'  => '1',
                                                                    'max'  => '20',
                                                                )
                                )
                            );

                            $this->oFunctions->render_duration_field(
                                '_tpfw_guestpass_cooldown_sec',
                                __('Guest Pass Checkin Cooldown', 'tickets-passes-for-woocommerce'),
                                __('The minimum time required between check-ins on a guest pass that allows multiple uses.', 'tickets-passes-for-woocommerce'),
                                $iCooldownValue,
                                $sHideClass
                            );

                            $this->oFunctions->render_duration_field(
                                '_tpfw_pass_guest_pass_valid_duration',
                                __('Valid After Parent Check-in', 'tickets-passes-for-woocommerce'),
                                __('How long after the primary pass is checked in that its guest passes remain valid for check-in.', 'tickets-passes-for-woocommerce'),
                                $iValidAfterValue,
                                $sHideClass
                            );
                        ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    

    /**
     * Renders the "Pass Settings" panel: the cards shared with tickets plus the profile photo card.
     *
     * @return void
     */
    public function tpfw_wc_pass_settings_tab_content()
    {
        global $post;
        $bUpload   = $this->meta($post->ID, 'profile_image_upload_enable') == 'yes';
        $aAlgos    = array();
        if(function_exists('imagewebp')) $aAlgos['gd']      = 'GD';
        if(class_exists('Imagick'))      $aAlgos['imagick'] = 'Imagick';
        if(empty($aAlgos))               $aAlgos['non']     = __('Not available', 'tickets-passes-for-woocommerce');
        ?>
        <div id='pass_product_settings_general' class='panel woocommerce_options_panel'>
            <div class="tpfw-cards tpfw-cards--grid">
                <?php $this->render_settings_cards('annual', __('pass', 'tickets-passes-for-woocommerce')); ?>

                <div class="tpfw-card tpfw-card--annual">
                    <div class="tpfw-card-header">
                        <svg class="tpfw-card-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4z"/><circle cx="12" cy="10" r="2.5"/><path d="M7.5 17c1-2.2 2.6-3.2 4.5-3.2s3.5 1 4.5 3.2"/></svg>
                        <h3 class="tpfw-card-title"><?php echo esc_html__('Profile Photos', 'tickets-passes-for-woocommerce'); ?></h3>
                    </div>
                    <div class="tpfw-card-body">
                        <?php
                            woocommerce_wp_checkbox(array(
                                'id'          => '_tpfw_pass_profile_image_upload_enable',
                                'label'       => __('Profile Image Upload', 'tickets-passes-for-woocommerce'),
                                'value'       => $bUpload ? 'yes' : 'no',
                                'description' => __('Enable profile photo upload for customers who purchase this product.', 'tickets-passes-for-woocommerce'),
                            ));
                            woocommerce_wp_select(array(
                                'id'            => '_tpfw_pass_profile_image_upload_compression_algo',
                                'label'         => __('Image Compression Algorithm', 'tickets-passes-for-woocommerce'),
                                'options'       => $aAlgos,
                                'value'         => $this->meta($post->ID, 'profile_image_upload_compression_algo', 'non'),
                                'wrapper_class' => $bUpload ? '' : 'hide',
                                'description'   => __('Controls which library compresses uploaded profile photos. If no options are available, your host has neither the GD nor ImageMagick extension installed, so users won\'t be able to upload a photo.', 'tickets-passes-for-woocommerce'),
                            ));
                            woocommerce_wp_text_input(array(
                                'id'                => '_tpfw_pass_profile_image_upload_max_size',
                                'label'             => __('Max Image Size (MB)', 'tickets-passes-for-woocommerce'),
                                'type'              => 'number',
                                'value'             => $this->meta($post->ID, 'profile_image_upload_max_size', 8),
                                'wrapper_class'     => $bUpload ? '' : 'hide',
                                'description'       => __('The maximum file size, in MB, allowed for an uploaded profile photo.', 'tickets-passes-for-woocommerce'),
                                'custom_attributes' => array('step' => '1', 'min' => '1', 'max' => '1000'),
                            ));
                        ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
 

    /**
     * Persists the "Guest Pass QR" panel and regenerates its preview image.
     *
     * @param int $post_id Product id being saved.
     * @return void
     */
    public function tpfw_wc_guestpass_qr_tab_save($post_id)
    {
        $this->oFunctions->save_qr_tab('guestpass', $post_id);
    }



    /**
     * Persists the "Pass QR" panel and regenerates its preview image.
     *
     * @param int $post_id Product id being saved.
     * @return void
     */
    public function tpfw_wc_pass_qr_tab_save($post_id)
    {
        $this->oFunctions->save_qr_tab('pass', $post_id);
    }
    

    /**
     * Persists the "Guest Pass Settings" panel.
     *
     * Turning guest passes off clears quantity, duration and cooldown, so a later re-enable
     * cannot silently reuse stale values.
     *
     * @param int $post_id Product id being saved.
     * @return void
     */
    public function tpfw_wc_guestpass_settings_tab_save($post_id)
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
        $_pass_guest_pass_enable = sanitize_text_field(wp_unslash($_POST['_tpfw_pass_guest_pass_enable'] ?? ''));
        update_post_meta($post_id, '_tpfw_pass_guest_pass_enable', $_pass_guest_pass_enable);
        if($_pass_guest_pass_enable == 'yes')
        {            
            // Whole numbers only: the count is a loop bound and the two durations are seconds.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
            $_pass_guest_pass_quantity       = absint($_POST['_tpfw_pass_guest_pass_quantity'] ?? 0);
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
            $_guestpass_cooldown_sec               = absint($_POST['_tpfw_guestpass_cooldown_sec'] ?? 0);
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
            $_pass_guest_pass_valid_duration = absint($_POST['_tpfw_pass_guest_pass_valid_duration'] ?? 0);
            if(!empty($_pass_guest_pass_quantity))
            {
                update_post_meta($post_id, '_tpfw_pass_guest_pass_quantity', $_pass_guest_pass_quantity);
            }            
            if(!empty($_guestpass_cooldown_sec))
            {
                update_post_meta($post_id, '_tpfw_guestpass_cooldown_sec', $_guestpass_cooldown_sec);
            }
            if(!empty($_pass_guest_pass_valid_duration))
            {
                update_post_meta($post_id, '_tpfw_pass_guest_pass_valid_duration', $_pass_guest_pass_valid_duration);
            }
        }
        else
        {
            delete_post_meta($post_id, '_tpfw_pass_guest_pass_quantity');               
            delete_post_meta($post_id, '_tpfw_guestpass_cooldown_sec');        
            delete_post_meta($post_id, '_tpfw_pass_guest_pass_valid_duration');        
        }
    }


    /**
     * Persists the "Pass Settings" panel: the shared cards plus the profile photo settings.
     *
     * The compression method is validated against the libraries the server actually has, so a
     * product can never be saved pointing at an extension that is not installed.
     *
     * @param int $post_id Product id being saved.
     * @return void
     */
    public function tpfw_wc_pass_settings_tab_save($post_id)
    {
        $this->save_settings_cards($post_id);

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
        $bUpload = sanitize_text_field(wp_unslash($_POST['_tpfw_pass_profile_image_upload_enable'] ?? '')) == 'yes';
        update_post_meta($post_id, '_tpfw_pass_profile_image_upload_enable', $bUpload ? 'yes' : 'no');
        if($bUpload)
        {
            $aAvailable = array();
            if(function_exists('imagewebp')) $aAvailable[] = 'gd';
            if(class_exists('Imagick'))      $aAvailable[] = 'imagick';
            $sAlgo = sanitize_key(wp_unslash($_POST['_tpfw_pass_profile_image_upload_compression_algo'] ?? ''));
            if(in_array($sAlgo, $aAvailable, true))
            {
                update_post_meta($post_id, '_tpfw_pass_profile_image_upload_compression_algo', $sAlgo);
                update_post_meta($post_id, '_tpfw_pass_profile_image_upload_max_size', max(1, absint($_POST['_tpfw_pass_profile_image_upload_max_size'] ?? 8)));
                return;
            }
        }
        delete_post_meta($post_id, '_tpfw_pass_profile_image_upload_compression_algo');
        delete_post_meta($post_id, '_tpfw_pass_profile_image_upload_max_size');
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

	/**
	 * Issues (or reinstates) the pass for one paid order line.
	 *
	 * One pass per order line - the quantity is expressed as separate lines, one per person, by
	 * tpfw_annual_pass_add_to_cart_action(). If a row already exists for this product/order/line
	 * it is un-deleted and its check-in history cleared rather than a second pass being minted,
	 * so a completed -> refunded -> completed cycle keeps the customer's original QR code valid.
	 *
	 * When the line carries an 'email' meta the pass is a gift: the recipient is looked up (or
	 * created) from that address and stored in user_id, while the buyer stays in user_payer_id,
	 * and the recipient is emailed either the new-user or the existing-user template.
	 *
	 * @param int           $iOrderID    Order id.
	 * @param int           $iCustomerID Purchasing customer's user id (0 for a guest order).
	 * @param WC_Order_Item $oOrderItem  The line item being fulfilled.
	 * @return array{sMessage:string,bStatus:bool} Result suitable for an order note.
	 */
	public function create_pass($iOrderID, $iCustomerID, $oOrderItem)
    {                
        $oParentProduct     = wc_get_product($oOrderItem->get_product_id());
        $iPassMaxUses = get_post_meta($oParentProduct->get_id(), '_tpfw_pass_max_uses', true);
        if($iPassMaxUses === null || $iPassMaxUses <= 0 || $iPassMaxUses === false || $iPassMaxUses === "")
        {            
            return array(
                'sMessage' => __('Pass max usage does not seem to be set', 'tickets-passes-for-woocommerce'),
                'bStatus' => false,
            );
        }

        $iProductValidDuration  = get_post_meta($oParentProduct->get_id(), '_tpfw_pass_valid_duration', true);                //get_field('valid_duration_days', $oParentProduct->get_id());        
        if($iProductValidDuration === null || $iProductValidDuration <= 0 || $iProductValidDuration === false || $iProductValidDuration === "")
        {
            return array(
                'sMessage' => $oParentProduct->get_name().' (#'.$oParentProduct->get_id() . ') - ' . __('is missing a valid duration value', 'tickets-passes-for-woocommerce'),
                'bStatus'  => false,
            );                    
        }

        global $wpdb;
        $oExistsPrepared = $wpdb->prepare(
            'SELECT * FROM %i WHERE parent_nano_id_fk IS NULL AND product_id = %d AND order_id = %d AND order_line_id = %d ORDER BY -deleted;',
            array(
				$wpdb->prefix . 'tpfw_pass',                 
                $oOrderItem->get_product_id(),                 
                $iOrderID, 
                $oOrderItem->get_id()
            )            
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oExistsPrepared is the return value of $wpdb->prepare() above.
        $aExistsResult = $wpdb->get_results($oExistsPrepared);

        if(!empty($aExistsResult))
        {
            $oExistsResult                 = $aExistsResult[0];
            $iProductID                    = $oExistsResult->product_id;
            $sPassTableName          = $wpdb->prefix . "tpfw_pass";
            $sPassStatisticTableName = $wpdb->prefix . "tpfw_pass_stats";
            $sCurrentDatetime              = current_time('mysql');

            // Reinstates the guest passes along with the parent (cancel soft-deletes both), so the
            // guest QR codes the customer already handed out keep working instead of My Account
            // minting a fresh set.
            $sUpdatePassSQL 		        = $wpdb->prepare('	UPDATE %i
                                                                    SET deleted = null, updated = %s
                                                                    WHERE nano_id = %s OR parent_nano_id_fk = %s', $sPassTableName, $sCurrentDatetime, $oExistsResult->nano_id, $oExistsResult->nano_id);
                                                                
            $sPassStatisticDeleteSQL 		= $wpdb->prepare('	DELETE FROM %i
                                                                    WHERE nano_id_fk = %s', $sPassStatisticTableName, $oExistsResult->nano_id);

            $sGuestPassStatisticDeleteSQL 		= $wpdb->prepare('	DELETE FROM %i
                                                                    WHERE nano_id_fk IN (SELECT nano_id FROM %i WHERE parent_nano_id_fk = %s)', $sPassStatisticTableName, $sPassTableName, $oExistsResult->nano_id);

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sUpdatePassSQL is the return value of $wpdb->prepare() above.
            $wpdb->get_results($sUpdatePassSQL);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sPassStatisticDeleteSQL is the return value of $wpdb->prepare() above.
            $wpdb->get_results($sPassStatisticDeleteSQL);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sGuestPassStatisticDeleteSQL is the return value of $wpdb->prepare() above.
            $wpdb->get_results($sGuestPassStatisticDeleteSQL);

            $oOrderItem->update_meta_data('tpfw_pass_id_1', $oExistsResult->nano_id);                                            
            $oOrderItem->save();

            $this->oFunctions->write_scanner_qr($iProductID, 'pass', $oExistsResult->nano_id);
        }
        else
        {            
            $oCustomerUser    = get_user_by('ID', $iCustomerID);
            $sGeneratedNanoID = $this->oFunctions->generateNanoId();
            $iUserID          = $iCustomerID;
            if($oOrderItem->get_meta('tpfw_email') !== null && $oOrderItem->get_meta('tpfw_email') != "" && filter_var($oOrderItem->get_meta('tpfw_email'), FILTER_VALIDATE_EMAIL))
            {
                $oUser   = $this->oFunctions->get_pass_user($oOrderItem->get_meta('tpfw_email'));
                // get_pass_user() returns false when wp_create_user() fails; the pass then goes to
                // the buyer rather than being inserted with user_id 0 and a "new user" email with
                // no password in it.
                if($oUser === false)
                {
                    return array(
                        'sMessage' => __('Could not create a customer account for the gifted pass recipient', 'tickets-passes-for-woocommerce') . ': ' . $oOrderItem->get_meta('tpfw_email'),
                        'bStatus'  => false,
                    );
                }
                $iUserID = $oUser['iUserID'];
                if(!$oUser['bUserExisted'])
                {			                    
                    $aReplacementText                   = $this->oFunctions->gifted_pass_email_new_user_replacement(wc_get_order($iOrderID), $sGeneratedNanoID, $oOrderItem->get_name(), $oOrderItem, $oOrderItem->get_meta('tpfw_email'), $oUser['sSetPasswordURL']);
                    $aSettingsOptions                   = get_option('tpfw_email_settings_options', array());
                    $gifted_pass_email_new_user_message = $this->oFunctions->get_email_setting('gifted_pass_email_new_user', 'message');
                    $gifted_pass_email_new_user_subject = $this->oFunctions->get_email_setting('gifted_pass_email_new_user', 'subject');
                    $sEmail                             = $oOrderItem->get_meta('tpfw_email');
                    $sMessage                           = '';
    
                    ob_start();
                    if($gifted_pass_email_new_user_message != "" && $gifted_pass_email_new_user_subject)
                    {
                        foreach($aReplacementText as $sReplacementKey => $sReplacementText)
                        {
                            $gifted_pass_email_new_user_message  = str_replace($sReplacementKey, $sReplacementText, $gifted_pass_email_new_user_message);
                            $gifted_pass_email_new_user_subject  = str_replace($sReplacementKey, $sReplacementText, $gifted_pass_email_new_user_subject);
                        }
                        echo wp_kses_post($gifted_pass_email_new_user_message);
                    }			
                    $sMessage = ob_get_clean();

                    // A missing/unconfigured email template must not block the pass
                    // itself from being created - just skip sending the notification.
                    if($sMessage != "" && $sMessage != null && $sMessage != false)
                    {
                        $aHeaders = array(
                            'Content-Type: text/html; charset=UTF-8',
                        );

                        $this->oFunctions->tpfw_custom_enmail($sEmail, $gifted_pass_email_new_user_subject, $sMessage, $aHeaders);
                    }
                }
                // $oCustomerUser is false on a guest order (customer id 0), so its email has to be
                // read defensively - dereferencing it fatalled the whole order-completed hook and
                // left the pass uncreated. A guest buyer can never be the recipient, so treating
                // the address as "different" and sending the notification is the correct fallback.
                else if($oUser['bUserExisted'] && $oOrderItem->get_meta('tpfw_email') != ($oCustomerUser ? $oCustomerUser->user_email : ''))
                {                    
                    $aReplacementText                        = $this->oFunctions->gifted_pass_email_existing_user_replacement(wc_get_order($iOrderID), $sGeneratedNanoID, $oOrderItem->get_name(), $oOrderItem);
                    $aSettingsOptions                        = get_option('tpfw_email_settings_options', array());
                    $gifted_pass_email_existing_user_message = $this->oFunctions->get_email_setting('gifted_pass_email_existing_user', 'message');
                    $gifted_pass_email_existing_user_subject = $this->oFunctions->get_email_setting('gifted_pass_email_existing_user', 'subject');
                    $sEmail                                  = $oOrderItem->get_meta('tpfw_email');                    
                    $sMessage                                = '';
    
                    ob_start();
                    if($gifted_pass_email_existing_user_message != "" && $gifted_pass_email_existing_user_subject)
                    {
                        foreach($aReplacementText as $sReplacementKey => $sReplacementText)
                        {
                            $gifted_pass_email_existing_user_message  = str_replace($sReplacementKey, $sReplacementText, $gifted_pass_email_existing_user_message);
                            $gifted_pass_email_existing_user_subject  = str_replace($sReplacementKey, $sReplacementText, $gifted_pass_email_existing_user_subject);
                        }
                        echo wp_kses_post($gifted_pass_email_existing_user_message);
                    }			
                    $sMessage = ob_get_clean();

                    // A missing/unconfigured email template must not block the pass
                    // itself from being created - just skip sending the notification.
                    if($sMessage != "" && $sMessage != null && $sMessage != false)
                    {
                        $aHeaders = array(
                            'Content-Type: text/html; charset=UTF-8',
                        );

                        $this->oFunctions->tpfw_custom_enmail($sEmail, $gifted_pass_email_existing_user_subject, $sMessage, $aHeaders);
                    }
                }
            }

            $sStartDate = current_time('mysql');            
            if(get_post_meta($oOrderItem->get_product_id(), '_tpfw_pass_predefined_start_date_enable', true) == 'yes')
            {                
                $sStartDate = gmdate('Y-m-d H:i:s', strtotime(get_post_meta($oOrderItem->get_product_id(), '_tpfw_pass_predefined_start_date', true)));
            }
            // A date the customer picked wins over both defaults - it is the one they were shown,
            // and it was written onto the order line at checkout.
            $sPickedStartDate = $oOrderItem->get_meta('tpfw_start_date');
            if(!empty($sPickedStartDate))
            {
                $sStartDate = gmdate('Y-m-d H:i:s', strtotime($sPickedStartDate));
            }
            $sEndDate = gmdate('Y-m-d H:i:s', (strtotime($sStartDate)+(int)$iProductValidDuration));
            
            $oCreatePassPrepared = $wpdb->prepare(
                'INSERT INTO %i (nano_id, product_id, user_id, user_payer_id, order_id, order_line_id, firstname, lastname, valid_duration, valid_from, valid_to, max_uses, created, updated)
                VALUES (%s, %d,  %d, %d, %d, %d, %s, %s, %d, %s, %s, %d, %s, %s);',
                array(
				$wpdb->prefix . 'tpfw_pass', 
                    $sGeneratedNanoID, 
                    $oOrderItem->get_product_id(), 
                    $iUserID, 
                    $iCustomerID, 
                    $iOrderID, 
                    $oOrderItem->get_id(), 
                    $oOrderItem->get_meta('tpfw_firstname'), 
                    $oOrderItem->get_meta('tpfw_lastname'), 
                    $iProductValidDuration, 
                    $sStartDate,
                    $sEndDate,
                    $iPassMaxUses,
                    current_time('mysql'),
                    current_time('mysql'),                
                )            
            );        
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oCreatePassPrepared is the return value of $wpdb->prepare() above.
            $wpdb->query($oCreatePassPrepared);
            
            $oOrderItem->update_meta_data('tpfw_pass_id_1', $sGeneratedNanoID);                                            
            $oOrderItem->save();
            
            $this->oFunctions->write_scanner_qr($oOrderItem->get_product_id(), 'pass', $sGeneratedNanoID);
        }

    
        
        return array(
            'sMessage' => __('Pass(\'s) for order line created', 'tickets-passes-for-woocommerce'),
            'bStatus' => true,
        );    
    }

    /**
     * Soft deletes a pass, its guest passes and all their check-in records, and removes the QR files.
     *
     * Safe to call repeatedly - an already-cancelled line matches nothing and returns early.
     *
     * @param int           $iOrderID    Order id.
     * @param int           $iCustomerID Purchasing customer's user id, matched against user_payer_id.
     * @param WC_Order_Item $oOrderItem  The line item being cancelled.
     * @return array{sMessage:string,bStatus:bool} Result suitable for an order note.
     */
    public function cancel_annual_pass($iOrderID, $iCustomerID, $oOrderItem)
    {
        global $wpdb;
        $oExistsPrepared = $wpdb->prepare(
            // Filter on user_payer_id (the purchaser), not user_id - create_pass() stores the
            // purchaser in user_payer_id and, for a gifted pass, the *recipient's* user id (looked
            // up from the associated email) in user_id. Filtering on user_id with $iCustomerID
            // (always the purchaser) matched nothing whenever the pass had an associated email
            // different from the buyer, so cancellation silently found no row to delete.
            'SELECT * FROM %i WHERE product_id = %d AND order_id = %d AND order_line_id = %d AND user_payer_id = %d AND deleted IS NULL;',
            array(
				$wpdb->prefix . 'tpfw_pass',
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
                'sMessage' => __('Pass for this order line already seems to be cancelled', 'tickets-passes-for-woocommerce'),
                'bStatus' => false,
            );
        }

        
		
        $iQuantity                     = 0;
        $oOrder                        = wc_get_order($iOrderID);
        $sCurrentDatetime              = current_time('mysql');
        $sPassTableName          = $wpdb->prefix . "tpfw_pass";
        $sPassStatisticTableName = $wpdb->prefix . "tpfw_pass_stats";
        $sGuestPassStatisticTableName  = $sPassStatisticTableName;
        foreach($oExistsResult as $iExistResultKey => $oExistResult)
        {
            $oDeletePassPrepared = $wpdb->prepare(
                'UPDATE %i
                 SET deleted = %s
                 WHERE id = %d',
                array(
				$wpdb->prefix . 'tpfw_pass',                 
                    current_time('mysql'),                                     
                    $oExistResult->id
					)            
				);
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oDeletePassPrepared is the return value of $wpdb->prepare() above.
			$wpdb->query($oDeletePassPrepared);            
            
            $sUpdatePassSQL 	= $wpdb->prepare('	UPDATE %i
                                SET deleted = %s
                                WHERE nano_id = %s OR parent_nano_id_fk = %s', $sPassTableName, $sCurrentDatetime, $oExistResult->nano_id, $oExistResult->nano_id);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sUpdatePassSQL is the return value of $wpdb->prepare() above.
            $wpdb->get_results($sUpdatePassSQL);

            $sPassStatisticUpdateSQL 		= $wpdb->prepare('	UPDATE %i
                                                SET deleted = %s, updated = %s
                                                WHERE nano_id_fk = %s', $sPassStatisticTableName, $sCurrentDatetime, $sCurrentDatetime, $oExistResult->nano_id);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sPassStatisticUpdateSQL is the return value of $wpdb->prepare() above.
            $wpdb->get_results($sPassStatisticUpdateSQL);

            $sGuestPassStatisticUpdateSQL 		= $wpdb->prepare('	UPDATE %i
                                                SET deleted = %s, updated = %s
                                                WHERE nano_id_fk IN (SELECT nano_id FROM %i WHERE parent_nano_id_fk = %s)', $sGuestPassStatisticTableName, $sCurrentDatetime, $sCurrentDatetime, $sPassTableName, $oExistResult->nano_id);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sGuestPassStatisticUpdateSQL is the return value of $wpdb->prepare() above.
            $wpdb->get_results($sGuestPassStatisticUpdateSQL);

            $this->oFunctions->delete_qr_code($oExistResult->nano_id);
			$iQuantity++;			
			$oOrderItem->delete_meta_data('tpfw_pass_id_'.$iQuantity);
			$oOrderItem->save();
			$oOrder->add_order_note(__('Pass with ID', 'tickets-passes-for-woocommerce') . ': ' .$oExistResult->nano_id . ' ' . __('cancelled from order', 'tickets-passes-for-woocommerce'));
			$oOrder->save();										
        }

        return array(
            'sMessage' => __('All related Pass\'s have been cancelled', 'tickets-passes-for-woocommerce'),
            'bStatus' => true,
        );
    }

    /**
     * Forces passes to be sold individually so the cart shows no quantity stepper.
     *
     * Each person is already their own cart line, so a quantity above one there would silently
     * create passes with no name attached to them.
     *
     * @param bool       $return  Whether WooCommerce already considers the product sold individually.
     * @param WC_Product $product Product being tested.
     * @return bool True for passes in a cart context, otherwise the incoming value.
     */
    public function wc_remove_quantity_field_from_cart($return, $product)
    {
        // is_cart() alone only covers the classic Cart page - the Mini Cart drawer and
        // Cart/Checkout blocks fetch cart data via the Store API instead, so quantity
        // there was never actually blocked. is_wc_cart_request() covers both.
        if($this->oFunctions->is_wc_cart_request() && is_a($product, 'TPFW_Product_Pass')) { return true; }
        return $return;
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
        return $this->create_pass($iOrderID, $iCustomerID, $oOrderItem);
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
        return $this->cancel_annual_pass($iOrderID, $iCustomerID, $oOrderItem);
    }

	/**
	 * Copies the person's name and optional email from the cart item onto the order line item.
	 *
	 * This is what survives into the order, and what create_pass() later reads to decide who the
	 * pass belongs to.
	 *
	 * @param WC_Order_Item_Product $item          Order line being built.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart item data.
	 * @param WC_Order              $order         Order being created.
	 * @return void
	 */
	public function tpfw_add_pass_meta_to_order_line($item, $cart_item_key, $values, $order) 
	{
        $oProduct     = wc_get_product($item->get_product_id());
        if(is_a($oProduct, 'TPFW_Product_Pass'))
        {
			if(!empty($values['email']) && isset($values['email']) && $values['email'] != "") 
			{
				$item->update_meta_data('tpfw_email', sanitize_email($values['email']));                
			}

            if(!empty($values['firstname']) && isset($values['firstname']) && $values['firstname'] != "") 
			{
				$item->update_meta_data('tpfw_firstname', sanitize_text_field($values['firstname']));                
			}

            if(!empty($values['lastname']) && isset($values['lastname']) && $values['lastname'] != "") 
			{
				$item->update_meta_data('tpfw_lastname', sanitize_text_field($values['lastname']));                
			}

			if(!empty($values['tpfw_start_date']))
			{
				$item->update_meta_data('tpfw_start_date', sanitize_text_field($values['tpfw_start_date']));
				$this->oFunctions->add_valid_to_order_item_meta($item, $values['tpfw_start_date'], '_tpfw_pass_valid_duration');
			}
        }
    } 

    
    /**
     * Server-side add-to-cart checks for a pass.
     *
     * Runs before WooCommerce writes anything to the cart, so a refusal here leaves the cart
     * untouched - unlike tpfw_annual_pass_add_to_cart_action(), which fires after the line
     * already exists. Checks, in order: the sales window (the buy button is hidden outside it,
     * but a direct POST is not), that every one of the requested persons has a first and last
     * name (and an email where the email toggle is on), and - when the customer picks the start
     * date - that a valid date inside the offered range was posted.
     *
     * @param bool $passed           Whether validation has passed so far.
     * @param int  $product_id       Product being added.
     * @param int  $request_quantity Quantity WooCommerce intends to add.
     * @return bool False to block the add-to-cart, otherwise the incoming value.
     */
    public function tpfw_pass_add_to_cart_validation($passed, $product_id, $request_quantity)
    {
        $oProduct = wc_get_product($product_id);
        if(!is_a($oProduct, 'TPFW_Product_Pass')) return $passed;

        // The per-person lines added by tpfw_annual_pass_add_to_cart_action() come back through
        // this filter too; they carry no persons[] and have already been checked.
        if($this->bSplittingCart) return $passed;

        if(get_post_meta($product_id, '_tpfw_pass_sales_timespan_enable', true) == 'yes'
            && !$this->oFunctions->is_within_sales_window(get_post_meta($product_id, '_tpfw_pass_sales_timespan_start', true), get_post_meta($product_id, '_tpfw_pass_sales_timespan_end', true)))
        {
            wc_add_notice(__('This product is not available for purchase right now.', 'tickets-passes-for-woocommerce'), 'error');
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its own add-to-cart nonce; this only reads the posted roster.
        $aPersons = map_deep(wp_unslash($_POST['persons'] ?? array()), 'sanitize_text_field');
        if(!is_array($aPersons) || count($aPersons) != max(1, absint($request_quantity)))
        {
            wc_add_notice(__('Please enter a name for every person on this pass.', 'tickets-passes-for-woocommerce'), 'error');
            return false;
        }
        foreach($aPersons as $aPerson)
        {
            if(!is_array($aPerson) || trim($aPerson['firstname'] ?? '') === '' || trim($aPerson['lastname'] ?? '') === '')
            {
                wc_add_notice(__('Please enter a first and last name for every person on this pass.', 'tickets-passes-for-woocommerce'), 'error');
                return false;
            }
            if(isset($aPerson['emailchecked']) && !is_email($aPerson['email'] ?? ''))
            {
                wc_add_notice(__('Please enter a valid email address for every person you chose to email.', 'tickets-passes-for-woocommerce'), 'error');
                return false;
            }
        }

        if(get_post_meta($product_id, '_tpfw_pass_user_start_date_enable', true) != 'yes') return $passed;

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its own add-to-cart nonce; this only reads the posted date.
        $sStartDate = sanitize_text_field(wp_unslash($_POST['tpfw-start-date'] ?? ''));
        if(!$this->oFunctions->is_valid_ymd($sStartDate))
        {
            wc_add_notice(__('Please pick a start date before adding this to the cart.', 'tickets-passes-for-woocommerce'), 'error');
            return false;
        }

        // Both ends compared as date strings rather than timestamps, so the near end does not
        // expire at midday and the far end stays inclusive of its own date.
        $sMinDate = get_post_meta($product_id, '_tpfw_pass_user_start_date_min', true);
        $sMaxDate = get_post_meta($product_id, '_tpfw_pass_user_start_date_max', true);
        $sToday   = gmdate('Y-m-d', current_time('timestamp'));
        if($sMinDate == '' || $sMinDate < $sToday) $sMinDate = $sToday;

        if($sStartDate < $sMinDate || ($sMaxDate != '' && $sStartDate > $sMaxDate))
        {
            wc_add_notice(__('Please pick a date within the dates offered for this pass.', 'tickets-passes-for-woocommerce'), 'error');
            return false;
        }

        return $passed;
    }

/**
     * Splits one "add to cart" of N passes into N single-quantity cart lines, one per named person.
     *
     * The product page collects a name (and optionally an email) per person; this replaces the
     * original cart item with one line per person so each pass carries its own holder details.
     * A unique_key is added to every line to stop WooCommerce merging them back together.
     *
     * @param string $cart_id          Cart item key of the line just added.
     * @param int    $product_id       Product added.
     * @param int    $request_quantity Quantity requested, which must match the number of person rows.
     * @param int    $variation_id     Variation id (unused for passes).
     * @param array  $variation        Variation attributes (unused for passes).
     * @param array  $cart_item_data   Custom cart item data.
     * @return void
     */
    public function tpfw_annual_pass_add_to_cart_action($cart_id, $product_id, $request_quantity, $variation_id, $variation, $cart_item_data )
    {
        $oCart        = WC()->cart;
        $oProduct     = wc_get_product($product_id);
        if(!is_a($oProduct, 'TPFW_Product_Pass')) return;
        if(is_admin() && !defined('DOING_AJAX')) return;
        // The per-person add_to_cart() calls below re-fire this same hook. The flag is what
        // stops the recursion; did_action() counting (the old guard) also tripped whenever any
        // other plugin had added to the cart earlier in the same request, which left the
        // original unnamed quantity-N line in place.
        if($this->bSplittingCart) return;

        // The roster was validated in tpfw_pass_add_to_cart_validation() before the cart was
        // touched; what is left here is the split itself.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its own add-to-cart nonce; this only reads the posted roster.
        $aPersons = map_deep(wp_unslash($_POST['persons'] ?? array()), 'sanitize_text_field');
        if(!is_array($aPersons) || empty($aPersons)) return;

        // The same picked date applies to every person on this add-to-cart, so read it once.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies its own add-to-cart nonce; this only reads the posted date.
        $sCustomerStartDate = sanitize_text_field(wp_unslash($_POST['tpfw-start-date'] ?? ''));

        $this->bSplittingCart = true;
        try
        {
            foreach($aPersons as $oPerson)
            {
                $aCartData = array(
                    'unique_key' => md5(microtime().wp_rand()),
                    'firstname'  => sanitize_text_field($oPerson['firstname'] ?? ''),
                    'lastname'   => sanitize_text_field($oPerson['lastname'] ?? ''),
                );
                if(isset($oPerson['emailchecked']))
                {
                    $aCartData['email'] = sanitize_email($oPerson['email'] ?? '');
                }
                if($sCustomerStartDate !== '') $aCartData['tpfw_start_date'] = $sCustomerStartDate;
                $oCart->add_to_cart($product_id, 1, $variation_id, array(), $aCartData);
            }
            $oCart->remove_cart_item($cart_id);
        }
        finally
        {
            $this->bSplittingCart = false;
        }
    }

    /**
     * Shows the pass holder's name, email and validity window as line item meta in the cart.
     *
     * @param array $item_data      Meta rows already queued for display.
     * @param array $cart_item_data The cart item.
     * @return array Item data with the pass details appended for pass products.
     */
    public function tpfw_cart_display_meta_data($item_data, $cart_item_data) 
    {                
        if(isset($cart_item_data['product_id']))
        {
            $oProduct     = wc_get_product($cart_item_data['product_id']);    
        }
        if(!empty($oProduct) && $oProduct != null && is_a($oProduct, 'TPFW_Product_Pass'))
        {
            if(isset($cart_item_data['firstname']) && isset($cart_item_data['lastname']) && isset($cart_item_data['firstname']) != "" && isset($cart_item_data['lastname']) != "")
            {
                $item_data[] = array(
                    'key'   => __('Name', 'tickets-passes-for-woocommerce'),
                    'value' => wc_clean($cart_item_data['firstname']) . ' ' . wc_clean($cart_item_data['lastname']),
                );
            }                        
            if(isset($cart_item_data['email']))
            {
                $item_data[] = array(
                    'key'   => __('Email', 'tickets-passes-for-woocommerce'),
                    'value' => wc_clean($cart_item_data['email']),
                );
            }

            $sDateTimeFormat    = $this->oFunctions->get_datetime_format('date');            
            $sStartDate         = gmdate($sDateTimeFormat);            
            if(get_post_meta($cart_item_data['product_id'], '_tpfw_pass_predefined_start_date_enable', true) == 'yes')
            {                
                $sStartDate = gmdate($sDateTimeFormat, strtotime(get_post_meta($cart_item_data['product_id'], '_tpfw_pass_predefined_start_date', true)));
            }
            // A date the customer picked wins over both defaults - it is the one they were shown.
            if(!empty($cart_item_data['tpfw_start_date']))
            {
                $sStartDate = gmdate($sDateTimeFormat, strtotime($cart_item_data['tpfw_start_date']));
            }
            $sEndDate = gmdate($sDateTimeFormat, (strtotime($sStartDate)+(int)get_post_meta($cart_item_data['product_id'], '_tpfw_pass_valid_duration', true)));

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
     * Renders the per-person fields above the add-to-cart button on a pass product page.
     *
     * The inline script keeps the number of person rows in sync with the quantity input and
     * blocks submission until every visible row has a name (and a valid email where the "email
     * this person" box is ticked). Server side validation still runs in
     * tpfw_annual_pass_add_to_cart_action() - this is only there to save a round trip.
     *
     * Colours come from the plugin's general settings so the block matches the shop's theme.
     *
     * @return void
     */
    public function tpfw_pass_product_html()
	{
		global $post;
		if(is_product() && is_singular('product'))
		{
            $oProduct = wc_get_product($post->ID);        			
			if(is_a($oProduct, 'TPFW_Product_Pass'))
			{
				$sPassHintText = __('Add a name for each person this pass covers. You can optionally email someone their own copy of the pass.', 'tickets-passes-for-woocommerce');
				$aGeneralSettings    = get_option('tpfw_general_settings_options');
				if(isset($aGeneralSettings['sPassHintText']) && $aGeneralSettings['sPassHintText'] != '')
				{
					$sPassHintText = $aGeneralSettings['sPassHintText'];
				}

				$sColorAccent     = !empty($aGeneralSettings['sPassColorAccent'])     ? $aGeneralSettings['sPassColorAccent']     : '#000000';
				$sColorText       = !empty($aGeneralSettings['sPassColorText'])       ? $aGeneralSettings['sPassColorText']       : '#000000';
				$sColorBorder     = !empty($aGeneralSettings['sPassColorBorder'])     ? $aGeneralSettings['sPassColorBorder']     : '#000000';
				$sColorBackground = !empty($aGeneralSettings['sPassColorBackground']) ? $aGeneralSettings['sPassColorBackground'] : '#ffffff';
				$sColorHint       = !empty($aGeneralSettings['sPassColorHint'])       ? $aGeneralSettings['sPassColorHint']       : '#646970';
				$sColorAccentFg   = $this->oFunctions->get_contrast_text_color($sColorAccent);
				$sColorStyle      = sprintf(
					'--tpfw-accent:%s;--tpfw-accent-fg:%s;--tpfw-ink:%s;--tpfw-border:%s;--tpfw-bg:%s;--tpfw-hint:%s;',
					esc_attr($sColorAccent), esc_attr($sColorAccentFg), esc_attr($sColorText), esc_attr($sColorBorder), esc_attr($sColorBackground), esc_attr($sColorHint)
				);

				if(get_post_meta($post->ID, '_tpfw_pass_user_start_date_enable', true) == 'yes')
				{
					$this->oFunctions->render_customer_start_date_picker(
						$sColorStyle,
						__('Select a Start Date', 'tickets-passes-for-woocommerce'),
						__('Pick the date this pass should be valid from.', 'tickets-passes-for-woocommerce'),
						get_post_meta($post->ID, '_tpfw_pass_user_start_date_min', true),
						get_post_meta($post->ID, '_tpfw_pass_user_start_date_max', true)
					);
				}
				?>
				<div class="season-passes tpfw-roster" data-count-one="<?php /* translators: %d: number of people on the pass. */ echo esc_attr__('%d person', 'tickets-passes-for-woocommerce'); ?>" data-count-many="<?php /* translators: %d: number of people on the pass. */ echo esc_attr__('%d people', 'tickets-passes-for-woocommerce'); ?>" style="<?php echo esc_attr($sColorStyle); ?>">
                    <div class="tpfw-roster-head">
                        <div>
                            <label for="type"><?php echo esc_html__('Passes', 'tickets-passes-for-woocommerce'); ?></label>
                            <p class="tpfw-roster-hint"><?php echo esc_html($sPassHintText); ?></p>
                        </div>
                        <?php /* Writes the WooCommerce quantity input below and lets its change handler do the rest, so the party size is set on the block it fills rather than under it. */ ?>
                        <div class="tpfw-stepper">
                            <button type="button" data-step="-1" aria-label="<?php echo esc_attr__('One fewer person', 'tickets-passes-for-woocommerce'); ?>">&minus;</button>
                            <span class="tpfw-stepper-value" data-tpfw-count>1</span>
                            <button type="button" data-step="1" aria-label="<?php echo esc_attr__('One more person', 'tickets-passes-for-woocommerce'); ?>">+</button>
                        </div>
                    </div>
                    <ul class="value tpfw-roster-list">
                        <li class="season-pass-person tpfw-roster-row">
                            <?php /* Number while the row is empty, initials once it is named - the row's whole status readout. */ ?>
                            <span class="tpfw-avatar" data-tpfw-avatar>1</span>
                            <div class="tpfw-namefield">
                                <input class="season-pass-person-firstname" name="persons[1][firstname]" type="text" value="" placeholder="<?php echo esc_attr__('Firstname', 'tickets-passes-for-woocommerce'); ?>">
                                <input class="season-pass-person-lastname" name="persons[1][lastname]" type="text" value="" placeholder="<?php echo esc_attr__('Lastname', 'tickets-passes-for-woocommerce'); ?>">
                            </div>
                            <div class="tpfw-rowtools">
                                <?php /* The checkbox stays in the form so the posted shape is unchanged - the button is only its label. */ ?>
                                <button type="button" class="tpfw-iconbtn season-pass-email-toggle" aria-pressed="false" title="<?php echo esc_attr__('Email this person their own pass', 'tickets-passes-for-woocommerce'); ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 7 10 6 10-6"/></svg></button>
                                <input class="season-pass-email-checkbox" type="checkbox" name="persons[1][emailchecked]" hidden />
                            </div>
                            <p class="field-error tpfw-error-name"><?php echo esc_html__('Enter a first and last name.', 'tickets-passes-for-woocommerce'); ?></p>
                            <div class="email-box tpfw-roster-email">
                                <input class="season-pass-person-email" name="persons[1][email]" type="text" value="" placeholder="<?php echo esc_attr__('Email address', 'tickets-passes-for-woocommerce'); ?>">
                                <p class="field-error tpfw-error-email"><?php echo esc_html__('Enter a valid email address.', 'tickets-passes-for-woocommerce'); ?></p>
                                <p class="tpfw-roster-email-hint"><?php echo esc_html__('They get their own copy of the pass at this address.', 'tickets-passes-for-woocommerce'); ?></p>
                            </div>
                        </li>
                    </ul>
                    <button type="button" class="tpfw-roster-add" data-step="1"><?php echo esc_html__('+ Add another person', 'tickets-passes-for-woocommerce'); ?></button>
                    <div class="tpfw-roster-foot">
                        <span><?php
                            printf(
                                /* translators: 1: number of people named so far, 2: total number of people on the pass. */
                                esc_html__('%1$s of %2$s named', 'tickets-passes-for-woocommerce'),
                                '<strong data-tpfw-named>0</strong>',
                                '<strong data-tpfw-total>1</strong>'
                            );
                        ?></span>
                        <span class="tpfw-progress"><i data-tpfw-bar></i></span>
                    </div>
                </div>
				<?php
			}
		}
	}

    /**
     * AJAX: checks a pass in from the admin dashboard or the customer's My Account page.
     *
     * Either the current user manages the plugin, or the pass belongs to them - anything else is
     * rejected. Returns the refreshed usage counter so the calling table row can be updated
     * without a reload.
     *
     * @return void Sends a JSON envelope through wp_send_json_success()/wp_send_json_error() and exits.
     */
    public function ajax_checkin_pass_callback()
    {
        // Shared with the other product types - see TPFW_Product_Type::ajax_checkin_callback().
        $this->ajax_checkin_callback();
    }

	/**
	 * AJAX: renders a throwaway guest pass QR image so an admin can preview colour/logo changes
	 * before saving.
	 *
	 * Admin only. The image is written to the preview upload directory keyed by product id, so
	 * repeated previews overwrite rather than accumulate.
	 *
	 * @return void Sends a JSON envelope through wp_send_json_success()/wp_send_json_error() and exits.
	 */
	public function ajax_settings_preview_guestpass_qr_callback()
	{
		$this->oFunctions->render_qr_preview('guestpass');
	}

	/**
	 * AJAX: renders a throwaway pass QR image so an admin can preview colour/logo changes before saving.
	 *
	 * Admin only. The image is written to the preview upload directory keyed by product id, so
	 * repeated previews overwrite rather than accumulate.
	 *
	 * @return void Sends a JSON envelope through wp_send_json_success()/wp_send_json_error() and exits.
	 */
	public function ajax_settings_preview_pass_qr_callback()
	{
		$this->oFunctions->render_qr_preview('pass');
	}


}

    // Declared at file scope, outside TPFW_Pass_WC_Product: WooCommerce instantiates this by
    // name from the woocommerce_product_class filter, so it has to exist even when the admin
    // class above is never constructed. The class_exists() guard keeps a double include harmless.
    if(!class_exists('TPFW_Product_Pass'))
    {
        /**
         * WooCommerce product class backing the 'tpfw-pass' product type.
         *
         * Behaviourally a simple product - all the pass logic lives in TPFW_Pass_WC_Product and
         * in the {prefix}pass table, so only the type slug needs overriding here.
         */
        class TPFW_Product_Pass extends WC_Product
        {
            /**
             * @return string The WooCommerce product type slug.
             */
            public function get_type()
            {
                return 'tpfw-pass';
            }
        }
    }