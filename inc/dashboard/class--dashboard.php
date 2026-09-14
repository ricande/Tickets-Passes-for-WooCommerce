<?php
defined('ABSPATH') or die('No script kiddies please!');

if (!class_exists('WP_List_Table'))
{
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * Shared shell for the Tickets, Timeslot Tickets and Passes admin screens.
 *
 * Owns the menu entry, the assets, and the three AJAX row actions every screen has (resend,
 * reset, cancel). The three screens used to be three ~600-line copies of the same file that
 * differed only in table names, action names and a couple of labels; a subclass now sets those
 * as properties and overrides only what is genuinely different (the Passes screen's guest
 * passes and profile photos).
 *
 * @package Tickets_Passes_For_WooCommerce
 */
abstract class TPFW_Dashboard
{
	/** @var string Option/handle prefix ('tpfw'). */
	protected $sPrefix;
	/** @var TPFW_Functions Shared helper instance. */
	protected $oFunctions;

	/** @var string Admin page slug, e.g. 'tpfw-tickets'. */
	protected $sSlug;
	/** @var string Translated menu label. */
	protected $sMenuLabel;
	/** @var string Translated page heading. */
	protected $sPageTitle;
	/** @var string List-table class rendered on the screen. */
	protected $sTableClass;
	/** @var string Suffix of the row-action AJAX names: ajax_admin_{resend|reset|cancel}_{sActionKey}. */
	protected $sActionKey;
	/** @var string Suffix of the manual check-in AJAX name: ajax_checkin_{sCheckinKey}. */
	protected $sCheckinKey;
	/** @var string Row table without prefix, e.g. 'tpfw_tickets'. */
	protected $sTable;
	/** @var string TPFW_Functions method that resets one row by nano id. */
	protected $sResetMethod;
	/** @var string TPFW_Functions method that cancels one row by nano id. */
	protected $sCancelMethod;
	/** @var string Translated status label a reset row returns to ('Unused' / 'Active'). */
	protected $sResetStatusLabel;
	/** @var string Email template key used by Resend. */
	protected $sResendTemplateKey;
	/** @var string TPFW_Functions method building the Resend placeholder map. */
	protected $sResendReplacementMethod;
	/** @var array Extra nonce keys a subclass' own AJAX actions need. */
	protected $aExtraNonceKeys = array();
	/** @var string Translated singular label used in order notes ('Ticket', 'Timeslot Ticket', 'Pass'). */
	protected $sRowLabel = '';
	/** @var bool Whether rows can be transferred to another customer from this screen. */
	protected $bSupportsTransfer = false;

	/**
	 * @param string         $sPrefix    Option/handle prefix for the plugin ('tpfw').
	 * @param TPFW_Functions $oFunctions Shared helper instance.
	 */
	public function __construct($sPrefix, $oFunctions)
	{
		$this->sPrefix    = $sPrefix;
		$this->oFunctions = $oFunctions;
		$this->run();
	}

	/**
	 * Registers the screen, its assets and the row actions.
	 *
	 * @return void
	 */
	protected function run()
	{
		add_action('admin_menu',            array($this, 'add_settings_page'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_script_admin'));

		add_action('wp_ajax_tpfw_ajax_admin_resend_' . $this->sActionKey, array($this, 'ajax_resend_callback'));
		add_action('wp_ajax_tpfw_ajax_admin_reset_'  . $this->sActionKey, array($this, 'ajax_reset_callback'));
		add_action('wp_ajax_tpfw_ajax_admin_cancel_' . $this->sActionKey, array($this, 'ajax_cancel_callback'));
		if($this->bSupportsTransfer)
		{
			add_action('wp_ajax_tpfw_ajax_admin_transfer_' . $this->sActionKey, array($this, 'ajax_transfer_callback'));
		}

		$this->register_extra_actions();
	}

	/**
	 * Hook for a subclass to register AJAX actions beyond resend/reset/cancel.
	 *
	 * @return void
	 */
	protected function register_extra_actions() {}

	/**
	 * Adds the screen to the plugin menu.
	 *
	 * @return void
	 */
	public function add_settings_page()
	{
		$sHook = add_submenu_page('tpfw', $this->sMenuLabel, $this->sMenuLabel, 'manage_woocommerce', $this->sSlug, array($this, 'page_content'));
		// Bulk actions run before any output so the request can be redirected to a clean URL;
		// left in the page render, the nonce and the action stayed in the address bar and a
		// reload re-ran them.
		add_action('load-' . $sHook, array($this, 'handle_bulk_action'));
		// Same reason: a CSV download has to send its own headers and body, so it cannot run
		// once the admin page has started printing.
		add_action('load-' . $sHook, array($this, 'maybe_export_csv'));
		// And the same again for a row's PDF, which ends in a redirect to the file.
		add_action('load-' . $sHook, array($this, 'maybe_download_pdf'));
	}

	/**
	 * Sends the current view as a CSV download, when the export link was followed.
	 *
	 * "Current view" means everything the screen's search and status filter select, not just the
	 * page being looked at - the link carries the same query args, and the table builds the same
	 * WHERE and ORDER BY, so the file is the list the admin is looking at with the pagination
	 * taken off.
	 *
	 * @return void Exits with the file when exporting, otherwise returns.
	 */
	public function maybe_export_csv()
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only; the nonce is verified on the next line before anything is read or sent.
		if(!isset($_GET['tpfw_export'])) return;

		check_admin_referer('tpfw_export_' . $this->sSlug);
		if(!$this->oFunctions->user_can_manage())
		{
			wp_die(esc_html__('This action is only allowed for site admins', 'tickets-passes-for-woocommerce'), '', array('response' => 403));
		}

		$oTable = new $this->sTableClass($this->oFunctions, $this);
		$sCSV   = $this->oFunctions->str_putcsv($oTable->get_export_rows());

		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . sanitize_file_name($this->sSlug . '-' . gmdate('Y-m-d', current_time('timestamp'))) . '.csv"');
		header('Content-Length: ' . strlen($sCSV));

		// Anything already buffered would be prepended to the file and corrupt it.
		while(ob_get_level() > 0) { ob_end_clean(); }

		echo $sCSV; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/csv body from str_putcsv(), which quotes every field and neutralises formula-leading cells; HTML escaping would corrupt it.
		exit;
	}

	/**
	 * URL of this screen's CSV export, carrying the current search, filter and sort.
	 *
	 * @return string Nonced URL.
	 */
	public function get_export_url()
	{
		return wp_nonce_url(add_query_arg('tpfw_export', 'csv'), 'tpfw_export_' . $this->sSlug);
	}

	/**
	 * Renders one row's printable PDF and hands it over, when a Download link was followed.
	 *
	 * The same PDF the holder gets from My Account, built by the same helper - it is rendered on
	 * demand and cached in the uploads folder, so the first click on a row costs a render and
	 * every later one does not. The browser is then pointed at the file's own URL, where
	 * TPFW_File_Access streams it as an attachment.
	 *
	 * A plain link rather than the AJAX the row's other actions use, because a file cannot come
	 * back through admin-ajax as a row patch, and there is nothing on the row to patch anyway.
	 *
	 * @return void Exits with the file when downloading, otherwise returns.
	 */
	public function maybe_download_pdf()
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only; the nonce is verified on the next line before anything is read or sent.
		if(!isset($_GET['tpfw_pdf'])) return;

		check_admin_referer('tpfw_pdf_' . $this->sSlug);
		if(!$this->oFunctions->user_can_manage())
		{
			wp_die(esc_html__('This action is only allowed for site admins', 'tickets-passes-for-woocommerce'), '', array('response' => 403));
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- check_admin_referer() above has verified the request.
		$sNanoID = sanitize_text_field(wp_unslash($_GET['tpfw_pdf'] ?? ''));

		global $wpdb;
		// Looked up in this screen's own table, so the id in the URL decides which row is
		// rendered but never which kind of document comes out.
		$oRow = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE nano_id = %s', $wpdb->prefix . $this->sTable, $sNanoID));
		if(!$oRow)
		{
			wp_die(esc_html__('No matching row found for the provided Nano ID', 'tickets-passes-for-woocommerce'), '', array('response' => 404, 'back_link' => true));
		}

		$aResult = $this->render_row_pdf($oRow);
		if(empty($aResult['bSuccess']))
		{
			wp_die(esc_html($aResult['sMessage']), '', array('response' => 500, 'back_link' => true));
		}

		wp_safe_redirect($aResult['sDownloadURL']);
		exit;
	}

	/**
	 * Renders one row's PDF. Ticket-shaped screens share this one; the pass screen overrides it.
	 *
	 * @param object $oRow Row from this screen's table.
	 * @return array bSuccess, sMessage and - on success - sDownloadURL and sFilename.
	 */
	protected function render_row_pdf($oRow)
	{
		return $this->oFunctions->create_fetch_ticket_pdf_qr($oRow->nano_id);
	}

	/**
	 * URL that downloads one row's PDF.
	 *
	 * @param string $sNanoID Row's nano id.
	 * @return string Nonced URL.
	 */
	public function get_pdf_url($sNanoID)
	{
		return wp_nonce_url(add_query_arg('tpfw_pdf', $sNanoID), 'tpfw_pdf_' . $this->sSlug);
	}

	/**
	 * Applies a submitted bulk action, then redirects to the list without the action in the URL.
	 *
	 * @return void
	 */
	public function handle_bulk_action()
	{
		$oTable  = new $this->sTableClass($this->oFunctions, $this);
		$aResult = $oTable->process_bulk_action();
		if($aResult === null) return;

		$sURL = remove_query_arg(array('action', 'action2', '_wpnonce', '_wp_http_referer', 'nano_ids', 'bulk_action'));
		wp_safe_redirect(add_query_arg('tpfw_bulk', implode(':', $aResult), $sURL));
		exit;
	}

	/**
	 * Builds the list table and includes the shared page wrapper.
	 *
	 * @return void
	 */
	public function page_content()
	{
		$oTable            = new $this->sTableClass($this->oFunctions, $this);
		$sPageTitle        = $this->sPageTitle;
		$sSlug             = $this->sSlug;
		$bSupportsTransfer = $this->bSupportsTransfer;
		include(dirname(__FILE__) . '/template/page-content.php');
	}

	/**
	 * Whether the current admin request is this screen.
	 *
	 * @return bool
	 */
	protected function is_own_screen()
	{
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of the current admin screen to decide whether to enqueue assets; no state is changed.
		return isset($pagenow) && $pagenow == 'admin.php' && isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'] ?? '')) == $this->sSlug;
	}

	/**
	 * Nonce keys every screen's script needs, plus the subclass' extras.
	 *
	 * @return array
	 */
	public function get_nonce_keys()
	{
		return array_merge(array(
			'ajax_admin_resend_' . $this->sActionKey,
			'ajax_admin_reset_'  . $this->sActionKey,
			'ajax_admin_cancel_' . $this->sActionKey,
			'ajax_checkin_'      . $this->sCheckinKey,
		), $this->bSupportsTransfer ? array('ajax_admin_transfer_' . $this->sActionKey) : array(), $this->aExtraNonceKeys);
	}

	/**
	 * Loads the shared dashboard script and stylesheet, and only on this screen.
	 *
	 * @return void
	 */
	public function enqueue_script_admin()
	{
		if(!$this->is_own_screen()) return;

		$aParams = array(
			'aNonces'      => $this->oFunctions->get_ajax_nonces($this->get_nonce_keys()),
			'sActionKey'   => $this->sActionKey,
			'sCheckinKey'  => $this->sCheckinKey,
			'translations' => array(
				'sActionFailed'       => __('The action could not be completed. Please try again.', 'tickets-passes-for-woocommerce'),
				'sResent'             => __('Sent', 'tickets-passes-for-woocommerce'),
				'sConfirmCancel'      => __('Cancel this item? Its QR code will stop working until it is reset.', 'tickets-passes-for-woocommerce'),
				'sConfirmReset'       => __('Reset this item? Its recorded check-ins will be cleared.', 'tickets-passes-for-woocommerce'),
				'sConfirmDeleteImage' => __('Remove this profile photo? The holder will be able to upload a new one.', 'tickets-passes-for-woocommerce'),
				'sConfirmBulk'        => __('Apply this action to every selected row?', 'tickets-passes-for-woocommerce'),
				'sTransferred'        => __('Transferred', 'tickets-passes-for-woocommerce'),
			),
		);

		$sBaseDir = dirname(__FILE__);
		$sBaseURL = plugins_url('', __FILE__);
		wp_register_script($this->sPrefix.'dashboard', $sBaseURL.'/js/dashboard.js', array('jquery'), filemtime($sBaseDir.'/js/dashboard.js'), true);
		wp_enqueue_script($this->sPrefix.'dashboard');
		wp_localize_script($this->sPrefix.'dashboard', 'tpfwParamsDashboard', $aParams);
		wp_enqueue_style($this->sPrefix.'dashboard', $sBaseURL.'/css/dashboard.css', array(), filemtime($sBaseDir.'/css/dashboard.css'));

		$this->enqueue_extra_assets();
	}

	/**
	 * Hook for a subclass to enqueue its own script/stylesheet after the shared ones.
	 *
	 * @return void
	 */
	protected function enqueue_extra_assets() {}

	/**
	 * Nonce + capability gate shared by every handler below. Sends the JSON error itself.
	 *
	 * @param string $sAction Nonce action without the tpfw_ prefix.
	 * @return array Empty response array to fill.
	 */
	protected function guard_ajax($sAction)
	{
		check_ajax_referer('tpfw_' . $sAction, 'security');
		if(!$this->oFunctions->user_can_manage())
		{
			wp_send_json_error(array('sMessage' => __('This action is only allowed for site admins', 'tickets-passes-for-woocommerce')));
		}
		return array();
	}

	/**
	 * The posted nano id, or a JSON error when it is missing.
	 *
	 * @return string
	 */
	protected function posted_nano_id()
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_ajax() has verified the nonce before this is called.
		$sNanoID = sanitize_text_field(wp_unslash($_POST['nano_id'] ?? ''));
		if($sNanoID === '')
		{
			wp_send_json_error(array('sMessage' => __('Nano ID not found', 'tickets-passes-for-woocommerce')));
		}
		return $sNanoID;
	}

	/**
	 * Re-sends an existing row's email to the account that owns it.
	 *
	 * The owner and order come from the row, never from the request, so a resend can only ever
	 * go to the account the ticket belongs to. Does nothing if the admin has not configured a
	 * resend template, rather than sending an empty email.
	 *
	 * @return void
	 */
	public function ajax_resend_callback()
	{
		$response = $this->guard_ajax('ajax_admin_resend_' . $this->sActionKey);
		$sNanoID  = $this->posted_nano_id();

		global $wpdb;
		$oRow = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE nano_id = %s', $wpdb->prefix . $this->sTable, $sNanoID));
		if(!$oRow)
		{
			wp_send_json_error(array('sMessage' => __('No matching row found for the provided Nano ID', 'tickets-passes-for-woocommerce')));
		}

		$oUser = get_user_by('ID', (int) $oRow->user_id);
		if(!$oUser)
		{
			wp_send_json_error(array('sMessage' => __('User with the given ID, was not found', 'tickets-passes-for-woocommerce')));
		}

		$mError = $this->send_row_email($oRow, $oUser);
		if($mError !== true)
		{
			wp_send_json_error(array('sMessage' => $mError));
		}

		/* translators: %s: email address the ticket or pass was sent to */
		$response['sMessage'] = sprintf(__('Successfully resent to %s', 'tickets-passes-for-woocommerce'), $oUser->user_email);
		wp_send_json_success($response);
	}

	/**
	 * Sends the configured resend email for one row to one account.
	 *
	 * @param object  $oRow  Row from the table.
	 * @param WP_User $oUser Recipient.
	 * @return true|string True when the mailer accepted it, otherwise the translated reason.
	 */
	protected function send_row_email($oRow, $oUser)
	{
		$oOrder = wc_get_order((int) $oRow->order_id);
		if(!$oOrder)
		{
			return __('Order not found', 'tickets-passes-for-woocommerce');
		}

		// The product behind the row can be gone (deleted since the sale), so its name is guarded.
		$oProduct = wc_get_product($oRow->product_id);
		if(!$oProduct)
		{
			return __('The product this ticket was bought from no longer exists', 'tickets-passes-for-woocommerce');
		}

		$sSubject = $this->oFunctions->get_email_setting($this->sResendTemplateKey, 'subject');
		$sBody    = $this->oFunctions->get_email_setting($this->sResendTemplateKey, 'message');
		if($sBody === '' || $sSubject === '')
		{
			return __('Email Message was empty, so no email was send', 'tickets-passes-for-woocommerce');
		}

		foreach($this->build_resend_replacements($oOrder, $oRow, $oProduct) as $sKey => $sValue)
		{
			$sBody    = str_replace($sKey, $sValue, $sBody);
			$sSubject = str_replace($sKey, $sValue, $sSubject);
		}

		$this->oFunctions->tpfw_custom_enmail($oUser->user_email, $sSubject, wp_kses_post($sBody), array('Content-Type: text/html; charset=UTF-8'));
		return true;
	}

	/**
	 * Moves a row to another customer's account.
	 *
	 * Admin only. The recipient is named by email address or user id and must already have an
	 * account; nothing is created here. The row's user_id is rewritten, the order the row was
	 * bought on gets a note naming both parties and the admin, and the new holder is emailed the
	 * row's QR code with the resend template so they have it without asking. The buyer's order
	 * itself is not changed: it remains the record of who paid.
	 *
	 * @return void
	 */
	public function ajax_transfer_callback()
	{
		$response   = $this->guard_ajax('ajax_admin_transfer_' . $this->sActionKey);
		$sNanoID    = $this->posted_nano_id();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_ajax() verified the nonce.
		$sRecipient = trim(sanitize_text_field(wp_unslash($_POST['recipient'] ?? '')));

		$oNewUser = false;
		if(is_email($sRecipient))          { $oNewUser = get_user_by('email', $sRecipient); }
		elseif(ctype_digit($sRecipient))   { $oNewUser = get_user_by('ID', (int) $sRecipient); }
		if(!$oNewUser)
		{
			wp_send_json_error(array('sMessage' => __('No account was found for that email address or user ID. The new holder needs an account on this site first.', 'tickets-passes-for-woocommerce')));
		}

		global $wpdb;
		$sTable = $wpdb->prefix . $this->sTable;
		$oRow   = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE nano_id = %s AND deleted IS NULL', $sTable, $sNanoID));
		if(!$oRow)
		{
			wp_send_json_error(array('sMessage' => __('No active row was found for the provided Nano ID', 'tickets-passes-for-woocommerce')));
		}
		if((int) $oRow->user_id === (int) $oNewUser->ID)
		{
			wp_send_json_error(array('sMessage' => __('That account already holds this item.', 'tickets-passes-for-woocommerce')));
		}

		$oOldUser = get_user_by('ID', (int) $oRow->user_id);
		$wpdb->query($wpdb->prepare('UPDATE %i SET user_id = %d, updated = %s WHERE nano_id = %s', $sTable, $oNewUser->ID, current_time('mysql'), $sNanoID));

		$sOld   = $oOldUser ? sprintf('%s (#%d)', $oOldUser->user_email, $oOldUser->ID) : sprintf('#%d', (int) $oRow->user_id);
		$sNew   = sprintf('%s (#%d)', $oNewUser->user_email, $oNewUser->ID);
		$oAdmin = wp_get_current_user();
		$oOrder = wc_get_order((int) $oRow->order_id);
		if($oOrder)
		{
			$oOrder->add_order_note(sprintf(
				/* translators: 1: row type ("Ticket"), 2: nano id, 3: previous holder, 4: new holder, 5: admin who did it */
				__('%1$s %2$s transferred from %3$s to %4$s by %5$s.', 'tickets-passes-for-woocommerce'),
				$this->sRowLabel, $sNanoID, $sOld, $sNew, $oAdmin->display_name
			));
			$oOrder->save();
		}

		$oRow->user_id = $oNewUser->ID;
		$mMail         = $this->send_row_email($oRow, $oNewUser);

		$response['sMessage']     = $mMail === true
			/* translators: %s: new holder's email address */
			? sprintf(__('Transferred and emailed to %s', 'tickets-passes-for-woocommerce'), $oNewUser->user_email)
			/* translators: 1: new holder's email address, 2: why the email was not sent */
			: sprintf(__('Transferred to %1$s, but the email could not be sent: %2$s', 'tickets-passes-for-woocommerce'), $oNewUser->user_email, $mMail);
		$response['iNewUserID']   = (int) $oNewUser->ID;
		$response['sNewUserHtml'] = '<a target="_blank" href="' . esc_url(get_edit_user_link($oNewUser->ID)) . '">' . esc_html($oNewUser->ID) . '</a>';
		wp_send_json_success($response);
	}

	/** @return bool */
	public function supports_transfer() { return $this->bSupportsTransfer; }

	/**
	 * Placeholder map for the Resend email. Ticket-shaped rows share one; passes override.
	 *
	 * @param WC_Order   $oOrder   Order the row was bought on.
	 * @param object     $oRow     Row from the table.
	 * @param WC_Product $oProduct Product behind the row.
	 * @return array Placeholder => value.
	 */
	protected function build_resend_replacements($oOrder, $oRow, $oProduct)
	{
		return $this->oFunctions->{$this->sResendReplacementMethod}($oOrder, $oRow->nano_id, $oProduct->get_name(), $oRow->product_id);
	}

	/**
	 * Clears a row's check-in history and un-cancels it.
	 *
	 * @return void
	 */
	public function ajax_reset_callback()
	{
		$response = $this->guard_ajax('ajax_admin_reset_' . $this->sActionKey);
		$sNanoID  = $this->posted_nano_id();

		$aResult = $this->oFunctions->{$this->sResetMethod}($sNanoID);
		if(empty($aResult['bSuccess']))
		{
			wp_send_json_error(array('sMessage' => $aResult['sMessage']));
		}

		$response['sNewStatus'] = $this->oFunctions->get_status_pill_html($this->sResetStatusLabel);
		$response['sMessage']   = __('Successfully reset', 'tickets-passes-for-woocommerce');
		$response                = $this->augment_reset_response($aResult, $response);
		wp_send_json_success($response);
	}

	/**
	 * Hook for a subclass to add keys to the reset response (the pass screen reports guest uses).
	 *
	 * @param array $aResult  Return value of the reset method.
	 * @param array $response Response so far.
	 * @return array
	 */
	protected function augment_reset_response($aResult, $response)
	{
		return $response;
	}

	/**
	 * Cancels a row so it can no longer be checked in.
	 *
	 * Soft delete - the row and its history stay, which is what the Cancelled pill and its
	 * timestamp are read from.
	 *
	 * @return void
	 */
	public function ajax_cancel_callback()
	{
		$response = $this->guard_ajax('ajax_admin_cancel_' . $this->sActionKey);
		$sNanoID  = $this->posted_nano_id();

		$aResult = $this->oFunctions->{$this->sCancelMethod}($sNanoID);
		if(empty($aResult['bSuccess']))
		{
			wp_send_json_error(array('sMessage' => $aResult['sMessage']));
		}

		$sDateTimeFormat        = $this->oFunctions->get_datetime_format('datetime');
		$response['sNewStatus'] = $this->oFunctions->get_status_pill_html(__('Cancelled', 'tickets-passes-for-woocommerce'), gmdate($sDateTimeFormat, current_time('timestamp')));
		$response['sMessage']   = __('Successfully cancelled', 'tickets-passes-for-woocommerce');
		wp_send_json_success($response);
	}

	/** @return string */
	public function get_action_key()  { return $this->sActionKey; }
	/** @return string */
	public function get_checkin_key() { return $this->sCheckinKey; }
	/** @return string */
	public function get_slug()        { return $this->sSlug; }
	/** @return string */
	public function get_reset_method()  { return $this->sResetMethod; }
	/** @return string */
	public function get_cancel_method() { return $this->sCancelMethod; }
}


