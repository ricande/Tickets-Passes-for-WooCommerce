<?php
defined('ABSPATH') or die('No script kiddies please!');

require_once dirname(__FILE__) . '/../settings/class--settings-tab.php';

/**
 * Email Settings tab, and the plugin's two hooks into WooCommerce's own emails.
 *
 * Customers never get a separate ticket email: the codes ride along on the order emails
 * WooCommerce already sends, so there is one message to configure and one to receive. The
 * page shell lives in TPFW_Settings_Tab.
 *
 * @package Tickets_Passes_For_WooCommerce
 */
class TPFW_Emails extends TPFW_Settings_Tab
{
	protected $sSlug           = 'tpfw-email-settings';
	protected $sTabKey         = 'emails';
	protected $sTemplate       = 'template/page-content.php';
	protected $sScriptFile     = 'js/email-settings.js';
	protected $sStyleFile      = 'css/email-settings.css';
	protected $aScriptDeps     = array('postbox');
	protected $sParamsName     = 'tpfwParamsEmailSettings';
	protected $sSaveAction     = 'ajax_save_tpfw_email_settings';
	protected $sOptionName     = 'tpfw_email_settings_options';
	protected $sSanitizeMethod = 'sanitize_email_settings';

	/**
	 * @param string         $sPrefix    Option/handle prefix for the plugin ('tpfw').
	 * @param TPFW_Functions $oFunctions Shared helper instance.
	 */
	public function __construct($sPrefix, $oFunctions)
	{
		$this->sMenuLabel = __('Email Settings', 'tickets-passes-for-woocommerce');
		parent::__construct($sPrefix, $oFunctions);
	}

	/** @return string */
	protected function get_dir() { return dirname(__FILE__); }

	/**
	 * The two WooCommerce email filters this class exists for.
	 *
	 * @return void
	 */
	protected function register_extra()
	{
		add_action('woocommerce_email_order_details',                array($this, 'add_ticket_and_pass_text'), 20, 4);
		add_action('woocommerce_order_item_get_formatted_meta_data', array($this, 'unset_specific_order_item_meta_data'), 10, 2);
	}

	/**
	 * The message editors sit in collapsible metaboxes, which need core's postbox script.
	 *
	 * @return array
	 */
	protected function get_script_params()
	{
		wp_enqueue_script('postbox');
		$aParams = parent::get_script_params();
		$aParams['translations']['sCopied']     = __('Copied!', 'tickets-passes-for-woocommerce');
		$aParams['translations']['sSaveFailed'] = __('Failed to save settings.', 'tickets-passes-for-woocommerce');
		return $aParams;
	}

	/**
	 * @return void
	 */
	public function ajax_save_callback()
	{
		$this->ajax_save_tpfw_email_settings_callback();
	}

