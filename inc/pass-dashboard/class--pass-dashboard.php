<?php
defined('ABSPATH') or die('No script kiddies please!');

require_once dirname(__FILE__) . '/../dashboard/class--dashboard.php';

/**
 * Passes admin screen: every issued pass with its guest passes, and the row actions.
 *
 * On top of what TPFW_Dashboard provides this screen has guest pass rows nested under their
 * parent, a profile photo column with a "Delete Image" action, and a resend email that is
 * worded differently for guest passes.
 *
 * @package Tickets_Passes_For_WooCommerce
 */
class TPFW_Pass_Dashboard extends TPFW_Dashboard
{
	protected $sSlug                    = 'tpfw-pass';
	protected $sTableClass              = 'TPFW_Pass_Table_Dashboard';
	protected $sActionKey               = 'pass';
	protected $sCheckinKey              = 'pass';
	protected $sTable                   = 'tpfw_pass';
	protected $sResetMethod             = 'reset_pass';
	protected $sCancelMethod            = 'cancel_pass';
	protected $sResendTemplateKey       = 'resend_pass_email';
	protected $sResendReplacementMethod = 'resend_pass_email_replacement';
	protected $aExtraNonceKeys          = array('ajax_admin_delete_pass_image');

	/**
	 * @param string         $sPrefix    Option/handle prefix for the plugin ('tpfw').
	 * @param TPFW_Functions $oFunctions Shared helper instance.
	 */
	public function __construct($sPrefix, $oFunctions)
	{
		$this->sMenuLabel        = __('Passes', 'tickets-passes-for-woocommerce');
		$this->sRowLabel         = __('Pass', 'tickets-passes-for-woocommerce');
		$this->sPageTitle        = __('Passes', 'tickets-passes-for-woocommerce');
		$this->sResetStatusLabel = __('Active', 'tickets-passes-for-woocommerce');
		parent::__construct($sPrefix, $oFunctions);
	}

	/**
	 * Registers the profile-photo removal action.
	 *
	 * @return void
	 */
	protected function register_extra_actions()
	{
		add_action('wp_ajax_tpfw_ajax_admin_delete_pass_image', array($this, 'ajax_admin_delete_pass_image_callback'));
	}

	/**
	 * Loads the guest-row script and stylesheet on top of the shared ones.
	 *
	 * @return void
	 */
	protected function enqueue_extra_assets()
	{
		wp_register_script($this->sPrefix.'pass-dashboard', plugins_url('', __FILE__).'/js/pass-dashboard.js', array('jquery', $this->sPrefix.'dashboard'), filemtime(dirname(__FILE__).'/js/pass-dashboard.js'), true);
		wp_enqueue_script($this->sPrefix.'pass-dashboard');
		wp_enqueue_style($this->sPrefix.'pass-dashboard', plugins_url('', __FILE__).'/css/pass-dashboard.css', array($this->sPrefix.'dashboard'), filemtime(dirname(__FILE__).'/css/pass-dashboard.css'));
	}

	/**
	 * Resend placeholder map: the pass wording, with the guest variant when the row has a parent.
	 *
	 * @param WC_Order   $oOrder   Order the pass was bought on.
	 * @param object     $oRow     Row from the pass table.
	 * @param WC_Product $oProduct Product behind the row.
	 * @return array Placeholder => value.
	 */
	protected function build_resend_replacements($oOrder, $oRow, $oProduct)
	{
		$oMatchedItem = null;
		foreach($oOrder->get_items() as $oItem)
		{
			if((int) $oItem->get_id() === (int) $oRow->order_line_id) { $oMatchedItem = $oItem; break; }
		}
		if(!$oMatchedItem)
		{
			wp_send_json_error(array('sMessage' => __('No matching order item found for the provided Order Line ID', 'tickets-passes-for-woocommerce')));
		}
		return $this->oFunctions->resend_pass_email_replacement($oOrder, $oRow->nano_id, $oProduct->get_name(), $oMatchedItem, !empty($oRow->parent_nano_id_fk));
	}

	/**
	 * A pass PDF is laid out differently from a ticket's, and a guest pass's QR code lives in
	 * its own folder, so the renderer has to be told which of the two it is looking at.
	 *
	 * @param object $oRow Row from the pass table.
	 * @return array bSuccess, sMessage and - on success - sDownloadURL and sFilename.
	 */
	protected function render_row_pdf($oRow)
	{
		return $this->oFunctions->create_fetch_pass_pdf_qr($oRow->nano_id, empty($oRow->parent_nano_id_fk) ? 'qr' : 'guest');
	}