/**
 * Shared WP_List_Table for the three dashboards: search, status filter, sortable columns,
 * pagination, bulk cancel/reset, and the batched per-page lookups every row needs.
 *
 * A subclass names its tables and columns, says which statuses it can be filtered on, and
 * builds one row from a database row plus the batched context.
 */
abstract class TPFW_Dashboard_Table extends WP_List_Table
{
	/** @var int Rows per page. */
	public $perPage = 20;
	/** @var TPFW_Functions */
	protected $oFunctions;
	/** @var TPFW_Dashboard Screen this table belongs to. */
	protected $oDashboard;

	/** @var string Row table without prefix. */
	protected $sTable;
	/** @var string Check-in stats table without prefix. */
	protected $sStatsTable;
	/** @var array Columns the free-text search matches with LIKE. */
	protected $aSearchColumns = array('nano_id', 'user_id', 'order_id', 'id');
	/** @var string Extra SQL always ANDed into the WHERE (no leading AND), e.g. parent-only. */
	protected $sBaseWhere = '';
	/** @var array Column id => database column it sorts on. */
	protected $aSortable = array('user_id' => 'user_id', 'valid' => 'valid_from', 'product_id' => 'product_id', 'order_id' => 'order_id', 'created' => 'created');


	/**
	 * @param TPFW_Functions $oFunctions Shared helper instance.
	 * @param TPFW_Dashboard $oDashboard Screen this table belongs to.
	 * @param array          $aArgs      singular/plural for WP_List_Table.
	 */
	public function __construct($oFunctions, $oDashboard, $aArgs)
	{
		$this->oFunctions = $oFunctions;
		$this->oDashboard = $oDashboard;
		parent::__construct(array_merge(array('ajax' => false), $aArgs));
	}