	/**
	 * Hides the raw ticket/pass nano ids from the order line meta shown to customers.
	 *
	 * Those ids are the codes themselves. They are stored as line meta so the order stays the
	 * record of what was issued, but printing them as a "tpfw_ticket_id_1: xxx" row is both noise
	 * and a way to read a code without the QR. The QR images are rendered separately by
	 * add_ticket_and_pass_text().
	 *
	 * Admin screens keep the meta so staff can still look a code up, and so do the customer's own
	 * My Account / order-received pages (is_wc_endpoint_url()), where the order is theirs anyway.
	 * The exception is the admin resending the order details email: that output is the customer's
	 * email and the meta is stripped as usual.
	 *
	 * @param array         $formatted_meta Meta ready for display.
	 * @param WC_Order_Item $item           Line the meta belongs to.
	 * @return array
	 */
	public function unset_specific_order_item_meta_data($formatted_meta, $item)
	{	
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only input used to decide what to display; no state is changed.
		$is_resend = isset($_POST['wc_order_action']) ?  sanitize_text_field(wp_unslash($_POST['wc_order_action'] ?? '')) === 'send_order_details' : false;

		if(!$is_resend && (is_admin() || is_wc_endpoint_url())) 
		{
			return $formatted_meta;
		}

		foreach($formatted_meta as $key => $meta)
		{
			// isset() first: another plugin filtering this list can hand over an entry with no
			// key at all, and str_contains(null, ...) is a deprecation notice on PHP 8.1+.
			if(!isset($meta->key)) continue;

			if(str_contains($meta->key, 'tpfw_timeslot_ticket_id_') || str_contains($meta->key, 'tpfw_ticket_id_') || str_contains($meta->key, 'tpfw_pass_id_'))
			{			
				unset($formatted_meta[$key]);
			}
		}

		return $formatted_meta;
	}
	/**
	 * Appends the QR codes for every ticket, timeslot ticket and pass in the order to the email.
	 *
	 * One row per issued code, not per line item: a quantity of three tickets was paid for as
	 * one line but admits three people, and each needs its own QR. The ids are read back from
	 * the per-unit line meta written when the order completed, so an order that has not issued
	 * its codes yet simply renders nothing.
	 *
	 * The intro text is the admin-configured message with its placeholders substituted. It is
	 * stored through wp_kses_post() when saved, so it is emitted as markup on purpose.
	 *
	 * Timeslot tickets also get an "Add to Calendar" link to the .ics endpoint, when that route is
	 * registered (API or built-in scanner enabled). Plain-text emails and the admin's copy of an
	 * email are left untouched.
	 *
	 * @param WC_Order $order         Order the email is about.
	 * @param bool     $sent_to_admin Whether this copy goes to an admin.
	 * @param bool     $plain_text    Whether the email is plain text.
	 * @param WC_Email $email         Email being sent.
	 * @return void
	 */
	public function add_ticket_and_pass_text($order, $sent_to_admin, $plain_text, $email)
	{
		$oOrder = $order;
		if ($order == null)
		{
			return;
		}

		// WooCommerce fires this hook from its plain-text templates too; a <table> of <img> QR
		// codes is unreadable there, and the codes are still in the customer's HTML email and
		// My Account. The admin copy of a "new order" email is a staff notification, not the
		// customer's confirmation, so it gets neither the greeting nor the codes.
		if ($plain_text || $sent_to_admin)
		{
			return;
		}

		if (empty($oOrder->get_items())) 
		{ 
			return; 
		}
	
		$aQRCodes           = array();
		// Tracked separately from $aQRCodes: the codes are only issued once the order is
		// completed, so on every earlier email there is nothing in $aQRCodes even though the
		// order is full of tickets. Keying the whole block off $aQRCodes meant the order
		// confirmation text never went out at all.
		$bHasAdmissionItem  = false;
		foreach ($oOrder->get_items() as $iOrderItemKey => $oOrderItem)
		{
			$oOrderItemProduct = wc_get_product($oOrderItem->get_product_id());
			if (is_a($oOrderItemProduct, 'TPFW_Product_Ticket') || is_a($oOrderItemProduct, 'TPFW_Product_Timeslot_Ticket') || is_a($oOrderItemProduct, 'TPFW_Product_Pass'))
			{
				$bHasAdmissionItem = true;
			}
			if (is_a($oOrderItemProduct, 'TPFW_Product_Ticket'))
			{
				for ($iLoopValue = 1; $iLoopValue <= $oOrderItem->get_quantity(); $iLoopValue++) 
				{            						
					if ($oOrderItem->get_meta('tpfw_ticket_id_' . $iLoopValue) !== null && $oOrderItem->get_meta('tpfw_ticket_id_' . $iLoopValue) != "") 
					{
						$aQRCodes[] = array(
							'qr_id' 		=> $oOrderItem->get_meta('tpfw_ticket_id_' . $iLoopValue),							
							'product_id' 	=> $oOrderItem->get_product_id(),
							'product_name' 	=> $oOrderItem->get_name(),
							'firstname'     => $oOrderItem->get_meta('tpfw_firstname'),
							'lastname'      => $oOrderItem->get_meta('tpfw_lastname'),
							'type' 			=> 'ticket',
						);							
					}
				}                
			}			
			if (is_a($oOrderItemProduct, 'TPFW_Product_Timeslot_Ticket')) 
			{
				for ($iLoopValue = 1; $iLoopValue <= $oOrderItem->get_quantity(); $iLoopValue++) 
				{            						
					if ($oOrderItem->get_meta('tpfw_timeslot_ticket_id_' . $iLoopValue) !== null && $oOrderItem->get_meta('tpfw_timeslot_ticket_id_' . $iLoopValue) != "") 
					{
						$aQRCodes[] = array(
							'qr_id' 		=> $oOrderItem->get_meta('tpfw_timeslot_ticket_id_' . $iLoopValue),							
							'product_id' 	=> $oOrderItem->get_product_id(),
							'product_name' 	=> $oOrderItem->get_name(),
							'firstname'     => $oOrderItem->get_meta('tpfw_firstname'),
							'lastname'      => $oOrderItem->get_meta('tpfw_lastname'),
							'type' 			=> 'timeslot_ticket',
						);							
					}
				}                
			} 
			else if (is_a($oOrderItemProduct, 'TPFW_Product_Pass')) 
			{
				for ($iLoopValue = 1; $iLoopValue <= $oOrderItem->get_quantity(); $iLoopValue++) 
				{            						
					if ($oOrderItem->get_meta('tpfw_pass_id_' . $iLoopValue) !== null && $oOrderItem->get_meta('tpfw_pass_id_' . $iLoopValue) != "") 
					{
						$aQRCodes[] = array(
							'qr_id' 		=> $oOrderItem->get_meta('tpfw_pass_id_' . $iLoopValue),							
							'product_id' 	=> $oOrderItem->get_product_id(),
							'product_name' 	=> $oOrderItem->get_name(),
							'firstname'     => $oOrderItem->get_meta('tpfw_firstname'),
							'lastname'      => $oOrderItem->get_meta('tpfw_lastname'),
							'type' 			=> 'pass',
						);							
					}
				}                
			}
		} 
	
		if (!$bHasAdmissionItem)
		{
			return;
		}

		// The completed-order email is the first one that can actually carry the QR codes, so
		// it gets its own template: the earlier emails have to promise the codes rather than
		// show them, and one text cannot honestly do both.
		$sTemplateKey = (is_a($email, 'WC_Email') && $email->id === 'customer_completed_order') ? 'wc_completed_email' : 'wc_confirmation_email';

		ob_start();
		?>
		<h2><?php echo esc_html__('Purchased Items', 'tickets-passes-for-woocommerce'); ?></h2>
		<div style="padding-bottom: 10px;">
			<?php
			$sEmailMessage = $this->oFunctions->get_email_setting($sTemplateKey, 'message');

			foreach($this->oFunctions->wc_confirmation_email_replacement($oOrder) as $sReplacementKey => $sReplacementText)
			{
				$sEmailMessage = str_replace($sReplacementKey, $sReplacementText, $sEmailMessage);
			}
			echo wp_kses_post($sEmailMessage);
			?>
		</div>
		<?php
		if (!empty($aQRCodes))
		{
			?>
			<table style="width: 100%; border-collapse: collapse; border: 1px solid #e0e0e0;">
				<thead>
					<tr>
						<th style="padding: 8px; text-align: left; border: 1px solid #e0e0e0;">
							<?php echo esc_html__('Product', 'tickets-passes-for-woocommerce'); ?>
						</th>
						<th style="padding: 8px; text-align: center; border: 1px solid #e0e0e0;">
							<?php echo esc_html__('Your QR Code', 'tickets-passes-for-woocommerce'); ?>
						</th>
					</tr>
				</thead>			
				<tbody>
					<?php
					foreach ($aQRCodes as $iQRKey => $oQR) 
					{
						?>
						<tr>
							<td style="padding: 8px; border: 1px solid #e0e0e0;">
								<strong><?php echo esc_html($oQR['product_name']); ?></strong><br/>
								<?php if (!empty($oQR['firstname']) || !empty($oQR['lastname'])): ?>
									<?php echo esc_html__('Firstname', 'tickets-passes-for-woocommerce') . ': ' . esc_html($oQR['firstname']); ?><br/>
									<?php echo esc_html__('Lastname', 'tickets-passes-for-woocommerce') . ': ' . esc_html($oQR['lastname']); ?><br/>
								<?php endif; ?>
								<?php // The .ics route only exists while TPFW_API is loaded (API or scanner enabled). ?>
								<?php if ($oQR['type'] === 'timeslot_ticket' && ($this->oFunctions->is_api_enabled() || $this->oFunctions->is_scanner_enabled())): ?>
									<a href="<?php echo esc_url(rest_url('tpfw/v1/timeslot-ticket/ics/'.$oQR['qr_id'])); ?>"><?php echo esc_html__('Add to Calendar', 'tickets-passes-for-woocommerce'); ?></a><br/>
								<?php endif; ?>
							</td>
							<td style="padding: 20px; border: 1px solid #e0e0e0; text-align: center;">
								<img src="<?php echo esc_url($this->oFunctions->get_file_url('qr', $oQR['qr_id'], 'webp', true)); ?>" alt="QR Code" style="width: 150px; height: auto;">
							</td>
						</tr>
						<?php
					}
					?>
				</tbody>
			</table>
			<br><br><br>
			<?php
		}
		echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buffered markup assembled above in this method; every dynamic value in it is escaped at the point it is written.
	}