	/**
	 * Resetting a parent cascades to its guest passes; report their own max uses back so the
	 * guest rows can be patched without a reload.
	 *
	 * @param array $aResult  Return value of reset_pass().
	 * @param array $response Response so far.
	 * @return array
	 */
	protected function augment_reset_response($aResult, $response)
	{
		$response['sNewUses']   = '0 / ' . $aResult['iMaxUses'];
		$response['aGuestUses'] = array_map(function($oGuest)
		{
			return array('sNanoId' => $oGuest->nano_id, 'sNewUses' => '0 / ' . $oGuest->max_uses);
		}, $aResult['aGuestUses']);
		return $response;
	}

	/**
	 * Removes a pass holder's profile photo.
	 *
	 * The row is matched on id, nano id AND user id together, so a tampered request cannot
	 * clear the photo off a pass it did not name in full. The file is only unlinked once that
	 * UPDATE actually matched a row.
	 *
	 * @return void
	 */
	public function ajax_admin_delete_pass_image_callback()
	{
		$response = $this->guard_ajax('ajax_admin_delete_pass_image');
		$sNanoID  = $this->posted_nano_id();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_ajax() verified the nonce.
		$iRowID = absint($_POST['row_id'] ?? 0);
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$iUserID = absint($_POST['user_id'] ?? 0);
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$sType = sanitize_key(wp_unslash($_POST['profile_image_type'] ?? ''));

		if($iRowID <= 0 || $iUserID <= 0 || !preg_match('/^[a-zA-Z0-9]+$/', $sNanoID) || !in_array($sType, array('jpeg', 'jpg', 'png', 'webp'), true))
		{
			wp_send_json_error(array('sMessage' => __('Invalid request', 'tickets-passes-for-woocommerce')));
		}

		global $wpdb;
		$wpdb->query($wpdb->prepare('UPDATE %i SET profile_image_type = NULL WHERE id = %d AND nano_id = %s AND user_id = %d AND profile_image_type IS NOT NULL', $wpdb->prefix . 'tpfw_pass', $iRowID, $sNanoID, $iUserID));
		if($wpdb->rows_affected < 1)
		{
			wp_send_json_error(array('sMessage' => __('No profile image was found on that pass', 'tickets-passes-for-woocommerce')));
		}

		$sImagePath = $this->oFunctions->get_profile_image_upload_dir() . $sNanoID . '.' . $sType;
		if(file_exists($sImagePath))
		{
			wp_delete_file($sImagePath);
		}

		$response['sMessage'] = __('Pass Profile image have successfully been removed', 'tickets-passes-for-woocommerce');
		wp_send_json_success($response);
	}
}

/**
 * WP_List_Table rendering of the pass table, with guest passes nested under their parent.
 */
class TPFW_Pass_Table_Dashboard extends TPFW_Dashboard_Table
{
	protected $sTable         = 'tpfw_pass';
	protected $sStatsTable    = 'tpfw_pass_stats';
	protected $sBaseWhere     = 't.parent_nano_id_fk IS NULL';
	protected $aSearchColumns = array('nano_id', 'user_id', 'order_id', 'id', 'firstname', 'lastname');
	protected $aSortable      = array('buyer_user' => 'user_payer_id', 'pass_holder_name' => 'lastname', 'valid' => 'valid_from', 'product_id' => 'product_id', 'order_id' => 'order_id', 'created' => 'created');

	/** @var array Guest passes for the current page, keyed by parent nano id. */
	private $aGuestPassesByParent = array();

	/**
	 * @param TPFW_Functions $oFunctions Shared helper instance.
	 * @param TPFW_Dashboard $oDashboard Screen this table belongs to.
	 */
	public function __construct($oFunctions, $oDashboard)
	{
		parent::__construct($oFunctions, $oDashboard, array('singular' => 'pass', 'plural' => 'passes'));
	}

	/**
	 * @return array Column id => translated heading.
	 */
	public function get_columns()
	{
		return array(
			'cb'               => '<input type="checkbox" />',
			'buyer_user'       => __('Buyer', 'tickets-passes-for-woocommerce'),
			'profile_image'    => __('Profile Image', 'tickets-passes-for-woocommerce'),
			'pass_holder_name' => __('Holder Name', 'tickets-passes-for-woocommerce'),
			'nano_id'          => __('Pass ID', 'tickets-passes-for-woocommerce'),
			'status'           => __('Status', 'tickets-passes-for-woocommerce'),
			'valid'            => __('Valid from', 'tickets-passes-for-woocommerce'),
			'uses'             => __('Check-ins', 'tickets-passes-for-woocommerce'),
			'product_id'       => __('Product', 'tickets-passes-for-woocommerce'),
			'order_id'         => __('Order', 'tickets-passes-for-woocommerce'),
			'created'          => __('Created', 'tickets-passes-for-woocommerce'),
			'actions'          => __('Actions', 'tickets-passes-for-woocommerce'),
		);
	}

