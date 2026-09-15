<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Base class of the three product types (pass, ticket, timeslot ticket).
 *
 * Holds what every type carries: the handle prefix, the shared TPFW_Functions instance and
 * the per-type identity - type key, table names (unprefixed; compose with $wpdb->prefix at
 * the point of use), meta key prefix and the WooCommerce product class WooCommerce
 * instantiates for the type. Subclasses set the identity as property defaults and register
 * their own hooks in load_settings_dependencies(), so hook priorities stay per-type.
 */
abstract class TPFW_Product_Type
{
	protected $sPrefix;
	protected $oFunctions;

	/** @var string Type key: 'pass', 'ticket' or 'timeslot'. */
	protected $sType;

	/** @var string Rows table, without the WordPress table prefix. */
	protected $sTable;

	/** @var string Check-in stats table, without the WordPress table prefix. */
	protected $sStatsTable;

	/** @var string Post meta key prefix, e.g. '_tpfw_pass_'. */
	protected $sMetaPrefix;

	/** @var string WooCommerce product class backing the type, e.g. 'TPFW_Product_Pass'. */
	protected $sProductClass;

	/**
	 * Wires up the product type.
	 *
	 * @param string         $sPrefix    Handle prefix used for registered scripts, styles and options.
	 * @param TPFW_Functions $oFunctions Shared helper instance (QR generation, check-ins, formats).
	 */
	public function __construct($sPrefix, $oFunctions)
	{
		$this->sPrefix 			= $sPrefix;
		$this->oFunctions		= $oFunctions;
		$this->load_settings_dependencies();
	}

	/**
	 * Registers every WordPress/WooCommerce hook the product type owns.
	 *
	 * Called once from the constructor; nothing else in a product class registers hooks, so
	 * each subclass's implementation is the single place to look for what its type reacts to.
	 *
	 * @return void
	 */
	abstract protected function load_settings_dependencies();

	/**
	 * Creates the type's rows for one order line. Idempotent per line.
	 *
	 * @param int           $iOrderID    Order id.
	 * @param int           $iCustomerID Purchasing customer's user id.
	 * @param WC_Order_Item $oOrderItem  Line item being processed.
	 * @return array{sMessage:string,bStatus:bool} Result suitable for an order note.
	 */
	abstract protected function create_entry($iOrderID, $iCustomerID, $oOrderItem);

	/**
	 * Soft deletes the type's rows for one order line. Idempotent per line.
	 *
	 * @param int           $iOrderID    Order id.
	 * @param int           $iCustomerID Purchasing customer's user id.
	 * @param WC_Order_Item $oOrderItem  Line item being cancelled.
	 * @return array{sMessage:string,bStatus:bool} Result suitable for an order note.
	 */
	abstract protected function cancel_entry($iOrderID, $iCustomerID, $oOrderItem);