	/**
	 * Saves the Email Settings tab.
	 *
	 * Requires the admin nonce and manage_woocommerce. Subjects are plain text; message bodies
	 * come from wp_editor and are meant to contain markup, so they go through wp_kses_post()
	 * rather than being stored raw. Both were previously written straight off $_POST, where a
	 * missing key raised an undefined-index warning that ended up in the JSON response.
	 *
	 * @return void
	 */
	public function ajax_save_tpfw_email_settings_callback()
	{
		$response = array();
		check_ajax_referer('tpfw_ajax_save_tpfw_email_settings', 'security');
		if(!$this->oFunctions->user_can_manage())
		{
			$response['sMessage'] = __('Current user does not have admin privileges', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}

		// Messages come from wp_editor and are meant to contain markup, so they go through
		// wp_kses_post rather than being stored raw; subjects are plain text. Both were
		// previously written to the option straight off $_POST, and a missing key raised an
		// undefined-index warning that ended up in the JSON response.
		$aSubjectKeys = array(
			'resend_ticket_email_subject',
			'resend_pass_email_subject',
			'gifted_pass_email_new_user_subject',
			'gifted_pass_email_existing_user_subject',
		);
		$aMessageKeys = array(
			'wc_confirmation_email_message',
			'wc_completed_email_message',
			'resend_ticket_email_message',
			'resend_pass_email_message',
			'gifted_pass_email_new_user_message',
			'gifted_pass_email_existing_user_message',
		);

		$aTPFWEmailSettings = array();
		foreach($aSubjectKeys as $sSubjectKey)
		{
			$aTPFWEmailSettings[$sSubjectKey] = isset($_POST[$sSubjectKey]) ? sanitize_text_field(wp_unslash($_POST[$sSubjectKey] ?? '')) : '';
		}
		foreach($aMessageKeys as $sMessageKey)
		{
			$aTPFWEmailSettings[$sMessageKey] = isset($_POST[$sMessageKey]) ? wp_kses_post(wp_unslash($_POST[$sMessageKey] ?? '')) : '';
		}

		update_option('tpfw_email_settings_options', $aTPFWEmailSettings);

		$response['sMessage']              = __('Email Settings have successfully been updated', 'tickets-passes-for-woocommerce');
		wp_send_json_success($response);
	}
}