	/**
	 * Statuses the table can be filtered on.
	 *
	 * Each entry is slug => array('label' => translated, 'where' => SQL against alias `t`, with
	 * {uses} standing for the row's live check-in count and {now} for the current site time).
	 *
	 * @return array
	 */
	abstract protected function get_status_filters();

	/**
	 * Builds one table row.
	 *
	 * @param object $oResult Database row.
	 * @param array  $aCtx    Batched context from get_row_context().
	 * @return array Row data keyed by column.
	 */
	abstract protected function build_row($oResult, $aCtx);

	/**
	 * Current free-text search term.
	 *
	 * @return string
	 */
	protected function get_search()
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list-table filter input; no state is changed.
		return isset($_REQUEST['s']) ? trim(sanitize_text_field(wp_unslash($_REQUEST['s']))) : '';
	}

	/**
	 * Current status filter slug, or '' when it is not one this table offers.
	 *
	 * @return string
	 */
	protected function get_status_filter()
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list-table filter input; no state is changed.
		$sStatus = isset($_REQUEST['status']) ? sanitize_key(wp_unslash($_REQUEST['status'])) : '';
		return isset($this->get_status_filters()[$sStatus]) ? $sStatus : '';
	}

	/**
	 * Search box and status dropdown inside the table's own tablenav.
	 *
	 * @param string $which 'top' or 'bottom'.
	 * @return void
	 */
	public function extra_tablenav($which)
	{
		if($which !== 'top') return;
		$sStatus = $this->get_status_filter();
		?>
		<div class="alignleft actions tpfw-dashboard-filters">
			<label class="screen-reader-text" for="tpfw-status-filter"><?php esc_html_e('Filter by status', 'tickets-passes-for-woocommerce'); ?></label>
			<select name="status" id="tpfw-status-filter">
				<option value=""><?php esc_html_e('All statuses', 'tickets-passes-for-woocommerce'); ?></option>
				<?php foreach($this->get_status_filters() as $sSlug => $aFilter): ?>
					<option value="<?php echo esc_attr($sSlug); ?>" <?php selected($sStatus, $sSlug); ?>><?php echo esc_html($aFilter['label']); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="post-search-input"><?php esc_html_e('Search', 'tickets-passes-for-woocommerce'); ?></label>
			<input type="search" id="post-search-input" name="s" value="<?php echo esc_attr($this->get_search()); ?>" placeholder="<?php esc_attr_e('Search', 'tickets-passes-for-woocommerce'); ?>">
			<?php submit_button(__('Filter', 'tickets-passes-for-woocommerce'), 'button', '', false, array('id' => 'search-submit')); ?>

			<?php
				// Sits with the filter controls rather than in the page header, because what it
				// exports is whatever those controls currently select. The count is the honest
				// label: it says up front how many rows the file will hold. Hidden when the
				// filter matches nothing, so it cannot hand over an empty file.
				$iTotalItems = (int) $this->get_pagination_arg('total_items');
				if($iTotalItems > 0) :
			?>
			<a class="button tpfw-export-csv" href="<?php echo esc_url($this->oDashboard->get_export_url()); ?>">
				<span class="dashicons dashicons-download" aria-hidden="true"></span>
				<?php
					printf(
						/* translators: %s: number of rows the current search and filter match. */
						esc_html(_n('Download CSV (%s row)', 'Download CSV (%s rows)', $iTotalItems, 'tickets-passes-for-woocommerce')),
						esc_html(number_format_i18n($iTotalItems))
					);
				?>
			</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @return array Column id => array(orderby key, desc-first).
	 */
	public function get_sortable_columns()
	{
		$aSortable = array();
		foreach(array_keys($this->aSortable) as $sColumn)
		{
			$aSortable[$sColumn] = array($sColumn, $sColumn === 'created');
		}
		return $aSortable;
	}

	/**
	 * @return array Bulk action slug => label.
	 */
	public function get_bulk_actions()
	{
		return array(
			'cancel' => __('Cancel', 'tickets-passes-for-woocommerce'),
			'reset'  => __('Reset', 'tickets-passes-for-woocommerce'),
		);
	}

	/**
	 * Checkbox cell for bulk actions.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_cb($item)
	{
		return '<input type="checkbox" name="nano_ids[]" value="' . esc_attr($item['nano_id_raw']) . '" />';
	}

	/**
	 * Runs a submitted bulk action against every selected nano id.
	 *
	 * Called from the screen's load hook, before output. Verified with the bulk nonce
	 * WP_List_Table prints and manage_woocommerce; the per-row helper decides per id whether it
	 * can be applied, so a mixed selection is not all-or-nothing.
	 *
	 * @return array|null array(action, done, selected), or null when no bulk action was submitted.
	 */
	public function process_bulk_action()
	{
		$sAction = $this->current_action();
		if(!in_array($sAction, array('cancel', 'reset'), true)) return null;

		check_admin_referer('bulk-' . $this->_args['plural']);
		if(!$this->oFunctions->user_can_manage()) return null;

		$aNanoIDs = array_filter(array_map('sanitize_text_field', (array) wp_unslash($_REQUEST['nano_ids'] ?? array())));
		if(empty($aNanoIDs)) return null;

		$sMethod = $sAction === 'cancel' ? $this->oDashboard->get_cancel_method() : $this->oDashboard->get_reset_method();
		$iDone   = 0;
		foreach($aNanoIDs as $sNanoID)
		{
			$aResult = $this->oFunctions->{$sMethod}($sNanoID);
			if(!empty($aResult['bSuccess'])) $iDone++;
		}

		return array($sAction, $iDone, count($aNanoIDs));
	}

	/**
	 * Prints the outcome of the bulk action the redirect reported in the URL, when there is one.
	 *
	 * @return void
	 */
	public function print_bulk_notice()
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only: three sanitized tokens the redirect added, nothing is changed.
		$aParts = explode(':', sanitize_text_field(wp_unslash($_GET['tpfw_bulk'] ?? '')));
		if(count($aParts) !== 3 || !in_array($aParts[0], array('cancel', 'reset'), true)) return;

		$sNotice = $aParts[0] === 'cancel'
			/* translators: 1: rows cancelled, 2: rows selected */
			? sprintf(__('%1$d of %2$d selected rows cancelled.', 'tickets-passes-for-woocommerce'), (int) $aParts[1], (int) $aParts[2])
			/* translators: 1: rows reset, 2: rows selected */
			: sprintf(__('%1$d of %2$d selected rows reset.', 'tickets-passes-for-woocommerce'), (int) $aParts[1], (int) $aParts[2]);
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($sNotice) . '</p></div>';
	}

	/**
	 * Live check-in count subquery for the row aliased `t`.
	 *
	 * @return string SQL fragment.
	 */
	protected function uses_subquery()
	{
		global $wpdb;
		return '(SELECT COUNT(*) FROM `' . esc_sql($wpdb->prefix . $this->sStatsTable) . '` s WHERE s.nano_id_fk = t.nano_id AND s.deleted IS NULL)';
	}

	/**
	 * WHERE clause for the current search + status filter, with a leading ' WHERE ' or ''.
	 *
	 * @return string Prepared SQL.
	 */
	protected function build_where()
	{
		global $wpdb;
		$aParts = array();
		if($this->sBaseWhere !== '') $aParts[] = $this->sBaseWhere;

		$sSearch = $this->get_search();
		if($sSearch !== '')
		{
			$sLike  = '%' . $wpdb->esc_like($sSearch) . '%';
			$aLikes = array();
			foreach($this->aSearchColumns as $sColumn)
			{
				$aLikes[] = $wpdb->prepare('t.`' . esc_sql($sColumn) . '` LIKE %s', $sLike); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column names are literals from the subclass.
			}
			$aParts[] = '(' . implode(' OR ', $aLikes) . ')';
		}

		$sStatus = $this->get_status_filter();
		if($sStatus !== '')
		{
			$aParts[] = '(' . str_replace(
				array('{uses}', '{now}'),
				array($this->uses_subquery(), $wpdb->prepare('%s', current_time('mysql'))),
				$this->get_status_filters()[$sStatus]['where']
			) . ')';
		}

		return empty($aParts) ? '' : ' WHERE ' . implode(' AND ', $aParts);
	}

	/**
	 * ORDER BY for the requested column, falling back to newest first.
	 *
	 * @return string SQL fragment.
	 */
	protected function build_order_by()
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list-table sort input; both values are checked against safelists below.
		$sOrderBy = isset($_REQUEST['orderby']) ? sanitize_key(wp_unslash($_REQUEST['orderby'])) : 'created';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$sOrder   = isset($_REQUEST['order']) && strtolower(sanitize_key(wp_unslash($_REQUEST['order']))) === 'asc' ? 'ASC' : 'DESC';
		$sColumn  = $this->aSortable[$sOrderBy] ?? 'created';
		return ' ORDER BY t.`' . esc_sql($sColumn) . '` ' . $sOrder . ', t.`id` ' . $sOrder;
	}

	/**
	 * Batched lookups for one page of rows: check-in counts, orders, and primed product/user caches.
	 *
	 * @param array $aResults Database rows.
	 * @return array aStatisticCounts, aOrders, sDateTimeFormat, sDateOnlyFormat, sTimeOnlyFormat, iNow.
	 */
	protected function get_row_context($aResults)
	{
		global $wpdb;
		$aCtx = array(
			'aStatisticCounts' => array(),
			'aOrders'          => array(),
			'sDateTimeFormat'  => $this->oFunctions->get_datetime_format('datetime'),
			'sDateOnlyFormat'  => $this->oFunctions->get_datetime_format('date'),
			'sTimeOnlyFormat'  => $this->oFunctions->get_datetime_format('time'),
			'iNow'             => current_time('timestamp'),
		);

		$aNanoIDs = $this->context_nano_ids($aResults);
		if(!empty($aNanoIDs))
		{
			$sPlaceholders = implode(', ', array_fill(0, count($aNanoIDs), '%s'));
			$oPrepared     = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder scaffold; every value is bound through the array.
				"SELECT nano_id_fk, COUNT(*) AS used_count FROM %i WHERE deleted IS NULL AND nano_id_fk IN ($sPlaceholders) GROUP BY nano_id_fk",
				array_merge(array($wpdb->prefix . $this->sStatsTable), $aNanoIDs)
			);
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oPrepared is the return value of $wpdb->prepare() above.
			$aCtx['aStatisticCounts'] = wp_list_pluck($wpdb->get_results($oPrepared), 'used_count', 'nano_id_fk');
		}

		$aOrderIDs = array_filter(array_map('intval', wp_list_pluck($aResults, 'order_id')));
		if(!empty($aOrderIDs))
		{
			foreach(wc_get_orders(array('id__in' => array_unique($aOrderIDs), 'limit' => -1)) as $oOrder)
			{
				$aCtx['aOrders'][$oOrder->get_id()] = $oOrder;
			}
		}

		$aProductIDs = array_filter(array_map('intval', wp_list_pluck($aResults, 'product_id')));
		if(!empty($aProductIDs)) { _prime_post_caches(array_unique($aProductIDs), false, true); }

		return $aCtx;
	}

	/**
	 * Nano ids whose check-in counts the page needs. Passes add their guest passes.
	 *
	 * @param array $aResults Database rows.
	 * @return array
	 */
	protected function context_nano_ids($aResults)
	{
		return wp_list_pluck($aResults, 'nano_id');
	}

	/**
	 * Runs the bulk action, then loads and renders one page of rows.
	 *
	 * @return void
	 */
	public function prepare_items()
	{
		$this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns());

		global $wpdb;
		$sFrom  = $wpdb->prepare(' FROM %i t', $wpdb->prefix . $this->sTable);
		$sWhere = $this->build_where();
		$iStart = ($this->get_pagenum() - 1) * $this->perPage;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- assembled from $wpdb->prepare() fragments and safelisted identifiers.
		$aResults   = $wpdb->get_results('SELECT t.*' . $sFrom . $sWhere . $this->build_order_by() . $wpdb->prepare(' LIMIT %d, %d', $iStart, $this->perPage));
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
		$iTotal     = (int) $wpdb->get_var('SELECT COUNT(*)' . $sFrom . $sWhere);

		$this->set_pagination_args(array('total_items' => $iTotal, 'per_page' => $this->perPage));

		$aCtx  = $this->get_row_context((array) $aResults);
		$aData = array();
		foreach((array) $aResults as $oResult)
		{
			$aData[] = $this->build_row($oResult, $aCtx);
		}
		$this->items = $aData;
	}

	/**
	 * Every row the current search and status filter select, as plain-text CSV records.
	 *
	 * Deliberately unpaginated - the point of the export is the whole filtered set, not the page
	 * being looked at. Reuses build_where() and build_order_by() so the file cannot drift from
	 * what the screen shows.
	 *
	 * ponytail: builds the whole file in memory, and get_row_context() loads every matching order
	 * through wc_get_orders(). Fine for the thousands of rows a venue accumulates; a shop with
	 * tens of thousands should stream instead - fputcsv() straight to php://output, fetching the
	 * rows and their context in batches.
	 *
	 * @return array List of associative rows; the first row's keys become the CSV header.
	 */
	public function get_export_rows()
	{
		global $wpdb;
		$sFrom = $wpdb->prepare(' FROM %i t', $wpdb->prefix . $this->sTable);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- assembled from $wpdb->prepare() fragments and safelisted identifiers, exactly as prepare_items() does.
		$aResults = (array) $wpdb->get_results('SELECT t.*' . $sFrom . $this->build_where() . $this->build_order_by());
		$aCtx     = $this->get_row_context($aResults);

		$aRows = array();
		foreach($aResults as $oResult)
		{
			foreach($this->export_rows_for($oResult, $aCtx) as $aRow) { $aRows[] = $aRow; }
		}
		return $aRows;
	}

	/**
	 * The CSV records one database row contributes. One, unless a subclass nests rows under it.
	 *
	 * @param object $oResult Database row.
	 * @param array  $aCtx    Batched context.
	 * @return array List of associative rows.
	 */
	protected function export_rows_for($oResult, $aCtx)
	{
		return array($this->export_row($oResult, $aCtx));
	}

	/**
	 * One database row as a CSV record: heading => plain-text value.
	 *
	 * Plain text rather than the table's own cells, which are markup built for the screen. Dates
	 * follow the format chosen in General Settings, so the file reads like the list it came from.
	 *
	 * @param object $oResult Database row.
	 * @param array  $aCtx    Batched context.
	 * @return array
	 */
	protected function export_row($oResult, $aCtx)
	{
		$iUses    = (int) ($aCtx['aStatisticCounts'][$oResult->nano_id] ?? 0);
		$oHolder  = get_user_by('ID', (int) $oResult->user_id);
		$oProduct = wc_get_product($oResult->product_id);
		$sFormat  = $aCtx['sDateTimeFormat'];

		return array(
			__('ID', 'tickets-passes-for-woocommerce')             => $oResult->nano_id,
			__('Status', 'tickets-passes-for-woocommerce')         => $this->export_status($oResult, $iUses, $aCtx['iNow']),
			__('Holder user ID', 'tickets-passes-for-woocommerce') => (int) $oResult->user_id,
			__('Holder email', 'tickets-passes-for-woocommerce')   => $oHolder ? $oHolder->user_email : '',
			__('Product ID', 'tickets-passes-for-woocommerce')     => (int) $oResult->product_id,
			__('Product', 'tickets-passes-for-woocommerce')        => $oProduct ? wp_strip_all_tags($oProduct->get_name()) : '',
			__('Order ID', 'tickets-passes-for-woocommerce')       => (int) $oResult->order_id,
			__('Valid from', 'tickets-passes-for-woocommerce')     => gmdate($sFormat, strtotime($oResult->valid_from)),
			__('Valid to', 'tickets-passes-for-woocommerce')       => gmdate($sFormat, strtotime($oResult->valid_to)),
			__('Check-ins', 'tickets-passes-for-woocommerce')      => $iUses,
			__('Max uses', 'tickets-passes-for-woocommerce')       => (int) $oResult->max_uses,
			__('Created', 'tickets-passes-for-woocommerce')        => gmdate($sFormat, strtotime($oResult->created)),
		);
	}

	/**
	 * The status label the export prints. Passes derive theirs differently - see that table.
	 *
	 * @param object $oResult Database row.
	 * @param int    $iUses   Live check-in count.
	 * @param int    $iNow    Current site timestamp.
	 * @return string Translated label.
	 */
	protected function export_status($oResult, $iUses, $iNow)
	{
		list(, $sLabel) = $this->derive_ticket_status($oResult, $iUses, $iNow);
		return $sLabel;
	}

	/**
	 * Derives the status of a ticket-shaped row: cancelled beats used beats expired beats
	 * not-yet-valid beats partial beats unused, so a row never shows two states.
	 *
	 * @param object $oResult Database row.
	 * @param int    $iUses   Live check-in count.
	 * @param int    $iNow    Current site timestamp.
	 * @return array slug and translated label.
	 */
	protected function derive_ticket_status($oResult, $iUses, $iNow)
	{
		if(!empty($oResult->deleted))                       return array('cancelled', __('Cancelled', 'tickets-passes-for-woocommerce'));
		if($iUses >= (int) $oResult->max_uses)              return array('used', __('Used', 'tickets-passes-for-woocommerce'));
		if(strtotime($oResult->valid_to) < $iNow)           return array('expired', __('Expired', 'tickets-passes-for-woocommerce'));
		if(strtotime($oResult->valid_from) > $iNow)         return array('not_valid_yet', __('Not valid yet', 'tickets-passes-for-woocommerce'));
		if($iUses >= 1)                                     return array('partial', __('Partial', 'tickets-passes-for-woocommerce'));
		return array('unused', __('Unused', 'tickets-passes-for-woocommerce'));
	}

	/**
	 * Status filter set shared by tickets and timeslot tickets.
	 *
	 * @return array
	 */
	protected function ticket_status_filters()
	{
		return array(
			'unused'        => array('label' => __('Unused', 'tickets-passes-for-woocommerce'),        'where' => 't.deleted IS NULL AND {uses} = 0 AND t.valid_to >= {now} AND t.valid_from <= {now}'),
			'partial'       => array('label' => __('Partial', 'tickets-passes-for-woocommerce'),       'where' => 't.deleted IS NULL AND {uses} >= 1 AND {uses} < t.max_uses AND t.valid_to >= {now} AND t.valid_from <= {now}'),
			'used'          => array('label' => __('Used', 'tickets-passes-for-woocommerce'),          'where' => 't.deleted IS NULL AND {uses} >= t.max_uses'),
			'expired'       => array('label' => __('Expired', 'tickets-passes-for-woocommerce'),       'where' => 't.deleted IS NULL AND {uses} < t.max_uses AND t.valid_to < {now}'),
			'not_valid_yet' => array('label' => __('Not valid yet', 'tickets-passes-for-woocommerce'), 'where' => 't.deleted IS NULL AND {uses} < t.max_uses AND t.valid_from > {now}'),
			'cancelled'     => array('label' => __('Cancelled', 'tickets-passes-for-woocommerce'),     'where' => 't.deleted IS NOT NULL'),
		);
	}

	/**
	 * One row-action button.
	 *
	 * The button carries the AJAX action and the ids it needs as data attributes; the shared
	 * dashboard.js reads them, so no per-screen script has to know the action names.
	 *
	 * @param string $sKind   'checkin', 'resend', 'reset', 'cancel', or a subclass kind.
	 * @param string $sAction AJAX action without the tpfw_ prefix.
	 * @param string $sLabel  Translated button label.
	 * @param array  $aData   data-attr-* values.
	 * @param bool   $bHidden Render hidden (the script may show it again after a reset).
	 * @return string
	 */
	protected function action_button($sKind, $sAction, $sLabel, $aData, $bHidden = false)
	{
		$sAttrs = '';
		foreach($aData as $sKey => $mValue)
		{
			$sAttrs .= ' data-attr-' . esc_attr($sKey) . '="' . esc_attr($mValue) . '"';
		}
		return '<button type="button" class="button button-secondary tpfw-btn tpfw-btn--' . esc_attr($sKind) . ' tpfw-row-action" data-kind="' . esc_attr($sKind) . '" data-action="' . esc_attr($sAction) . '"' . $sAttrs . ($bHidden ? ' style="display:none;"' : '') . '>' . esc_html($sLabel) . '</button>';
	}

	/**
	 * The Download action for one row, or '' when the row has no QR image.
	 *
	 * The PDF is drawn around the row's QR code, so a row whose code was never written has
	 * nothing to hand over - the action is left off it rather than offered and then failing on
	 * the click. That is one stat per row on the page, which is what makes it honest: the QR
	 * folder is the only place that knows.
	 *
	 * A link, not one of the AJAX buttons above: it ends in a file, not in a changed row.
	 *
	 * @param object $oResult Database row.
	 * @param string $sQRType File type key of the folder its QR lives in: 'qr', or 'guest' for a guest pass.
	 * @return string
	 */
	protected function download_button($oResult, $sQRType = 'qr')
	{
		if(!file_exists($this->oFunctions->get_upload_dir_for_type($sQRType) . $oResult->nano_id . '.webp'))
		{
			return '';
		}

		return '<a class="button button-secondary tpfw-btn tpfw-btn--download" href="' . esc_url($this->oDashboard->get_pdf_url($oResult->nano_id)) . '">' . esc_html__('Download', 'tickets-passes-for-woocommerce') . '</a>';
	}

	/**
	 * The standard row actions for a ticket-shaped row.
	 *
	 * @param object $oResult     Database row.
	 * @param string $sStatusSlug From derive_ticket_status().
	 * @return string
	 */
	protected function standard_actions($oResult, $sStatusSlug)
	{
		$sKey        = $this->oDashboard->get_action_key();
		$bCancelled  = $sStatusSlug === 'cancelled';
		$aIDs        = array('nanoid' => $oResult->nano_id, 'orderid' => $oResult->order_id, 'orderlineid' => $oResult->order_line_id, 'userid' => $oResult->user_id, 'maxuses' => $oResult->max_uses);

		$sTransfer = $this->oDashboard->supports_transfer()
			? $this->action_button('transfer', 'ajax_admin_transfer_' . $sKey, __('Transfer', 'tickets-passes-for-woocommerce'), $aIDs, $bCancelled)
			: '';

		return '<div class="tpfw-actions">'
			. $this->action_button('checkin', 'ajax_checkin_' . $this->oDashboard->get_checkin_key(), __('Checkin', 'tickets-passes-for-woocommerce'), $aIDs, in_array($sStatusSlug, array('cancelled', 'used'), true))
			. $this->action_button('resend',  'ajax_admin_resend_' . $sKey, __('Resend', 'tickets-passes-for-woocommerce'), $aIDs, $bCancelled)
			. $this->download_button($oResult)
			. $this->action_button('reset',   'ajax_admin_reset_'  . $sKey, __('Reset', 'tickets-passes-for-woocommerce'),  $aIDs)
			. $this->action_button('cancel',  'ajax_admin_cancel_' . $sKey, __('Cancel', 'tickets-passes-for-woocommerce'), $aIDs, $bCancelled)
			. $sTransfer
			. '</div>';
	}

	/**
	 * Cells shared by every row type: ids, status, validity, product, order, created.
	 *
	 * @param object $oResult Database row.
	 * @param array  $aCtx    Batched context.
	 * @param string $sStatus Rendered status pill.
	 * @param int    $iUses   Live check-in count.
	 * @return array
	 */
	protected function common_cells($oResult, $aCtx, $sStatus, $iUses)
	{
		$oProduct     = wc_get_product($oResult->product_id);
		$sProductName = $oProduct ? wp_strip_all_tags($oProduct->get_formatted_name()) : '#' . (int) $oResult->product_id . ' ' . __('(deleted product)', 'tickets-passes-for-woocommerce');

		return array(
			'nano_id_raw' => $oResult->nano_id,
			'user_id'     => '<a target="_blank" href="' . esc_url(get_edit_user_link($oResult->user_id)) . '">' . esc_html($oResult->user_id) . '</a>',
			'nano_id'     => $this->oFunctions->get_truncated_id_html($oResult->nano_id),
			'status'      => $sStatus,
			'valid'       => $this->oFunctions->get_stacked_datetime_html($aCtx['sDateOnlyFormat'], $aCtx['sTimeOnlyFormat'], $oResult->valid_from, __('Valid to', 'tickets-passes-for-woocommerce') . ': ' . gmdate($aCtx['sDateTimeFormat'], strtotime($oResult->valid_to))),
			'checkins'    => esc_html($iUses . ' / ' . $oResult->max_uses),
			'product_id'  => '<a target="_blank" class="tpfw-product-link" title="' . esc_attr($sProductName) . '" href="' . esc_url(admin_url('post.php?post=' . (int) $oResult->product_id . '&action=edit')) . '">' . esc_html($sProductName) . '</a>',
			'order_id'    => '<a target="_blank" href="' . esc_url($this->oFunctions->get_order_edit_url($oResult->order_id)) . '">#' . esc_html($oResult->order_id) . '</a>',
			'created'     => $this->oFunctions->get_stacked_datetime_html($aCtx['sDateOnlyFormat'], $aCtx['sTimeOnlyFormat'], $oResult->created),
		);
	}

	/**
	 * Returns a cell's value, escaped late through the dashboard allowlist.
	 *
	 * @param array  $item        Row data.
	 * @param string $column_name Column being rendered.
	 * @return string
	 */
	public function column_default($item, $column_name)
	{
		return isset($item[$column_name]) ? wp_kses($item[$column_name], $this->oFunctions->get_dashboard_allowed_html()) : '';
	}
}