	/**
	 * AJAX: checks one of this type's rows in from the admin dashboard or the holder's My Account.
	 *
	 * Either the current user manages the plugin, or the row belongs to them - anything else is
	 * rejected. Only a manager may bypass the validity window (a holder checking themselves in
	 * outside it is refused like a scan would be). A refusal from the shared check-in helper
	 * (cooldown, max uses, lock contention) is reported as a failure rather than swallowed.
	 * Answers with the refreshed usage counter, and for tickets the refreshed status pill, so
	 * the calling table row can be patched without a reload.
	 *
	 * Each subclass keeps a thin, hook-named wrapper around this so the wp_ajax_* action names
	 * stay what the dashboards and My Account scripts post to.
	 *
	 * @return void Sends a JSON envelope through wp_send_json_success()/wp_send_json_error() and exits.
	 */
	public function ajax_checkin_callback()
	{
		check_ajax_referer('tpfw_ajax_checkin_' . $this->sType, 'security');

		$sNanoID = sanitize_text_field(wp_unslash($_POST['nano_id'] ?? ''));
		if($sNanoID === '')
		{
			wp_send_json_error(array('sMessage' => __('Could not find the nano id', 'tickets-passes-for-woocommerce')));
		}

		global $wpdb;
		$oRow = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE nano_id = %s AND deleted IS NULL', $wpdb->prefix . $this->sTable, $sNanoID));
		if(!$oRow)
		{
			wp_send_json_error(array('sMessage' => __('Could not find the item with the given nano id', 'tickets-passes-for-woocommerce')));
		}

		$bManager = $this->oFunctions->user_can_manage();
		if(!$bManager && (int) $oRow->user_id !== get_current_user_id())
		{
			wp_send_json_error(array('sMessage' => __('You are not permitted to do this action', 'tickets-passes-for-woocommerce')));
		}

		$mData = $this->oFunctions->checkin($wpdb, $this->sType, $oRow, get_current_user_id(), $bManager);
		if(is_a($mData, 'WP_REST_Response'))
		{
			$aErrorData = $mData->get_data();
			wp_send_json_error(array('sMessage' => $aErrorData['sMessage'] ?? __('Could not check in', 'tickets-passes-for-woocommerce')));
		}

		$iMaxUses = (int) $oRow->max_uses;
		$iUsed    = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE nano_id_fk = %s AND deleted IS NULL', $wpdb->prefix . $this->sStatsTable, $sNanoID));

		$response = array(
			'sMessage'        => __('Successfully checked in', 'tickets-passes-for-woocommerce'),
			'sNewUses'        => $iUsed . ' / ' . $iMaxUses,
			'bMaxUsesReached' => $iUsed >= $iMaxUses,
		);
		// A pass's max uses is a per-period allowance, so its status does not move on a check-in.
		if($this->sType !== 'pass')
		{
			$sLabel = $iUsed >= $iMaxUses ? __('Used', 'tickets-passes-for-woocommerce') : ($iUsed >= 1 ? __('Partial', 'tickets-passes-for-woocommerce') : __('Unused', 'tickets-passes-for-woocommerce'));
			$response['sNewStatus'] = $this->oFunctions->get_status_pill_html($sLabel);
		}
		wp_send_json_success($response);
	}

	/**
	 * A stored meta value, or a fallback when it has never been saved.
	 *
	 * @param int    $iPostID  Product id.
	 * @param string $sKey     Key without the type prefix, e.g. 'max_uses'.
	 * @param mixed  $mDefault Value to use when empty.
	 * @return mixed
	 */
	protected function meta($iPostID, $sKey, $mDefault = '')
	{
		$mValue = get_post_meta($iPostID, $this->sMetaPrefix . $sKey, true);
		return ($mValue === '' || $mValue === null || $mValue === false) ? $mDefault : $mValue;
	}

