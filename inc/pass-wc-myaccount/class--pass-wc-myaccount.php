<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Pass tab in WooCommerce My Account.
 *
 * Where a customer finds their pass, its QR, a printable PDF, their guest passes and the
 * profile photo on the pass. Every query here is scoped to the logged-in user in SQL.
 *
 * @package Tickets_Passes_For_WooCommerce
 */
class TPFW_Pass_WC_MyAccount
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
		$this->run();
	}


	/**
	 * Registers the endpoint, the tab, its assets and the three AJAX handlers.
	 *
	 * add_myaccount_pass_endpoint() is called directly rather than re-hooked to 'init': this
	 * class is itself constructed from an 'init' callback (see tpfw_init_plugin()), so a nested
	 * add_action('init', ...) would silently never fire and the endpoint would never exist.
	 *
	 * @return void
	 */
	public function run()
	{
		add_action('wp_enqueue_scripts', array($this, 'enqueue_css_pass'));

		add_action('wp_footer', array($this, 'enqueue_script_pass'));
		
		// Called directly rather than re-hooked to 'init': this class is itself constructed
		// from an 'init' callback (see tpfw_init_plugin() in tickets-passes-for-woocommerce.php), so by this point
		// WordPress has already moved past the default priority - a nested add_action('init', ...)
		// here would silently never fire, and add_rewrite_endpoint() would never run.
		$this->add_myaccount_pass_endpoint();

		add_action('query_vars', array($this, 'myaccount_pass_query_vars'), 0);
		
		add_action('woocommerce_account_menu_items', array($this, 'add_new_myaccount_pass_tab'));
		
		add_action('woocommerce_account_tpfw-pass_endpoint', array($this, 'myaccount_pass_tab_content'));

		add_action('wp_ajax_tpfw_ajax_tpfw_profile_image_upload', array($this, 'ajax_tpfw_profile_image_upload_callback'));

		add_action('wp_ajax_tpfw_ajax_tpfw_get_myaccount_guestpass', array($this, 'ajax_tpfw_get_myaccount_guestpass_callback'));

		add_action('wp_ajax_tpfw_ajax_fetch_pass_pdf', array($this, 'ajax_fetch_pass_pdf_callback'));
	}
	/**
	 * Whether the current request is the Passes tab itself, rather than any other My Account page.
	 *
	 * Not is_wc_endpoint_url(): that only knows WooCommerce's own endpoints, and this one is
	 * registered straight through add_rewrite_endpoint().
	 *
	 * @return bool
	 */
	private function is_pass_tab()
	{
		global $wp;
		return is_account_page() && isset($wp->query_vars['tpfw-pass']);
	}
	/**
	 * Loads the tab's stylesheet on the Passes tab, and just its menu icon on the other
	 * My Account pages.
	 *
	 * @return void
	 */
	public function enqueue_css_pass()
    {
		if(!is_account_page()) return;

		$this->oFunctions->add_front_inline_style('.woocommerce-MyAccount-navigation-link--tpfw-pass>a::before{content:"";display:inline-block;width:1.05em;height:1.05em;margin-right:.5em;vertical-align:-.18em;background-color:currentColor;-webkit-mask:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'black\' stroke-width=\'1.8\'%3E%3Crect x=\'3\' y=\'4\' width=\'18\' height=\'16\' rx=\'2\'/%3E%3Cpath d=\'M9 4V2.8\'/%3E%3Ccircle cx=\'9\' cy=\'11\' r=\'2.2\'/%3E%3Cpath d=\'M5.6 16.6a3.6 3.6 0 0 1 6.8 0M14.5 10h4M14.5 13.5h4\'/%3E%3C/svg%3E") no-repeat center/contain;mask:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'black\' stroke-width=\'1.8\'%3E%3Crect x=\'3\' y=\'4\' width=\'18\' height=\'16\' rx=\'2\'/%3E%3Cpath d=\'M9 4V2.8\'/%3E%3Ccircle cx=\'9\' cy=\'11\' r=\'2.2\'/%3E%3Cpath d=\'M5.6 16.6a3.6 3.6 0 0 1 6.8 0M14.5 10h4M14.5 13.5h4\'/%3E%3C/svg%3E") no-repeat center/contain}');

		if($this->is_pass_tab())
    	{
        	wp_enqueue_style($this->sPrefix.'pass-myaccount', plugins_url('', __FILE__).'/css/pass-myaccount.css', array(), filemtime(dirname(__FILE__).'/css/pass-myaccount.css'));
		}
	}
	/**
	 * Loads the tab's script on the Passes tab.
	 *
	 * Hooked to 'wp_footer' rather than 'wp_enqueue_scripts' so it lands after the markup the
	 * script binds to.
	 *
	 * @return void
	 */
	public function enqueue_script_pass()
    {
		if(!$this->is_pass_tab()) return;

        $aParams = array
        (
			'aNonces'           => $this->oFunctions->get_ajax_nonces(array('ajax_fetch_pass_pdf', 'ajax_tpfw_get_myaccount_guestpass', 'ajax_tpfw_profile_image_upload')),
			'ajaxurl'            => admin_url('admin-ajax.php'),
			'sAVatarPlaceholder' => TPFW_PLUGIN_URL . 'images/avatar-placeholder.webp',
			'translations' =>  array
			(
				'sFailedGuestFetch'  => __('Failed to fetch guest passes.', 'tickets-passes-for-woocommerce'),
				'sImageGuidelines'   => __('Are you sure the image complies with our guidelines? You can NOT change the image afterwards', 'tickets-passes-for-woocommerce'),
				'sMissedNanoID'      => __('Nano ID not found', 'tickets-passes-for-woocommerce'),
				'sMissedRowID'       => __('Row ID not found', 'tickets-passes-for-woocommerce'),
				'sMissingFileInput'  => __('File input not found', 'tickets-passes-for-woocommerce'),
				'sOneFileMax'        => __('Please select only one file', 'tickets-passes-for-woocommerce'),
				'sMissingFile'       => __('Please select a file', 'tickets-passes-for-woocommerce'),
				'sFilesizeError'     => __('File size is greater than 8MB', 'tickets-passes-for-woocommerce'),
				'sFileTypeError'     => __('File type not allowed', 'tickets-passes-for-woocommerce'),
				'sGuestPass'         => __('Guest Pass', 'tickets-passes-for-woocommerce'),
				'sGuestPassID'       => __('Guest Pass ID', 'tickets-passes-for-woocommerce'),
				// A photo upload or a PDF download that never came back used to say nothing at
				// all, so the customer had no way to tell it apart from one that worked.
				'sUploadFailed'      => __('The upload could not be completed. Please try again.', 'tickets-passes-for-woocommerce'),
				'sDownloadFailed'    => __('The pass could not be downloaded. Please try again.', 'tickets-passes-for-woocommerce'),
			)
        );
        wp_register_script($this->sPrefix.'pass-wc-myaccount', plugins_url('', __FILE__).'/js/pass-wc-myaccount.js', array('jquery'), filemtime(dirname(__FILE__).'/js/pass-wc-myaccount.js'), true);
        wp_enqueue_script($this->sPrefix.'pass-wc-myaccount');
        wp_localize_script($this->sPrefix.'pass-wc-myaccount', 'tpfwParamsPassWcMyaccount', $aParams);
    }
	/**
	 * Registers the /tpfw-pass My Account endpoint.
	 *
	 * The rewrite flush this needs is deferred to tpfw_maybe_flush_rewrites() on 'wp_loaded'.
	 *
	 * @return void
	 */
	public function add_myaccount_pass_endpoint() 
	{
		add_rewrite_endpoint('tpfw-pass', EP_ROOT | EP_PAGES);
	}
	/**
	 * Whitelists the endpoint's query var.
	 *
	 * @param array $vars Public query vars.
	 * @return array
	 */
	public function myaccount_pass_query_vars($vars) 
	{
		$vars[] = 'tpfw-pass';
		return $vars;
	}
	/**
	 * Inserts the Pass tab into the My Account menu.
	 *
	 * Log out is pulled out and re-appended so it stays last - WooCommerce core has already
	 * added it by the time this filter runs, so a plain append would push it above the new tab.
	 *
	 * @param array $items Menu items, slug => label.
	 * @return array
	 */
	public function add_new_myaccount_pass_tab($items)
	{
		// Keep "Log out" last - it's already in $items by this point (WooCommerce core
		// adds it before this filter runs), so appending here would otherwise push it
		// above the tab being added.
		$logout = $items['customer-logout'] ?? null;
		unset($items['customer-logout']);
		$items['tpfw-pass']       = __("Passes", 'tickets-passes-for-woocommerce');
		if($logout !== null) $items['customer-logout'] = $logout;
		return $items;
	}
	/**
	 * Renders the Pass tab.
	 *
	 * @return void
	 */
	public function myaccount_pass_tab_content()
	{
		$aUserPass  = $this->get_user_orders(get_current_user_id());

		$aGeneralSettings = get_option('tpfw_general_settings_options');
		$sColorAccent     = !empty($aGeneralSettings['sPassColorAccent']) ? $aGeneralSettings['sPassColorAccent'] : '#000000';
		$sColorAccentSoft = $this->oFunctions->mix_hex_colors($sColorAccent, '#ffffff', 0.85);
		$sColorAccentHover = $this->oFunctions->mix_hex_colors($sColorAccent, '#000000', 0.15);
		$sMyAccountColorStyle = sprintf('--tpfw-pass-accent:%s;--tpfw-pass-accent-soft:%s;--tpfw-pass-accent-hover:%s;', esc_attr($sColorAccent), esc_attr($sColorAccentSoft), esc_attr($sColorAccentHover));

		include('template/page-content.php');
	}
	/**
	 * Reads a user's passes.
	 *
	 * Parent passes only (parent_nano_id_fk IS NULL) - guest passes are fetched on demand by
	 * ajax_tpfw_get_myaccount_guestpass_callback() and shown under the pass they belong to.
	 * Scoped to the user id in SQL rather than filtered afterwards.
	 *
	 * @param int $iUserID Owner to read for.
	 * @param int $iLimit  Rows per page.
	 * @param int $iOffset Rows to skip.
	 * @return array
	 */
	public function get_user_orders($iUserID, $iLimit = 200, $iOffset = 0)
	{
		global $wpdb;
        $sPassTableName 		= $wpdb->prefix . "tpfw_pass";
        $sUserPassSQL   		= $wpdb->prepare('SELECT * FROM %i WHERE user_id = %d and parent_nano_id_fk IS NULL AND deleted IS NULL ORDER BY created DESC, id DESC LIMIT %d OFFSET %d', $sPassTableName, $iUserID, $iLimit, $iOffset);
  		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sUserPassSQL is the return value of $wpdb->prepare() above.
  		return $wpdb->get_results($sUserPassSQL);
	}

    /**
     * Builds the printable PDF for one of the current user's passes.
     *
     * The lookup carries the current user id in the WHERE clause, so a guessed row or nano id
     * belonging to someone else simply matches nothing.
     *
     * @return void
     */
    function ajax_fetch_pass_pdf_callback()
    {
        $response = array();
        $response['sMessage'] = __('Something went wrong', 'tickets-passes-for-woocommerce');
        check_ajax_referer('tpfw_ajax_fetch_pass_pdf', 'security');

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
		$sPassPrepared = $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d AND user_id = %d AND nano_id = %s and deleted IS NULL;',
			array(
				$wpdb->prefix . 'tpfw_pass',                 
				$_ROW_ID, 
				get_current_user_id(),
				$_NANO_ID, 
				)            
			);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sPassPrepared is the return value of $wpdb->prepare() above.
		$aPassResult   = $wpdb->get_results($sPassPrepared);		   
		if(empty($aPassResult))
		{			
			$response['sMessage'] = __('Failed to find pass with the given ID', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
		}

		$aPDFDownload = $this->oFunctions->create_fetch_pass_pdf_qr($_NANO_ID);
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
    /**
     * Returns the guest passes hanging off one of the current user's passes, minting any missing.
     *
     * Guest passes are created lazily on first view rather than at purchase. Their validity
     * window stays NULL until the parent pass is checked in, so minting them up front cannot
     * burn the window. Quota is UNIQUE(parent_nano_id_fk, guest_slot) so parallel AJAX cannot
     * exceed N. The parent pass is matched on row id, nano id AND the current user id together.
     *
     * @return void
     */
    function ajax_tpfw_get_myaccount_guestpass_callback()
    {
        $response = array();
        $response['sMessage'] = __('Something went wrong', 'tickets-passes-for-woocommerce');
        check_ajax_referer('tpfw_ajax_tpfw_get_myaccount_guestpass', 'security');

        $wp_current_user = wp_get_current_user();
        if(!$wp_current_user->exists())
        {
            $response['sMessage'] = __('You must be logged in to upload an image', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
        }
        
		if(!isset($_POST['parent_nano_id']))
        {
			$response['sMessage'] = __('Nano ID seems to be missing', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
        }

		if(!isset($_POST['row_id']))
        {
			$response['sMessage'] = __('Nano ID seems to be missing', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
        }		

		$_ROW_ID         = absint(wp_unslash($_POST['row_id'] ?? ''));
		$_PARENT_NANO_ID = sanitize_text_field(wp_unslash($_POST['parent_nano_id'] ?? ''));

		global $wpdb;
		$oPassPrepared = $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d AND user_id = %d AND nano_id = %s and deleted IS NULL;',
			array(
				$wpdb->prefix . 'tpfw_pass',                 
				$_ROW_ID, 
				get_current_user_id(),
				$_PARENT_NANO_ID, 
				)            
			);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oPassPrepared is the return value of $wpdb->prepare() above.
		$aPassResult   = $wpdb->get_results($oPassPrepared);		   
		if(empty($aPassResult))
		{			
			$response['sMessage'] = __('Failed to find pass with the given ID', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
		}
		$oPassResult = $aPassResult[0];

		$_pass_guest_pass_quantity       = (int)get_post_meta($oPassResult->product_id, '_tpfw_pass_guest_pass_quantity', true);
		$_pass_guest_pass_valid_duration = (int)get_post_meta($oPassResult->product_id, '_tpfw_pass_guest_pass_valid_duration', true);
		$_pass_guest_pass_max_uses       = max(1, (int)$oPassResult->max_uses);

		$oIssuer = new TPFW_Guest_Pass_Issuer(array($this->oFunctions, 'generateNanoId'));
		$aGuestRows = $oIssuer->ensure_quota($wpdb, $oPassResult, $_pass_guest_pass_quantity, $_pass_guest_pass_valid_duration, $_pass_guest_pass_max_uses);
		$aTempNanoID = wp_list_pluck($aGuestRows, 'nano_id');
		
		if(empty($aTempNanoID))
		{
			$response['sMessage'] = __('Error occured when trying to get guest pass\'s', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
		}

		$iProductID            = $oPassResult->product_id;
		$aGuestPassURL = array();
		foreach($aTempNanoID as $sGuestPassNanoID)
		{
			if(!file_exists($this->oFunctions->get_guest_upload_dir().''.$sGuestPassNanoID.'.webp'))
			{			
				$this->oFunctions->write_scanner_qr($iProductID, 'guestpass', $sGuestPassNanoID);
			}
			$aGuestPassURL[] = $this->oFunctions->get_file_url('guest', $sGuestPassNanoID, 'webp', true);
		}

		$response['aGuestPassURL'] = $aGuestPassURL;
		$response['aGuestPassID']  = $aTempNanoID;
		$response['sMessage']      = __('Sucessfully fetched all guest pass\'s.', 'tickets-passes-for-woocommerce');
        wp_send_json_success($response);
    }
    /**
     * Stores a pass holder's profile photo.
     *
     * The file is re-encoded rather than moved: it is accepted from a logged-in customer and
     * ends up in the uploads directory, so anything that is not actually a decodable image is
     * rejected by the decode itself, and any payload smuggled into the original bytes does not
     * survive being re-written. It is also scaled down to 300px, since it is only ever shown as
     * a thumbnail on the pass.
     *
     * The row is matched on row id, nano id AND the current user id, so a tampered request
     * cannot attach a photo to somebody else's pass.
     *
     * @return void
     */
    function ajax_tpfw_profile_image_upload_callback()
    {
        $response = array();
        $response['sMessage'] = __('Something went wrong', 'tickets-passes-for-woocommerce');
        check_ajax_referer('tpfw_ajax_tpfw_profile_image_upload', 'security');

        $wp_current_user = wp_get_current_user();
        if(!$wp_current_user->exists())
        {
            $response['sMessage'] = __('You must be logged in to upload an image', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
        }

		if(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? '')) != 'POST') 
		{
			$response['sMessage'] = __('Failed to upload iamge', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
		}
        
		// order_id and user_id used to be required here. Neither gated anything: order_id was
		// only fed to wc_get_order() to check it resolved and never compared against the pass
		// row, and the posted user_id was never read - ownership is the "id + user_id +
		// nano_id" lookup below, which uses get_current_user_id() rather than anything posted.
		if(!isset($_POST['row_id']))
        {
			$response['sMessage'] = __('Row ID seems to be missing', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
        }
		
		if(!isset($_POST['nano_id']))
        {
			$response['sMessage'] = __('Nano ID seems to be missing', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
        }

		if(!class_exists('Imagick') && !function_exists('imagewebp'))
		{
			$response['sMessage'] = __('Compressions functions seems not to be available', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
		}

		$_ROW_ID                  	= absint(wp_unslash($_POST['row_id'] ?? ''));
        $_NANO_ID             		= preg_replace('/[^A-Za-z0-9\-]/', '', ((string) sanitize_text_field(wp_unslash($_POST['nano_id'] ?? ''))));

		
		global $wpdb;
		// %d for the row id (it is cast to int above), and "deleted IS NULL" to match the
		// sibling lookups in this class - without it a cancelled pass still accepted uploads.
		$sPassPrepared = $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d AND user_id = %d AND nano_id = %s AND deleted IS NULL;',
			array(
				$wpdb->prefix . 'tpfw_pass',
				$_ROW_ID,
				get_current_user_id(),
				$_NANO_ID,
				)
			);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sPassPrepared is the return value of $wpdb->prepare() above.
		$aPassResult   = $wpdb->get_results($sPassPrepared);
		   
		if(empty($aPassResult))
		{
			$response['sMessage'] = __('Failed to find pass with the given ID', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
		}
		$oPassResult                       = $aPassResult[0];
		
		$_pass_profile_image_upload_enable = get_post_meta($oPassResult->product_id, '_tpfw_pass_profile_image_upload_enable', true);
		if($_pass_profile_image_upload_enable != "yes")
		{
			$response['sMessage'] = __('Profile image upload is not enabled for this product', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
		}

		// The photo is what door staff compare the holder against, and the customer is told it
		// cannot be changed afterwards. Hiding the button was the only thing enforcing that; a
		// hand-built POST could swap the photo at will.
		if(!empty($oPassResult->profile_image_type))
		{
			$response['sMessage'] = __('A profile image has already been uploaded for this pass and cannot be changed.', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
		}

        if(!isset($_FILES['profile_image']) || !is_array($_FILES['profile_image']))
        {
			$response['sMessage'] = __('No image or invalid image uploaded', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
        }

		// PHP reports oversize/partial uploads through 'error' with an empty tmp_name; say so
		// rather than falling through to a misleading "not an image".
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP's own upload status code, cast to int; there is nothing textual to sanitize.
		$iUploadError = (int) ($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE);
		if($iUploadError !== UPLOAD_ERR_OK)
		{
			$response['sMessage'] = in_array($iUploadError, array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)
				? __('The uploaded file is too big', 'tickets-passes-for-woocommerce')
				: __('The upload did not complete. Please try again.', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is the path PHP generated for the upload, not user-supplied data.
		if(empty($_FILES['profile_image']['tmp_name']) || !is_uploaded_file($_FILES['profile_image']['tmp_name']))
		{
			$response['sMessage'] = __('No image or invalid image uploaded', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
		}
		
		
		if(empty(wp_check_filetype(basename(sanitize_file_name(wp_unslash($_FILES['profile_image']['name']))))['ext'])) 
		{
			$response['sMessage'] = __('Not image or invalid image uploaded', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
		} 

		$aAllowedMimeTypes = array('image/jpeg', 'image/png', 'image/webp');

		// The sniffed type is compared against the allowlist, not merely checked for being
		// non-empty: finfo answers "text/html" just as happily as "image/png", and the only
		// thing that used to reject it was getimagesize() further down. Both now have to agree.
		$oFinfo = finfo_open(FILEINFO_MIME_TYPE);
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is the path PHP generated for the upload, not user-supplied data; the file itself is validated by wp_check_filetype/finfo/getimagesize before use.
		$sSniffedMime = $oFinfo ? finfo_file($oFinfo, $_FILES['profile_image']['tmp_name']) : false;
		// The handle holds an open magic database for the rest of the request otherwise.
		if($oFinfo) { finfo_close($oFinfo); }
		if(!$sSniffedMime || !in_array($sSniffedMime, $aAllowedMimeTypes, true))
		{
			$response['sMessage'] = __('Not image or invalid image uploaded', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.PHP.NoSilencedErrors.Discouraged -- tmp_name is the path PHP generated for the upload, not user-supplied data; the file itself is validated by wp_check_filetype/finfo/getimagesize before use, and the @ only mutes getimagesize()'s warning on a non-image, which the false check below handles.
		$aImageSize = @getimagesize($_FILES['profile_image']['tmp_name']);
		if(!$aImageSize)
		{
			$response['sMessage'] = __('Not image or invalid image uploaded', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
		}
						
        // Second opinion on the same file: finfo above sniffs the magic bytes, this decodes the
        // image header. A file has to look like an allowed image to both.
        if(!in_array($aImageSize['mime'], $aAllowedMimeTypes, true))
        {
            $response['sMessage'] = __('File is not an image', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
        }

		if(!TPFW_Image_Limits::from_getimagesize($aImageSize))
		{
			$response['sMessage'] = __('The uploaded image is too large. Please use a smaller photo.', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}

		$_pass_profile_image_upload_max_size = (int)get_post_meta($oPassResult->product_id, '_tpfw_pass_profile_image_upload_max_size', true);                
		if(empty($_pass_profile_image_upload_max_size)) 
		{
			$_pass_profile_image_upload_max_size = 8;
		} 
		
		// "5000 < $iFileSize &&" made the max size check unreachable for small files and never
		// enforced the intended 5kb minimum on its own - the two limits are separate rules.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is the path PHP generated for the upload, not user-supplied data; the file itself is validated by wp_check_filetype/finfo/getimagesize before use.
		$iFileSize = filesize($_FILES['profile_image']['tmp_name']);
		if($iFileSize > ($_pass_profile_image_upload_max_size * 1024 * 1024))
        {
            $response['sMessage'] = __('The uploaded file is too big', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
        }

		$_pass_profile_image_upload_compression_algo = get_post_meta($oPassResult->product_id, '_tpfw_pass_profile_image_upload_compression_algo', true);
		// The check further up only refuses the upload when BOTH extensions are missing, so a
		// host with just one of them still reaches the branch for the other and used to fatal
		// on the first call into it.
		// GD is checked per format too: a GD built without WebP has no imagecreatefromwebp().
		$sGDReader = array('image/jpeg' => 'imagecreatefromjpeg', 'image/webp' => 'imagecreatefromwebp', 'image/png' => 'imagecreatefrompng');
		if(($_pass_profile_image_upload_compression_algo == 'gd'      && (!function_exists('imagecreatefromjpeg') || !function_exists($sGDReader[$aImageSize['mime']]))) ||
		   ($_pass_profile_image_upload_compression_algo == 'imagick' && !class_exists('Imagick')))
		{
			$response['sMessage'] = __('The image compression method selected for this product is not available on this server', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}

		if($_pass_profile_image_upload_compression_algo == 'gd')
		{
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is the path PHP generated for the upload, not user-supplied data; the file itself is validated by wp_check_filetype/finfo/getimagesize before use.
			$sTmpName = $_FILES['profile_image']['tmp_name'];

			if($aImageSize['mime'] == 'image/jpeg')     { $oGDimage = imagecreatefromjpeg($sTmpName); $sUploadedFileType = 'jpeg'; }
			elseif($aImageSize['mime'] == 'image/webp') { $oGDimage = imagecreatefromwebp($sTmpName); $sUploadedFileType = 'webp'; }
			elseif($aImageSize['mime'] == 'image/png')  { $oGDimage = imagecreatefrompng($sTmpName);  $sUploadedFileType = 'png';  }
			else
			{
				$response['sMessage'] = __('No valid Image compression for the given mime type', 'tickets-passes-for-woocommerce');
				wp_send_json_error($response);
			}

			if(!$oGDimage)
			{
				$response['sMessage'] = __('The image could not be read', 'tickets-passes-for-woocommerce');
				wp_send_json_error($response);
			}

			// The GD branch re-encoded at full resolution while the Imagick branch downscaled to
			// 300x300, so the same upload produced either a 20kb thumbnail or an 8mb original
			// purely on which extension the product happened to be set to. Both paths now cap at
			// the same box; -1 keeps the aspect ratio.
			$iScaleWidth = min(300, imagesx($oGDimage));
			$oGDscaled   = imagescale($oGDimage, $iScaleWidth, -1);
			if($oGDscaled)
			{
				imagedestroy($oGDimage);
				$oGDimage = $oGDscaled;
			}

			// Keep PNG transparency through the re-encode; without these two calls transparent
			// regions come out black.
			if($sUploadedFileType == 'png')
			{
				imagealphablending($oGDimage, false);
				imagesavealpha($oGDimage, true);
			}

			$sTargetPath = $this->oFunctions->get_profile_image_upload_dir() . $_NANO_ID . '.' . $sUploadedFileType;
			if($sUploadedFileType == 'jpeg')     { $bWritten = imagejpeg($oGDimage, $sTargetPath, 85); }
			elseif($sUploadedFileType == 'webp') { $bWritten = imagewebp($oGDimage, $sTargetPath, 85); }
			else                                 { $bWritten = imagepng($oGDimage, $sTargetPath, 8);   }

			// Without this the GD resource stayed allocated for the rest of the request - on a
			// large upload that is tens of megabytes held past the point it is needed.
			imagedestroy($oGDimage);
		}
		else if($_pass_profile_image_upload_compression_algo == 'imagick')
		{
			// Imagick throws rather than returning false; an uncaught ImagickException here was a
			// bare 500 to the customer instead of the JSON error the page knows how to show.
			try
			{
				$im = new Imagick();
				TPFW_Image_Limits::apply_imagick_limits($im);
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is the path PHP generated for the upload, not user-supplied data; the file itself is validated by wp_check_filetype/finfo/getimagesize before use.
				$im->readImage($_FILES['profile_image']['tmp_name']);
				$im->resizeImage(300,300,Imagick::FILTER_CATROM , 1, TRUE);
				$im->setImageFormat("webp");
				$im->setOption('webp:method', '6');
				$bWritten = $im->writeImage($this->oFunctions->get_profile_image_upload_dir() . $_NANO_ID . '.webp');
				$sUploadedFileType = 'webp';
				$im->clear();
			}
			catch(Exception $e)
			{
				$bWritten = false;
			}
		}
		else
		{
			$response['sMessage'] = __('No valid Image compression algo selected for this product', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
		}

		// The DB row used to be updated whether or not the file was written, which hid the
		// upload button for good and left a broken image the customer could never replace.
		if(empty($bWritten))
		{
			$response['sMessage'] = __('The image could not be saved. Please try again or contact the shop.', 'tickets-passes-for-woocommerce');
            wp_send_json_error($response);
		}

		// Files are named <nano_id>.<ext>, so swapping a PNG for a JPEG left the PNG behind
		// forever - orphaned, still web-reachable, and never counted against anything.
		foreach(array('jpeg', 'png', 'webp') as $sStaleType)
		{
			$sStalePath = $this->oFunctions->get_profile_image_upload_dir() . $_NANO_ID . '.' . $sStaleType;
			if($sStaleType !== $sUploadedFileType && file_exists($sStalePath))
			{
				wp_delete_file($sStalePath);
			}
		}

		$oUploadFileTypePrepared = $wpdb->prepare(
			'UPDATE %i
			SET profile_image_type = %s
			WHERE id = %d;',
			array(
				$wpdb->prefix . 'tpfw_pass',             
				$sUploadedFileType,
				$_ROW_ID,
			)            
		);		
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oUploadFileTypePrepared is the return value of $wpdb->prepare() above.
		$wpdb->query($oUploadFileTypePrepared);	
                
        $response['sMessage']   = __('You have successfully uploaded an image to your membership card.', 'tickets-passes-for-woocommerce');
        wp_send_json_success($response);
    }


}