	/**
	 * A pass has no "used up" state - max_uses is a per-period allowance, not a total.
	 *
	 * @return array See TPFW_Dashboard_Table::get_status_filters().
	 */
	protected function get_status_filters()
	{
		return array(
			'active'        => array('label' => __('Active', 'tickets-passes-for-woocommerce'),        'where' => 't.deleted IS NULL AND t.valid_from <= {now} AND t.valid_to >= {now}'),
			'expired'       => array('label' => __('Expired', 'tickets-passes-for-woocommerce'),       'where' => 't.deleted IS NULL AND t.valid_to < {now}'),
			'not_valid_yet' => array('label' => __('Not valid yet', 'tickets-passes-for-woocommerce'), 'where' => 't.deleted IS NULL AND t.valid_from > {now}'),
			'cancelled'     => array('label' => __('Cancelled', 'tickets-passes-for-woocommerce'),     'where' => 't.deleted IS NOT NULL'),
		);
	}

	/**
	 * Derives a pass's status: cancelled beats expired beats not-yet-valid beats active.
	 *
	 * @param object $oResult Row from the pass table.
	 * @param int    $iNow    Current site timestamp.
	 * @return array slug and translated label.
	 */
	private function derive_pass_status($oResult, $iNow)
	{
		if(!empty($oResult->deleted))               return array('cancelled', __('Cancelled', 'tickets-passes-for-woocommerce'));
		if(strtotime($oResult->valid_to) < $iNow)   return array('expired', __('Expired', 'tickets-passes-for-woocommerce'));
		if(strtotime($oResult->valid_from) > $iNow) return array('not_valid_yet', __('Not valid yet', 'tickets-passes-for-woocommerce'));
		return array('active', __('Active', 'tickets-passes-for-woocommerce'));
	}