	/**
	 * Renders the settings cards shared by tickets and passes: Product Note, Access & Usage,
	 * Check-in Rules, Sales Window and Validity Window.
	 *
	 * Every field id is the type's meta prefix plus the same key the save handler reads, so the
	 * two can only ever agree. The ticket and pass classes used to carry their own ~320-line
	 * copies of this markup that differed in the prefix, the card colour and a noun.
	 *
	 * @param string $sCardModifier tpfw-card--{modifier} accent class: 'ticket' or 'annual'.
	 * @param string $sNoun         Translated lower-case noun for descriptions: 'ticket' or 'pass'.
	 * @return void Prints the cards; the caller owns the surrounding panel and grid.
	 */
	protected function render_settings_cards($sCardModifier, $sNoun)
	{
		global $post;
		// Only ever rendered inside the product edit screen, where $post exists. Bailing rather
		// than dereferencing null keeps a third-party call site from fataling.
		if(empty($post)) { return; }

		$iPostID = $post->ID;
		$p       = $this->sMetaPrefix;
		$sToday  = gmdate('Y-m-d', current_time('timestamp'));
		$sCard   = 'tpfw-card tpfw-card--' . esc_attr($sCardModifier);
		?>
		<div class="<?php echo esc_attr($sCard); ?> tpfw-card--wide">
			<div class="tpfw-card-header">
				<svg class="tpfw-card-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h11l3 3v13H5z"/><path d="M8 10h8M8 14h5"/></svg>
				<h3 class="tpfw-card-title"><?php echo esc_html__('Product Note', 'tickets-passes-for-woocommerce'); ?></h3>
				<span class="tpfw-card-tag"><?php echo esc_html__('Max 350 characters', 'tickets-passes-for-woocommerce'); ?></span>
			</div>
			<div class="tpfw-card-body">
				<?php
					$sNote = (string) $this->meta($iPostID, 'note');
					woocommerce_wp_textarea_input(array(
						'id'                => $p . 'note',
						'label'             => __('Note', 'tickets-passes-for-woocommerce'),
						'value'             => $sNote,
						/* translators: %s: "ticket" or "pass" */
						'description'       => sprintf(__('Shown on the %s PDF, in the customer emails, on the order view and in My Account.', 'tickets-passes-for-woocommerce'), $sNoun),
						'custom_attributes' => array('maxlength' => '350'),
					));
				?>
				<span class="tpfw-note-counter" id="tpfw-<?php echo esc_attr($this->sType); ?>-note-counter"><?php echo esc_html(mb_strlen($sNote) . ' / 350'); ?></span>
			</div>
		</div>

		<div class="<?php echo esc_attr($sCard); ?>">
			<div class="tpfw-card-header">
				<svg class="tpfw-card-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l8 4v6c0 4-3.4 7.2-8 8-4.6-.8-8-4-8-8V7z"/><path d="M9 12l2 2 4-4"/></svg>
				<h3 class="tpfw-card-title"><?php echo esc_html__('Access & Usage', 'tickets-passes-for-woocommerce'); ?></h3>
			</div>
			<div class="tpfw-card-body">
				<?php
					woocommerce_wp_text_input(array(
						'id'                => $p . 'max_uses',
						'label'             => __('Max Uses', 'tickets-passes-for-woocommerce'),
						'type'              => 'number',
						'value'             => $this->meta($iPostID, 'max_uses', 1),
						/* translators: %s: "ticket" or "pass" */
						'description'       => sprintf(__('The maximum number of times a %s can be checked in before further check-ins are rejected.', 'tickets-passes-for-woocommerce'), $sNoun),
						'custom_attributes' => array('step' => '1', 'min' => '1', 'max' => '9999'),
					));
					woocommerce_wp_checkbox(array(
						'id'          => $p . 'show_max_uses',
						'label'       => __('Show On Product Page', 'tickets-passes-for-woocommerce'),
						'description' => __('Enable this to list the maximum number of uses in the details table on the product page.', 'tickets-passes-for-woocommerce'),
						'value'       => $this->meta($iPostID, 'show_max_uses'),
					));
				?>
			</div>
		</div>

		<div class="<?php echo esc_attr($sCard); ?>">
			<div class="tpfw-card-header">
				<svg class="tpfw-card-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/></svg>
				<h3 class="tpfw-card-title"><?php echo esc_html__('Check-in Rules', 'tickets-passes-for-woocommerce'); ?></h3>
			</div>
			<div class="tpfw-card-body">
				<?php
					$this->oFunctions->render_duration_field(
						$p . 'cooldown_sec',
						__('Checkin Cooldown', 'tickets-passes-for-woocommerce'),
						/* translators: %s: "ticket" or "pass" */
						sprintf(__('The minimum time required between check-ins on a %s that allows multiple uses.', 'tickets-passes-for-woocommerce'), $sNoun),
						(int) $this->meta($iPostID, 'cooldown_sec', 1)
					);
				?>
			</div>
		</div>

		<?php
			$bSales      = $this->meta($iPostID, 'sales_timespan_enable') == 'yes';
			$sSalesStart = (string) $this->meta($iPostID, 'sales_timespan_start', $sToday);
			$sSalesEnd   = (string) $this->meta($iPostID, 'sales_timespan_end', $sToday);
			if($sSalesEnd < $sSalesStart) $sSalesEnd = $sSalesStart;
			$sSalesHide  = $bSales ? '' : 'hide';
		?>
		<div class="<?php echo esc_attr($sCard); ?>">
			<div class="tpfw-card-header">
				<svg class="tpfw-card-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8l2-4h12l2 4"/><path d="M4 8h16v12H4z"/><path d="M9 12h6"/></svg>
				<h3 class="tpfw-card-title"><?php echo esc_html__('Sales Window', 'tickets-passes-for-woocommerce'); ?></h3>
			</div>
			<div class="tpfw-card-body">
				<?php
					woocommerce_wp_checkbox(array(
						'id'          => $p . 'sales_timespan_enable',
						'label'       => __('Sales Timespan', 'tickets-passes-for-woocommerce'),
						'description' => __('Enable this to restrict purchasing to a specific date range - the product can only be bought while today\'s date falls within it.', 'tickets-passes-for-woocommerce'),
						'value'       => $bSales ? 'yes' : 'no',
					));
					woocommerce_wp_text_input(array(
						'id'            => $p . 'sales_timespan_start',
						'label'         => __('Sales Timespan, Start Date', 'tickets-passes-for-woocommerce'),
						'description'   => __('The date from which this product can be purchased.', 'tickets-passes-for-woocommerce'),
						'value'         => $sSalesStart,
						'wrapper_class' => $sSalesHide,
						'type'          => 'date',
					));
					woocommerce_wp_text_input(array(
						'id'                => $p . 'sales_timespan_end',
						'label'             => __('Sales Timespan, End Date', 'tickets-passes-for-woocommerce'),
						'description'       => __('The last date on which this product can be purchased.', 'tickets-passes-for-woocommerce'),
						'value'             => $sSalesEnd,
						'wrapper_class'     => $sSalesHide,
						'type'              => 'date',
						'custom_attributes' => array('min' => $sSalesStart),
					));
					woocommerce_wp_checkbox(array(
						'id'            => $p . 'show_sales_window',
						'label'         => __('Show On Product Page', 'tickets-passes-for-woocommerce'),
						/* translators: %s: "ticket" or "pass" */
						'description'   => sprintf(__('Enable this to list the dates between which the %s can be bought in the details table on the product page.', 'tickets-passes-for-woocommerce'), $sNoun),
						'value'         => $this->meta($iPostID, 'show_sales_window'),
						'wrapper_class' => $sSalesHide,
					));
				?>
			</div>
		</div>

		<?php
			$bPredefined = $this->meta($iPostID, 'predefined_start_date_enable') == 'yes';
			$bUserDate   = $this->meta($iPostID, 'user_start_date_enable') == 'yes';
			// A blank range is not "no limit" here - it is a product that has never had the
			// fields saved, so it opens on the same year-long default the picker uses.
			$sMin = (string) $this->meta($iPostID, 'user_start_date_min');
			$sMax = (string) $this->meta($iPostID, 'user_start_date_max');
			if($sMin === '' || $sMin < $sToday) $sMin = $sToday;
			if($sMax === '' || $sMax < $sMin)   $sMax = gmdate('Y-m-d', strtotime('+1 year', current_time('timestamp')));
			// Only offered when the shop owns the dates and they are a window: a customer-picked
			// start is not known until purchase, and a predefined one publishes itself.
			$sHideWindow = ($bUserDate || $bPredefined) ? 'hide' : '';
		?>
		<div class="<?php echo esc_attr($sCard); ?>">
			<div class="tpfw-card-header">
				<svg class="tpfw-card-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v14H4z"/><path d="M4 10h16M9 3v4M15 3v4"/></svg>
				<h3 class="tpfw-card-title"><?php echo esc_html__('Validity Window', 'tickets-passes-for-woocommerce'); ?></h3>
			</div>
			<div class="tpfw-card-body">
				<?php
					woocommerce_wp_checkbox(array(
						'id'          => $p . 'predefined_start_date_enable',
						'label'       => __('Predefined Start Date', 'tickets-passes-for-woocommerce'),
						/* translators: %s: "ticket" or "pass" */
						'description' => sprintf(__('Enable this to make the %s valid from a fixed date, instead of the validity period starting the moment it is purchased.', 'tickets-passes-for-woocommerce'), $sNoun),
						'value'       => $bPredefined ? 'yes' : 'no',
					));
					woocommerce_wp_text_input(array(
						'id'            => $p . 'predefined_start_date',
						'label'         => __('Predefined Start Date', 'tickets-passes-for-woocommerce'),
						/* translators: %s: "ticket" or "pass" */
						'description'   => sprintf(__('The fixed date the %s becomes valid from, regardless of when it was purchased.', 'tickets-passes-for-woocommerce'), $sNoun),
						'value'         => $this->meta($iPostID, 'predefined_start_date', $sToday),
						'wrapper_class' => $bPredefined ? '' : 'hide',
						'type'          => 'date',
					));
					// Mutually exclusive with the fixed date: the panel's script unticks whichever
					// was on, and save_settings_cards() is what actually refuses both.
					woocommerce_wp_checkbox(array(
						'id'          => $p . 'user_start_date_enable',
						'label'       => __('Customer Selected Start Date', 'tickets-passes-for-woocommerce'),
						/* translators: %s: "ticket" or "pass" */
						'description' => sprintf(__('Enable this to let the customer pick the date the %s is valid from, on the product page. Cannot be combined with a predefined start date.', 'tickets-passes-for-woocommerce'), $sNoun),
						'value'       => $bUserDate ? 'yes' : 'no',
					));
					woocommerce_wp_text_input(array(
						'id'                => $p . 'user_start_date_min',
						'label'             => __('Selectable From', 'tickets-passes-for-woocommerce'),
						'description'       => __('The earliest date the customer can pick on the product page.', 'tickets-passes-for-woocommerce'),
						'value'             => $sMin,
						'wrapper_class'     => $bUserDate ? '' : 'hide',
						'type'              => 'date',
						'custom_attributes' => array('min' => $sToday),
					));
					woocommerce_wp_text_input(array(
						'id'                => $p . 'user_start_date_max',
						'label'             => __('Selectable To', 'tickets-passes-for-woocommerce'),
						'description'       => __('The latest date the customer can pick on the product page.', 'tickets-passes-for-woocommerce'),
						'value'             => $sMax,
						'wrapper_class'     => $bUserDate ? '' : 'hide',
						'type'              => 'date',
						'custom_attributes' => array('min' => $sMin),
					));
					$this->oFunctions->render_duration_field(
						$p . 'valid_duration',
						__('Valid Duration', 'tickets-passes-for-woocommerce'),
						/* translators: %s: "ticket" or "pass" */
						sprintf(__('How long a %s is valid, from the point of purchase.', 'tickets-passes-for-woocommerce'), $sNoun),
						(int) $this->meta($iPostID, 'valid_duration', DAY_IN_SECONDS)
					);
					woocommerce_wp_checkbox(array(
						'id'            => $p . 'show_predefined_date',
						'label'         => __('Show Date On Product Page', 'tickets-passes-for-woocommerce'),
						'description'   => __('Enable this to list the predefined date in the details table on the product page.', 'tickets-passes-for-woocommerce'),
						'value'         => $this->meta($iPostID, 'show_predefined_date'),
						'wrapper_class' => $bPredefined ? '' : 'hide',
					));
					woocommerce_wp_checkbox(array(
						'id'            => $p . 'show_valid_from',
						'label'         => __('Show Valid from On Product Page', 'tickets-passes-for-woocommerce'),
						/* translators: %s: "ticket" or "pass" */
						'description'   => sprintf(__('Enable this to list the date the %s becomes valid in the details table on the product page.', 'tickets-passes-for-woocommerce'), $sNoun),
						'value'         => $this->meta($iPostID, 'show_valid_from'),
						'wrapper_class' => $sHideWindow,
					));
					woocommerce_wp_checkbox(array(
						'id'            => $p . 'show_valid_to',
						'label'         => __('Show Valid to On Product Page', 'tickets-passes-for-woocommerce'),
						/* translators: %s: "ticket" or "pass" */
						'description'   => sprintf(__('Enable this to list the date the %s expires in the details table on the product page.', 'tickets-passes-for-woocommerce'), $sNoun),
						'value'         => $this->meta($iPostID, 'show_valid_to'),
						'wrapper_class' => $sHideWindow,
					));
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Persists everything render_settings_cards() renders.
	 *
	 * Runs on the woocommerce_process_product_meta_{type} hook, where WooCommerce has already
	 * verified woocommerce_meta_nonce and edit_post. Numbers are stored as whole numbers or
	 * dropped; the two start-date toggles are mutually exclusive, with the customer's date
	 * winning so a stale form cannot store both; disabled windows clear their dates so a later
	 * re-enable cannot silently reuse them.
	 *
	 * @param int $post_id Product id being saved.
	 * @return void
	 */
	protected function save_settings_cards($post_id)
	{
		$p = $this->sMetaPrefix;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.

		$sNote = mb_substr(sanitize_textarea_field(wp_unslash($_POST[$p . 'note'] ?? '')), 0, 350);
		if($sNote !== '') update_post_meta($post_id, $p . 'note', $sNote);
		else               delete_post_meta($post_id, $p . 'note');

		foreach(array('max_uses', 'valid_duration', 'cooldown_sec') as $sKey)
		{
			$iValue = absint($_POST[$p . $sKey] ?? 0);
			if($iValue > 0) update_post_meta($post_id, $p . $sKey, $iValue);
			else            delete_post_meta($post_id, $p . $sKey);
		}

		$this->oFunctions->save_checkbox_meta($post_id, $p . 'show_max_uses');

		$bUserStartDate = $this->oFunctions->save_checkbox_meta($post_id, $p . 'user_start_date_enable');
		$bPredefined    = sanitize_text_field(wp_unslash($_POST[$p . 'predefined_start_date_enable'] ?? '')) == 'yes' && !$bUserStartDate;

		$this->oFunctions->save_customer_date_range($post_id, $bUserStartDate, $p . 'user_start_date_min', $p . 'user_start_date_max');

		// Nothing to publish up front when the date is the customer's to pick, and a predefined
		// date publishes itself through its own row rather than as a window.
		$this->oFunctions->save_checkbox_meta($post_id, $p . 'show_valid_from', !$bUserStartDate && !$bPredefined);
		$this->oFunctions->save_checkbox_meta($post_id, $p . 'show_valid_to', !$bUserStartDate && !$bPredefined);
		$this->oFunctions->save_checkbox_meta($post_id, $p . 'show_predefined_date', $bPredefined);

		$sPredefinedDate = sanitize_text_field(wp_unslash($_POST[$p . 'predefined_start_date'] ?? ''));
		if($bPredefined && $this->oFunctions->is_valid_ymd($sPredefinedDate))
		{
			update_post_meta($post_id, $p . 'predefined_start_date', $sPredefinedDate);
			update_post_meta($post_id, $p . 'predefined_start_date_enable', 'yes');
		}
		else
		{
			delete_post_meta($post_id, $p . 'predefined_start_date');
			delete_post_meta($post_id, $p . 'predefined_start_date_enable');
		}

		$bSales = sanitize_text_field(wp_unslash($_POST[$p . 'sales_timespan_enable'] ?? '')) == 'yes';
		$sStart = sanitize_text_field(wp_unslash($_POST[$p . 'sales_timespan_start'] ?? ''));
		$sEnd   = sanitize_text_field(wp_unslash($_POST[$p . 'sales_timespan_end'] ?? ''));
		if($bSales && $this->oFunctions->is_valid_ymd($sStart))
		{
			update_post_meta($post_id, $p . 'sales_timespan_start', $sStart);
			update_post_meta($post_id, $p . 'sales_timespan_end', $this->oFunctions->clamp_sales_timespan_end($sStart, $sEnd));
			update_post_meta($post_id, $p . 'sales_timespan_enable', 'yes');
		}
		else
		{
			$bSales = false;
			delete_post_meta($post_id, $p . 'sales_timespan_start');
			delete_post_meta($post_id, $p . 'sales_timespan_end');
			delete_post_meta($post_id, $p . 'sales_timespan_enable');
		}
		// Without a sales window there are no dates to publish.
		$this->oFunctions->save_checkbox_meta($post_id, $p . 'show_sales_window', $bSales);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * WooCommerce payment_complete — the order is paid. Shared mint path after the policy.
	 *
	 * @param int $order_id
	 * @return void
	 */
	public function order_payment_complete($order_id)
	{
		$this->issue_for_intent($order_id, TPFW_Issue_Policy::PAYMENT_COMPLETE);
	}

	/**
	 * Order moved to completed — including offline checkouts the merchant finishes.
	 *
	 * @param int $order_id
	 * @return void
	 */
	public function order_status_completed($order_id)
	{
		$this->issue_for_intent($order_id, TPFW_Issue_Policy::COMPLETED);
	}

	/**
	 * Admin Create metabox. Mints without waiting for payment_complete or completed.
	 *
	 * @param int $order_id
	 * @return void
	 */
	public function order_force_issue($order_id)
	{
		$this->issue_for_intent($order_id, TPFW_Issue_Policy::FORCE);
	}

	/**
	 * @param int    $order_id
	 * @param string $sIntent
	 * @return void
	 */
	private function issue_for_intent($order_id, $sIntent)
	{
		$oOrder = wc_get_order($order_id);
		if(empty($oOrder))
		{
			return;
		}
		$sStatus = method_exists($oOrder, 'get_status') ? $oOrder->get_status() : '';
		if(!TPFW_Issue_Policy::should_issue_for_intent($sIntent, $sStatus))
		{
			return;
		}
		$this->issue_order_lines($order_id);
	}

	/**
	 * Issues this type's rows for every matching line. Idempotent per line (upsert).
	 *
	 * @param int $order_id WooCommerce order id.
	 * @return void
	 */
	public function order_completed($order_id)
	{
		$this->order_force_issue($order_id);
	}

	/**
	 * @param int $order_id
	 * @return void
	 */
	private function issue_order_lines($order_id)
	{
		$iOrderID     = $order_id;
		$oOrder       = wc_get_order($iOrderID);
		// The empty() guard has to come before get_user_id(): wc_get_order() returns false for
		// an order that no longer exists, and calling a method on that is a fatal.
		if(empty($oOrder))
		{
			return;
		}
		$iOrderUserID = $oOrder->get_user_id();

		foreach($oOrder->get_items() as $iOrderItemKey => $oOrderItem)
		{
			$oOrderItemProduct = wc_get_product($oOrderItem->get_product_id());
			if(empty($oOrderItemProduct)) continue;
			if(is_a($oOrderItemProduct, $this->sProductClass))
			{
				$aResult = $this->create_entry($iOrderID, $iOrderUserID, $oOrderItem);
				$oOrder->add_order_note($aResult['sMessage']);
				$oOrder->save();
			}
		}
	}

	/**
	 * Partial or full refund created. Reconciles issued rows to purchased minus refunded ITEM qty.
	 *
	 * Amount-only refunds (item qty 0) are ignored here. A later status of refunded/cancelled/failed
	 * still goes through order_cancelled() and revokes everything.
	 *
	 * @param int $order_id  WooCommerce order id.
	 * @param int $refund_id Unused; WooCommerce supplies it on woocommerce_order_refunded.
	 * @return void
	 */
	public function order_refunded($order_id, $refund_id = 0)
	{
		$this->reconcile_order_lines($order_id);
	}

	/**
	 * @param int $order_id
	 * @return void
	 */
	private function reconcile_order_lines($order_id)
	{
		$oOrder = wc_get_order($order_id);
		if(empty($oOrder))
		{
			return;
		}
		$iOrderUserID = $oOrder->get_user_id();

		foreach($oOrder->get_items() as $oOrderItem)
		{
			$oOrderItemProduct = wc_get_product($oOrderItem->get_product_id());
			if(empty($oOrderItemProduct)) continue;
			if(!is_a($oOrderItemProduct, $this->sProductClass)) continue;
			if(!TPFW_Refund_Policy::should_reconcile($oOrder, $oOrderItem)) continue;

			$iTarget = TPFW_Refund_Policy::target_active_quantity($oOrder, $oOrderItem);
			$aResult = $this->reconcile_entry($order_id, $iOrderUserID, $oOrderItem, $iTarget);
			if(!empty($aResult['sMessage']))
			{
				$oOrder->add_order_note($aResult['sMessage']);
				$oOrder->save();
			}
		}
	}

	/**
	 * @param int           $iOrderID
	 * @param int           $iCustomerID
	 * @param object        $oOrderItem
	 * @param int           $iTarget
	 * @return array{sMessage:string,bStatus:bool}
	 */
	protected function reconcile_entry($iOrderID, $iCustomerID, $oOrderItem, $iTarget)
	{
		if((int)$iTarget <= 0)
		{
			return $this->cancel_entry($iOrderID, $iCustomerID, $oOrderItem);
		}
		return $this->shrink_issued_rows($iOrderID, $oOrderItem, (int)$iTarget);
	}

	/**
	 * Live issued rows for this line, deterministic id ASC. Passes exclude guest children.
	 *
	 * @param object $wpdb
	 * @param int    $iOrderID
	 * @param object $oOrderItem
	 * @return array
	 */
	protected function select_live_issued_rows($wpdb, $iOrderID, $oOrderItem)
	{
		$sSql = 'SELECT * FROM %i WHERE product_id = %d AND order_id = %d AND order_line_id = %d AND deleted IS NULL';
		$aArgs = array(
			$wpdb->prefix.$this->sTable,
			$oOrderItem->get_product_id(),
			$iOrderID,
			$oOrderItem->get_id(),
		);
		if($this->issued_rows_are_parents_only())
		{
			$sSql .= ' AND parent_nano_id_fk IS NULL';
		}
		$sSql .= ' ORDER BY id ASC';
		return $wpdb->get_results($wpdb->prepare($sSql, $aArgs));
	}

	/**
	 * @return bool
	 */
	protected function issued_rows_are_parents_only()
	{
		return false;
	}

	/**
	 * Soft-deletes surplus live rows. Does not insert. Remaining nano_ids stay.
	 *
	 * @param int    $iOrderID
	 * @param object $oOrderItem
	 * @param int    $iTarget
	 * @return array{sMessage:string,bStatus:bool}
	 */
	protected function shrink_issued_rows($iOrderID, $oOrderItem, $iTarget)
	{
		global $wpdb;
		$sNow  = current_time('mysql');
		$aLive = $this->select_live_issued_rows($wpdb, $iOrderID, $oOrderItem);
		$aSync = TPFW_Order_Line_Upsert::shrink($wpdb, $wpdb->prefix.$this->sTable, $aLive, $iTarget, $sNow);
		if(empty($aSync['ok']))
		{
			return array(
				'sMessage' => __('Could not reconcile issued quantity because the database write failed.', 'tickets-passes-for-woocommerce'),
				'bStatus'  => false,
			);
		}
		foreach($aSync['deleted'] as $sNano)
		{
			$this->after_revoke_row($sNano);
		}
		if(empty($aSync['deleted']))
		{
			return array(
				'sMessage' => '',
				'bStatus'  => true,
			);
		}
		return array(
			'sMessage' => sprintf(
				/* translators: 1: product type label, 2: comma-separated nano ids */
				__('%1$s quantity reconciled; cancelled: %2$s', 'tickets-passes-for-woocommerce'),
				$this->sType,
				implode(', ', $aSync['deleted'])
			),
			'bStatus'  => true,
		);
	}

	/**
	 * Per-row cleanup after a refund shrink (stats + QR). Passes also revoke guests.
	 *
	 * @param string $sNanoId
	 * @return void
	 */
	protected function after_revoke_row($sNanoId)
	{
		global $wpdb;
		$sNow = current_time('mysql');
		$wpdb->query($wpdb->prepare(
			'UPDATE %i SET deleted = %s, updated = %s WHERE nano_id_fk = %s',
			$wpdb->prefix.$this->sStatsTable,
			$sNow,
			$sNow,
			$sNanoId
		));
		if($this->oFunctions && method_exists($this->oFunctions, 'delete_qr_code'))
		{
			$this->oFunctions->delete_qr_code($sNanoId);
		}
	}

	/**
	 * Revokes the type's entries on an order that has just been cancelled, refunded or failed.
	 *
	 * Hooked on all three statuses by each subclass - see its load_settings_dependencies().
	 * Also called directly by TPFW_Admin's cancel metabox action.
	 *
	 * @param int $order_id WooCommerce order id supplied by the status hook.
	 * @return void
	 */
	public function order_cancelled($order_id)
	{
		$iOrderID     = $order_id;
		$oOrder       = wc_get_order($iOrderID);
		// Same as order_completed(): guard before dereferencing, wc_get_order() can return false.
		if(empty($oOrder))
		{
			return;
		}
		$iOrderUserID = $oOrder->get_user_id();

		foreach($oOrder->get_items() as $iOrderItemKey => $oOrderItem)
		{
			$oOrderItemProduct = wc_get_product($oOrderItem->get_product_id());
			if(empty($oOrderItemProduct)) continue;
			if(is_a($oOrderItemProduct, $this->sProductClass))
			{
				$aResult = $this->cancel_entry($iOrderID, $iOrderUserID, $oOrderItem);
				$oOrder->add_order_note($aResult['sMessage']);
				$oOrder->save();
			}
		}
	}
}