	/**
	 * Guest passes are fetched for the page's parents in one query, and their check-in counts
	 * are batched together with the parents'.
	 *
	 * @param array $aResults Parent rows.
	 * @return array
	 */
	protected function context_nano_ids($aResults)
	{
		global $wpdb;
		$this->aGuestPassesByParent = array();
		$aParentIDs = wp_list_pluck($aResults, 'nano_id');
		if(!empty($aParentIDs))
		{
			$sPlaceholders = implode(', ', array_fill(0, count($aParentIDs), '%s'));
			$oPrepared     = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder scaffold; every value is bound through the array.
				"SELECT * FROM %i WHERE parent_nano_id_fk IN ($sPlaceholders) ORDER BY id ASC",
				array_merge(array($wpdb->prefix . 'tpfw_pass'), $aParentIDs)
			);
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oPrepared is the return value of $wpdb->prepare() above.
			foreach((array) $wpdb->get_results($oPrepared) as $oGuest)
			{
				$this->aGuestPassesByParent[$oGuest->parent_nano_id_fk][] = $oGuest;
				$aParentIDs[] = $oGuest->nano_id;
			}
		}
		return $aParentIDs;
	}

	/**
	 * Buyers are looked up in one query for the page too.
	 *
	 * @param array $aResults Parent rows.
	 * @return array
	 */
	protected function get_row_context($aResults)
	{
		$aCtx      = parent::get_row_context($aResults);
		$aBuyerIDs = array_filter(array_map('intval', wp_list_pluck($aResults, 'user_payer_id')));
		if(!empty($aBuyerIDs)) { cache_users(array_unique($aBuyerIDs)); }
		return $aCtx;
	}

	/**
	 * Assembles one row, for a parent pass or a guest pass.
	 *
	 * Action buttons are rendered even when they do not apply and merely hidden, so the shared
	 * script can show them again after a reset without rebuilding markup.
	 *
	 * @param object $oResult      Row from the pass table.
	 * @param array  $aCtx         Batched context.
	 * @param bool   $bIsGuestRow  Whether this is a guest pass rather than a parent.
	 * @param int    $iGuestCount  Guest passes hanging off this parent.
	 * @return array Row data keyed by column.
	 */
	private function build_pass_row($oResult, $aCtx, $bIsGuestRow, $iGuestCount)
	{
		$iUses = (int) ($aCtx['aStatisticCounts'][$oResult->nano_id] ?? 0);
		list($sSlug, $sLabel) = $this->derive_pass_status($oResult, $aCtx['iNow']);
		$bCancelled = $sSlug === 'cancelled';
		$sStatus    = $this->oFunctions->get_status_pill_html($sLabel, $bCancelled ? gmdate($aCtx['sDateTimeFormat'], strtotime($oResult->deleted)) : '');

		$oBuyer     = get_user_by('ID', (int) $oResult->user_payer_id);
		$sBuyerName = $oBuyer ? $oBuyer->display_name : __('(deleted user)', 'tickets-passes-for-woocommerce');

		// A cancelled guest row can only be revived by resetting its parent, so it shows no
		// actions at all rather than a misleading lone Reset.
		$bHideAll = $bIsGuestRow && $bCancelled;
		$sKey     = $this->oDashboard->get_action_key();
		$aIDs     = array('nanoid' => $oResult->nano_id, 'orderid' => $oResult->order_id, 'orderlineid' => $oResult->order_line_id, 'userid' => $oResult->user_id, 'maxuses' => $oResult->max_uses);

		$sActions  = '<div class="tpfw-actions">';
		$sActions .= $this->action_button('delete-image', 'ajax_admin_delete_pass_image', __('Delete Image', 'tickets-passes-for-woocommerce'), array_merge($aIDs, array('rowid' => $oResult->id, 'imagetype' => $oResult->profile_image_type)), $bHideAll || empty($oResult->profile_image_type));
		$sActions .= $this->action_button('checkin', 'ajax_checkin_pass', __('Checkin', 'tickets-passes-for-woocommerce'), $aIDs, $bHideAll || $bCancelled);
		if(!$bIsGuestRow && $iGuestCount > 0)
		{
			$sActions .= '<button type="button" class="button button-secondary tpfw-btn tpfw-btn--guestpass btn-admin-toggle-guestpass" data-attr-nanoid="' . esc_attr($oResult->nano_id) . '">' . esc_html__('Guest Pass', 'tickets-passes-for-woocommerce') . '</button>';
		}
		$sActions .= $this->action_button('reset',  'ajax_admin_reset_'  . $sKey, __('Reset', 'tickets-passes-for-woocommerce'),  $aIDs, $bHideAll);
		$sActions .= $this->action_button('resend', 'ajax_admin_resend_' . $sKey, __('Resend', 'tickets-passes-for-woocommerce'), $aIDs, $bHideAll || $bCancelled);
		$sActions .= $bHideAll ? '' : $this->download_button($oResult, $bIsGuestRow ? 'guest' : 'qr');
		$sActions .= $this->action_button('cancel', 'ajax_admin_cancel_' . $sKey, __('Cancel', 'tickets-passes-for-woocommerce'), $aIDs, $bHideAll || $bCancelled);
		$sActions .= '</div>';

		$aRow = $this->common_cells($oResult, $aCtx, $sStatus, $iUses);
		$aRow['buyer_user']       = '<a target="_blank" href="' . esc_url(get_edit_user_link((int) $oResult->user_payer_id)) . '">#' . (int) $oResult->user_payer_id . ' ' . esc_html($sBuyerName) . '</a>';
		$aRow['profile_image']    = (!$bIsGuestRow && !empty($oResult->profile_image_type)) ? '<img src="' . esc_url($this->oFunctions->get_file_url('profile', $oResult->nano_id, $oResult->profile_image_type)) . '" class="tpfw-profile-thumb" alt="">' : '';
		$aRow['pass_holder_name'] = esc_html($oResult->firstname . (!empty($oResult->lastname) ? ' ' . mb_substr($oResult->lastname, 0, 1) . '.' : ''));
		$aRow['nano_id']          = $this->oFunctions->get_truncated_id_html($oResult->nano_id, 'tpfw-nano-id');
		$aRow['uses']             = $aRow['checkins'];
		$aRow['actions']          = $sActions;
		$aRow['guest_passes']     = array();
		return $aRow;
	}

	/**
	 * A pass exports its guest passes with it, the same way the screen lists them under it.
	 *
	 * @param object $oResult Parent pass row.
	 * @param array  $aCtx    Batched context.
	 * @return array List of associative rows.
	 */
	protected function export_rows_for($oResult, $aCtx)
	{
		$aRows = array($this->export_row($oResult, $aCtx));
		foreach($this->aGuestPassesByParent[$oResult->nano_id] ?? array() as $oGuest)
		{
			$aRows[] = $this->export_row($oGuest, $aCtx);
		}
		return $aRows;
	}

	/**
	 * Adds the columns a pass has and a ticket does not: whether the row is a guest pass, who
	 * paid for it, and the name of the person it admits.
	 *
	 * @param object $oResult Row from the pass table.
	 * @param array  $aCtx    Batched context.
	 * @return array
	 */
	protected function export_row($oResult, $aCtx)
	{
		$oBuyer = get_user_by('ID', (int) $oResult->user_payer_id);

		return array_merge(
			array(
				__('Type', 'tickets-passes-for-woocommerce')          => empty($oResult->parent_nano_id_fk)
					? __('Pass', 'tickets-passes-for-woocommerce')
					: __('Guest Pass', 'tickets-passes-for-woocommerce'),
				__('Parent pass ID', 'tickets-passes-for-woocommerce') => (string) $oResult->parent_nano_id_fk,
				__('Buyer user ID', 'tickets-passes-for-woocommerce')  => (int) $oResult->user_payer_id,
				__('Buyer email', 'tickets-passes-for-woocommerce')    => $oBuyer ? $oBuyer->user_email : '',
				__('Holder name', 'tickets-passes-for-woocommerce')    => trim($oResult->firstname . ' ' . $oResult->lastname),
			),
			parent::export_row($oResult, $aCtx)
		);
	}

	/**
	 * A pass has no "used up" state, so the export reads its status the same way the screen does.
	 *
	 * @param object $oResult Row from the pass table.
	 * @param int    $iUses   Live check-in count (unused; kept for the base signature).
	 * @param int    $iNow    Current site timestamp.
	 * @return string Translated label.
	 */
	protected function export_status($oResult, $iUses, $iNow)
	{
		list(, $sLabel) = $this->derive_pass_status($oResult, $iNow);
		return $sLabel;
	}

	/**
	 * @param object $oResult Database row.
	 * @param array  $aCtx    Batched context.
	 * @return array Row data keyed by column, with the guest rows under 'guest_passes'.
	 */
	protected function build_row($oResult, $aCtx)
	{
		$aGuests = $this->aGuestPassesByParent[$oResult->nano_id] ?? array();
		$aRow    = $this->build_pass_row($oResult, $aCtx, false, count($aGuests));
		foreach($aGuests as $oGuest)
		{
			$aRow['guest_passes'][] = $this->build_pass_row($oGuest, $aCtx, true, 0);
		}
		return $aRow;
	}

	/**
	 * Prints a pass row followed by its guest pass rows.
	 *
	 * Guest rows are real sibling <tr>s in the same table rather than a nested table, so their
	 * columns share this table's single sizing pass. Each guest cell's content sits in a
	 * .tpfw-row-inner div that slides open and closed; the <tr> itself is never display:none
	 * because animating a row's height is unreliable across browsers.
	 *
	 * @param array $item Row data from build_row().
	 * @return void
	 */
	public function single_row($item)
	{
		echo !empty($item['guest_passes']) ? '<tr class="tpfw-has-guestpass">' : '<tr>';
		$this->single_row_columns($item);
		echo '</tr>';

		$iGuestCount = count($item['guest_passes']);
		foreach($item['guest_passes'] as $iIndex => $aGuestItem)
		{
			$sPositionClass  = ($iIndex === 0) ? ' tpfw-guestpass-row--first' : '';
			$sPositionClass .= ($iIndex === $iGuestCount - 1) ? ' tpfw-guestpass-row--last' : '';
			echo '<tr class="tpfw-guestpass-row' . esc_attr($sPositionClass) . '" data-parent-nanoid="' . esc_attr($item['nano_id_raw']) . '" data-nanoid="' . esc_attr($aGuestItem['nano_id_raw']) . '">';
			foreach(array_keys($this->get_columns()) as $sColumnName)
			{
				// Guest rows are not bulk-selectable: cancel/reset cascade from the parent.
				$sInner = $sColumnName === 'cb' ? '' : $this->column_default($aGuestItem, $sColumnName);
				echo '<td class="column-' . esc_attr($sColumnName) . '"><div class="tpfw-row-inner">' . $sInner . '</div></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- column_default() returns wp_kses()'d markup.
			}
			echo '</tr>';
		}
	}
}
