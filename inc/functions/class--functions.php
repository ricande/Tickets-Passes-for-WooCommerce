<?php
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelLow;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Label\Label;
use Endroid\QrCode\Label\Font\OpenSans;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin;
use Endroid\QrCode\Writer\WebPWriter;
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Shared helpers used by every other part of the plugin.
 *
 * One instance is built in the bootstrap and handed to each feature class. Integrity-critical
 * pieces live in inc/support/ (named lock, check-in payload, issue policy, order-line upsert,
 * guest-pass quota, timeslot capacity, file tokens). This class remains the facade callers
 * already use: QR and PDF generation, check-in, upload paths, email placeholders, permissions.
 *
 * The check-in methods are the security-critical ones - see with_checkin_lock() for why they
 * all run under a MySQL named lock.
 */
class TPFW_Functions
{
	protected $sPrefix;

	// Inline JS built while the product template renders, attached in the footer - see
	// stash_front_inline_script() for why it cannot be attached at render time.
	protected $sFrontInlineJS = '';

	// Whether the order line note's rule has been queued yet - one order can print many notes.
	protected $bOrderNoteStyled = false;

	// Bumped only if the scanner role's capabilities change, to re-run add_role() once.
	// 2: the role slug gained the plugin prefix ('scanner' -> 'tpfw_scanner'). Bumping this is
	// what gets the renamed role created on sites that already had version 1 registered.
	const SCANNER_ROLE_VERSION = '2';

	/**
	 * Stores the plugin identity strings and registers the shared hooks.
	 *
	 * @param string $sPrefix        Option and handle prefix used across the plugin.
	 */
	public function __construct($sPrefix)
	{
		$this->sPrefix 			= $sPrefix;
		$this->load_general_dependencies();
	}

	/**
	 * Registers the hooks this shared class owns and ensures the scanner role exists.
	 *
	 * @return void
	 */
	private function load_general_dependencies()
	{
		//CLASS ENQUEUE JS
		add_action('admin_enqueue_scripts', array($this, 'enqueue_script_admin'));
		// After WooCommerce has registered wc-admin-product-meta-boxes, so the inline
		// tag below can run before that script's show/hide pass.
		add_action('admin_enqueue_scripts', array($this, 'tag_inventory_fields_for_custom_types'), 20);

		add_action('woocommerce_order_item_meta_end', array($this, 'render_order_item_product_note'), 10, 3);

		add_action('wp_enqueue_scripts', array($this, 'enqueue_front_inline_styles'));
		add_action('wp_enqueue_scripts', array($this, 'enqueue_front_product_assets'));

		$this->maybe_register_scanner_role();
	}


	/**
	 * Stacks the Mini Cart block's line item data one row per line.
	 *
	 * The block prints every row of a line's item data as one inline run separated by a
	 * class-less " / " span, so a ticket's "Valid From" and "Valid To" ran together on a single
	 * line inside a drawer only a few hundred pixels wide. Laying the rows out as a column gives
	 * each its own line, and the separator has nothing left to separate once they stack.
	 *
	 * Scoped to the Mini Cart on purpose: the Cart and Checkout blocks already render the same
	 * data as a real list, one row per line.
	 *
	 * Attached to the Mini Cart block's own stylesheet, so it only prints on a page that
	 * renders the block instead of on every front end page.
	 *
	 * @return void
	 */
	public function enqueue_front_inline_styles()
	{
		wp_add_inline_style(
			'wc-blocks-style-mini-cart',
			'.wc-block-mini-cart-items .wc-block-components-product-details{display:flex;flex-direction:column;}'
			.'.wc-block-mini-cart-items .wc-block-components-product-details > span > span:not([class]){display:none;}'
		);
	}

	/**
	 * Adds a few rules to the shared front end handle, registering it on first use.
	 *
	 * For rules too small to be worth a file and a request of their own (a menu icon, a note
	 * box). The handle is only enqueued once something is added, so a page that needs nothing
	 * prints nothing.
	 *
	 * Rules added while the page body renders need a handle of their own: the shared one may
	 * already have been printed in the head, and nothing can be attached to a printed handle.
	 * A handle first enqueued mid-page is printed with the late styles in the footer.
	 *
	 * @param string $sCSS    Rules to print.
	 * @param string $sHandle Handle suffix; 'front' for anything queued on 'wp_enqueue_scripts'.
	 * @return void
	 */
	public function add_front_inline_style($sCSS, $sHandle = 'front')
	{
		if(!wp_style_is($this->sPrefix.$sHandle, 'registered'))
		{
			wp_register_style($this->sPrefix.$sHandle, false, array(), TPFW_VERSION);
		}
		wp_enqueue_style($this->sPrefix.$sHandle);
		wp_add_inline_style($this->sPrefix.$sHandle, $sCSS);
	}


	/**
	 * Prints the product's customer note under its line on a viewable order.
	 *
	 * woocommerce_order_item_meta_end also fires while building the order emails, which already
	 * carry the note through the {{product_note}} placeholder, so this is limited to the two
	 * front-end order screens to avoid printing it twice.
	 *
	 * @param int           $iItemID    Order item id.
	 * @param WC_Order_Item $oOrderLine The order line being rendered.
	 * @param WC_Order      $oOrder     Order the line belongs to.
	 * @return void
	 */
	public function render_order_item_product_note($iItemID, $oOrderLine, $oOrder)
	{
		if(!is_wc_endpoint_url('view-order') && !is_wc_endpoint_url('order-received'))
		{
			return;
		}

		if(!$oOrderLine instanceof WC_Order_Item_Product)
		{
			return;
		}

		$sNote = $this->get_product_note($oOrderLine->get_product_id());
		if($sNote == '')
		{
			return;
		}

		// Styled from here rather than from 'wp_enqueue_scripts', so an order without a note
		// prints nothing. Enqueued mid-page, the rule lands in the footer with the late styles.
		if(!$this->bOrderNoteStyled)
		{
			$this->bOrderNoteStyled = true;
			$this->add_front_inline_style('.tpfw-order-item-note{margin-top:8px;padding:8px 10px;border-left:3px solid rgba(0,0,0,.15);background:rgba(0,0,0,.04);font-size:13px;line-height:1.45}', 'order-note');
		}

		echo '<div class="tpfw-order-item-note">' . nl2br(esc_html($sNote)) . '</div>';
	}
	/**
	 * Creates the 'scanner' role the check-in permission test has always been written against.
	 *
	 * user_can_scan() has always let the 'scanner' role through, but nothing ever created it, so
	 * in practice only administrators could check anyone in - which meant handing door staff an
	 * admin login for a phone that lives in a pocket all night.
	 *
	 * Done here rather than in the activation hook because the plugin is already active on
	 * existing sites, where activation will never run again. The option guard makes it a one-time
	 * write instead of a DB touch on every request, and means an administrator who deliberately
	 * deletes the role does not get it silently recreated under them.
	 *
	 * @return void
	 */
	private function maybe_register_scanner_role()
	{
		if(get_option('tpfw_scanner_role_version') === self::SCANNER_ROLE_VERSION)
		{
			return;
		}

		// 'read' only: enough to be a real user who can log in, nothing else. They never see
		// wp-admin anyway - the scanner page at /check-in/ is standalone and its only link
		// is Log out - but if they did land there it would be an empty shell.
		add_role('tpfw_scanner', __('Scanner', 'tickets-passes-for-woocommerce'), array('read' => true));

		update_option('tpfw_scanner_role_version', self::SCANNER_ROLE_VERSION);
	}


	/**
	 * Allowed HTML for the admin dashboard list-table cells.
	 *
	 * The dashboards assemble their cells as markup (status pills, action buttons carrying
	 * data-attr-* hooks for the AJAX scripts, profile thumbnails), which wp_kses_post() would
	 * gut - it allows neither <button> nor the data attributes. This is the "escape late"
	 * counterpart: every cell is run through wp_kses() with this list at the point it is
	 * echoed, so however a cell was built, only this markup can ever reach the page.
	 *
	 * @return array<string,array<string,bool>> wp_kses() allowed-HTML array.
	 */
	public function get_dashboard_allowed_html()
	{
		$aCommon = array(
			'class'  => true,
			'style'  => true,
			'title'  => true,
			'id'     => true,
			'data-*' => true,
		);

		return array(
			'div'    => $aCommon,
			'span'   => $aCommon,
			'p'      => $aCommon,
			'br'     => array(),
			'strong' => $aCommon,
			'em'     => $aCommon,
			'small'  => $aCommon,
			'code'   => $aCommon,
			'a'      => $aCommon + array('href' => true, 'target' => true, 'rel' => true),
			'img'    => $aCommon + array('src' => true, 'alt' => true, 'width' => true, 'height' => true, 'loading' => true),
			'button' => $aCommon + array('type' => true, 'disabled' => true),
			'input'  => $aCommon + array('type' => true, 'value' => true, 'name' => true, 'checked' => true, 'disabled' => true, 'readonly' => true),
			'label'  => $aCommon + array('for' => true),
			'ul'     => $aCommon,
			'ol'     => $aCommon,
			'li'     => $aCommon,
			'table'  => $aCommon,
			'thead'  => $aCommon,
			'tbody'  => $aCommon,
			'tr'     => $aCommon,
			'th'     => $aCommon + array('colspan' => true, 'scope' => true),
			'td'     => $aCommon + array('colspan' => true),
		);
	}


	/**
	 * Builds a dompdf renderer with one of the PDF stylesheets in inc/functions/css/ loaded.
	 *
	 * The stylesheet is handed to dompdf through its own Stylesheet API instead of a <style>
	 * block in the markup, so no CSS is ever inlined into output. Two ordering details matter:
	 * dompdf only reads local files below its own root, so the css folder has to be added to
	 * chroot, and it snapshots its protocol rules when the instance is built, so the Options
	 * object must be complete beforehand - a later set_option() call would leave chroot unseen
	 * and the stylesheet would silently be refused.
	 *
	 * @param string $sFilename File name inside inc/functions/css/, e.g. 'ticket-pdf.css'.
	 * @return \Dompdf\Dompdf Renderer ready for load_html().
	 */
	public function create_pdf_renderer($sFilename)
	{
		require_once __DIR__.'/lib/dompdf/autoload.php';

		// Remote fetching stays off. Every image in both PDF templates is a data: URI, which
		// dompdf allows unconditionally, so the only thing enabling http(s) here bought was a
		// server-side request the moment any future template carried an external URL.
		$oOptions = new \Dompdf\Options();
		$oOptions->setChroot(array(__DIR__.'/css'));

		$oDomPDF = new \Dompdf\Dompdf($oOptions);
		$oDomPDF->setPaper('A5', 'landscape');

		// basename() so a caller can never walk out of the css folder, and the readable check so
		// a missing file yields an unstyled PDF instead of a dompdf warning in the download.
		$sPath = __DIR__.'/css/'.basename($sFilename);
		if(is_readable($sPath))
		{
			$oDomPDF->getCss()->load_css_file($sPath);
		}

		return $oDomPDF;
	}


	/**
	 * Returns the admin-written customer note for a product, whatever tpfw product type it is.
	 *
	 * A product is only ever one of the three types, so the first non-empty meta wins.
	 *
	 * @param int $iProductID Product id.
	 * @return string The note, or an empty string when none is set.
	 */
	public function get_product_note($iProductID)
	{
		if(empty($iProductID))
		{
			return '';
		}

		foreach(array('_tpfw_ticket_note', '_tpfw_timeslot_ticket_note', '_tpfw_pass_note') as $sMetaKey)
		{
			$sNote = get_post_meta($iProductID, $sMetaKey, true);
			if(!empty($sNote))
			{
				return $sNote;
			}
		}

		return '';
	}


	/**
	 * Renders (and caches) the printable PDF for a ticket or timeslot ticket.
	 *
	 * Looks the nano id up in both the tickets and the timeslot_tickets tables, builds the ticket
	 * layout from the product's own colour/logo settings and writes it through dompdf into the
	 * PDF uploads folder, returning the public download URL.
	 *
	 * @param string $sNanoID Ticket nano id.
	 * @return array bSuccess, sMessage and - on success - sDownloadURL and sFilename.
	 */
	public function create_fetch_ticket_pdf_qr($sNanoID)
	{
				$sQRUploadDir = $this->get_qr_upload_dir();		
		
		if(empty($sNanoID) || $sNanoID == '')
		{
			return array(
				'bSuccess'  => false,
				'sMessage' => __('Missing Nano ID', 'tickets-passes-for-woocommerce'),
			);
		}

				$sPDFUploadDir = $this->get_pdf_upload_dir();
		
		if(!file_exists($sQRUploadDir.'/'.$sNanoID.'.webp')) 
		{
			return array(
				'bSuccess'      => false,
				'sMessage'     => __('QR code image does not seem to exist', 'tickets-passes-for-woocommerce'),				
			);
		}


		global $wpdb;
		$sTicketsTableName   = $wpdb->prefix . "tpfw_tickets";
		$sTicketsExistSQL    = $wpdb->prepare('	SELECT * FROM %i WHERE nano_id = %s LIMIT 1', $sTicketsTableName, $sNanoID);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sTicketsExistSQL is the return value of $wpdb->prepare() above.
		$aTicketsExistResult = $wpdb->get_results($sTicketsExistSQL);

		$sTimeslotTableName   = $wpdb->prefix . "tpfw_timeslot_tickets";
		$sTimeslotExistSQL    = $wpdb->prepare('	SELECT * FROM %i WHERE nano_id = %s LIMIT 1', $sTimeslotTableName, $sNanoID);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sTimeslotExistSQL is the return value of $wpdb->prepare() above.
		$aTimeslotExistResult = $wpdb->get_results($sTimeslotExistSQL);
		
		if(!empty($aTicketsExistResult))
		{
			$oTicketOrTimeslotResult = $aTicketsExistResult[0];
		} 
		else if(!empty($aTimeslotExistResult))
		{			
			$oTicketOrTimeslotResult = $aTimeslotExistResult[0];
		}
		else
		{
			return array(
				'bSuccess'  => false,
				'sMessage' => __('Ticket / Timeslot with given Nano ID does not seem to exist', 'tickets-passes-for-woocommerce'),
			);
		}
		

		$oProduct        = wc_get_product($oTicketOrTimeslotResult->product_id);
		$sDateTimeFormat = $this->get_datetime_format('datetime');

		// wc_get_product() returns false for a product that has since been deleted or trashed,
		// and the template below calls ->get_name() on it unconditionally - a deleted product
		// turned every PDF download for its old tickets into a fatal instead of a message.
		if(!$oProduct)
		{
			return array(
				'bSuccess'  => false,
				'sMessage' => __('The product this ticket belongs to no longer exists', 'tickets-passes-for-woocommerce'),
			);
		}

		// file_get_contents() emits a warning and returns false on an unreadable file, which
		// base64_encode() then happily turned into an empty src - a PDF with a blank QR box and
		// no indication anything went wrong. Read it once, up front, and fail loudly instead.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local plugin-generated image for base64 embedding, not a remote request.
		$sQRImageData = file_get_contents($sQRUploadDir . '/' . $sNanoID . '.webp');
		if($sQRImageData === false)
		{
			return array(
				'bSuccess'  => false,
				'sMessage' => __('QR code image could not be read', 'tickets-passes-for-woocommerce'),
			);
		}
		$sQRImageBase64 = base64_encode($sQRImageData); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- embedding a plugin-generated image as a data URI in a PDF, not obfuscating code.

		$sSiteName = get_bloginfo('name');

		$oDomPDF = $this->create_pdf_renderer('ticket-pdf.css');
		ob_start();
		?>
			<html>
				<body>
				<div class="ticket-wrapper">
				<table class="ticket-table" data-attr-nanoid="<?php echo esc_attr($sNanoID); ?>">
					<tr>
						<td class="ticket-band" colspan="2">
							<table class="band-table">
								<tr>
									<td class="site-name"><?php echo esc_html($sSiteName); ?></td>
									<td class="band-label"><?php echo esc_html__('Ticket', 'tickets-passes-for-woocommerce'); ?></td>
								</tr>
							</table>
						</td>
					</tr>
					<tr>
						<td class="ticket-body">
							<div class="product-name"><?php echo esc_html($oProduct->get_name()); ?></div>

							<table class="valid-dates">
								<tr>
									<td class="valid-from">
										<div class="date-label"><?php echo esc_html__('Valid from', 'tickets-passes-for-woocommerce'); ?></div>
										<div class="date-value"><?php echo esc_html(gmdate($sDateTimeFormat, strtotime($oTicketOrTimeslotResult->valid_from))); ?></div>
									</td>
									<td class="valid-to">
										<div class="date-label"><?php echo esc_html__('Valid to', 'tickets-passes-for-woocommerce'); ?></div>
										<div class="date-value"><?php echo esc_html(gmdate($sDateTimeFormat, strtotime($oTicketOrTimeslotResult->valid_to))); ?></div>
									</td>
								</tr>
							</table>

							<?php $sProductNote = $this->get_product_note($oTicketOrTimeslotResult->product_id); ?>
							<?php if($sProductNote != '') { ?>
								<div class="product-note"><?php echo esc_html($sProductNote); ?></div>
							<?php } ?>
						</td>

						<td class="ticket-stub">
							<img class="qr-image" src="data:image/webp;base64, <?php echo esc_attr($sQRImageBase64); ?>"
								alt="<?php echo esc_attr($sNanoID); ?>"
								width="140" height="140">
							<div class="nano-id"><?php echo esc_html($sNanoID); ?></div>
						</td>
					</tr>
				</table>
				</div>

				</body>
			</html>
		<?php
		// dompdf throws on a malformed template, a missing font and an unreadable remote image,
		// and file_put_contents fatals on an unwritable uploads dir. Every caller already
		// handles bSuccess => false, so failing that way beats a white screen mid-download.
		try
		{
			$oDomPDF->load_html(ob_get_clean());
			$oDomPDF->render();

			if(!file_exists($sPDFUploadDir))
			{
				wp_mkdir_p($sPDFUploadDir);
			}
			file_put_contents($sPDFUploadDir.'/'.$sNanoID.'.pdf', $oDomPDF->output()); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a plugin-generated PDF into wp_upload_dir(); WP_Filesystem is for credentialed user-initiated writes.
		}
		catch(\Throwable $oThrowable)
		{
			$this->log_error('create_fetch_ticket_pdf_qr failed for ' . $sNanoID, $oThrowable);
			return array(
				'bSuccess'  => false,
				'sMessage' => __('The ticket PDF could not be generated', 'tickets-passes-for-woocommerce'),
			);
		}

		return array(
			'bSuccess'      => true,
			'sMessage'     => __('PDF file successfully created and fetched', 'tickets-passes-for-woocommerce'),
			'sDownloadURL' => $this->get_file_url('pdf', $sNanoID, 'pdf'),
			'sFilename'    => $sNanoID.'.pdf',
		);
	}
	/**
	 * Builds an RFC 5545 VCALENDAR string for a timeslot ticket, for the "Add to calendar" link.
	 *
	 * valid_from/valid_to are site-local wall-clock datetimes (they derive from the admin-typed
	 * timeslot start and are compared against current_time() everywhere), so they are parsed in
	 * the site timezone and converted to UTC before the ICS "Z" suffix is appended.
	 *
	 * @param string $sNanoID Timeslot ticket nano id.
	 * @return string|false The .ics body, or false when the ticket does not exist.
	 */
	public function generate_timeslot_ticket_ics($sNanoID)
	{
		global $wpdb;
		$sTableName = $wpdb->prefix . 'tpfw_timeslot_tickets';
		$sSQL       = $wpdb->prepare('SELECT * FROM %i WHERE nano_id = %s AND deleted IS NULL LIMIT 1', $sTableName, $sNanoID);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sSQL is the return value of $wpdb->prepare() above.
		$aResult = $wpdb->get_results($sSQL);
		if(empty($aResult))
		{
			return false;
		}

		$oTicket  = $aResult[0];
		$oProduct = wc_get_product($oTicket->product_id);
		$sSummary = $oProduct ? $oProduct->get_name() : __('Timeslot Ticket', 'tickets-passes-for-woocommerce');

		$aLines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//'.$this->ics_escape_text(get_bloginfo('name')).'//Tickets & Passes for WooCommerce//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:'.$sNanoID.'@'.wp_parse_url(home_url(), PHP_URL_HOST),
			'DTSTAMP:'.gmdate('Ymd\THis\Z'),
			'DTSTART:'.$this->local_to_ics_utc($oTicket->valid_from),
			'DTEND:'.$this->local_to_ics_utc($oTicket->valid_to),
			'SUMMARY:'.$this->ics_escape_text($sSummary),
			'LOCATION:'.$this->ics_escape_text(get_bloginfo('name')),
			'END:VEVENT',
			'END:VCALENDAR',
		);

		return implode("\r\n", $aLines)."\r\n";
	}



	/**
	 * Edit-screen URL for an order under either order storage.
	 *
	 * post.php?post=ID only works with the legacy post table; under HPOS the screen is
	 * admin.php?page=wc-orders. WooCommerce knows which one is active, so ask it.
	 *
	 * @param int $iOrderID Order id.
	 * @return string
	 */
	public function get_order_edit_url($iOrderID)
	{
		$oOrder = wc_get_order((int) $iOrderID);
		return $oOrder ? $oOrder->get_edit_order_url() : admin_url('post.php?post=' . (int) $iOrderID . '&action=edit');
	}

	/**
	 * Whether "now" falls inside a product's sales window.
	 *
	 * Shared by the three product types' add-to-cart button and add-to-cart validation, so the
	 * button and the server agree. The end date is inclusive of its whole day: "purchasable until
	 * 2026-09-30" means the customer can still buy at 23:59 on the 30th. Blank or unparsable ends
	 * are treated as open.
	 *
	 * @param string $sStart 'Y-m-d' the window opens on, or ''.
	 * @param string $sEnd   'Y-m-d' the window closes on (inclusive), or ''.
	 * @return bool
	 */
	public function is_within_sales_window($sStart, $sEnd)
	{
		$iNow = current_time('timestamp');
		if($sStart !== '' && $sStart !== null && strtotime($sStart) !== false && strtotime($sStart) > $iNow)
		{
			return false;
		}
		if($sEnd !== '' && $sEnd !== null && strtotime($sEnd) !== false && strtotime($sEnd . ' 23:59:59') < $iNow)
		{
			return false;
		}
		return true;
	}

	/**
	 * Whether a string is a real calendar date in the exact 'Y-m-d' form the plugin stores.
	 *
	 * strtotime() alone accepts "tomorrow", "2026-09-02T10:00" and much else, which then fails the
	 * lexical range comparisons the date pickers rely on.
	 *
	 * @param string $sDate Candidate.
	 * @return bool
	 */
	public function is_valid_ymd($sDate)
	{
		if(!is_string($sDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sDate))
		{
			return false;
		}
		$oDate = DateTime::createFromFormat('Y-m-d', $sDate);
		return $oDate !== false && $oDate->format('Y-m-d') === $sDate;
	}

	/**
	 * Converts a site-local 'Y-m-d H:i:s' datetime to the ICS UTC form 'Ymd\THis\Z'.
	 *
	 * @param string $sLocalDatetime Site-local datetime as stored in the plugin's tables.
	 * @return string
	 */
	public function local_to_ics_utc($sLocalDatetime)
	{
		try
		{
			$oDate = new DateTime($sLocalDatetime, wp_timezone());
		}
		catch(Exception $e)
		{
			$oDate = new DateTime('now', wp_timezone());
		}
		return $oDate->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
	}

	/**
	 * Escapes a value for an ICS text field (backslash, comma, semicolon, newline).
	 *
	 * @param string $sText Raw value.
	 * @return string Escaped value.
	 */
	private function ics_escape_text($sText)
	{
		return str_replace(array("\\", ',', ';', "\n"), array('\\\\', '\,', '\;', '\n'), $sText);
	}



	/**
	 * Renders (and caches) the QR image or the printable PDF for a pass.
	 *
	 * Handles both owner passes and guest passes - guest QR codes live in their own uploads
	 * folder - and includes the holder's profile photo when one has been uploaded.
	 *
	 * @param string $sNanoID Pass nano id.
	 * @param string $sType   Which QR image to embed: 'qr' reads the pass folder, anything else the guest-pass folder. A PDF is always produced.
	 * @return array bSuccess, sMessage and - on success - the download URL and filename.
	 */
	public function create_fetch_pass_pdf_qr($sNanoID, $sType = 'qr')
	{
		if($sType == 'qr')
		{
						$sQRUploadDir = $this->get_qr_upload_dir();
		}
		else //Must be guestpass then
		{
						$sQRUploadDir = $this->get_guest_upload_dir();
		}
		
		if(empty($sNanoID) || $sNanoID == '')
		{
			return array(
				'bSuccess'  => false,
				'sMessage' => __('Missing Nano ID', 'tickets-passes-for-woocommerce'),
			);
		}
		
				$sPDFUploadDir = $this->get_pdf_upload_dir();

		
		if(!file_exists($sQRUploadDir.'/'.$sNanoID.'.webp')) 
		{
			return array(
				'bSuccess'      => false,
				'sMessage'     => __('QR code image does not seem to exist', 'tickets-passes-for-woocommerce'),				
			);
		}

		global $wpdb;
		$sPassTableName  = $wpdb->prefix . "tpfw_pass";
		$sPassExistSQL    = $wpdb->prepare('	SELECT * FROM %i												
														WHERE nano_id = %s LIMIT 1', $sPassTableName, $sNanoID);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sPassExistSQL is the return value of $wpdb->prepare() above.
		$aPassExistResult = $wpdb->get_results($sPassExistSQL);
		if(empty($aPassExistResult))
		{
			return array(
				'bSuccess'  => false,
				'sMessage' => __('Pass with given Nano ID does not seem to exist', 'tickets-passes-for-woocommerce'),
			);
		}
		$oPassExistResult = $aPassExistResult[0];

		// Deliberately NOT serving a cached PDF when one already exists (as this used to), so a
		// redesign - or a profile photo uploaded after the first download - is reflected on the
		// next download instead of being masked by a stale file. Matches the ticket PDF.

		$sTPFWProfileImagePath = $this->get_profile_image_upload_dir();
		$oProduct             = wc_get_product($oPassExistResult->product_id);
		$sDateTimeFormat      = $this->get_datetime_format('date');

		// See create_fetch_ticket_pdf_qr() - the template calls ->get_name() unconditionally, so
		// a deleted product fataled the download rather than reporting it.
		if(!$oProduct)
		{
			return array(
				'bSuccess'  => false,
				'sMessage' => __('The product this pass belongs to no longer exists', 'tickets-passes-for-woocommerce'),
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local plugin-generated image for base64 embedding, not a remote request.
		$sQRImageData = file_get_contents($sQRUploadDir . '/' . $sNanoID . '.webp');
		if($sQRImageData === false)
		{
			return array(
				'bSuccess'  => false,
				'sMessage' => __('QR code image could not be read', 'tickets-passes-for-woocommerce'),
			);
		}
		$sQRImageBase64 = base64_encode($sQRImageData); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- embedding a plugin-generated image as a data URI in a PDF, not obfuscating code.

		$sSiteName   = get_bloginfo('name');
		$sBandLabel  = ($sType == 'qr') ? __('Pass', 'tickets-passes-for-woocommerce') : __('Guest Pass', 'tickets-passes-for-woocommerce');
		$sHolderName = trim($oPassExistResult->firstname . ' ' . $oPassExistResult->lastname);

		// Guest passes (and any pass predating the profile photo feature) have no
		// uploaded photo - fall back to the same placeholder avatar the My Account page
		// uses, instead of the old code's unconditional file_get_contents() on a path
		// built from a null profile_image_type.
		$sProfilePhotoPath = (!empty($oPassExistResult->profile_image_type)) ? $sTPFWProfileImagePath . $sNanoID . '.' . $oPassExistResult->profile_image_type : '';
		$sProfilePhotoMime = (!empty($oPassExistResult->profile_image_type)) ? $oPassExistResult->profile_image_type : 'webp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local uploaded/bundled image for base64 embedding, not a remote request.
		$sProfilePhotoData = ($sProfilePhotoPath !== '' && file_exists($sProfilePhotoPath)) ? file_get_contents($sProfilePhotoPath) : false;
		if($sProfilePhotoData === false)
		{
			$sProfilePhotoMime = 'webp';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled plugin image for base64 embedding, not a remote request.
			$sProfilePhotoData = file_get_contents(TPFW_PLUGIN_DIR . 'images/avatar-placeholder.webp');
		}

		$sProfilePhotoDataURI = 'data:image/' . $sProfilePhotoMime . ';base64,' . base64_encode((string)$sProfilePhotoData); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- embedding the cardholder photo as a data URI in the pass PDF, not obfuscating code.

		$oDomPDF = $this->create_pdf_renderer('pass-pdf.css');
		ob_start();
		?>
			<html>
				<body>
				<div class="ticket-wrapper">
				<table class="ticket-table" data-attr-nanoid="<?php echo esc_attr($sNanoID); ?>">
					<tr>
						<td class="ticket-band" colspan="2">
							<table class="band-table">
								<tr>
									<td class="site-name"><?php echo esc_html($sSiteName); ?></td>
									<td class="band-label"><?php echo esc_html($sBandLabel); ?></td>
								</tr>
							</table>
						</td>
					</tr>
					<tr>
						<td class="ticket-body">
							<table class="identity-row">
								<tr>
									<td class="profile-photo-cell">
										<img class="profile-photo" src="<?php echo esc_url($sProfilePhotoDataURI, array('data')); ?>" alt="">
									</td>
									<td class="identity-text-cell">
										<div class="product-name"><?php echo esc_html($oProduct->get_name()); ?></div>
										<div class="holder-name"><?php echo esc_html($sHolderName); ?></div>
									</td>
								</tr>
							</table>

							<table class="valid-dates">
								<tr>
									<td class="valid-from">
										<div class="date-label"><?php echo esc_html__('Valid from', 'tickets-passes-for-woocommerce'); ?></div>
										<div class="date-value"><?php echo esc_html(gmdate($sDateTimeFormat, strtotime($oPassExistResult->valid_from))); ?></div>
									</td>
									<td class="valid-to">
										<div class="date-label"><?php echo esc_html__('Valid to', 'tickets-passes-for-woocommerce'); ?></div>
										<div class="date-value"><?php echo esc_html(gmdate($sDateTimeFormat, strtotime($oPassExistResult->valid_to))); ?></div>
									</td>
								</tr>
							</table>

							<?php $sProductNote = $this->get_product_note($oPassExistResult->product_id); ?>
							<?php if($sProductNote != '') { ?>
								<div class="product-note"><?php echo esc_html($sProductNote); ?></div>
							<?php } ?>
						</td>

						<td class="ticket-stub">
							<img class="qr-image" src="data:image/webp;base64, <?php echo esc_attr($sQRImageBase64); ?>"
								alt="<?php echo esc_attr($sNanoID); ?>"
								width="140" height="140">
							<div class="nano-id"><?php echo esc_html($sNanoID); ?></div>
						</td>
					</tr>
				</table>
				</div>

				</body>
			</html>
		<?php
		// See create_fetch_ticket_pdf_qr() - same failure modes, same degrade-not-fatal handling.
		try
		{
			$oDomPDF->load_html(ob_get_clean());
			$oDomPDF->render();

			if(!file_exists($sPDFUploadDir))
			{
				wp_mkdir_p($sPDFUploadDir);
			}
			file_put_contents($sPDFUploadDir.'/'.$sNanoID.'.pdf', $oDomPDF->output()); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a plugin-generated PDF into wp_upload_dir(); WP_Filesystem is for credentialed user-initiated writes.
		}
		catch(\Throwable $oThrowable)
		{
			$this->log_error('create_fetch_pass_pdf_qr failed for ' . $sNanoID, $oThrowable);
			return array(
				'bSuccess'  => false,
				'sMessage' => __('The pass PDF could not be generated', 'tickets-passes-for-woocommerce'),
			);
		}

		return array(
			'bSuccess'      => true,
			'sMessage'     => __('PDF file successfully created and fetched', 'tickets-passes-for-woocommerce'),
			'sDownloadURL' => $this->get_file_url('pdf', $sNanoID, 'pdf'),
			'sFilename'    => $sNanoID.'.pdf',
		);
	}



	/**
	 * Lets WooCommerce's product-type show/hide treat Ticket and Pass like Simple for stock.
	 *
	 * Core only puts show_if_simple / show_if_variable on the "Stock management" checkbox and
	 * the quantity fields. Without these extra classes the checkbox vanishes as soon as the
	 * type dropdown leaves Simple - including on a brand-new Ticket that has never been saved.
	 * Injected before wc-admin-product-meta-boxes so the first show_and_hide_panels() pass
	 * already sees the tags.
	 *
	 * @return void
	 */
	public function tag_inventory_fields_for_custom_types()
	{
		$oScreen = function_exists('get_current_screen') ? get_current_screen() : null;
		if($oScreen === null || $oScreen->base !== 'post' || $oScreen->post_type !== 'product')
		{
			return;
		}
		if(!wp_script_is('wc-admin-product-meta-boxes', 'registered'))
		{
			return;
		}

		wp_add_inline_script(
			'wc-admin-product-meta-boxes',
			'jQuery(function($){$("#inventory_product_data ._manage_stock_field,#inventory_product_data .stock_fields").addClass("show_if_tpfw-ticket show_if_tpfw-pass");});',
			'before'
		);
	}

	/**
	 * Loads the shared admin script on this plugin's own settings pages and on the product
	 * edit screen.
	 *
	 * The product screen needs it for the colour reset buttons on the QR tabs, which reuse the
	 * same delegated handler as the settings screens.
	 *
	 * @return void
	 */
	public function enqueue_script_admin()
    {
		global $pagenow;

		$oScreen            = function_exists('get_current_screen') ? get_current_screen() : null;
		$bProductEditScreen = ($oScreen !== null && $oScreen->base === 'post' && $oScreen->post_type === 'product');

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of the current admin screen to decide whether to enqueue assets; no state is changed.
		if($bProductEditScreen || (isset($pagenow) && $pagenow == 'admin.php' && isset($_GET['page']) && in_array(sanitize_text_field(wp_unslash($_GET['page'] ?? '')), array('tpfw-settings', 'tpfw-ticket-settings', 'tpfw-timeslot-ticket-settings', 'tpfw-pass-settings', 'tpfw-api-settings', 'tpfw-email-settings'), true)))
    	{
	        wp_register_script($this->sPrefix.'functions-admin', plugins_url('', __FILE__).'/js/functions-admin.js', array('jquery'), filemtime(dirname(__FILE__).'/js/functions-admin.js'), true);
	        wp_enqueue_script($this->sPrefix.'functions-admin');
    	}
    }

	/**
	 * Returns the tab bar shared by every settings screen.
	 *
	 * NOTE: this opens the `.wrap` div. Each *-page-content.php template included after this call
	 * must close it.
	 *
	 * @param string $sTab Active tab key: general, ticket, timeslot, pass, api or emails.
	 * @return void Prints the heading and tab row.
	 */
	public function print_settings_navigation($sTab)
	{
		// Tab key => admin page slug and label. Six near-identical button blocks used to be
		// written out by hand, which is how the active-state ternary drifted between them.
		$aTabs = array(
			'general'  => array('tpfw-settings',                  __('General', 'tickets-passes-for-woocommerce')),
			'ticket'   => array('tpfw-ticket-settings',           __('Ticket', 'tickets-passes-for-woocommerce')),
			'timeslot' => array('tpfw-timeslot-ticket-settings',  __('Timeslot Ticket', 'tickets-passes-for-woocommerce')),
			'pass'     => array('tpfw-pass-settings',             __('Pass', 'tickets-passes-for-woocommerce')),
			'api'      => array('tpfw-api-settings',              __('Scanner / API', 'tickets-passes-for-woocommerce')),
			'emails'   => array('tpfw-email-settings',            __('Email', 'tickets-passes-for-woocommerce')),
		);
		?>
		<div class="wrap tpfw-settings-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e('Ticket & Passes', 'tickets-passes-for-woocommerce'); ?></h1>
			<hr class="wp-header-end">
			<h2 class="nav-tab-wrapper">
				<?php foreach($aTabs as $sTabKey => $aTab): ?>
					<a href="<?php echo esc_url(get_admin_url().'admin.php?page='.$aTab[0]); ?>" class="nav-tab <?php echo esc_attr(($sTab == $sTabKey) ? 'nav-tab-active' : ''); ?>">
						<?php echo esc_html($aTab[1]); ?>
					</a>
				<?php endforeach; ?>
			</h2>
		<?php
		// .wrap is deliberately left open: each *-page-content.php template closes it.
	}

	/**
	 * Wraps a customer note in the markup used wherever a note is shown in an email.
	 *
	 * @param string $sNote Raw note text.
	 * @return string Escaped markup, or an empty string for an empty note.
	 */
	public function render_product_note_html($sNote)
	{
		if($sNote == '')
		{
			return '';
		}

		return '<p style="margin: 20px 0; padding: 12px; background: #f7f7f7; border-left: 3px solid #cccccc; font-size: 14px;">' . nl2br(esc_html($sNote)) . '</p>';
	}


	/**
	 * The customer notes of every tpfw product on an order, one block per product.
	 *
	 * Used by the order-wide emails, where a single order can carry several products - each note
	 * is rendered once, under the product name it belongs to.
	 *
	 * @param WC_Order $oOrder Order to collect notes from.
	 * @return string Escaped markup, or an empty string when no product on the order has a note.
	 */
	public function get_order_product_notes($oOrder)
	{
		if(!$oOrder instanceof WC_Order)
		{
			return '';
		}

		$aNotes = array();
		foreach($oOrder->get_items() as $oOrderLine)
		{
			$iProductID = $oOrderLine->get_product_id();
			if(isset($aNotes[$iProductID]))
			{
				continue;
			}

			$sNote = $this->get_product_note($iProductID);
			if($sNote == '')
			{
				continue;
			}

			$aNotes[$iProductID] = '<strong>' . esc_html($oOrderLine->get_name()) . '</strong>' . $this->render_product_note_html($sNote);
		}

		return implode('', $aNotes);
	}


	/**
	 * Placeholder map for the order confirmation email.
	 *
	 * @param WC_Order $oOrder Order the email belongs to.
	 * @return array Placeholder => replacement value.
	 */
	public function wc_confirmation_email_replacement($oOrder)
	{
		return array(
			'{{product_note}}'  => $this->get_order_product_notes($oOrder),
			'{{site_name}}'     => get_bloginfo('name'),
			'{{site_url}}'      => get_site_url(),
			'{{site_login}}'    => wp_login_url(),
			'{{myaccount_url}}' => get_permalink(get_option('woocommerce_myaccount_page_id')),
			// Escaped: the template body is HTML and only passes wp_kses_post(), which would keep
			// an <a href> a customer typed into their billing name.
			'{{customer_name}}' => esc_html($oOrder->get_billing_first_name() . ' ' . $oOrder->get_billing_last_name()),
		);
	}



	/**
	 * Placeholder map for the "resend ticket" email, including the rendered QR block.
	 *
	 * @param WC_Order $oOrder       Order the ticket belongs to.
	 * @param string   $sNanoID      Ticket nano id.
	 * @param string   $sProductName Product name shown above the QR code.
	 * @param int      $iProductID   Product the ticket belongs to, for its customer note.
	 * @return array Placeholder => replacement value.
	 */
	public function resend_ticket_email_replacement($oOrder, $sNanoID, $sProductName, $iProductID = 0)
	{
		ob_start();
			echo '<div style="text-align: center; margin: 20px auto;">';
			echo '<h2 style="margin-bottom: 20px; text-align: center;">' . esc_html($sProductName) . '</h2>';
			echo '<img src="' . esc_url($this->get_file_url('qr', $sNanoID, 'webp', true)) . '" alt="QR Code" style="width: 200px; height: auto; margin: 0 auto;" />';
			echo '<p style="margin-top: 20px; font-size: 14px; text-align: center;">' . esc_html(sprintf(/* translators: %s: ticket nano id */ __('Ticket ID: %s', 'tickets-passes-for-woocommerce'), $sNanoID)) . '</p>';
			echo '</div>';
		$sImageHtml = ob_get_clean();

		return array(
			'{{site_name}}'     => get_bloginfo('name'),
			'{{site_url}}'      => get_site_url(),
			'{{site_login}}'    => wp_login_url(),
			'{{myaccount_url}}' => get_permalink(get_option('woocommerce_myaccount_page_id')),
			// Escaped: the template body is HTML and only passes wp_kses_post(), which would keep
			// an <a href> a customer typed into their billing name.
			'{{customer_name}}' => esc_html($oOrder->get_billing_first_name() . ' ' . $oOrder->get_billing_last_name()),
			'{{nano_id}}' 		=> $sNanoID,
			'{{qr_code_image}}' => $sImageHtml,
			'{{qr_code_link}}' 	=> $this->get_file_url('qr', $sNanoID, 'webp', true),
			'{{order_id}}' 		=> $oOrder->get_id(),
			'{{product_note}}'  => $this->render_product_note_html($this->get_product_note($iProductID)),
		);
	}



	/**
	 * Placeholder map for the "resend pass" email, including the holder details and QR block.
	 *
	 * @param WC_Order           $oOrder       Order the pass belongs to.
	 * @param string             $sNanoID      Pass nano id.
	 * @param string             $sProductName Product name shown in the details table.
	 * @param WC_Order_Item      $oOrderLine   Order line carrying the holder's first/last name.
	 * @param bool               $bIsGuestPass True to read the QR image from the guest folder.
	 * @return array Placeholder => replacement value.
	 */
	public function resend_pass_email_replacement($oOrder, $sNanoID, $sProductName, $oOrderLine, $bIsGuestPass = false)
	{
		$sQrType = $bIsGuestPass ? 'guest' : 'qr';

		ob_start();
			echo '<table style="width:100%; border-collapse: collapse;">';
			echo '<tr>';
			echo '<td style="vertical-align: top; width: 70%; padding: 10px;">';
			echo '<h3>' . esc_html__('Products', 'tickets-passes-for-woocommerce') . '</h3>';
			echo '<table class="woocommerce-table" style="width: 100%; border-collapse: collapse;">';
			echo '<thead>';
			echo '</thead>';
			echo '<tbody>';
		
			echo '<tr>';
			echo '<td style="padding: 8px;">';
			echo '<strong>' . esc_html($sProductName) . '</strong><br/>';
			echo esc_html__('Firstname', 'tickets-passes-for-woocommerce') . ': ' . esc_html($oOrderLine->get_meta('tpfw_firstname', true)) . '<br/>';
			echo esc_html__('Lastname', 'tickets-passes-for-woocommerce') . ': ' . esc_html($oOrderLine->get_meta('tpfw_lastname', true)) . '<br/>';
			echo '</td>';
			echo '</tr>';
		
			echo '</tbody>';
			echo '</table>';
			echo '</td>';
		
			echo '<td style="vertical-align: top; width: 30%; padding: 10px; text-align: center;">';
			echo '<h3>' . esc_html__('Your QR Code', 'tickets-passes-for-woocommerce') . '</h3>';
			echo '<img src="' . esc_url($this->get_file_url($sQrType, $sNanoID, 'webp', true)) . '" alt="QR Code" style="width: 100%; max-width: 150px; height: auto;" />';
			echo '</td>';
			echo '</tr>';
			echo '</table>';
		$sImageHtml = ob_get_clean();

		return array(
			'{{site_name}}'     => get_bloginfo('name'),
			'{{site_url}}'      => get_site_url(),
			'{{site_login}}'    => wp_login_url(),
			'{{myaccount_url}}' => get_permalink(get_option('woocommerce_myaccount_page_id')),
			// Escaped: the template body is HTML and only passes wp_kses_post(), which would keep
			// an <a href> a customer typed into their billing name.
			'{{customer_name}}' => esc_html($oOrder->get_billing_first_name() . ' ' . $oOrder->get_billing_last_name()),
			'{{nano_id}}' 		=> $sNanoID,
			'{{qr_code_image}}' => $sImageHtml,
			'{{qr_code_link}}' 	=> $this->get_file_url($sQrType, $sNanoID, 'webp', true),
			'{{order_id}}' 		=> $oOrder->get_id(),
			'{{product_note}}'  => $this->render_product_note_html($this->get_product_note($oOrderLine->get_product_id())),
		);
	}



	/**
	 * The email text every template falls back to until an admin saves their own.
	 *
	 * Written entirely out of the placeholders each template supports, so a site that never opens
	 * the Email settings screen still sends a complete, on-brand email - and so the resend and
	 * guest-pass emails, which used to send nothing at all while their template was empty, always
	 * have something to send.
	 *
	 * @return array Email key => (subject, message). The confirmation email has no subject of its own.
	 */
	public function get_default_email_texts()
	{
		return array(
			// No mention of the QR codes here: this text rides along on the order emails sent
			// before the order is completed, and the codes are only issued once it is, so the
			// email that carries this has nothing to show yet. The completed-order text below
			// is the one that goes out beside the codes themselves.
			'wc_confirmation_email' => array(
				'subject' => '',
				'message' => "<p>" . __('Hi {{customer_name}},', 'tickets-passes-for-woocommerce') . "</p>\n"
						   . "<p>" . __('Thanks for your order at {{site_name}}. We will email your QR codes as soon as the order is completed - until then there is nothing you need to do.', 'tickets-passes-for-woocommerce') . "</p>\n"
						   . "{{product_note}}\n"
						   . "<p>" . __('Your tickets and passes will also appear under <a href="{{myaccount_url}}">My Account</a>.', 'tickets-passes-for-woocommerce') . "</p>",
			),
			'wc_completed_email' => array(
				'subject' => '',
				'message' => "<p>" . __('Hi {{customer_name}},', 'tickets-passes-for-woocommerce') . "</p>\n"
						   . "<p>" . __('Your order at {{site_name}} is complete. Your QR codes are below - have them ready at the door.', 'tickets-passes-for-woocommerce') . "</p>\n"
						   . "{{product_note}}\n"
						   . "<p>" . __('You can find them again at any time under <a href="{{myaccount_url}}">My Account</a>.', 'tickets-passes-for-woocommerce') . "</p>",
			),
			'resend_ticket_email' => array(
				'subject' => __('Your ticket for {{site_name}} (order #{{order_id}})', 'tickets-passes-for-woocommerce'),
				'message' => "<p>" . __('Hi {{customer_name}},', 'tickets-passes-for-woocommerce') . "</p>\n"
						   . "<p>" . __('Here is your ticket again - show the QR code below at the door.', 'tickets-passes-for-woocommerce') . "</p>\n"
						   . "{{qr_code_image}}\n"
						   . "{{product_note}}\n"
						   . "<p>" . __('Ticket ID: {{nano_id}}', 'tickets-passes-for-woocommerce') . "</p>\n"
						   . "<p>" . __('You can also find it under <a href="{{myaccount_url}}">My Account</a>.', 'tickets-passes-for-woocommerce') . "</p>",
			),
			'resend_pass_email' => array(
				'subject' => __('Your pass for {{site_name}} (order #{{order_id}})', 'tickets-passes-for-woocommerce'),
				'message' => "<p>" . __('Hi {{customer_name}},', 'tickets-passes-for-woocommerce') . "</p>\n"
						   . "<p>" . __('Here is your pass again - show the QR code below at the door.', 'tickets-passes-for-woocommerce') . "</p>\n"
						   . "{{qr_code_image}}\n"
						   . "{{product_note}}\n"
						   . "<p>" . __('Pass ID: {{nano_id}}', 'tickets-passes-for-woocommerce') . "</p>\n"
						   . "<p>" . __('You can also find it under <a href="{{myaccount_url}}">My Account</a>.', 'tickets-passes-for-woocommerce') . "</p>",
			),
			'gifted_pass_email_new_user' => array(
				'subject' => __('{{buyer_name}} has sent you a pass for {{site_name}}', 'tickets-passes-for-woocommerce'),
				'message' => "<p>" . __('Hi {{customer_name}},', 'tickets-passes-for-woocommerce') . "</p>\n"
						   . "<p>" . __('{{buyer_name}} has bought you a pass for {{site_name}}. Show the QR code below at the door.', 'tickets-passes-for-woocommerce') . "</p>\n"
						   . "{{qr_code_image}}\n"
						   . "{{product_note}}\n"
						   . "<p>" . __('We have created an account for you so you can find your pass again at any time:', 'tickets-passes-for-woocommerce') . "</p>\n"
						   . "{{new_user_login_details}}\n"
						   . "<p>" . __('Sign in at <a href="{{site_login}}">{{site_login}}</a> and open <a href="{{myaccount_url}}">My Account</a>.', 'tickets-passes-for-woocommerce') . "</p>",
			),
			'gifted_pass_email_existing_user' => array(
				'subject' => __('{{buyer_name}} has sent you a pass for {{site_name}}', 'tickets-passes-for-woocommerce'),
				'message' => "<p>" . __('Hi {{customer_name}},', 'tickets-passes-for-woocommerce') . "</p>\n"
						   . "<p>" . __('{{buyer_name}} has bought you a pass for {{site_name}}. Show the QR code below at the door.', 'tickets-passes-for-woocommerce') . "</p>\n"
						   . "{{qr_code_image}}\n"
						   . "{{product_note}}\n"
						   . "<p>" . __('It is also waiting for you under <a href="{{myaccount_url}}">My Account</a>.', 'tickets-passes-for-woocommerce') . "</p>",
			),
		);
	}


	/**
	 * One email template field, falling back to the shipped default while the admin has saved none.
	 *
	 * Every place that sends one of these emails reads its text through here, so the settings
	 * screen and the senders can never disagree about what an untouched template contains.
	 *
	 * @param string $sKey   Email key, e.g. 'resend_ticket_email'.
	 * @param string $sField 'subject' or 'message'.
	 * @return string The saved text, or the default when nothing is saved.
	 */
	public function get_email_setting($sKey, $sField)
	{
		$aSettingsOptions = get_option('tpfw_email_settings_options', array());
		return TPFW_Email_Settings::resolve($aSettingsOptions, $sKey, $sField, $this->get_default_email_texts());
	}


    /**
     * Human readable descriptions of every placeholder, per email type.
     *
     * Drives the "available placeholders" tables on the Email settings screen, so the list an
     * admin sees can never drift from the ones the replacement maps above actually provide.
     *
     * @return array Email key => (placeholder => description).
     */
    public function get_email_text_replacements_descriptions()
    {
        $aReplacements = array(
            'wc_confirmation_email'  => 
                                        array(
                                            '{{site_name}}'     => __('Name of your site', 'tickets-passes-for-woocommerce'),
                                            '{{site_login}}'    => __('Login URL, to your site', 'tickets-passes-for-woocommerce'),
											'{{site_url}}'      => __('Site URL', 'tickets-passes-for-woocommerce'),
                                            '{{myaccount_url}}' => __('Direct URL to \'My Account\'', 'tickets-passes-for-woocommerce'),
                                            '{{customer_name}}' => __('Name of the customer', 'tickets-passes-for-woocommerce'),
                                            '{{product_note}}'  => __('The product note written on the product, if any', 'tickets-passes-for-woocommerce'),
                                        ),
            'wc_completed_email'  =>
                                        array(
                                            '{{site_name}}'     => __('Name of your site', 'tickets-passes-for-woocommerce'),
                                            '{{site_login}}'    => __('Login URL, to your site', 'tickets-passes-for-woocommerce'),
											'{{site_url}}'      => __('Site URL', 'tickets-passes-for-woocommerce'),
                                            '{{myaccount_url}}' => __('Direct URL to \'My Account\'', 'tickets-passes-for-woocommerce'),
                                            '{{customer_name}}' => __('Name of the customer', 'tickets-passes-for-woocommerce'),
                                            '{{product_note}}'  => __('The product note written on the product, if any', 'tickets-passes-for-woocommerce'),
                                        ),
			'resend_ticket_email'  =>
                                        array(
                                            '{{site_name}}'     => __('Name of your site', 'tickets-passes-for-woocommerce'),
                                            '{{site_login}}'    => __('Login URL, to your site', 'tickets-passes-for-woocommerce'),
											'{{site_url}}'      => __('Site URL', 'tickets-passes-for-woocommerce'),
                                            '{{myaccount_url}}' => __('Direct URL to \'My Account\'', 'tickets-passes-for-woocommerce'),
                                            '{{customer_name}}' => __('Name of the customer', 'tickets-passes-for-woocommerce'),
                                            '{{nano_id}}' 		=> __('The Nano ID of the Ticket', 'tickets-passes-for-woocommerce'),
                                            '{{qr_code_image}}' => __('Ticket QR Image', 'tickets-passes-for-woocommerce'),
                                            '{{qr_code_link}}' 	=> __('Direct URL link to the QR code', 'tickets-passes-for-woocommerce'),
                                            '{{order_id}}' 		=> __('The ID of the connected Order', 'tickets-passes-for-woocommerce'),
                                            '{{product_note}}'  => __('The product note written on the product, if any', 'tickets-passes-for-woocommerce'),
                                        ),
			'resend_pass_email'  	=> 
                                        array(
                                            '{{site_name}}'     => __('Name of your site', 'tickets-passes-for-woocommerce'),
                                            '{{site_login}}'    => __('Login URL, to your site', 'tickets-passes-for-woocommerce'),
											'{{site_url}}'      => __('Site URL', 'tickets-passes-for-woocommerce'),
                                            '{{myaccount_url}}' => __('Direct URL to \'My Account\'', 'tickets-passes-for-woocommerce'),
                                            '{{customer_name}}' => __('Name of the customer', 'tickets-passes-for-woocommerce'),
                                            '{{nano_id}}' 		=> __('The Nano ID of the Pass', 'tickets-passes-for-woocommerce'),
                                            '{{qr_code_image}}' => __('Pass QR Image', 'tickets-passes-for-woocommerce'),
                                            '{{qr_code_link}}' 	=> __('Direct URL link to the QR code', 'tickets-passes-for-woocommerce'),
                                            '{{order_id}}' 		=> __('The ID of the connected Order', 'tickets-passes-for-woocommerce'),
                                            '{{product_note}}'  => __('The product note written on the product, if any', 'tickets-passes-for-woocommerce'),
                                        ),
			'gifted_pass_email_new_user'  	=> 
										array(
											'{{site_name}}'              => __('Name of your site', 'tickets-passes-for-woocommerce'),
											'{{site_login}}'             => __('Login URL, to your site', 'tickets-passes-for-woocommerce'),
											'{{site_url}}'      		 => __('Site URL', 'tickets-passes-for-woocommerce'),
											'{{myaccount_url}}'          => __('Direct URL to \'My Account\'', 'tickets-passes-for-woocommerce'),
											'{{customer_name}}'          => __('Name of the customer', 'tickets-passes-for-woocommerce'),
											'{{buyer_name}}'             => __('Name of the customer', 'tickets-passes-for-woocommerce'),
											'{{new_user_login_details}}' => __('Username of the new account and a link to choose its password', 'tickets-passes-for-woocommerce'),
											'{{nano_id}}'                => __('The Nano ID of the Pass', 'tickets-passes-for-woocommerce'),
											'{{qr_code_image}}'          => __('Pass QR Image', 'tickets-passes-for-woocommerce'),
											'{{qr_code_link}}'           => __('Direct URL link to the QR code', 'tickets-passes-for-woocommerce'),
											'{{order_id}}'               => __('The ID of the connected Order', 'tickets-passes-for-woocommerce'),
											'{{product_note}}'           => __('The product note written on the product, if any', 'tickets-passes-for-woocommerce'),
										),
			'gifted_pass_email_existing_user'  	=> 
										array(
											'{{site_name}}'              => __('Name of your site', 'tickets-passes-for-woocommerce'),
											'{{site_login}}'             => __('Login URL, to your site', 'tickets-passes-for-woocommerce'),
											'{{site_url}}'      		 => __('Site URL', 'tickets-passes-for-woocommerce'),
											'{{myaccount_url}}'          => __('Direct URL to \'My Account\'', 'tickets-passes-for-woocommerce'),
											'{{customer_name}}'          => __('Name of the customer', 'tickets-passes-for-woocommerce'),
											'{{buyer_name}}'             => __('Name of the customer', 'tickets-passes-for-woocommerce'),											
											'{{nano_id}}'                => __('The Nano ID of the Pass', 'tickets-passes-for-woocommerce'),
											'{{qr_code_image}}'          => __('Pass QR Image', 'tickets-passes-for-woocommerce'),
											'{{qr_code_link}}'           => __('Direct URL link to the QR code', 'tickets-passes-for-woocommerce'),
											'{{order_id}}'               => __('The ID of the connected Order', 'tickets-passes-for-woocommerce'),
											'{{product_note}}'           => __('The product note written on the product, if any', 'tickets-passes-for-woocommerce'),
										),										
        );        
        return $aReplacements;    
    }
	

	/**
	 * Placeholder map for a gifted pass sent to someone who already has an account.
	 *
	 * @param WC_Order      $oOrder       Order the pass was bought on.
	 * @param string        $sNanoID      Pass nano id.
	 * @param string        $sProductName Product name shown in the details table.
	 * @param WC_Order_Item $oOrderLine   Order line carrying the recipient's name.
	 * @return array Placeholder => replacement value.
	 */
	public function gifted_pass_email_existing_user_replacement($oOrder, $sNanoID, $sProductName, $oOrderLine)
	{	
		ob_start();
			echo '<table style="width:100%; border-collapse: collapse;">';
			echo '<tr>';
			echo '<td style="vertical-align: top; width: 70%; padding: 10px;">';
			echo '<h3>' . esc_html__('Products', 'tickets-passes-for-woocommerce') . '</h3>';
			echo '<table class="woocommerce-table" style="width: 100%; border-collapse: collapse;">';
			echo '<thead>';
			echo '</thead>';
			echo '<tbody>';
		
			echo '<tr>';
			echo '<td style="padding: 8px;">';
			echo '<strong>' . esc_html($sProductName) . '</strong><br/>';
			echo esc_html__('Firstname', 'tickets-passes-for-woocommerce') . ': ' . esc_html($oOrderLine->get_meta('tpfw_firstname', true)) . '<br/>';
			echo esc_html__('Lastname', 'tickets-passes-for-woocommerce') . ': ' . esc_html($oOrderLine->get_meta('tpfw_lastname', true)) . '<br/>';
			echo '</td>';
			echo '</tr>';
		
			echo '</tbody>';
			echo '</table>';
			echo '</td>';
		
			echo '<td style="vertical-align: top; width: 30%; padding: 10px; text-align: center;">';
			echo '<h3>' . esc_html__('Your QR Code', 'tickets-passes-for-woocommerce') . '</h3>';
			echo '<img src="' . esc_url($this->get_file_url('qr', $sNanoID, 'webp', true)) . '" alt="QR Code" style="width: 100%; max-width: 150px; height: auto;" />';
			echo '</td>';
			echo '</tr>';
			echo '</table>';
		$sImageHtml = ob_get_clean();

		return array(
			'{{site_name}}'              => get_bloginfo('name'),
			'{{site_url}}'               => get_site_url(),
			'{{site_login}}'             => wp_login_url(),
			'{{myaccount_url}}'          => get_permalink(get_option('woocommerce_myaccount_page_id')),
			'{{customer_name}}'          => esc_html($oOrderLine->get_meta('tpfw_firstname', true)) . ' ' . esc_html($oOrderLine->get_meta('tpfw_lastname', true)),
			'{{buyer_name}}'             => esc_html($oOrder->get_billing_first_name()) . ' ' . esc_html($oOrder->get_billing_last_name()),			
			'{{nano_id}}'                => $sNanoID,
			'{{qr_code_image}}'          => $sImageHtml,
			'{{qr_code_link}}'           => $this->get_file_url('qr', $sNanoID, 'webp', true),
			'{{order_id}}'               => $oOrder->get_id(),
			'{{product_note}}'  => $this->render_product_note_html($this->get_product_note($oOrderLine->get_product_id())),
		);
	}



	/**
	 * Placeholder map for a gifted pass that also created an account for the recipient.
	 *
	 * Adds {{new_user_login_details}} - the username and a "set your password" link - on top of
	 * what the existing-user variant provides. No password is ever placed in the email.
	 *
	 * @param WC_Order      $oOrder          Order the pass was bought on.
	 * @param string        $sNanoID         Pass nano id.
	 * @param string        $sProductName    Product name shown in the details table.
	 * @param WC_Order_Item $oOrderLine      Order line carrying the recipient's name.
	 * @param string        $sUsername       Login for the account just created.
	 * @param string        $sSetPasswordURL One-time set-password link for that account.
	 * @return array Placeholder => replacement value.
	 */
	public function gifted_pass_email_new_user_replacement($oOrder, $sNanoID, $sProductName, $oOrderLine, $sUsername, $sSetPasswordURL)
	{	
		ob_start();
			echo '<table style="width:100%; border-collapse: collapse;">';
			echo '<tr>';
			echo '<td style="vertical-align: top; width: 70%; padding: 10px;">';
			echo '<h3>' . esc_html__('Products', 'tickets-passes-for-woocommerce') . '</h3>';
			echo '<table class="woocommerce-table" style="width: 100%; border-collapse: collapse;">';
			echo '<thead>';
			echo '</thead>';
			echo '<tbody>';
		
			echo '<tr>';
			echo '<td style="padding: 8px;">';
			echo '<strong>' . esc_html($sProductName) . '</strong><br/>';
			echo esc_html__('Firstname', 'tickets-passes-for-woocommerce') . ': ' . esc_html($oOrderLine->get_meta('tpfw_firstname', true)) . '<br/>';
			echo esc_html__('Lastname', 'tickets-passes-for-woocommerce') . ': ' . esc_html($oOrderLine->get_meta('tpfw_lastname', true)) . '<br/>';
			echo '</td>';
			echo '</tr>';
		
			echo '</tbody>';
			echo '</table>';
			echo '</td>';
		
			echo '<td style="vertical-align: top; width: 30%; padding: 10px; text-align: center;">';
			echo '<h3>' . esc_html__('Your QR Code', 'tickets-passes-for-woocommerce') . '</h3>';
			echo '<img src="' . esc_url($this->get_file_url('qr', $sNanoID, 'webp', true)) . '" alt="QR Code" style="width: 100%; max-width: 150px; height: auto;" />';
			echo '</td>';
			echo '</tr>';
			echo '</table>';
		$sImageHtml = ob_get_clean();


		ob_start();
			echo '<h3>' . esc_html__('Login Details', 'tickets-passes-for-woocommerce') . '</h3>';
			echo '<table class="woocommerce-table" style="width: 100%; border-collapse: collapse;">';
			echo '<thead>';
			echo '</thead>';
			echo '<tbody>';
		
			echo '<tr>';
			echo '<td style="padding: 8px;">';		
			echo esc_html__('Username', 'tickets-passes-for-woocommerce');
			echo '</td>';
			echo '<td style="padding: 8px;">';		
			echo esc_html($sUsername);
			echo '</td>';
			echo '</tr>';
			echo '<tr>';
			echo '<td style="padding: 8px;">';
			echo esc_html__('Password', 'tickets-passes-for-woocommerce');
			echo '</td>';
			echo '<td style="padding: 8px;">';
			echo '<a href="' . esc_url($sSetPasswordURL) . '">' . esc_html__('Choose your password', 'tickets-passes-for-woocommerce') . '</a>';
			echo '</td>';
			echo '</tr>';
		
			echo '</tbody>';
			echo '</table>';
		$sNewUserLogin = ob_get_clean();

		return array(
			'{{site_name}}'              => get_bloginfo('name'),
			'{{site_url}}'               => get_site_url(),
			'{{site_login}}'             => wp_login_url(),
			'{{myaccount_url}}'          => get_permalink(get_option('woocommerce_myaccount_page_id')),
			'{{customer_name}}'          => esc_html($oOrderLine->get_meta('tpfw_firstname', true)) . ' ' . esc_html($oOrderLine->get_meta('tpfw_lastname', true)),
			'{{buyer_name}}'             => esc_html($oOrder->get_billing_first_name()) . ' ' . esc_html($oOrder->get_billing_last_name()),
			'{{new_user_login_details}}' => $sNewUserLogin,
			'{{nano_id}}'                => $sNanoID,
			'{{qr_code_image}}'          => $sImageHtml,
			'{{qr_code_link}}'           => $this->get_file_url('qr', $sNanoID, 'webp', true),
			'{{order_id}}'               => $oOrder->get_id(),
			'{{product_note}}'  => $this->render_product_note_html($this->get_product_note($oOrderLine->get_product_id())),
		);
	}

	/**
	 * Resolves the WordPress account a pass belongs to, creating one when the address is new.
	 *
	 * A new account is created with a random password that is never sent anywhere; the recipient
	 * gets a one-time set-password link instead (the same mechanism as "Lost your password?"),
	 * so no credential ever travels in plain text through email.
	 *
	 * @param string $sEmail Holder's email address.
	 * @return array|false iUserID plus bUserExisted (and sSetPasswordURL for a new account), or
	 *                     false when the account could not be created.
	 */
	public function get_pass_user($sEmail)
	{
		$oUser = get_user_by('email', $sEmail);
		if($oUser)
		{
			return array(
				'iUserID'      => $oUser->ID,
				'bUserExisted' => true,
			);
		}

		$sRandomPassword = wp_generate_password(24, true, true);
		$mUserID         = wp_create_user($sEmail, $sRandomPassword, $sEmail);

		// wp_create_user() returns a WP_Error - which is an OBJECT, and therefore truthy -
		// when the login is already taken (a different account registered under this address
		// as its username) or the address is rejected. The old `if($iUserID)` accepted that
		// error object and it was written straight into the pass row's user_id column as a
		// garbage id, silently binding the pass to nobody.
		if(is_wp_error($mUserID))
		{
			return false;
		}

		$oNewUser        = get_user_by('ID', (int) $mUserID);
		$mResetKey       = $oNewUser ? get_password_reset_key($oNewUser) : false;
		$sSetPasswordURL = (is_string($mResetKey) && $oNewUser)
			? network_site_url('wp-login.php?action=rp&key=' . rawurlencode($mResetKey) . '&login=' . rawurlencode($oNewUser->user_login), 'login')
			: wp_lostpassword_url();

		return array(
			'iUserID'         => (int)$mUserID,
			'bUserExisted'    => false,
			'sSetPasswordURL' => $sSetPasswordURL,
		);
	}
	/**
	 * Normalises the headers tpfw_custom_enmail() accepts into the single CRLF separated string
	 * wp_mail() expects, or false when there is nothing usable.
	 *
	 * Callers pass a plain list ('Content-Type: text/html; charset=UTF-8'), so the array key is
	 * the numeric index - the old version always prefixed it and produced a bogus
	 * "0: Content-Type: ..." header. It also joined with a single quoted '\r\n', putting a
	 * literal backslash-r-backslash-n inside the header value instead of terminating it.
	 *
	 * Pure function on purpose: tests/test-email-headers.php exercises it without WordPress.
	 *
	 * @param array|string $mHeaders Header list, header map or ready-made header string.
	 * @return string|false CRLF joined headers, or false when empty.
	 */
	public static function build_mail_headers($mHeaders)
	{
		if(is_array($mHeaders))
		{
			$aTempHeaders = array();
			foreach($mHeaders as $mHeaderKey => $sHeaderValue)
			{
				if($sHeaderValue === '' || $sHeaderValue === null) continue;
				$aTempHeaders[] = is_int($mHeaderKey) ? $sHeaderValue : $mHeaderKey . ': ' . $sHeaderValue;
			}

			$mHeaders = implode("\r\n", $aTempHeaders);
		}

		if(!is_string($mHeaders) || $mHeaders === '') return false;

		return $mHeaders;
	}
	/**
	 * Sends one plugin email through WooCommerce's mailer, wrapped in the store's own template.
	 *
	 * NOTE: this is a plain mail helper - it deliberately does NOT check capabilities. It used to
	 * bail unless current_user_can('administrator'), which silently dropped every guest pass
	 * invite: those are sent from woocommerce_order_status_completed, which also fires from
	 * payment gateway callbacks, WooCommerce's auto-complete for virtual products and WP-Cron,
	 * where there is no logged-in admin at all. The permission check belongs on the
	 * admin-triggered resend endpoints instead, and lives there now.
	 *
	 * @param string       $sEmail       Recipient address; rejected unless it validates.
	 * @param string       $sSubject     Subject line; trimmed to 150 characters.
	 * @param string       $sMessage     HTML body, inserted between the WC header and footer.
	 * @param array|string $sHeaders     Headers, see build_mail_headers().
	 * @param array|string $aAttachments Attachment paths passed straight to the mailer.
	 * @return bool True when the mailer accepted the message.
	 */
	public function tpfw_custom_enmail($sEmail, $sSubject, $sMessage, $sHeaders, $aAttachments = "")
	{
		if(!filter_var($sEmail, FILTER_VALIDATE_EMAIL))
		{
			return false;
		}

		// Trimmed rather than refused: a long site name in "Your ticket for {{site_name}}" used
		// to make every resend silently return false with nothing logged.
		$sSubject = mb_substr($sSubject, 0, 150);

		if(!class_exists('WC_Emails'))
		{
			include_once WC_ABSPATH . 'includes/class-wc-emails.php';
		}

		$sHeaders = self::build_mail_headers($sHeaders);
		if($sHeaders === false)
		{
			return false;
		}

		// wrap_message() is WC_Emails' old plain-table wrapper - it skips the header logo,
		// heading and footer styling every other WooCommerce email uses, so this looked
		// like a bare fragment instead of a normal store email. Building the same
		// header/body/footer templates a real WC_Email renders gives these the identical
		// look, then style_inline() does the same CSS inlining pass WC_Email::send() does.
		// style_inline() lives on WC_Email (the per-template class), not WC_Emails (the
		// mailer/manager WC()->mailer() returns) - any registered email instance works,
		// since the method itself doesn't depend on which specific template it came from.
		$oMailer         = WC()->mailer();
		$aRegisteredEmails = $oMailer->get_emails();
		$oEmailTemplate  = reset($aRegisteredEmails);
		$sEmailHeader    = wc_get_template_html('emails/email-header.php', array('email_heading' => $sSubject));
		$sEmailFooter    = wc_get_template_html('emails/email-footer.php');
		$sMessage        = $oEmailTemplate ? $oEmailTemplate->style_inline($sEmailHeader . $sMessage . $sEmailFooter) : ($sEmailHeader . $sMessage . $sEmailFooter);

		return $oMailer->send($sEmail, $sSubject, $sMessage, $sHeaders, $aAttachments);
	}



	/**
	 * Deletes a generated QR image from the uploads folder.
	 *
	 * @param string $sQRCode Nano id the .webp is named after.
	 * @return bool False when there was no such file.
	 */
	public function delete_qr_code($sQRCode)
	{
		$sFilePath = $this->get_qr_upload_dir().$sQRCode.'.webp';
		if(!file_exists($sFilePath))
		{
			return false;
		}
		wp_delete_file($sFilePath);
		return true;
	}

	/**
	 * Deletes a guest pass's generated QR image.
	 *
	 * Guest codes live in their own folder, so delete_qr_code() above never touched them - a
	 * cancelled pass left every guest image it had issued on disk, still fetchable by anyone
	 * holding the signed link that was mailed out. The check-in itself was always refused, but
	 * the image had no business surviving the pass it belongs to.
	 *
	 * @param string $sNanoID Guest pass nano id the .webp is named after.
	 * @return bool False when there was no such file.
	 */
	public function delete_guest_qr_code($sNanoID)
	{
		$sFilePath = $this->get_guest_upload_dir().$sNanoID.'.webp';
		if(!file_exists($sFilePath))
		{
			return false;
		}
		wp_delete_file($sFilePath);
		return true;
	}
	/**
	 * Writes a caught throwable to the WooCommerce log under the 'tpfw' source.
	 *
	 * Nothing in this plugin used to record why a ticket failed to generate: no error_log, no
	 * logger, no catch blocks. An unwritable uploads dir, a missing GD extension or a corrupt
	 * logo image surfaced to the customer as a fatal or a silently missing ticket, with no trace
	 * anywhere. WooCommerce's own logger writes to WooCommerce > Status > Logs, a screen the site
	 * owner already has, so there is nothing new to build or to teach them.
	 *
	 * @param string     $sContext    Short description of what was being attempted.
	 * @param \Throwable $oThrowable  The caught error or exception.
	 * @return void
	 */
	public function log_error($sContext, $oThrowable)
	{
		if(!function_exists('wc_get_logger'))
		{
			return;
		}

		wc_get_logger()->error(
			$sContext . ': ' . $oThrowable->getMessage() . ' (' . $oThrowable->getFile() . ':' . $oThrowable->getLine() . ')',
			array('source' => 'tpfw')
		);
	}
	/**
	 * Generates a QR image into the uploads folder for the given purpose.
	 *
	 * The guard lives here rather than at the 16 call sites: every one of them routes through
	 * this method, and a QR failure should degrade to "no image" (which callers already treat as
	 * a failed return), never to a fatal in the middle of an order or a scan.
	 *
	 * @param string $sQRData          Payload encoded in the QR code (the check-in URL).
	 * @param int    $iSize            Image size in pixels.
	 * @param int    $iMargin          Quiet-zone margin in pixels.
	 * @param string $sLabelText       Caption printed under the code; empty for none.
	 * @param string $sFullLogoPath    Absolute path to the centre logo; null for none.
	 * @param Color  $oBackgroundColor Background colour.
	 * @param Color  $oForegroundColor Module colour.
	 * @param Color  $oLabelColor      Caption colour.
	 * @param string $sType            'preview', 'guest' or anything else for the normal folder.
	 * @param string $sFileName        Filename without extension (the nano id).
	 * @return bool True on success, false when generation threw.
	 */
	/**
	 * Where each product type keeps its QR appearance settings.
	 *
	 * @var array<string,string> Check-in type => the meta key prefix its settings share.
	 */
	const QR_META_PREFIXES = array(
		'ticket'    => '_tpfw_ticket_qr_',
		'timeslot'  => '_tpfw_timeslot_qr_',
		'pass'      => '_tpfw_pass_qr_',
		'guestpass' => '_tpfw_guestpass_qr_',
	);



	/**
	 * (Re)writes the scanner QR image for one ticket, timeslot ticket or pass.
	 *
	 * Every caller wants the same code - the check-in URL for that nano id, at the size the
	 * scanner reads, in the colours and with the logo and caption chosen on the product's own
	 * edit screen - so the settings are read here rather than spelled out again per type.
	 *
	 * A logo whose attachment has since been deleted is dropped rather than passed on as a path
	 * that does not exist, and an unset colour falls back to a fixed default: hex_to_color()
	 * answers an empty string with a random colour, which would repaint the code on every reset.
	 *
	 * @param int    $iProductID Product the QR settings belong to.
	 * @param string $sType      Check-in type; a key of QR_META_PREFIXES.
	 * @param string $sNanoID    Nano id, used both as the payload and as the file name.
	 * @return bool False when generation failed; the caller carries on either way.
	 */
	public function write_scanner_qr($iProductID, $sType, $sNanoID)
	{
		$sPrefix = self::QR_META_PREFIXES[$sType] ?? '';
		if($sPrefix === '') { return false; }

		$sLogoPath = null;
		$iLogoID   = (int)get_post_meta($iProductID, $sPrefix . 'logo_id', true);
		if($iLogoID > 0)
		{
			$sLogoPath = get_attached_file($iLogoID);
			if(!$sLogoPath || !file_exists($sLogoPath)) { $sLogoPath = null; }
		}

		$sBackground = get_post_meta($iProductID, $sPrefix . 'background_color', true);
		$sForeground = get_post_meta($iProductID, $sPrefix . 'foreground_color', true);
		$sLabelColor = get_post_meta($iProductID, $sPrefix . 'label_color', true);

		// A guest pass is checked in at its own route and its image lives in its own folder -
		// the scanner reads the URL out of the code, so the two have to agree.
		$bGuestPass = ($sType === 'guestpass');

		return $this->create_qr_code(
			get_rest_url() . 'tpfw/v1/scanner/checkin/' . $sNanoID . ($bGuestPass ? '/guest' : ''),
			300,
			10,
			(string)get_post_meta($iProductID, $sPrefix . 'label_text', true),
			$sLogoPath,
			$this->hex_to_color($sBackground !== '' ? $sBackground : '#000000'),
			$this->hex_to_color($sForeground !== '' ? $sForeground : '#FFFFFF'),
			$this->hex_to_color($sLabelColor !== '' ? $sLabelColor : '#000000'),
			$bGuestPass ? 'guest' : 'qr',
			$sNanoID
		);
	}



	/**
	 * Renders a QR code image to the upload directory that matches its type.
	 *
	 * @param string $sQRData          Payload encoded into the code - the check-in REST URL.
	 * @param int    $iSize            Image size in pixels.
	 * @param int    $iMargin          Quiet-zone margin in modules.
	 * @param string $sLabelText       Text printed under the code, '' for none.
	 * @param string $sFullLogoPath    Filesystem path to a centre logo, '' for none.
	 * @param object $oBackgroundColor Background colour object from hex_to_color().
	 * @param object $oForegroundColor Module colour object from hex_to_color().
	 * @param object $oLabelColor      Label text colour object from hex_to_color().
	 * @param string $sType            'qr', 'guest' or 'preview' - picks the target directory.
	 * @param string $sFileName        File name without extension, normally the NanoID.
	 * @return bool True when the image was written, false when the QR library threw.
	 */
	public function create_qr_code($sQRData, $iSize, $iMargin, $sLabelText, $sFullLogoPath, $oBackgroundColor, $oForegroundColor, $oLabelColor, $sType, $sFileName)
	{
		try
		{
			if($sType == 'preview')
			{
				return tpfw_create_qr_code_function($sQRData, $iSize, $iMargin, $sLabelText, $sFullLogoPath, $oBackgroundColor, $oForegroundColor, $oLabelColor, $this->get_preview_qr_upload_dir(), $sFileName);
			}
			else if($sType == 'guest')
			{
				return tpfw_create_qr_code_function($sQRData, $iSize, $iMargin, $sLabelText, $sFullLogoPath, $oBackgroundColor, $oForegroundColor, $oLabelColor, $this->get_guest_upload_dir(), $sFileName);
			}
			else
			{
				return tpfw_create_qr_code_function($sQRData, $iSize, $iMargin, $sLabelText, $sFullLogoPath, $oBackgroundColor, $oForegroundColor, $oLabelColor, $this->get_qr_upload_dir(), $sFileName);
			}
		}
		catch(\Throwable $oThrowable)
		{
			$this->log_error('create_qr_code failed for ' . $sFileName, $oThrowable);
			return false;
		}
	}



	/**
	 * Turns a hex colour string into the QR library's Color object.
	 *
	 * Accepts #rrggbb and #rgb, with or without the leading #. An empty value yields a random
	 * colour (the preview needs *something* to draw with); anything else that is not a hex
	 * colour yields black. The library's Color constructor is typed `int`, so handing it the
	 * nulls sscanf() returns for a malformed string used to throw a TypeError in the middle
	 * of a product save or an order completion.
	 *
	 * @param string $sHexString Hex colour, e.g. '#1a2b3c', '#abc' or 'abc'.
	 * @return Color
	 */
	public function hex_to_color($sHexString)
	{
		include_once dirname(__FILE__).'/lib/qrcodegen/autoload.php';
		if($sHexString == "" || $sHexString == null)
		{
			return new Color(wp_rand(0, 255), wp_rand(0, 255), wp_rand(0, 255));
		}
		$sHex = sanitize_hex_color(strpos($sHexString, '#') === 0 ? $sHexString : '#'.$sHexString);
		if(!$sHex)
		{
			return new Color(0, 0, 0);
		}
		$sHex = ltrim($sHex, '#');
		if(strlen($sHex) === 3)
		{
			$sHex = $sHex[0].$sHex[0].$sHex[1].$sHex[1].$sHex[2].$sHex[2];
		}
		return new Color(hexdec(substr($sHex, 0, 2)), hexdec(substr($sHex, 2, 2)), hexdec(substr($sHex, 4, 2)));
	}


	
	/**
	 * Renders a list of associative rows as a CSV string, header row included.
	 *
	 * Used by the analytics CSV exports. Writes through php://temp rather than a real file so
	 * nothing has to be cleaned up afterwards.
	 *
	 * Cells that start with =, +, -, @ or a tab/CR are prefixed with a single quote so a
	 * customer name like "=HYPERLINK(...)" cannot become a live formula when the export is
	 * opened in Excel or LibreOffice (CSV injection).
	 *
	 * @param array $data Rows; the keys of the first row become the header. Empty yields ''.
	 * @return string CSV text.
	 */
	public function str_putcsv($data)
	{
		if(empty($data) || !is_array(current($data)))
		{
			return '';
		}

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-memory stream, not a file path; WP_Filesystem has no equivalent.
		$fh = fopen('php://temp', 'w+');

		$fnNeutralise = function($mCell)
		{
			if(is_string($mCell) && $mCell !== '' && strpbrk($mCell[0], "=+-@\t\r") !== false)
			{
				return "'".$mCell;
			}
			return $mCell;
		};

		// The $escape argument is passed explicitly, and empty. PHP 8.4 deprecates leaving it out
		// because its default - a backslash escape - is a PHP invention no CSV reader implements;
		// RFC 4180 escapes a quote by doubling it, which is what an empty $escape produces. Left
		// implicit, this emitted a deprecation notice on every export, and an export writes its
		// body straight to the browser, so on a site with display_errors on that notice landed
		// inside the downloaded file.
        fputcsv($fh, array_keys(current($data)), ',', '"', '');
        foreach($data as $row)
		{
            fputcsv($fh, array_map($fnNeutralise, $row), ',', '"', '');
        }
        rewind($fh);	
        $csv = stream_get_contents($fh);		
        fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory stream, see fopen above.
        return $csv;
	}
	/**
	 * Linear-interpolates between two hex colors so a single admin-picked accent color can still
	 * produce a lighter "soft" background or a darker "hover" shade instead of those staying
	 * hardcoded to whatever tint happened to match the plugin's old default accent.
	 *
	 * @param string $sHexA   Start colour, with or without leading #.
	 * @param string $sHexB   End colour, with or without leading #.
	 * @param float  $fWeight 0 = all A, 1 = all B; clamped into that range.
	 * @return string '#rrggbb'.
	 */
	public function mix_hex_colors($sHexA, $sHexB, $fWeight)
	{
		$sHexA   = ltrim($sHexA, '#');
		$sHexB   = ltrim($sHexB, '#');
		$fWeight = max(0, min(1, $fWeight));

		$iR = round(hexdec(substr($sHexA, 0, 2)) + (hexdec(substr($sHexB, 0, 2)) - hexdec(substr($sHexA, 0, 2))) * $fWeight);
		$iG = round(hexdec(substr($sHexA, 2, 2)) + (hexdec(substr($sHexB, 2, 2)) - hexdec(substr($sHexA, 2, 2))) * $fWeight);
		$iB = round(hexdec(substr($sHexA, 4, 2)) + (hexdec(substr($sHexB, 4, 2)) - hexdec(substr($sHexA, 4, 2))) * $fWeight);

		return sprintf('#%02x%02x%02x', $iR, $iG, $iB);
	}

	/**
	 * Picks black or white - whichever reads better as text on top of $sHex - using a simple
	 * perceived-luminance check, so text sitting on an admin-picked accent colour stays legible.
	 *
	 * @param string $sHex '#rrggbb'.
	 * @return string '#1d2327' or '#ffffff'.
	 */
	public function get_contrast_text_color($sHex)
	{
		$aRgb = sscanf($sHex, '#%02x%02x%02x');
		if($aRgb === null || count($aRgb) < 3 || in_array(null, $aRgb, true))
		{
			return '#ffffff';
		}
		list($iRed, $iGreen, $iBlue) = $aRgb;
		$fLuminance = (0.299 * $iRed + 0.587 * $iGreen + 0.114 * $iBlue) / 255;
		return $fLuminance > 0.6 ? '#1d2327' : '#ffffff';
	}

	/**
	 * Renders a number+unit duration control that writes its value into a hidden seconds input.
	 *
	 * The hidden input keeps the field's original id and name, so the save handlers and the
	 * show/hide toggle JS - which both target elements by id - work unchanged. Shared by all
	 * three product types' admin panels.
	 *
	 * @param string $sId          Field id and POST name; also the hidden input carrying raw seconds.
	 * @param string $sLabel       Visible label.
	 * @param string $sDescription Optional inline description shown under the label.
	 * @param int    $iSeconds     Current value in seconds.
	 * @param string $sExtraClass  Extra class on the wrapping row, used by the toggle JS.
	 * @return void
	 */
	public function render_duration_field($sId, $sLabel, $sDescription, $iSeconds, $sExtraClass = '')
	{
		?>
		<p class="form-field <?php echo esc_attr($sId); ?>_field tpfw-duration-field <?php echo esc_attr($sExtraClass); ?>">
			<label for="<?php echo esc_attr($sId); ?>_number"><?php echo esc_html($sLabel); ?></label>
			<span class="tpfw-duration-control">
				<input type="number" min="1" step="1" class="tpfw-duration-number" id="<?php echo esc_attr($sId); ?>_number" />
				<select class="tpfw-duration-unit">
					<option value="1"><?php echo esc_html__('Seconds', 'tickets-passes-for-woocommerce'); ?></option>
					<option value="60"><?php echo esc_html__('Minutes', 'tickets-passes-for-woocommerce'); ?></option>
					<option value="3600"><?php echo esc_html__('Hours', 'tickets-passes-for-woocommerce'); ?></option>
					<option value="86400"><?php echo esc_html__('Days', 'tickets-passes-for-woocommerce'); ?></option>
					<option value="604800"><?php echo esc_html__('Weeks', 'tickets-passes-for-woocommerce'); ?></option>
				</select>
			</span>
			<input type="hidden" class="tpfw-duration-seconds" id="<?php echo esc_attr($sId); ?>" name="<?php echo esc_attr($sId); ?>" value="<?php echo esc_attr($iSeconds); ?>" />
			<?php if(!empty($sDescription)) : ?>
			<span class="description"><?php echo esc_html($sDescription); ?></span>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * AJAX body shared by every "preview this QR design" endpoint.
	 *
	 * The four product-type callbacks differed only in their nonce name, $_POST key prefix and
	 * response key, all of which derive from the type key, so the whole body lives here once.
	 * The nonce is tpfw_ajax_settings_preview_{$sType}_qr, the fields {$sType}_qr_*, and the
	 * response always answers with sPreviewURL. Admin only. The image is written to the preview
	 * upload directory keyed by product id, so repeated previews overwrite rather than accumulate.
	 *
	 * @param string $sType Type key: 'pass', 'guestpass', 'ticket' or 'timeslot'.
	 * @return void Sends a JSON envelope through wp_send_json_success()/wp_send_json_error() and exits.
	 */
	public function render_qr_preview($sType)
	{
		$response = array();
		check_ajax_referer('tpfw_ajax_settings_preview_'.$sType.'_qr', 'security');
		if(!$this->user_can_manage())
		{
			$response['sMessage'] = __('Current user does not have admin privileges', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}

		// absint(), not the posted string: this is fed to get_attached_file(), which expects an
		// attachment id, and the loose "> 0" it replaces let any non-numeric string through.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer() above verified the nonce.
		$sLogoImageDir = null;
		$iLogoImageID  = absint($_POST[$sType.'_qr_image_id'] ?? 0);
		if($iLogoImageID > 0)
		{
			$sLogoImageDir = get_attached_file($iLogoImageID);
			if(!$sLogoImageDir || !file_exists($sLogoImageDir))
			{
				$sLogoImageDir = null;
			}
		}

		$post_id = absint($_POST['post_id'] ?? 0);
		if($post_id <= 0)
		{
			$response['sMessage'] = __('Could not find the post id', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}

		$sPreviewName = $post_id.'-'.$sType.'-preview';
		$this->create_qr_code(
			$sPreviewName,
			300,
			10,
			sanitize_text_field(wp_unslash($_POST[$sType.'_qr_text'] ?? '')),
			$sLogoImageDir,
			$this->hex_to_color(sanitize_text_field(wp_unslash($_POST[$sType.'_qr_hex_background_color'] ?? ''))),
			$this->hex_to_color(sanitize_text_field(wp_unslash($_POST[$sType.'_qr_hex_foreground_color'] ?? ''))),
			$this->hex_to_color(sanitize_text_field(wp_unslash($_POST[$sType.'_qr_hex_label_color'] ?? ''))),
			'preview',
			$sPreviewName,
		);

		$response['sPreviewURL'] = $this->get_file_url('preview', $sPreviewName);
		$response['sMessage']    = __('QR code preview image created', 'tickets-passes-for-woocommerce');
		wp_send_json_success($response);
	}

	/**
	 * Renders one "QR Appearance + Preview" product panel.
	 *
	 * The four QR tabs (pass, guest pass, ticket, timeslot) are the same two cards; the meta
	 * keys (_tpfw_{$sType}_qr_*), CSS hooks (tpfw-{$sType}-qr-*) and preview filename all
	 * derive from the type key, so the whole panel renders from here. Each value falls back
	 * to the shipped default when its meta key has never been saved.
	 *
	 * @param string $sType         Type key: 'pass', 'guestpass', 'ticket' or 'timeslot'.
	 * @param string $sPanelID      id attribute of the WooCommerce panel div.
	 * @param string $sCardModifier tpfw-card--{modifier} accent class: 'annual', 'guest', 'ticket' or 'timeslot'.
	 * @param string $sKicker       Translated small heading shown above the product name on the preview card.
	 * @return void
	 */
	public function render_qr_tab($sType, $sPanelID, $sCardModifier, $sKicker)
	{
		global $post;
		// The panel is only ever rendered inside the product edit screen, where $post exists.
		// Bailing rather than dereferencing null keeps a third-party call site from fataling.
		if(empty($post)) { return; }

		$aQRColorDefaults = array('label' => '#000000', 'background' => '#FFFFFF', 'foreground' => '#000000');
		// Named rather than repeated as literals: the reset buttons on these three rows hand the
		// same values back, so the shipped default has one place to change.
		$sBackgroundHexColor = $aQRColorDefaults['background'];
		$sForegroundHexColor = $aQRColorDefaults['foreground'];
		$sLabelHexColor      = $aQRColorDefaults['label'];
		$sLabelText          = '';
		$iLogoID             = '';
		$sPreviewImage       = '';

		if(file_exists($this->get_preview_qr_upload_dir().$post->ID.'-'.$sType.'-preview.webp'))
		{
			$sPreviewImage = $this->get_file_url('preview', $post->ID.'-'.$sType.'-preview');
		}

		if(get_post_meta($post->ID, '_tpfw_'.$sType.'_qr_background_color', true) != NULL && get_post_meta($post->ID, '_tpfw_'.$sType.'_qr_background_color', true) != "")
		{
			$sBackgroundHexColor = get_post_meta($post->ID, '_tpfw_'.$sType.'_qr_background_color', true);
		}

		if(get_post_meta($post->ID, '_tpfw_'.$sType.'_qr_foreground_color', true) != NULL && get_post_meta($post->ID, '_tpfw_'.$sType.'_qr_foreground_color', true) != "")
		{
			$sForegroundHexColor = get_post_meta($post->ID, '_tpfw_'.$sType.'_qr_foreground_color', true);
		}

		if(get_post_meta($post->ID, '_tpfw_'.$sType.'_qr_label_color', true) != NULL && get_post_meta($post->ID, '_tpfw_'.$sType.'_qr_label_color', true) != "")
		{
			$sLabelHexColor = get_post_meta($post->ID, '_tpfw_'.$sType.'_qr_label_color', true);
		}

		if(get_post_meta($post->ID, '_tpfw_'.$sType.'_qr_label_text', true) != NULL && get_post_meta($post->ID, '_tpfw_'.$sType.'_qr_label_text', true) != "")
		{
			$sLabelText = get_post_meta($post->ID, '_tpfw_'.$sType.'_qr_label_text', true);
		}

		if(get_post_meta($post->ID, '_tpfw_'.$sType.'_qr_logo_id', true) != NULL && get_post_meta($post->ID, '_tpfw_'.$sType.'_qr_logo_id', true) != "")
		{
			$iLogoID = get_post_meta($post->ID, '_tpfw_'.$sType.'_qr_logo_id', true);
		}
		?>
		<div id='<?php echo esc_attr($sPanelID); ?>' class='panel woocommerce_options_panel'>
			<div class="tpfw-qr-layout">
				<div class="tpfw-card tpfw-card--<?php echo esc_attr($sCardModifier); ?> tpfw-qr-fields-card">
					<div class="tpfw-card-header">
						<svg class="tpfw-card-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4z"/><path d="M14 14h2v2h-2zM18 18h2v2h-2z"/></svg>
						<h3 class="tpfw-card-title"><?php echo esc_html__('QR Appearance', 'tickets-passes-for-woocommerce'); ?></h3>
					</div>
					<div class="tpfw-card-body">
						<p class="form-field tpfw-qr-text-field">
							<label for="tpfw-<?php echo esc_attr($sType); ?>-qr-text"><?php echo esc_html__('Label Text', 'tickets-passes-for-woocommerce'); ?></label>
							<input type="text" id="tpfw-<?php echo esc_attr($sType); ?>-qr-text" name="_tpfw_<?php echo esc_attr($sType); ?>_qr_label_text" class="tpfw-<?php echo esc_attr($sType); ?>-qr-text" value="<?php echo esc_attr($sLabelText); ?>">
						</p>

						<p class="form-field">
							<label for="tpfw-<?php echo esc_attr($sType); ?>-label-color"><?php echo esc_html__('Label Color', 'tickets-passes-for-woocommerce'); ?></label>
							<span class="tpfw-color-control">
								<input type="text" id="tpfw-<?php echo esc_attr($sType); ?>-label-color" name="_tpfw_<?php echo esc_attr($sType); ?>_qr_label_color" class="tpfw-<?php echo esc_attr($sType); ?>-qr-label-hexcolor" value="<?php echo esc_attr($sLabelHexColor); ?>">
								<input type="color" class="tpfw-<?php echo esc_attr($sType); ?>-qr-label-colorpicker colorpick-eyedropper-input-trigger" value="<?php echo esc_attr($sLabelHexColor); ?>">
								<button type="button" class="button tpfw-color-reset" data-tpfw-default="<?php echo esc_attr($aQRColorDefaults['label']); ?>" title="<?php echo esc_attr__('Reset to the default color', 'tickets-passes-for-woocommerce'); ?>" aria-label="<?php echo esc_attr__('Reset to the default color', 'tickets-passes-for-woocommerce'); ?>"><span class="dashicons dashicons-image-rotate" aria-hidden="true"></span></button>
							</span>
						</p>

						<p class="form-field">
							<label for="tpfw-<?php echo esc_attr($sType); ?>-background-color"><?php echo esc_html__('Background Color', 'tickets-passes-for-woocommerce'); ?></label>
							<span class="tpfw-color-control">
								<input type="text" id="tpfw-<?php echo esc_attr($sType); ?>-background-color" name="_tpfw_<?php echo esc_attr($sType); ?>_qr_background_color" class="tpfw-<?php echo esc_attr($sType); ?>-qr-background-hexcolor" value="<?php echo esc_attr($sBackgroundHexColor); ?>">
								<input type="color" class="tpfw-<?php echo esc_attr($sType); ?>-qr-background-colorpicker colorpick-eyedropper-input-trigger" value="<?php echo esc_attr($sBackgroundHexColor); ?>">
								<button type="button" class="button tpfw-color-reset" data-tpfw-default="<?php echo esc_attr($aQRColorDefaults['background']); ?>" title="<?php echo esc_attr__('Reset to the default color', 'tickets-passes-for-woocommerce'); ?>" aria-label="<?php echo esc_attr__('Reset to the default color', 'tickets-passes-for-woocommerce'); ?>"><span class="dashicons dashicons-image-rotate" aria-hidden="true"></span></button>
							</span>
						</p>

						<p class="form-field">
							<label for="tpfw-<?php echo esc_attr($sType); ?>-foreground-color"><?php echo esc_html__('Foreground Color', 'tickets-passes-for-woocommerce'); ?></label>
							<span class="tpfw-color-control">
								<input type="text" id="tpfw-<?php echo esc_attr($sType); ?>-foreground-color" name="_tpfw_<?php echo esc_attr($sType); ?>_qr_foreground_color" class="tpfw-<?php echo esc_attr($sType); ?>-qr-foreground-hexcolor" value="<?php echo esc_attr($sForegroundHexColor); ?>">
								<input type="color" class="tpfw-<?php echo esc_attr($sType); ?>-qr-foreground-colorpicker colorpick-eyedropper-input-trigger" value="<?php echo esc_attr($sForegroundHexColor); ?>">
								<button type="button" class="button tpfw-color-reset" data-tpfw-default="<?php echo esc_attr($aQRColorDefaults['foreground']); ?>" title="<?php echo esc_attr__('Reset to the default color', 'tickets-passes-for-woocommerce'); ?>" aria-label="<?php echo esc_attr__('Reset to the default color', 'tickets-passes-for-woocommerce'); ?>"><span class="dashicons dashicons-image-rotate" aria-hidden="true"></span></button>
							</span>
						</p>
					</div>
				</div>

				<div class="tpfw-card tpfw-card--<?php echo esc_attr($sCardModifier); ?> tpfw-qr-preview-card">
					<div class="tpfw-card-header">
						<svg class="tpfw-card-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z"/><circle cx="12" cy="12" r="2.5"/></svg>
						<h3 class="tpfw-card-title"><?php echo esc_html__('Preview', 'tickets-passes-for-woocommerce'); ?></h3>
					</div>
					<div class="tpfw-card-body tpfw-card-body--preview">
						<div class="tpfw-qr-preview-col">
							<div class="tpfw-pass-card tpfw-<?php echo esc_attr($sType === 'pass' ? 'pass-own' : $sType.'-pass'); ?>-card" style="--tpfw-pass-fg: <?php echo esc_attr($sForegroundHexColor); ?>; --tpfw-pass-bg: <?php echo esc_attr($sBackgroundHexColor); ?>; --tpfw-pass-header-text: <?php echo esc_attr($this->get_contrast_text_color($sForegroundHexColor)); ?>;">
								<div class="tpfw-pass-card-header">
									<span class="tpfw-pass-card-kicker"><?php echo esc_html($sKicker); ?></span>
									<span class="tpfw-pass-card-name"><?php echo esc_html(get_the_title($post->ID)); ?></span>
								</div>
								<div class="tpfw-pass-card-qr">
									<img class="tpfw-<?php echo esc_attr($sType); ?>-qr-preview-image" src="<?php echo esc_url($sPreviewImage); ?>" alt="">
									<input type="text" style="display: none" class="tpfw-<?php echo esc_attr($sType); ?>-qr-logo-id" name="_tpfw_<?php echo esc_attr($sType); ?>_qr_logo_id" value="<?php echo esc_attr($iLogoID); ?>">
								</div>
							</div>

							<div class="tpfw-button-group">
								<input type="button" class="button button-primary tpfw-<?php echo esc_attr($sType); ?>-qr-upload" value="<?php echo esc_attr__('Upload Logo', 'tickets-passes-for-woocommerce'); ?>">
								<input type="button" class="button button-secondary tpfw-<?php echo esc_attr($sType); ?>-qr-remove" value="<?php echo esc_attr__('Remove Logo', 'tickets-passes-for-woocommerce'); ?>" style="<?php echo esc_attr(empty($iLogoID) ? 'display:none;' : ''); ?>">
								<input type="button" data-attr-postid="<?php echo esc_attr($post->ID); ?>" class="button button-primary tpfw-<?php echo esc_attr($sType); ?>-qr-preview" value="<?php echo esc_attr__('Preview QR', 'tickets-passes-for-woocommerce'); ?>">
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Persists one QR panel's five fields and regenerates its preview image.
	 *
	 * Empty fields delete their meta rather than storing an empty string, so the defaults in
	 * render_qr_tab() apply again. Per-type extras (the pass sales window, the guest pass
	 * profile image, the timeslot toggles) are not QR fields and stay in each product class's
	 * save callback next to its call to this.
	 *
	 * Note: the pass, guest pass and ticket copies this replaces saved the label colour
	 * conditional on the label *text* being non-empty - a copy-paste slip only the timeslot
	 * copy had fixed. The colour now follows its own value everywhere.
	 *
	 * @param string $sType   Type key: 'pass', 'guestpass', 'ticket' or 'timeslot'.
	 * @param int    $iPostID Product id being saved.
	 * @return void
	 */
	public function save_qr_tab($sType, $iPostID)
	{
		$aValues = array();
		foreach(array('label_text', 'label_color', 'background_color', 'foreground_color', 'logo_id') as $sField)
		{
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
			$sValue = sanitize_text_field(wp_unslash($_POST['_tpfw_'.$sType.'_qr_'.$sField] ?? ''));
			// Validate by shape: colours must be hex, the logo id an attachment id. A value that
			// fails is dropped rather than stored, so hex_to_color() never sees garbage later.
			if(str_ends_with($sField, '_color'))
			{
				$sValue = (string) sanitize_hex_color($sValue);
			}
			elseif($sField === 'logo_id')
			{
				$sValue = absint($sValue) > 0 ? (string) absint($sValue) : '';
			}
			if(!empty($sValue))
			{
				// Stored raw: escaping belongs at output. esc_attr() here used to write "&amp;"
				// into the label text, which the QR renderer then printed literally.
				update_post_meta($iPostID, '_tpfw_'.$sType.'_qr_'.$sField, $sValue);
			}
			else
			{
				delete_post_meta($iPostID, '_tpfw_'.$sType.'_qr_'.$sField);
			}
			$aValues[$sField] = $sValue;
		}

		$sLogoImageDir = null;
		if($aValues['logo_id'] != null && $aValues['logo_id'] != "" && $aValues['logo_id'] > 0)
		{
			$sLogoImageDir = get_attached_file($aValues['logo_id']);
			if(!file_exists($sLogoImageDir))
			{
				$sLogoImageDir = null;
			}
		}

		$sPreviewName = $iPostID.'-'.$sType.'-preview';
		$this->create_qr_code(
			'Random '.$sType.' Preview Data',
			300,
			10,
			$aValues['label_text'],
			$sLogoImageDir,
			$this->hex_to_color($aValues['background_color']),
			$this->hex_to_color($aValues['foreground_color']),
			$this->hex_to_color($aValues['label_color']),
			'preview',
			$sPreviewName,
		);

		$this->rewrite_issued_qr_images($iPostID, $sType);
	}

	/**
	 * Rewrites live QR files for one product after its appearance settings changed.
	 *
	 * Issued codes keep the same nano id and filename, so emails and My Account pick up the
	 * new image. Without this, a logo added on the product left already-issued tickets on the
	 * previous (often unscannable) file until each row was Reset by hand.
	 *
	 * @param int    $iPostID Product id.
	 * @param string $sType   QR panel key: ticket, timeslot, pass or guestpass.
	 * @return void
	 */
	private function rewrite_issued_qr_images($iPostID, $sType)
	{
		global $wpdb;
		$iPostID = (int) $iPostID;
		if($iPostID < 1 || empty($wpdb) || !isset(self::QR_META_PREFIXES[$sType]))
		{
			return;
		}

		if($sType === 'ticket')
		{
			$sTable = $wpdb->prefix . 'tpfw_tickets';
			$sSql   = $wpdb->prepare('SELECT nano_id FROM %i WHERE product_id = %d AND deleted IS NULL', $sTable, $iPostID);
		}
		elseif($sType === 'timeslot')
		{
			$sTable = $wpdb->prefix . 'tpfw_timeslot_tickets';
			$sSql   = $wpdb->prepare('SELECT nano_id FROM %i WHERE product_id = %d AND deleted IS NULL', $sTable, $iPostID);
		}
		elseif($sType === 'pass')
		{
			$sTable = $wpdb->prefix . 'tpfw_pass';
			$sSql   = $wpdb->prepare('SELECT nano_id FROM %i WHERE product_id = %d AND deleted IS NULL AND parent_nano_id_fk IS NULL', $sTable, $iPostID);
		}
		else
		{
			$sTable = $wpdb->prefix . 'tpfw_pass';
			$sSql   = $wpdb->prepare('SELECT nano_id FROM %i WHERE product_id = %d AND deleted IS NULL AND parent_nano_id_fk IS NOT NULL', $sTable, $iPostID);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sSql is the return value of $wpdb->prepare() above.
		$aRows = $wpdb->get_results($sSql);
		if(empty($aRows) || !is_array($aRows))
		{
			return;
		}

		foreach($aRows as $oRow)
		{
			if(empty($oRow->nano_id))
			{
				continue;
			}
			$this->write_scanner_qr($iPostID, $sType, (string) $oRow->nano_id);
		}
	}

	/**
	 * Builds the inline custom-property style the front end blocks are themed with.
	 *
	 * Each product type stores its own palette under its own prefix ('sTicketColor',
	 * 'sPassColor', 'sTimeslotColor'), but the properties they feed are the same, so the
	 * lookup lives here rather than being repeated per product type.
	 *
	 * @param string $sSettingPrefix General settings key prefix, e.g. 'sTicketColor'.
	 * @return string Ready for a style attribute.
	 */
	public function get_front_color_style($sSettingPrefix)
	{
		$aGeneralSettings = get_option('tpfw_general_settings_options');
		$sColorAccent     = !empty($aGeneralSettings[$sSettingPrefix.'Accent'])     ? $aGeneralSettings[$sSettingPrefix.'Accent']     : '#000000';
		$sColorText       = !empty($aGeneralSettings[$sSettingPrefix.'Text'])       ? $aGeneralSettings[$sSettingPrefix.'Text']       : '#000000';
		$sColorBorder     = !empty($aGeneralSettings[$sSettingPrefix.'Border'])     ? $aGeneralSettings[$sSettingPrefix.'Border']     : '#c3c4c7';
		$sColorBackground = !empty($aGeneralSettings[$sSettingPrefix.'Background']) ? $aGeneralSettings[$sSettingPrefix.'Background'] : '#ffffff';
		$sColorHint       = !empty($aGeneralSettings[$sSettingPrefix.'Hint'])       ? $aGeneralSettings[$sSettingPrefix.'Hint']       : '#646970';
		// Calendar-only, and only the Ticket tab exposes them so far; a prefix without them
		// falls back to the neutral pair the card design was drawn with.
		$sColorDayName    = !empty($aGeneralSettings[$sSettingPrefix.'DayName'])    ? $aGeneralSettings[$sSettingPrefix.'DayName']    : '#646970';
		$sColorNavTitle   = !empty($aGeneralSettings[$sSettingPrefix.'NavTitle'])   ? $aGeneralSettings[$sSettingPrefix.'NavTitle']   : '#000000';

		return sprintf(
			'--tpfw-accent:%s;--tpfw-accent-fg:%s;--tpfw-ink:%s;--tpfw-border:%s;--tpfw-bg:%s;--tpfw-hint:%s;--tpfw-dayname:%s;--tpfw-navtitle:%s;',
			esc_attr($sColorAccent),
			esc_attr($this->get_contrast_text_color($sColorAccent)),
			esc_attr($sColorText),
			esc_attr($sColorBorder),
			esc_attr($sColorBackground),
			esc_attr($sColorHint),
			esc_attr($sColorDayName),
			esc_attr($sColorNavTitle)
		);
	}
	/**
	 * Reads the Scanner / API settings.
	 *
	 * Falls back to sane traffic-light defaults (WordPress core's own notice-success/
	 * notice-warning/notice-error palette) instead of get_option()'s default `false`, since
	 * nothing seeds this option until an admin opens and saves the API Settings page at least
	 * once - without this, every scanner API response hits "Trying to access array offset on
	 * value of type bool" and returns sHexColor as null instead of a usable color.
	 *
	 * @return array bEnableAPI, bEnableScanner and the status_200/202/406 colours.
	 */
	public function get_api_settings_options($bDefaultsOnly = false)
	{
		$aDefaults = array(
			'bEnableAPI'     => 0,
			'bEnableScanner' => 1,
			'status_200'     => '#00a32a',
			'status_202'     => '#dba617',
			'status_406'     => '#d63638',
		);
		if($bDefaultsOnly)
		{
			return $aDefaults;
		}
		$aSaved = get_option('tpfw_api_settings_options', false);
		if(!is_array($aSaved))
		{
			return $aDefaults;
		}
		// Only the colours are back-filled. The two toggles are deliberately left absent when a
		// pre-toggle save never wrote them: is_api_enabled()/is_scanner_enabled() read a missing
		// key as "on", and merging a 0 in here would switch an upgraded site's API off.
		foreach(array('status_200', 'status_202', 'status_406') as $sColorKey)
		{
			if(empty($aSaved[$sColorKey]))
			{
				$aSaved[$sColorKey] = $aDefaults[$sColorKey];
			}
		}
		return $aSaved;
	}
	/**
	 * Whether the built-in scanner page should be served.
	 *
	 * Sites that saved their API settings before the scanner had its own switch have no
	 * bEnableScanner key at all, and for them the scanner was on - so a missing key has to keep
	 * meaning on, or their door would go dark on update.
	 *
	 * This used to return false whenever the API toggle was off, which made the two switches one
	 * switch: an admin who only wanted the built-in scanner had to expose the endpoints to
	 * external callers as well. The scanner page still calls the check-in endpoint, so the routes
	 * are registered for either toggle - what the API toggle controls is whether an outside
	 * caller may authenticate against them (see get_basic_auth_user()).
	 *
	 * @return bool
	 */
	public function is_scanner_enabled()
	{
		$aSettings = $this->get_api_settings_options();

		return !isset($aSettings['bEnableScanner']) || (bool)$aSettings['bEnableScanner'];
	}


	/**
	 * Whether external callers may use the check-in API.
	 *
	 * A missing key means on: only a site that has never saved this screen can be missing it, and
	 * the endpoints were unconditional before the toggle existed.
	 *
	 * @return bool
	 */
	public function is_api_enabled()
	{
		$aSettings = $this->get_api_settings_options();

		return !isset($aSettings['bEnableAPI']) || (bool)$aSettings['bEnableAPI'];
	}
	/**
	 * Loads WooCommerce's tooltip script and styles on this plugin's settings screens.
	 *
	 * wc_help_tip() renders WooCommerce's own ".woocommerce-help-tip" markup, but the hover
	 * bubble only works on screens WooCommerce recognises as its own (wc_get_screen_ids()) - our
	 * plugin's settings pages aren't in that list, so the icon would render inert without this.
	 * Reuses WooCommerce's bundled tipTip library/styles instead of shipping our own.
	 *
	 * @return void
	 */
	public function enqueue_help_tip_assets()
	{
		wp_enqueue_style('woocommerce_admin_styles');
		wp_enqueue_script($this->sPrefix.'help-tip-init', plugins_url('js/help-tip-init.js', __FILE__), array('jquery', 'wc-jquery-tiptip'), filemtime(dirname(__FILE__).'/js/help-tip-init.js'), true);
	}
	/**
	 * Returns the scanner user WordPress (or a plugin token) already authenticated, or false.
	 *
	 * Cookie + X-WP-Nonce and Application Passwords are owned by WordPress REST: this method
	 * reads wp_get_current_user() and only checks user_can_scan() (and Enable API for external
	 * Basic). It does not decode Authorization passwords or call wp_authenticate().
	 *
	 * X-TPFW-Scanner-Token is the plugin's own external method. The built-in /check-in/ page
	 * uses the cookie path; rest_cookie_check_errors() clears the user without a valid nonce.
	 *
	 * @param string $sBasicAuth     Raw Authorization header value, used only to detect Basic.
	 * @param string $sScannerToken  Optional X-TPFW-Scanner-Token header value.
	 * @return WP_User|false The user, when they are allowed to scan.
	 */
	public function get_basic_auth_user($sBasicAuth, $sScannerToken = '')
	{
		if($sScannerToken === '' && !empty($_SERVER['HTTP_X_TPFW_SCANNER_TOKEN']))
		{
			$sScannerToken = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_TPFW_SCANNER_TOKEN']));
		}

		$bHasBasic = (is_string($sBasicAuth) && preg_match('/^Basic\s+/i', $sBasicAuth) === 1)
			|| !empty($_SERVER['PHP_AUTH_USER']);

		$oTokenUser  = false;
		$bTokenValid = false;
		if($sScannerToken !== '')
		{
			$aHit = TPFW_Scanner_Tokens::verify_against_option($sScannerToken);
			if($aHit && !empty($aHit['user_id']) && function_exists('get_user_by'))
			{
				$oTokenUser  = get_user_by('id', (int)$aHit['user_id']);
				$bTokenValid = (bool)$oTokenUser;
			}
		}

		$oCurrent = (function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('wp_get_current_user'))
			? wp_get_current_user()
			: false;
		$bLoggedIn = is_object($oCurrent) && !empty($oCurrent->ID);

		return TPFW_Scanner_Auth::resolve(array(
			'sScannerToken'   => $sScannerToken,
			'bTokenValid'     => $bTokenValid,
			'oTokenUser'      => $oTokenUser,
			'bLoggedIn'       => $bLoggedIn,
			'oCurrentUser'    => $oCurrent,
			'bHasBasicHeader' => $bHasBasic,
			'bApiEnabled'     => $this->is_api_enabled(),
			'fnCanScan'       => array($this, 'user_can_scan'),
		));
	}
	/**
	 * Single definition of who is allowed to check people in, so the REST endpoints and the
	 * standalone scanner page cannot drift apart on who gets let through.
	 *
	 * @param WP_User|mixed $oUser Candidate user.
	 * @return bool True for the 'scanner' role or anyone holding manage_woocommerce.
	 */
	public function user_can_scan($oUser)
	{
		if(!$oUser || !is_a($oUser, 'WP_User') || !$oUser->ID) return false;

		// The dedicated door-staff role, or anyone who may already administer this plugin.
		// The second test is a capability rather than the literal 'administrator' role it
		// replaced: once the admin screens moved to manage_woocommerce, a Shop Manager could
		// cancel and resend tickets in wp-admin but was refused by the scanner at the door.
		// Testing the capability also keeps custom and membership-plugin roles working.
		return in_array('tpfw_scanner', $oUser->roles, true) || user_can($oUser, 'manage_woocommerce');
	}
	/**
	 * Last $iLimit successful check-ins performed BY $iUserID (the scanner/admin), most recent
	 * first.
	 *
	 * Pulls $iLimit candidates from each of the three stats tables rather than a SQL UNION across
	 * differently-shaped rows, then merges and trims in PHP - simple, and at $iLimit=50 the extra
	 * rows are not worth the query complexity.
	 *
	 * @param int $iUserID Scanner/admin whose scans to list.
	 * @param int $iLimit  Maximum rows returned.
	 * @return array Rows of sTypeKey, sHolderName and sCreatedUTC.
	 */
	public function get_scanner_checkin_history($iUserID, $iLimit = 50)
	{
		global $wpdb;

		$aTicketRows = $wpdb->get_results($wpdb->prepare(
			'SELECT `t`.user_id AS holder_user_id, `s`.created
			 FROM %i s
			 INNER JOIN %i t ON t.nano_id = s.nano_id_fk
			 WHERE s.user_id = %d AND s.deleted IS NULL
			 ORDER BY s.created DESC LIMIT %d',
			array(
				$wpdb->prefix . 'tpfw_tickets_stats',
				$wpdb->prefix . 'tpfw_tickets',$iUserID, $iLimit)
		));

		$aTimeslotRows = $wpdb->get_results($wpdb->prepare(
			'SELECT `t`.user_id AS holder_user_id, `s`.created
			 FROM %i s
			 INNER JOIN %i t ON t.nano_id = s.nano_id_fk
			 WHERE s.user_id = %d AND s.deleted IS NULL
			 ORDER BY s.created DESC LIMIT %d',
			array(
				$wpdb->prefix . 'tpfw_timeslot_tickets_stats',
				$wpdb->prefix . 'tpfw_timeslot_tickets',$iUserID, $iLimit)
		));

		$aPassRows = $wpdb->get_results($wpdb->prepare(
			'SELECT `p`.firstname, `p`.lastname, `p`.parent_nano_id_fk, `s`.created
			 FROM %i s
			 INNER JOIN %i p ON p.nano_id = s.nano_id_fk
			 WHERE s.user_id = %d AND s.deleted IS NULL
			 ORDER BY s.created DESC LIMIT %d',
			array(
				$wpdb->prefix . 'tpfw_pass_stats',
				$wpdb->prefix . 'tpfw_pass',$iUserID, $iLimit)
		));

		// Ticket and timeslot rows carry only the holder's user id, so each one used to cost a
		// get_user_by() query of its own - up to 100 queries to draw one 50-row list. cache_users()
		// fetches them all in one, and get_user_by() then reads them straight out of the cache.
		$aHolderIDs = array_column(array_merge($aTicketRows, $aTimeslotRows), 'holder_user_id');
		if(!empty($aHolderIDs)) { cache_users(array_unique($aHolderIDs)); }

		$aHistory = array();
		foreach($aTicketRows as $oRow)     { $aHistory[] = $this->format_checkin_history_row('ticket', $oRow); }
		foreach($aTimeslotRows as $oRow)   { $aHistory[] = $this->format_checkin_history_row('timeslot', $oRow); }
		foreach($aPassRows as $oRow)
		{
			$aHistory[] = $this->format_checkin_history_row(empty($oRow->parent_nano_id_fk) ? 'pass' : 'guestpass', $oRow);
		}

		usort($aHistory, function($a, $b) { return strtotime($b['sCreatedUTC']) <=> strtotime($a['sCreatedUTC']); });

		return array_slice($aHistory, 0, $iLimit);
	}
	/**
	 * Shapes one stats row into the structure the scanner's history list renders.
	 *
	 * sTypeKey is a stable, untranslated identifier - the scanner colour-codes and labels by it,
	 * so no translated type string needs to cross the wire.
	 *
	 * @param string $sTypeKey One of ticket, timeslot, pass, guestpass.
	 * @param object $oRow     Joined stats row; carries either a holder user id or a name.
	 * @return array sTypeKey, sHolderName, sCreatedUTC.
	 */
	private function format_checkin_history_row($sTypeKey, $oRow)
	{
		if(isset($oRow->firstname))
		{
			$sHolderName = trim($oRow->firstname . ' ' . $oRow->lastname);
		}
		else
		{
			$oHolder     = get_user_by('ID', $oRow->holder_user_id);
			$sHolderName = $oHolder ? trim($oHolder->first_name . ' ' . $oHolder->last_name) : '';
			if(empty($sHolderName) && $oHolder) { $sHolderName = $oHolder->display_name; }
		}
		if(empty($sHolderName)) { $sHolderName = __('Unknown', 'tickets-passes-for-woocommerce'); }

		return array(
			'sTypeKey'    => $sTypeKey,
			'sHolderName' => $sHolderName,
			// ISO-8601 carrying the site's real UTC offset. A bare "Y-m-d H:i:s" is read as
			// *device local* time by the scanner's Date parser, which showed a just-now
			// check-in as hours off on any device not in the venue's timezone.
			// The stats tables store venue wall-clock (current_time('mysql')), so the string
			// has to be parsed in wp_timezone() - reading it as UTC re-introduced the same
			// offset error this field exists to remove.
			'sCreatedUTC' => (new DateTime($oRow->created, wp_timezone()))->format('c'),
		);
	}
	/**
	 * Single definition of "may administer this plugin", used by every admin screen and every
	 * admin AJAX handler so the two cannot drift apart.
	 *
	 * manage_woocommerce, not manage_options: this is a WooCommerce extension, and a Shop Manager
	 * - the role WooCommerce creates precisely for running the shop - does not hold
	 * manage_options. Gating on that forced venues to hand out full Administrator accounts just so
	 * staff could resend a ticket, which is worse for security than widening this. Administrators
	 * hold manage_woocommerce too, so they are unaffected.
	 *
	 * Renamed from the former "user_is_admin": it returns true for users who are not
	 * administrators, and a security gate whose name misleads is how the next bug gets written.
	 *
	 * @return bool
	 */
	public function user_can_manage()
	{
		return current_user_can('manage_woocommerce');
	}
	/**
	 * Mints one nonce per admin-ajax action, keyed by action name.
	 *
	 * Every script used to be handed a single nonce for the shared action string
	 * "tpfw_admin_nonce" (or "tpfw_myaccount_nonce"), which meant one valid nonce
	 * unlocked all 40-odd endpoints. WordPress asks for the action to be "as specific
	 * as possible", so each endpoint now verifies "tpfw_" . its own action name and the
	 * enqueueing class asks for only the actions its own script actually posts.
	 *
	 * @param string[] $aActions admin-ajax action names, without the "tpfw_" prefix.
	 * @return array<string,string> action name => nonce, ready for wp_localize_script().
	 */
	public function get_ajax_nonces($aActions)
	{
		$aNonces = array();

		foreach($aActions as $sAction)
		{
			$aNonces[$sAction] = wp_create_nonce('tpfw_' . $sAction);
		}

		return $aNonces;
	}

	/**
	 * Per-key sanitizer shared by the three register_setting() callbacks below.
	 *
	 * Each option array mixes value shapes - checkbox flags, HEX colours, plain text,
	 * multi-line text and post-style markup - so a blanket sanitize_text_field() over the
	 * whole array is wrong in both directions: it would mangle a textarea's newlines and
	 * strip every tag out of an email body that is meant to contain markup. The map names
	 * the type of every key the plugin writes, and anything not in the map is dropped
	 * rather than stored, so a crafted options.php POST cannot smuggle extra keys in.
	 *
	 * @param mixed                 $mValue  Raw value WordPress is about to save.
	 * @param array<string,string>  $aSchema key => one of bool|hex|text|textarea|path|html.
	 * @return array Sanitized value; anything that is not already an array is discarded.
	 */
	private function sanitize_settings_by_schema($mValue, $aSchema)
	{
		if(!is_array($mValue)) return array();

		$aClean = array();
		foreach($aSchema as $sKey => $sType)
		{
			if(!array_key_exists($sKey, $mValue)) continue;

			$mRaw = $mValue[$sKey];
			if(is_array($mRaw)) continue;

			switch($sType)
			{
				case 'bool':
					$aClean[$sKey] = empty($mRaw) ? 0 : 1;
					break;

				case 'hex':
					// sanitize_hex_color() returns null for anything that is not #rgb/#rrggbb,
					// and the settings pages treat an empty colour as "use the default".
					$sHex = sanitize_hex_color((string) $mRaw);
					$aClean[$sKey] = ($sHex === null) ? '' : $sHex;
					break;

				case 'textarea':
					$aClean[$sKey] = sanitize_textarea_field((string) $mRaw);
					break;

				case 'path':
					// Same shape the Pass settings save handler enforces: a relative folder
					// under wp-content/uploads, with no traversal and a single trailing slash.
					$sPath = sanitize_text_field((string) $mRaw);
					$sPath = str_replace('..', '', $sPath);
					$sPath = preg_replace('#[^a-zA-Z0-9_\-/]#', '', $sPath);
					$aClean[$sKey] = ($sPath === '') ? '' : trim($sPath, '/').'/';
					break;

				case 'html':
					$aClean[$sKey] = wp_kses_post((string) $mRaw);
					break;

				default:
					$aClean[$sKey] = sanitize_text_field((string) $mRaw);
					break;
			}
		}

		return $aClean;
	}

	/**
	 * sanitize_callback for register_setting() on tpfw_general_settings_options.
	 *
	 * The General, Ticket, Timeslot Ticket and Pass tabs all keep their keys in this one
	 * option, so the schema covers all four.
	 *
	 * @param mixed $mValue Raw value WordPress is about to save.
	 * @return array
	 */
	public function sanitize_general_settings($mValue)
	{
		return $this->sanitize_settings_by_schema($mValue, array(
			// General tab.
			'bEnableAnalytics'         => 'bool',
			'sDateTimeformat'          => 'text',
			// Ticket tab.
			'bEnableTicketProduct'     => 'bool',
			'sTicketHintText'          => 'textarea',
			'sTicketColorAccent'       => 'hex',
			'sTicketColorText'         => 'hex',
			'sTicketColorBorder'       => 'hex',
			'sTicketColorBackground'   => 'hex',
			'sTicketColorHint'         => 'hex',
			'sTicketColorDayName'      => 'hex',
			'sTicketColorNavTitle'     => 'hex',
			// Timeslot Ticket tab.
			'bEnableTimeslotProduct'   => 'bool',
			'sTimeslotHintText'        => 'textarea',
			'sTimeslotColorAccent'     => 'hex',
			'sTimeslotColorText'       => 'hex',
			'sTimeslotColorBorder'     => 'hex',
			'sTimeslotColorBackground' => 'hex',
			'sTimeslotColorHint'       => 'hex',
			'sTimeslotColorDayName'    => 'hex',
			'sTimeslotColorNavTitle'   => 'hex',
			// Pass tab.
			'bEnablePassProduct'       => 'bool',
			'sPassHintText'            => 'textarea',
			'sProfileUploadPath'       => 'path',
			'sPassColorAccent'         => 'hex',
			'sPassColorText'           => 'hex',
			'sPassColorBorder'         => 'hex',
			'sPassColorBackground'     => 'hex',
			'sPassColorHint'           => 'hex',
		));
	}

	/**
	 * sanitize_callback for register_setting() on tpfw_email_settings_options.
	 *
	 * Subjects are plain text; the message bodies come from wp_editor and are meant to carry
	 * markup, so they go through wp_kses_post() rather than being flattened.
	 *
	 * @param mixed $mValue Raw value WordPress is about to save.
	 * @return array
	 */
	public function sanitize_email_settings($mValue)
	{
		return $this->sanitize_settings_by_schema($mValue, array(
			'resend_ticket_email_subject'             => 'text',
			'resend_pass_email_subject'               => 'text',
			'gifted_pass_email_new_user_subject'      => 'text',
			'gifted_pass_email_existing_user_subject' => 'text',
			'wc_confirmation_email_message'           => 'html',
			'wc_completed_email_message'              => 'html',
			'resend_ticket_email_message'             => 'html',
			'resend_pass_email_message'               => 'html',
			'gifted_pass_email_new_user_message'      => 'html',
			'gifted_pass_email_existing_user_message' => 'html',
		));
	}

	/**
	 * sanitize_callback for register_setting() on tpfw_api_settings_options.
	 *
	 * @param mixed $mValue Raw value WordPress is about to save.
	 * @return array
	 */
	public function sanitize_api_settings($mValue)
	{
		return $this->sanitize_settings_by_schema($mValue, array(
			'bEnableAPI'     => 'bool',
			'bEnableScanner' => 'bool',
			'status_406'     => 'hex',
			'status_202'     => 'hex',
			'status_200'     => 'hex',
		));
	}
	/**
	 * True on the classic Cart page AND on a WooCommerce Blocks Store API request (the Mini Cart
	 * drawer, Cart block, Checkout block - all fetch/mutate cart data via /wc/store/ REST routes
	 * rather than a normal page load, so is_cart() alone misses them).
	 *
	 * Used to keep quantity edits blocked everywhere the cart itself is shown, while leaving the
	 * single product page (where choosing a quantity > 1 is intended) unaffected.
	 *
	 * @return bool
	 */
	public function is_wc_cart_request()
	{
		if(is_cart()) return true;

		if(defined('REST_REQUEST') && REST_REQUEST && isset($_SERVER['REQUEST_URI']) && strpos(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')), '/wc/store/') !== false)
		{
			return true;
		}

		return false;
	}
	/**
	 * Generates a URL-safe random id, used as the unguessable secret in every QR code.
	 *
	 * Adapted from https://gist.github.com/ranaroussi/ec91bc1eec21703f7e7cf78ff748425f. Always
	 * backed by random_int()/random_bytes(), so the ids are cryptographically random rather than
	 * merely unique.
	 *
	 * @param int    $len      Length of the id.
	 * @param mixed  $entropy  'balanced' (default), true for full masked generation, or false.
	 * @param string $alphabet Characters to draw from.
	 * @return string
	 */
	public function generateNanoId(int $len = 21, mixed $entropy = 'balanced', string $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'): string
	{
		$id           = '';
		$alphabet_len = strlen($alphabet);
		// Strict comparisons: under loose comparison `true == 'balanced'` is true, so passing
		// true used to fall into the balanced branch instead of the full masked one.
		if ($entropy === false || $entropy === 'balanced')
		{
			$tolen = $entropy === 'balanced' ? $len / 2 : $len;
			for($i = 0; $i < $tolen; $i++)
			{
				$id .= $alphabet[random_int(0, $alphabet_len - 1)];
			}
			if($entropy !== 'balanced')
			{
				return $id;
			}
		}

		// Rejection sampling: mask each byte down to the next power of two above the alphabet
		// size and keep it only when it indexes a real character. $step bytes per round is
		// enough, on average, to fill the id; draw that many, not $len, or the loop reads past
		// the end of the buffer.
		$mask = (2 << (int) (log($alphabet_len - 1) / M_LN2)) - 1;
		$step = (int) ceil(1.6 * $mask * $len / $alphabet_len);
		while (true)
		{
			$bytes = unpack('C*', random_bytes($step));
			for ($i = 1; $i <= $step; $i++) 
			{
				$byte = $bytes[$i]&$mask;    
				if(isset($alphabet[$byte])) 
				{
					$id .= $alphabet[$byte];    
					if (strlen($id) === $len) 
					{
						return $id;
					}
				}
			}
		}
	}




	/**
	 * Map of file type => folder name inside the plugin's randomised upload base.
	 *
	 * The type string is what travels in the ?tpfw_file= URL, so it is deliberately short and
	 * fixed: TPFW_File_Access::serve_file() looks the folder up here rather than taking any part of a
	 * path from the request.
	 *
	 * @var array<string,string>
	 */
	const FILE_TYPE_FOLDERS = array(
		'qr'      => 'qr-codes',
		'guest'   => 'qr-guest',
		'preview' => 'qr-preview',
		'pdf'     => 'qr-pdf',
		'profile' => 'profile-images',
	);

	/**
	 * Trailing-slashed filesystem path of the plugin's upload base.
	 *
	 * The base carries a random suffix - uploads/tpfw-a3f9c81b04/ - generated once and kept in
	 * the tpfw_upload_slug option. Nothing the plugin stores is served as a static file any
	 * more (see TPFW_File_Access::serve_file()), so the suffix is not the access control; it is there so
	 * that on a host where the .htaccess below is ignored - nginx, IIS - a caller who somehow
	 * learns a nano id still has no path to guess.
	 *
	 * Self-healing: a site restored from a database-only backup, or one where the option was
	 * dropped, gets a fresh slug and a fresh folder rather than a fatal.
	 *
	 * @return string Trailing-slashed path.
	 */
	public function get_upload_base_dir()
	{
		$sSlug = get_option('tpfw_upload_slug');
		if(!is_string($sSlug) || !preg_match('/^[a-f0-9]{10}$/', $sSlug))
		{
			$sSlug = bin2hex(random_bytes(5));
			update_option('tpfw_upload_slug', $sSlug, false);
			$this->migrate_legacy_upload_dir($sSlug);
		}

		return trailingslashit(wp_upload_dir()['basedir']).'tpfw-'.$sSlug.'/';
	}

	/**
	 * Moves a pre-1.2.0 upload folder into the randomised one, once.
	 *
	 * Before 1.2.0 everything lived under uploads/tpfw/, and the five folders inside it are
	 * named exactly as they still are - only the parent changed. So the whole tree moves in a
	 * single rename() and every QR code, guest pass, PDF, preview and profile photo a shop has
	 * already issued keeps working. Without this an upgrade silently 404s all of them: the
	 * files stay where they were while every link the plugin emits points at the new folder.
	 *
	 * Only ever reached from the branch that mints a slug, which happens once per site.
	 *
	 * ponytail: a straight rename, so it stands down rather than merging if the target already
	 * exists. Merge the two trees file by file if a site turns up that has run both layouts.
	 *
	 * @param string $sSlug The slug that was just generated.
	 * @return void
	 */
	private function migrate_legacy_upload_dir($sSlug)
	{
		$sBaseDir = trailingslashit(wp_upload_dir()['basedir']);
		$sLegacy  = $sBaseDir.'tpfw';
		$sTarget  = $sBaseDir.'tpfw-'.$sSlug;

		if(!is_dir($sLegacy) || file_exists($sTarget))
		{
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- moving a plugin-owned folder inside wp_upload_dir(); WP_Filesystem is for credentialed user-initiated writes and has no atomic directory move.
		if(!rename($sLegacy, $sTarget))
		{
			return;
		}

		// Before 1.2.0 the profile setting was saved with 'tpfw/profile-images/' as its default,
		// so most upgrading sites hold that string. It points into the folder that has just
		// moved, and leaving it set would send every photo lookup back to the empty legacy path.
		// A path the owner deliberately chose is a different thing and is left alone.
		$aSettings = get_option('tpfw_general_settings_options');
		if(is_array($aSettings) && isset($aSettings['sProfileUploadPath'])
			&& trim((string)$aSettings['sProfileUploadPath'], '/') === 'tpfw/profile-images')
		{
			$aSettings['sProfileUploadPath'] = '';
			update_option('tpfw_general_settings_options', $aSettings);
		}
	}

	/**
	 * Filesystem path of one file-type folder, created and hardened on first use.
	 *
	 * @param string $sType One of the FILE_TYPE_FOLDERS keys.
	 * @return string Trailing-slashed path, or '' for an unknown type.
	 */
	public function get_upload_dir_for_type($sType)
	{
		if(!isset(self::FILE_TYPE_FOLDERS[$sType]))
		{
			return '';
		}

		// Per-request memo: a pass PDF download resolves this four times and every served
		// image once, each costing an option read, wp_upload_dir() and six stat calls.
		static $aResolved = array();
		if(isset($aResolved[$sType]))
		{
			return $aResolved[$sType];
		}

		// The profile folder is the one an owner can relocate from General Settings, so it is
		// resolved separately and may sit outside the randomised base entirely.
		$sDir = ($sType === 'profile') ? $this->get_profile_image_upload_dir() : $this->get_upload_base_dir().self::FILE_TYPE_FOLDERS[$sType].'/';

		// The base gets guarded as well as the folder inside it, so a host that honours
		// .htaccess denies the whole tree rather than each leaf individually.
		$sBase = $this->get_upload_base_dir();
		if(!file_exists($sBase))
		{
			wp_mkdir_p($sBase);
		}

		if(!file_exists($sDir))
		{
			wp_mkdir_p($sDir);
		}

		// Deliberately not limited to the folder-creation branch above. A folder that already
		// existed - one an owner pointed the profile setting at, or one left by an earlier
		// version - would otherwise never get its guards, and a deleted .htaccess would never
		// come back. protect_upload_dir() writes only what is missing, so the steady-state cost
		// is a pair of file_exists() calls.
		$this->protect_upload_dir($sBase);
		$this->protect_upload_dir($sDir);

		$aResolved[$sType] = $sDir;
		return $sDir;
	}

	/**
	 * Writes the belt-and-braces static-access guards into an upload folder.
	 *
	 * These are a second layer, not the mechanism: access control lives in
	 * TPFW_File_Access::serve_file(). Apache and LiteSpeed honour the .htaccess; nginx and IIS ignore it
	 * and fall back to the randomised base path plus the index.php that stops directory
	 * listing. The Header directive is guarded because mod_headers is not guaranteed - an
	 * unguarded one is a 500 on the whole site.
	 *
	 * @param string $sDir Trailing-slashed path of an existing folder.
	 * @return void
	 */
	public function protect_upload_dir($sDir)
	{
		if(!is_dir($sDir))
		{
			return;
		}

		if(!file_exists($sDir.'.htaccess'))
		{
			$sRules = "Options -Indexes\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n"
				. "<IfModule mod_headers.c>\n\tHeader set X-Robots-Tag \"noindex, nofollow\"\n</IfModule>\n";
			file_put_contents($sDir.'.htaccess', $sRules); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a plugin-owned guard file into wp_upload_dir(); WP_Filesystem is for credentialed user-initiated writes.
		}

		if(!file_exists($sDir.'index.php'))
		{
			file_put_contents($sDir.'index.php', "<?php\n// Silence is golden.\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- see above.
		}
	}

	/**
	 * The HMAC key behind every signed file URL, created once and kept in an option.
	 *
	 * Separate from the WordPress salts on purpose: rotating this invalidates every QR link the
	 * plugin has ever emailed, so it must not be tied to a key an owner may rotate for an
	 * unrelated reason.
	 *
	 * @return string
	 */
	private function get_file_secret()
	{
		$sSecret = get_option('tpfw_file_secret');
		if(!is_string($sSecret) || strlen($sSecret) < 32)
		{
			$sSecret = bin2hex(random_bytes(32));
			update_option('tpfw_file_secret', $sSecret, false);
		}

		return $sSecret;
	}

	/**
	 * Signs a file request.
	 *
	 * @param string $sType   File type key.
	 * @param string $sName   Base filename without extension - a nano id, or a preview name.
	 * @param int    $iExpiry Unix timestamp the token stops working at; 0 never expires.
	 * @return string Hex HMAC.
	 */
	public function sign_file_token($sType, $sName, $iExpiry = 0)
	{
		return TPFW_File_Token::sign($sType, $sName, $iExpiry, $this->get_file_secret());
	}

	/**
	 * Constant-time check of a signed file request.
	 *
	 * @param string $sType   File type key.
	 * @param string $sName   Base filename without extension.
	 * @param int    $iExpiry Expiry carried in the URL.
	 * @param string $sToken  Token carried in the URL.
	 * @return bool
	 */
	public function verify_file_token($sType, $sName, $iExpiry, $sToken)
	{
		return TPFW_File_Token::verify($sType, $sName, $iExpiry, $sToken, $this->get_file_secret());
	}

	/**
	 * URL that serves one stored file through TPFW_File_Access::serve_file().
	 *
	 * Two shapes come out of here. With $bSigned the URL carries an HMAC and needs no session,
	 * which is what a QR image in an email requires - the mail client has no cookies. Without
	 * it the URL is bare and serve_file() resolves the caller's own login, which is what the My
	 * Account PDF and profile links use, so that a forwarded link is worth nothing.
	 *
	 * @param string $sType    File type key.
	 * @param string $sName    Base filename without extension.
	 * @param string $sExt     Extension without the dot, for the download filename only.
	 * @param bool   $bSigned  Whether to append an HMAC.
	 * @param int    $iTTL     Seconds the signature stays valid; 0 never expires.
	 * @return string
	 */
	public function get_file_url($sType, $sName, $sExt = 'webp', $bSigned = false, $iTTL = 0)
	{
		$aArgs = array(
			'tpfw_file' => $sType,
			'id'        => $sName,
			'ext'       => $sExt,
		);

		if($bSigned)
		{
			$iExpiry      = ($iTTL > 0) ? time() + (int)$iTTL : 0;
			$aArgs['exp'] = $iExpiry;
			$aArgs['t']   = $this->sign_file_token($sType, $sName, $iExpiry);
		}

		return add_query_arg($aArgs, home_url('/'));
	}

	/**
	 * Filesystem path of the folder holding the settings-screen QR previews.
	 *
	 * @return string Trailing-slashed path.
	 */
	public function get_preview_qr_upload_dir()
	{
		return $this->get_upload_dir_for_type('preview');
	}

	/**
	 * URL of a pass holder's profile photo, or the shared placeholder.
	 *
	 * The signed form is used here because the one caller is the scanner check-in response, and
	 * the scanner may be an external app authenticating with Basic Auth - its <img> tag carries
	 * no login cookie, so an ownership check would leave the door staff looking at a broken
	 * image. The signature is short-lived for the same reason it exists: the URL is a bearer
	 * token, and a door scan does not need one that outlives the shift.
	 *
	 * @param string $sNanoID           Pass nano id the image is named after.
	 * @param string $sProfileImageType File extension of the uploaded image; empty when there is none.
	 * @return string
	 */
	public function get_profile_photo_url($sNanoID, $sProfileImageType)
	{
		if(!empty($sProfileImageType))
		{
			return $this->get_file_url('profile', $sNanoID, $sProfileImageType, true, 4 * HOUR_IN_SECONDS);
		}

		return TPFW_PLUGIN_URL . 'images/avatar-placeholder.webp';
	}

	/**
	 * Filesystem path of the profile image folder, honouring the custom path in General Settings.
	 *
	 * @return string Trailing-slashed path.
	 */
	public function get_profile_image_upload_dir()
	{
		return TPFW_File_Paths::profile_dir($this->get_upload_base_dir());
	}

	/**
	 * Filesystem path of the ticket/pass QR code folder.
	 *
	 * @return string Trailing-slashed path.
	 */
	public function get_qr_upload_dir()
	{
		return $this->get_upload_dir_for_type('qr');
	}

	/**
	 * Filesystem path of the guest pass QR code folder.
	 *
	 * @return string Trailing-slashed path.
	 */
	public function get_guest_upload_dir()
	{
		return $this->get_upload_dir_for_type('guest');
	}

	/**
	 * Filesystem path of the generated PDF folder.
	 *
	 * @return string Trailing-slashed path.
	 */
	public function get_pdf_upload_dir()
	{
		return $this->get_upload_dir_for_type('pdf');
	}

	/**
	 * Runs a check-in under a MySQL named lock keyed on the nano id.
	 *
	 * Every check-in below does: read the usage rows -> compare the count to max_uses (and the
	 * newest row to the cooldown) -> insert a usage row. Two scanners hitting the same nano_id at
	 * the same moment both read the old count, both pass the check, and both insert - so a
	 * single-use ticket admits two people, and the cooldown is bypassed the same way. That is
	 * exploitable on purpose: hand someone a screenshot of the QR and scan at two doors at once.
	 *
	 * A MySQL named lock keyed on the nano_id serialises the whole read-check-insert. It is
	 * per-code, so unrelated scans never wait on each other, and it needs no schema change and no
	 * transaction handling wrapped around wpdb (which would also have to cover the caller's
	 * queries to be correct).
	 *
	 * GET_LOCK returns '1' when held, '0' on timeout, and NULL if the server has no such function.
	 * Anything other than '1' refuses the scan: proceeding without the lock is how two doors
	 * admit the same single-use code.
	 *
	 * @param wpdb     $wpdb      Database handle the check-in will use.
	 * @param string   $sNanoID   Code being checked in; the lock is keyed on it.
	 * @param callable $fnCheckin The check-in body to run while holding the lock.
	 * @return WP_REST_Response|array The check-in result, or a 202 when the lock was not held.
	 */
	public function with_checkin_lock($wpdb, $sNanoID, $fnCheckin)
	{
		$oLock = TPFW_Named_Lock::acquire($wpdb, 'tpfw_checkin_'.$sNanoID, 5);

		if(!$oLock->held())
		{
			$aSettingsOptions = $this->get_api_settings_options();
			$aData = array(
				'sMessage'  => __('This code is already being checked in on another scanner. Try again in a moment.', 'tickets-passes-for-woocommerce'),
				'sHexColor' => $aSettingsOptions['status_202'],
			);
			return new WP_REST_Response($aData, 202);
		}

		try
		{
			$mResult = call_user_func($fnCheckin);
		}
		finally
		{
			$oLock->release();
		}

		return $mResult;
	}


	/**
	 * Everything the four check-in flows do NOT have in common.
	 *
	 * Ticket, timeslot ticket, pass and guest pass each carried their own copy of the same
	 * read-check-insert - which is how the same cooldown bug came to be fixed three times and
	 * how their HTTP statuses drifted apart. They differ only in the four values below, so this
	 * table is the difference and checkin_row() is the single implementation.
	 *
	 * @param string $sType One of ticket, timeslot, pass, guestpass.
	 * @return array|null sStatsTable, sProductClass (null skips the check), sCooldownMeta and
	 *                    sNoun, or null for a type this method does not know.
	 */
	private function get_checkin_rules($sType)
	{
		$aRules = array(
			'ticket' => array(
				'sStatsTable'   => 'tpfw_tickets_stats',
				'sProductClass' => 'TPFW_Product_Ticket',
				'sCooldownMeta' => '_tpfw_ticket_cooldown_sec',
				'sNoun'         => __('Ticket', 'tickets-passes-for-woocommerce'),
			),
			'timeslot' => array(
				'sStatsTable'   => 'tpfw_timeslot_tickets_stats',
				'sProductClass' => 'TPFW_Product_Timeslot_Ticket',
				'sCooldownMeta' => '_tpfw_timeslot_ticket_cooldown_sec',
				'sNoun'         => __('Timeslot ticket', 'tickets-passes-for-woocommerce'),
			),
			'pass' => array(
				'sStatsTable'   => 'tpfw_pass_stats',
				'sProductClass' => 'TPFW_Product_Pass',
				'sCooldownMeta' => '_tpfw_pass_cooldown_sec',
				'sNoun'         => __('Pass', 'tickets-passes-for-woocommerce'),
			),
			// A guest pass hangs off a parent pass rather than being a product of its own, so
			// there is no product class to insist on: product_id points at the parent's product,
			// which was already the right type when the parent was issued.
			'guestpass' => array(
				'sStatsTable'   => 'tpfw_pass_stats',
				'sProductClass' => null,
				'sCooldownMeta' => '_tpfw_guestpass_cooldown_sec',
				'sNoun'         => __('Guest pass', 'tickets-passes-for-woocommerce'),
			),
		);

		return $aRules[$sType] ?? null;
	}



	/**
	 * How many times a code has been used, and when it was last used.
	 *
	 * COUNT + MAX rather than fetching the rows: every scan at the door used to pull the code's
	 * entire scan history across the wire only to count it and read one timestamp off the top,
	 * which on a pass with a high max_uses is a season's worth of rows per scan.
	 *
	 * MAX(created) rather than the newest row by id - created is written at insert time, so the
	 * two agree, and this way one aggregate answers both questions.
	 *
	 * @param wpdb   $wpdb        Database handle.
	 * @param string $sStatsTable Stats table name, without the site's table prefix.
	 * @param string $sNanoID     Code to count uses for.
	 * @return array iUses and sLastUsed (null when never used).
	 */
	private function get_checkin_usage($wpdb, $sStatsTable, $sNanoID)
	{
		$oPrepared = $wpdb->prepare(
			'SELECT COUNT(*) AS iUses, MAX(created) AS sLastUsed FROM %i WHERE nano_id_fk = %s AND deleted IS NULL;',
			array(
				$wpdb->prefix . $sStatsTable,
				$sNanoID,
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oPrepared is the return value of $wpdb->prepare() above.
		$oRow = $wpdb->get_row($oPrepared);

		return array(
			'iUses'     => $oRow ? (int)$oRow->iUses : 0,
			'sLastUsed' => ($oRow && $oRow->sLastUsed !== null) ? $oRow->sLastUsed : null,
		);
	}



	/**
	 * Checks a code in, serialised against concurrent scans of the same code.
	 *
	 * This is the entry point for every check-in - the door scanner, the REST API and the three
	 * admin dashboards all arrive here.
	 *
	 * @param wpdb   $wpdb    Database handle.
	 * @param string $sType   One of ticket, timeslot, pass, guestpass.
	 * @param object $oRow    Row from the matching table; needs nano_id, product_id, max_uses
	 *                        and the valid_from/valid_to window.
	 * @param int    $iUserID Scanner/admin performing the check-in.
	 * @param bool   $bManual True to skip the validity-window check (admin screens).
	 * @return WP_REST_Response|array Refusals are a response, success is a data array.
	 */
	public function checkin($wpdb, $sType, $oRow, $iUserID, $bManual = false)
	{
		$oSelf = $this;
		return $this->with_checkin_lock($wpdb, $oRow->nano_id, function() use ($oSelf, $wpdb, $sType, $oRow, $iUserID, $bManual) {
			return $oSelf->checkin_row($wpdb, $sType, $oRow, $iUserID, $bManual);
		});
	}


	/**
	 * @see checkin() - kept as a named entry point because the dashboards and the REST route
	 *      read better calling the type they mean.
	 *
	 * @param wpdb   $wpdb          Database handle.
	 * @param object $oTicketResult Row from the tickets table.
	 * @param int    $iUserID       Scanner/admin performing the check-in.
	 * @param bool   $bManual       True to skip the validity-window check.
	 * @return WP_REST_Response|array
	 */
	public function checkin_ticket($wpdb, $oTicketResult, $iUserID, $bManual = false)
	{
		return $this->checkin($wpdb, 'ticket', $oTicketResult, $iUserID, $bManual);
	}


	/**
	 * @see checkin()
	 *
	 * @param wpdb   $wpdb                  Database handle.
	 * @param object $oTimeslotTicketResult Row from the timeslot_tickets table.
	 * @param int    $iUserID               Scanner/admin performing the check-in.
	 * @param bool   $bManual               True to skip the validity-window check.
	 * @return WP_REST_Response|array
	 */
	public function checkin_timeslot_ticket($wpdb, $oTimeslotTicketResult, $iUserID, $bManual = false)
	{
		return $this->checkin($wpdb, 'timeslot', $oTimeslotTicketResult, $iUserID, $bManual);
	}


	/**
	 * @see checkin()
	 *
	 * @param wpdb   $wpdb        Database handle.
	 * @param object $oPassResult Row from the pass table.
	 * @param int    $iUserID     Scanner/admin performing the check-in.
	 * @param bool   $bManual     True to skip the validity-window check.
	 * @return WP_REST_Response|array
	 */
	public function checkin_pass($wpdb, $oPassResult, $iUserID, $bManual = false)
	{
		return $this->checkin($wpdb, 'pass', $oPassResult, $iUserID, $bManual);
	}


	/**
	 * @see checkin()
	 *
	 * @param wpdb   $wpdb             Database handle.
	 * @param object $oGuestPassResult Row from the pass table carrying a parent_nano_id_fk.
	 * @param int    $iUserID          Scanner/admin performing the check-in.
	 * @param bool   $bManual          True to skip the validity-window check.
	 * @return WP_REST_Response|array
	 */
	public function checkin_guest_pass($wpdb, $oGuestPassResult, $iUserID, $bManual = false)
	{
		return $this->checkin($wpdb, 'guestpass', $oGuestPassResult, $iUserID, $bManual);
	}



	/**
	 * The check-in itself, run while holding the per-code lock; call checkin() instead.
	 *
	 * Validity window, product type, max uses and cooldown, then one row in the stats table.
	 * Nothing here writes until every check has passed, so a refusal never consumes a use.
	 *
	 * Refusals answer 202 - "refused for a reason the door staff needs to read", which is what
	 * the API reference on the settings screen has always documented. Tickets and timeslot
	 * tickets used to answer 401 for the very cases a pass answered 202 for, so an app written
	 * against that reference read a used-up ticket as an authentication failure.
	 *
	 * @param wpdb   $wpdb    Database handle.
	 * @param string $sType   One of ticket, timeslot, pass, guestpass.
	 * @param object $oRow    Row being checked in.
	 * @param int    $iUserID Scanner/admin performing the check-in.
	 * @param bool   $bManual True to skip the validity-window check.
	 * @return WP_REST_Response|array
	 */
	private function checkin_row($wpdb, $sType, $oRow, $iUserID, $bManual)
	{
		$aRules    = $this->get_checkin_rules($sType);
		$aSettings = $this->get_api_settings_options();

		// An unknown type has no stats table to write to and no cooldown to read, so there is no
		// safe way to "mostly" check it in - refuse rather than let the missing rules read as
		// "no rules apply".
		if($aRules === null)
		{
			return new WP_REST_Response(array(
				'sMessage'  => __('The scanned code does not belong to a ticket or pass product', 'tickets-passes-for-woocommerce'),
				'sHexColor' => $aSettings['status_406'],
			), 401);
		}

		$sNoun     = $aRules['sNoun'];

		if($sType === 'guestpass')
		{
			$oParent = !empty($oRow->parent_nano_id_fk)
				? $wpdb->get_row($wpdb->prepare(
					'SELECT nano_id FROM %i WHERE nano_id = %s AND deleted IS NULL LIMIT 1;',
					$wpdb->prefix.'tpfw_pass', $oRow->parent_nano_id_fk
				))
				: null;
			$iParentUses = 0;
			if($oParent)
			{
				$aParentUsage = $this->get_checkin_usage($wpdb, 'tpfw_pass_stats', $oParent->nano_id);
				$iParentUses  = $aParentUsage['iUses'];
			}
			$sGate = TPFW_Guest_Pass_Issuer::may_checkin($oRow, (bool)$oParent, $iParentUses);
			if($sGate !== 'ok')
			{
				$sMessage = ($sGate === 'no_parent')
					? __('Parent Pass could not be found with the given id', 'tickets-passes-for-woocommerce')
					: __('Guest pass is not valid until the holder has checked in', 'tickets-passes-for-woocommerce');
				return new WP_REST_Response(array(
					'sMessage'  => $sMessage,
					'sHexColor' => ($sGate === 'no_parent') ? $aSettings['status_406'] : $aSettings['status_202'],
				), ($sGate === 'no_parent') ? 401 : 202);
			}
		}

		if(!$bManual && (empty($oRow->valid_from) || empty($oRow->valid_to) || strtotime($oRow->valid_from) > current_time('timestamp') || current_time('timestamp') > strtotime($oRow->valid_to)))
		{
			return new WP_REST_Response(array(
				/* translators: 1: ticket, timeslot ticket, pass or guest pass. 2: start of the validity window. 3: end of it. */
				'sMessage'  => sprintf(__('%1$s is outside its validity window (%2$s - %3$s)', 'tickets-passes-for-woocommerce'), $sNoun, $oRow->valid_from, $oRow->valid_to),
				'sHexColor' => $aSettings['status_202'],
			), 202);
		}

		// 401 rather than 202: a code whose product is the wrong type is not something staff can
		// act on at the door, it is the same class of problem as a code that does not exist.
		if($aRules['sProductClass'] !== null && !is_a(wc_get_product($oRow->product_id), $aRules['sProductClass']))
		{
			return new WP_REST_Response(array(
				'sMessage'  => __('The scanned code does not belong to a ticket or pass product', 'tickets-passes-for-woocommerce'),
				'sHexColor' => $aSettings['status_406'],
			), 401);
		}

		$aUsage = $this->get_checkin_usage($wpdb, $aRules['sStatsTable'], $oRow->nano_id);

		if($aUsage['iUses'] >= (int)$oRow->max_uses)
		{
			return new WP_REST_Response(array(
				/* translators: 1: ticket, timeslot ticket, pass or guest pass. 2: the maximum number of uses. */
				'sMessage'  => sprintf(__('%1$s has already reached its maximum uses (%2$d)', 'tickets-passes-for-woocommerce'), $sNoun, (int)$oRow->max_uses),
				'sHexColor' => $aSettings['status_202'],
			), 202);
		}

		// (int) on the meta, not is_int(): post meta always comes back as a string, so the
		// is_int() guard this replaced was never true and the cooldown simply never applied.
		$iCooldownSec = (int)get_post_meta($oRow->product_id, $aRules['sCooldownMeta'], true);
		if($iCooldownSec > 0 && $aUsage['sLastUsed'] !== null)
		{
			// When the cooldown ends, not when the last scan was - the scanner used to show
			// staff a moment that had already passed.
			$iCooldownOver = strtotime($aUsage['sLastUsed']) + $iCooldownSec;
			if($iCooldownOver > current_time('timestamp'))
			{
				return new WP_REST_Response(array(
					'iCooldown'     => $iCooldownSec,
					'iCooldownOver' => gmdate($this->get_datetime_format('datetime'), $iCooldownOver),
					/* translators: %s: ticket, timeslot ticket, pass or guest pass. */
					'sMessage'      => sprintf(__('%s is on cooldown after a previous check-in', 'tickets-passes-for-woocommerce'), $sNoun),
					'sHexColor'     => $aSettings['status_202'],
				), 202);
			}
		}

		$oAddUsagePrepared = $wpdb->prepare(
			'INSERT INTO %i (nano_id_fk, user_id, created, updated)
			VALUES (%s, %d, %s, %s);',
			array(
				$wpdb->prefix . $aRules['sStatsTable'],
				$oRow->nano_id,
				$iUserID,
				current_time('mysql'),
				current_time('mysql'),
			)
		);

		// $wpdb->insert_id still holds the id of an earlier successful insert in the same
		// request, so it is not a failure signal - query() returning false is.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oAddUsagePrepared is the return value of $wpdb->prepare() above.
		if($wpdb->query($oAddUsagePrepared) === false)
		{
			return new WP_REST_Response(array(
				'sMessage'  => __('The check-in could not be recorded - nobody was let through, try again', 'tickets-passes-for-woocommerce'),
				'sHexColor' => $aSettings['status_406'],
			), 401);
		}

		if($sType === 'pass')
		{
			$this->activate_guest_passes($wpdb, $oRow);
		}

		$aData = TPFW_Checkin_Payload::allowlist(array(
			/* translators: %s: ticket, timeslot ticket, pass or guest pass. */
			'sMessage'       => sprintf(__('%s is valid and checked in', 'tickets-passes-for-woocommerce'), $sNoun),
			'sHexColor'      => $aSettings['status_200'],
			'sType'          => $sType,
			'sHolderName'    => TPFW_Checkin_Payload::holder_name($oRow),
			'max_uses'       => $oRow->max_uses,
			'iUsesRemaining' => max(0, (int)$oRow->max_uses - $aUsage['iUses'] - 1),
			'valid_from'     => $oRow->valid_from,
			'valid_to'       => $oRow->valid_to,
			'sPhotoURL'      => ($sType === 'pass' || $sType === 'guestpass')
				? $this->get_profile_photo_url($oRow->nano_id, $oRow->profile_image_type)
				: '',
		));

		return $aData;
	}



	/**
	 * Opens the validity window of every guest pass issued under a pass, at the moment the pass
	 * itself is checked in - so a guest pass cannot be walked in ahead of its holder.
	 *
	 * Does nothing unless the product both enables guest passes and gives them a duration.
	 *
	 * @param wpdb   $wpdb        Database handle.
	 * @param object $oPassResult The parent pass row that was just checked in.
	 * @return void
	 */
	private function activate_guest_passes($wpdb, $oPassResult)
	{
		if(get_post_meta($oPassResult->product_id, '_tpfw_pass_guest_pass_enable', true) !== 'yes') return;

		$iValidDuration = (int)get_post_meta($oPassResult->product_id, '_tpfw_pass_guest_pass_valid_duration', true);
		if($iValidDuration <= 0) return;

		$oPrepared = $wpdb->prepare(
			'UPDATE %i SET valid_from = %s, valid_to = %s, updated = %s
			WHERE deleted IS NULL AND parent_nano_id_fk = %s AND valid_from IS NULL;',
			array(
				$wpdb->prefix . 'tpfw_pass',
				current_time('mysql'),
				gmdate('Y-m-d H:i:s', current_time('timestamp') + $iValidDuration),
				current_time('mysql'),
				$oPassResult->nano_id,
			)
		);

		// query(), not get_results(): this is an UPDATE, and asking a result set back from one
		// only throws away the affected-row count that would say whether it did anything.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oPrepared is the return value of $wpdb->prepare() above.
		$wpdb->query($oPrepared);
	}

	/**
	 * Tops up the concrete timeslots generated from each recurring series.
	 *
	 * Runs both from cron (no argument - every series whose end date is still ahead and that has
	 * not been touched in the last 30 minutes) and from the admin "force create" button for one
	 * series. Each series is filled forward to the product's configured number of weeks ahead of
	 * today, never past the series end date; slots that already exist (generated or manual) are
	 * skipped, and strays left behind by a moved week window are retired unless they sold.
	 *
	 * @param string|int|null $sRecurringTimeslotID Series to fill, or null for the cron sweep.
	 * @return void
	 */
	public function create_recurring_timeslots($sRecurringTimeslotID = NULL)
	{
		global $wpdb;
		$sTimeslotTableName           = $wpdb->prefix . "tpfw_timeslots";
		$sRecurringTimeslotsTableName = $wpdb->prefix.'tpfw_timeslots_recurring';
		$sCurrentDatetime             = current_time('mysql');
		if($sRecurringTimeslotID == NULL)
		{
			$sRecurringTimeslotsSQL       = $wpdb->prepare('	SELECT * FROM %i				
																WHERE deleted IS NULL AND end >= %s AND updated <= %s;', $sRecurringTimeslotsTableName, 
															current_time('Y-m-d'),
															gmdate('Y-m-d H:i:s', strtotime('-30 minutes', current_time('timestamp')))				
			);  
		}
		else
		{
			// This is the "force create children for THIS recurring timeslot" path - "id <= %s"
			// silently rebuilt every recurring timeslot with a lower id as well. The id is a
			// nano id, so it has to be matched as the string it is: %d cast it to 0 and the
			// button quietly matched nothing at all.
			$sRecurringTimeslotsSQL       = $wpdb->prepare('	SELECT * FROM %i
																WHERE deleted IS NULL AND end >= %s AND id = %s;', $sRecurringTimeslotsTableName,
															current_time('Y-m-d'),
															$sRecurringTimeslotID
			);
		}
		                                            
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sRecurringTimeslotsSQL is the return value of $wpdb->prepare() above.
		$aRecurringTimeslotsResult = $wpdb->get_results($sRecurringTimeslotsSQL);

		if(!empty($aRecurringTimeslotsResult))
		{
			foreach($aRecurringTimeslotsResult as $iRecurringTimeslotKey => $oRecurringTimeslot)
			{
				$_timeslot_ticket_recurring_enable = get_post_meta($oRecurringTimeslot->product_id, '_tpfw_timeslot_ticket_recurring_enable', true); 
				if($_timeslot_ticket_recurring_enable != 'yes') continue;
				
				$_timeslot_ticket_recurring_future = (int)get_post_meta($oRecurringTimeslot->product_id, '_tpfw_timeslot_ticket_recurring_future', true);
				if($_timeslot_ticket_recurring_future <= 0) continue;

				// start/end already carry a datetime (e.g. "2027-08-11 00:00:00") - appending
				// slot_start/slot_end onto them (as this used to do) built an unparsable string
				// like "2027-08-11 00:00:00 00:00:00", so strtotime() silently returned false
				// for every candidate slot and the continue guards below always fired. Strip
				// start/end down to just the date before pairing them with a slot time.
				$sRecurringStartDate = gmdate('Y-m-d', strtotime($oRecurringTimeslot->start));
				$sRecurringEndDate   = gmdate('Y-m-d', strtotime($oRecurringTimeslot->end));
				$iSlotDurationSec    = strtotime($sRecurringStartDate . ' ' . $oRecurringTimeslot->slot_end) - strtotime($sRecurringStartDate . ' ' . $oRecurringTimeslot->slot_start);

				// Editing a rule's weeks moves its window, but the slots the old window generated
				// stay behind - and they still count towards the "how many future slots exist"
				// total below, so the slots the new window covers never get generated at all.
				// Strays are retired first, though never one that has already sold a ticket.
				$sRetireStrayTimeslotsSQL = $wpdb->prepare('	UPDATE %i SET deleted = %s, updated = %s
															WHERE deleted IS NULL AND manual = 0 AND timeslot_recurring_id_fk = %s
															AND (start < %s OR start >= %s)
															AND id NOT IN (SELECT timeslot_id FROM %i WHERE deleted IS NULL)', $sTimeslotTableName,
						$sCurrentDatetime,
						$sCurrentDatetime,
						$oRecurringTimeslot->id,
						$sRecurringStartDate . ' 00:00:00',
						$sRecurringEndDate . ' 00:00:00',
						$wpdb->prefix . 'tpfw_timeslot_tickets'
				);
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sRetireStrayTimeslotsSQL is the return value of $wpdb->prepare() above.
				$wpdb->query($sRetireStrayTimeslotsSQL);

				// Every child the series already has (manual ones included, so a hand-made slot at
				// the same time is not doubled), keyed by start so the walk below can skip it.
				$sRecurringChildTimeslotsSQL = $wpdb->prepare('	SELECT start FROM %i
															WHERE deleted IS NULL AND timeslot_recurring_id_fk = %s', $sTimeslotTableName,
						$oRecurringTimeslot->id
				);
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sRecurringChildTimeslotsSQL is the return value of $wpdb->prepare() above.
				$aExistingStarts = array_flip((array) $wpdb->get_col($sRecurringChildTimeslotsSQL));

				// Walk the rule week by week from its first occurrence. A slot is created when it
				// is still ahead, inside the series window, within the product's "weeks ahead of
				// today" horizon, and not already there. Because the horizon is measured from
				// today rather than from the series start, the series keeps rolling forward on
				// every run instead of stopping once the first N weeks exist.
				$iNow       = current_time('timestamp');
				$iHorizon   = strtotime('+'.$_timeslot_ticket_recurring_future.' weeks', $iNow);
				$iSeriesEnd = strtotime($sRecurringEndDate);
				for($iFutureTimeslot = strtotime($sRecurringStartDate . ' ' . $oRecurringTimeslot->slot_start); $iFutureTimeslot < $iSeriesEnd && $iFutureTimeslot <= $iHorizon; $iFutureTimeslot = strtotime('+1 week', $iFutureTimeslot))
				{
					if($iFutureTimeslot < $iNow) continue;
					$sSlotStart = gmdate('Y-m-d H:i:s', $iFutureTimeslot);
					if(isset($aExistingStarts[$sSlotStart])) continue;

					$sCreateFutureTimeslotPrepared = $wpdb->prepare(
						'INSERT INTO %i (id, product_id, user_id, start, end, available_slots, timeslot_recurring_id_fk, created, updated)
						 VALUES (%s, %d, %d, %s, %s, %d, %s, %s, %s);',
						array(
							$sTimeslotTableName,
							$this->generateNanoId(),
							$oRecurringTimeslot->product_id,
							$oRecurringTimeslot->user_id,
							$sSlotStart,
							gmdate('Y-m-d H:i:s', $iFutureTimeslot + $iSlotDurationSec),
							$oRecurringTimeslot->available_slots,
							$oRecurringTimeslot->id,
							$sCurrentDatetime,
							$sCurrentDatetime
						)
					);
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sCreateFutureTimeslotPrepared is the return value of $wpdb->prepare() above.
					$wpdb->query($sCreateFutureTimeslotPrepared);
				}
			}
		}
	}

	

	/**
	 * Per-type table names, order-line meta prefix and message strings shared by the ticket and
	 * timeslot ticket reset/cancel helpers.
	 *
	 * These used to be four ~90-line copies of the same body that differed only in these values,
	 * which is how the timeslot copies came to re-key order-line meta under the wrong prefix.
	 *
	 * @param string $sType 'ticket' or 'timeslot'.
	 * @return array|null sTable, sStatsTable, sMetaPrefix, sQRType and the message strings, or null.
	 */
	private function get_ticket_type_rules($sType)
	{
		$aRules = array(
			'ticket' => array(
				'sTable'       => 'tpfw_tickets',
				'sStatsTable'  => 'tpfw_tickets_stats',
				'sMetaPrefix'  => 'tpfw_ticket_id_',
				'sQRType'      => 'ticket',
				'sEmptyID'     => __('Ticket Nano ID seems to be empty', 'tickets-passes-for-woocommerce'),
				'sNotFound'    => __('Ticket with given Nano ID does not seem to exist', 'tickets-passes-for-woocommerce'),
				/* translators: %s: ticket nano id */
				'sResetNote'   => __('Ticket with ID, %s, has been reset', 'tickets-passes-for-woocommerce'),
				/* translators: %s: ticket nano id */
				'sCancelNote'  => __('Cancelled Ticket with Nano ID: %s', 'tickets-passes-for-woocommerce'),
			),
			'timeslot' => array(
				'sTable'       => 'tpfw_timeslot_tickets',
				'sStatsTable'  => 'tpfw_timeslot_tickets_stats',
				'sMetaPrefix'  => 'tpfw_timeslot_ticket_id_',
				'sQRType'      => 'timeslot',
				'sEmptyID'     => __('Timeslot Nano ID seems to be empty', 'tickets-passes-for-woocommerce'),
				'sNotFound'    => __('Timeslot Ticket with given Nano ID does not seem to exist', 'tickets-passes-for-woocommerce'),
				/* translators: %s: timeslot ticket nano id */
				'sResetNote'   => __('Timeslot Ticket with ID, %s, has been reset', 'tickets-passes-for-woocommerce'),
				/* translators: %s: timeslot ticket nano id */
				'sCancelNote'  => __('Cancelled Timeslot Ticket with Nano ID: %s', 'tickets-passes-for-woocommerce'),
			),
		);
		return $aRules[$sType] ?? null;
	}

	/**
	 * Loads the row a reset/cancel acts on, or the error envelope to return when it cannot.
	 *
	 * @param string $sType   'ticket' or 'timeslot'.
	 * @param string $sNanoID Nano id.
	 * @return array Either {aRules, oRow} or {bSuccess:false, sMessage}.
	 */
	private function load_ticket_row($sType, $sNanoID)
	{
		$aRules = $this->get_ticket_type_rules($sType);
		if($aRules === null)
		{
			return array('bSuccess' => false, 'sMessage' => __('Unknown ticket type', 'tickets-passes-for-woocommerce'));
		}
		if(empty($sNanoID))
		{
			return array('bSuccess' => false, 'sMessage' => $aRules['sEmptyID']);
		}

		global $wpdb;
		$oRow = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE nano_id = %s LIMIT 1', $wpdb->prefix . $aRules['sTable'], $sNanoID));
		if(empty($oRow))
		{
			return array('bSuccess' => false, 'sMessage' => $aRules['sNotFound']);
		}

		return array('aRules' => $aRules, 'oRow' => $oRow);
	}

	/**
	 * The per-unit ticket ids stored on an order line, in the order they were written.
	 *
	 * Both prefixes are collected regardless of type: a line only ever carries one family, and
	 * a line that was mis-keyed by an older version is healed by the next renumbering.
	 *
	 * @param WC_Order_Item $oOrderLine Line to read.
	 * @return array Meta key => nano id.
	 */
	private function get_line_ticket_ids($oOrderLine)
	{
		$aIDs = array();
		foreach((array) $oOrderLine->get_meta_data() as $oMeta)
		{
			if(str_starts_with($oMeta->key, 'tpfw_ticket_id_') || str_starts_with($oMeta->key, 'tpfw_timeslot_ticket_id_'))
			{
				$aIDs[$oMeta->key] = $oMeta->value;
			}
		}
		return $aIDs;
	}

	/**
	 * Clears every recorded check-in for a ticket or timeslot ticket so it becomes unused again.
	 *
	 * Hard-deletes the rows in the stats table, un-deletes the ticket row, puts its id back on
	 * the order line when it had been removed by a cancel, notes the reset on the order when the
	 * order still exists, and redraws the QR image so a cancelled code works again.
	 *
	 * @param string $sType   'ticket' or 'timeslot'.
	 * @param string $sNanoID Nano id.
	 * @return array bSuccess and sMessage.
	 */
	private function reset_ticket_row($sType, $sNanoID)
	{
		$aLoaded = $this->load_ticket_row($sType, $sNanoID);
		if(isset($aLoaded['bSuccess'])) return $aLoaded;
		$aRules = $aLoaded['aRules'];
		$oRow   = $aLoaded['oRow'];

		global $wpdb;
		$sCurrentDatetime = current_time('mysql');

		$mRestore = $wpdb->query($wpdb->prepare('UPDATE %i SET deleted = NULL, updated = %s WHERE nano_id = %s', $wpdb->prefix . $aRules['sTable'], $sCurrentDatetime, $sNanoID));
		if(TPFW_Db_Write::failed($mRestore))
		{
			return array(
				'bSuccess' => false,
				'sMessage' => __('Could not reset the ticket because the database write failed.', 'tickets-passes-for-woocommerce'),
			);
		}
		$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE nano_id_fk = %s', $wpdb->prefix . $aRules['sStatsTable'], $sNanoID));

		// wc_get_order() returns false when the order has since been deleted. The ticket row
		// still has to be reset in that case, so the order bookkeeping is simply skipped.
		$oOrder = wc_get_order((int) $oRow->order_id);
		if($oOrder && !empty($oOrder->get_items()))
		{
			foreach($oOrder->get_items() as $oOrderLine)
			{
				if($oOrderLine->get_id() != (int) $oRow->order_line_id) continue;

				$aExisting = $this->get_line_ticket_ids($oOrderLine);
				if(!in_array($sNanoID, $aExisting, true))
				{
					$oOrderLine->add_meta_data($aRules['sMetaPrefix'] . (count($aExisting) + 1), $sNanoID);
					$oOrderLine->save();
				}
				$oOrder->add_order_note(sprintf($aRules['sResetNote'], $sNanoID));
				$oOrder->save();
			}
		}

		$this->write_scanner_qr((int) $oRow->product_id, $aRules['sQRType'], $sNanoID);

		return array(
			'bSuccess' => true,
			'sMessage' => sprintf($aRules['sResetNote'], $sNanoID),
		);
	}

	/**
	 * Cancels a ticket or timeslot ticket: soft-deletes the row and its check-ins, takes its id
	 * off the order line (renumbering the ids that remain), removes the QR image and notes the order.
	 *
	 * @param string $sType   'ticket' or 'timeslot'.
	 * @param string $sNanoID Nano id.
	 * @return array bSuccess and sMessage.
	 */
	private function cancel_ticket_row($sType, $sNanoID)
	{
		$aLoaded = $this->load_ticket_row($sType, $sNanoID);
		if(isset($aLoaded['bSuccess'])) return $aLoaded;
		$aRules = $aLoaded['aRules'];
		$oRow   = $aLoaded['oRow'];

		global $wpdb;
		$sCurrentDatetime = current_time('mysql');

		$mCancel = $wpdb->query($wpdb->prepare('UPDATE %i SET deleted = %s, updated = %s WHERE nano_id = %s', $wpdb->prefix . $aRules['sTable'], $sCurrentDatetime, $sCurrentDatetime, $sNanoID));
		if(TPFW_Db_Write::failed($mCancel))
		{
			return array(
				'bSuccess' => false,
				'sMessage' => __('Could not cancel the ticket because the database write failed.', 'tickets-passes-for-woocommerce'),
			);
		}
		$wpdb->query($wpdb->prepare('UPDATE %i SET deleted = %s, updated = %s WHERE nano_id_fk = %s', $wpdb->prefix . $aRules['sStatsTable'], $sCurrentDatetime, $sCurrentDatetime, $sNanoID));

		$oOrder = wc_get_order((int) $oRow->order_id);
		if($oOrder && !empty($oOrder->get_items()))
		{
			foreach($oOrder->get_items() as $oOrderLine)
			{
				if($oOrderLine->get_id() != (int) $oRow->order_line_id) continue;

				$aExisting = $this->get_line_ticket_ids($oOrderLine);
				if(!empty($aExisting))
				{
					foreach(array_keys($aExisting) as $sMetaKey)
					{
						$oOrderLine->delete_meta_data($sMetaKey);
					}
					// Renumber the survivors under this type's own prefix, 1..n with no gaps: the
					// emails and the order-completion path walk the ids by index.
					$iIndex = 1;
					foreach($aExisting as $sExistingNanoID)
					{
						if($sExistingNanoID === $sNanoID) continue;
						$oOrderLine->add_meta_data($aRules['sMetaPrefix'] . $iIndex, $sExistingNanoID);
						$iIndex++;
					}
					$oOrderLine->save();
				}
				$oOrder->add_order_note(sprintf($aRules['sCancelNote'], $sNanoID));
				$oOrder->save();
			}
		}

		$this->delete_qr_code($sNanoID);

		return array(
			'bSuccess' => true,
			'sMessage' => sprintf($aRules['sCancelNote'], $sNanoID),
		);
	}

	/**
	 * Clears every recorded check-in for a timeslot ticket so it becomes unused again.
	 *
	 * @param string $sNanoID Timeslot ticket nano id.
	 * @return array bSuccess and sMessage.
	 */
	public function reset_timeslot_ticket($sNanoID)
	{
		return $this->reset_ticket_row('timeslot', $sNanoID);
	}

	/**
	 * Cancels a timeslot ticket: soft-deletes the row, removes the QR image and notes the order.
	 *
	 * @param string $sNanoID Timeslot ticket nano id.
	 * @return array bSuccess (bool) and sMessage (string).
	 */
	public function cancel_timeslot_ticket($sNanoID)
	{
		return $this->cancel_ticket_row('timeslot', $sNanoID);
	}

	/**
	 * Clears every recorded check-in for a ticket so it becomes unused again.
	 *
	 * @param string $sNanoID Ticket nano id.
	 * @return array bSuccess and sMessage.
	 */
	public function reset_ticket($sNanoID)
	{
		return $this->reset_ticket_row('ticket', $sNanoID);
	}

	/**
	 * Cancels a ticket: soft-deletes the row, removes the QR image and notes the order.
	 *
	 * @param string $sNanoID Ticket nano id.
	 * @return array bSuccess (bool) and sMessage (string).
	 */
	public function cancel_ticket($sNanoID)
	{
		return $this->cancel_ticket_row('ticket', $sNanoID);
	}

	/**
	 * Clears every recorded check-in for a pass so it becomes unused again.
	 *
	 * @param string $sNanoID Pass nano id.
	 * @return array bSuccess and sMessage.
	 */
	public function reset_pass($sNanoID)
	{
		if(!isset($sNanoID) || empty($sNanoID) || $sNanoID == "")
		{
			return array(
				'bSuccess'  => false,
				'sMessage' => __('Pass Nano ID seems to be empty', 'tickets-passes-for-woocommerce'),
			);
		}

		global $wpdb;
        $sPassTableName          	= $wpdb->prefix . "tpfw_pass";
        $sPassStatisticTableName 	= $wpdb->prefix . "tpfw_pass_stats";
		$sPassExistSQL    = $wpdb->prepare('	SELECT * FROM %i												
														WHERE nano_id = %s LIMIT 1', $sPassTableName, $sNanoID);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sPassExistSQL is the return value of $wpdb->prepare() above.
		$aPassExistResult = $wpdb->get_results($sPassExistSQL);
		if(empty($aPassExistResult))
		{
			return array(
				'bSuccess'  => false,
				'sMessage' => __('Pass with given Nano ID does not seem to exist', 'tickets-passes-for-woocommerce'),
			);
		}
		$oPassExistResult     = $aPassExistResult[0];

		$iProductID       = $oPassExistResult->product_id;
		$iOrderID         = $oPassExistResult->order_id;
		$iOrderlineID     = $oPassExistResult->order_line_id;
		$oOrder           = wc_get_order($iOrderID);
		$sCurrentDatetime = current_time('mysql');

		$sUpdatePassSQL 				= $wpdb->prepare('	UPDATE %i
													SET deleted = null, updated = %s
													WHERE nano_id = %s OR parent_nano_id_fk = %s', $sPassTableName, $sCurrentDatetime, $sNanoID, $sNanoID);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sUpdatePassSQL is the return value of $wpdb->prepare() above.
		if(TPFW_Db_Write::failed($wpdb->query($sUpdatePassSQL)))
		{
			return array(
				'bSuccess' => false,
				'sMessage' => __('Could not reset the pass because the database write failed.', 'tickets-passes-for-woocommerce'),
			);
		}

		$sPassStatisticDeleteSQL 		= $wpdb->prepare('	DELETE FROM %i
													WHERE nano_id_fk = %s OR nano_id_fk IN (SELECT nano_id FROM %i WHERE parent_nano_id_fk = %s)', $sPassStatisticTableName, $sNanoID, $sPassTableName, $sNanoID);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sPassStatisticDeleteSQL is the return value of $wpdb->prepare() above.
		$wpdb->query($sPassStatisticDeleteSQL);
		
		// wc_get_order() returns false when the order has since been deleted. The ticket/pass
		// rows still have to be reset or cancelled in that case, so skip the order bookkeeping
		// rather than fatalling on it.
		if($oOrder && !empty($oOrder->get_items()))
		{
			foreach($oOrder->get_items() as $iOrderLineKey => $oOrderLine)
			{
				if($oOrderLine->get_id() == $iOrderlineID)
				{
					// update, not add: a second reset must not leave two tpfw_pass_id_1 rows behind.
					$oOrderLine->update_meta_data('tpfw_pass_id_1', $sNanoID);
					$oOrderLine->save();
				}
			}
		}
		
		$this->write_scanner_qr($iProductID, 'pass', $sNanoID);

		if($oOrder)
		{
			$oOrder->add_order_note(__('Pass with ID', 'tickets-passes-for-woocommerce') . ', ' .$sNanoID . ' - ' . __('has been reset', 'tickets-passes-for-woocommerce'));
			$oOrder->save();
		}

		// Resetting a pass also resets its guest passes (the UPDATE above matches
		// nano_id OR parent_nano_id_fk) - report their own max_uses back too, not
		// just the parent's, since a guest pass isn't guaranteed to share it.
		$sGuestUsesSQL = $wpdb->prepare('	SELECT nano_id, max_uses FROM %i
											WHERE parent_nano_id_fk = %s', $sPassTableName, $sNanoID);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sGuestUsesSQL is the return value of $wpdb->prepare() above.
		$aGuestUses = $wpdb->get_results($sGuestUsesSQL);

		return array(
			'bSuccess'    => true,
			'sMessage'   => __('Reset Pass with Nano ID', 'tickets-passes-for-woocommerce') . ': ' . $sNanoID,
			'iMaxUses'   => $oPassExistResult->max_uses,
			'aGuestUses' => $aGuestUses,
		);
	}



	/**
	 * Cancels a pass: soft-deletes the row and its guest passes, removes the QR images and notes
	 * the order.
	 *
	 * @param string $sNanoID Pass nano id.
	 * @return array bSuccess (bool) and sMessage (string).
	 */
	public function cancel_pass($sNanoID)
	{
		if(!isset($sNanoID) || empty($sNanoID) || $sNanoID == "")
		{
			return array(
				'bSuccess'  => false,
				'sMessage' => __('Pass Nano ID seems to be empty', 'tickets-passes-for-woocommerce'),
			);
		}

		global $wpdb;
        $sPassTableName          	= $wpdb->prefix . "tpfw_pass";
        $sPassStatisticTableName 	= $wpdb->prefix . "tpfw_pass_stats";
		$sPassExistSQL    = $wpdb->prepare('	SELECT * FROM %i												
														WHERE nano_id = %s LIMIT 1', $sPassTableName, $sNanoID);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sPassExistSQL is the return value of $wpdb->prepare() above.
		$aPassExistResult = $wpdb->get_results($sPassExistSQL);
		if(empty($aPassExistResult))
		{
			return array(
				'bSuccess'  => false,
				'sMessage' => __('Pass with given Nano ID does not seem to exist', 'tickets-passes-for-woocommerce'),
			);
		}
		$oPassExistResult     = $aPassExistResult[0];	

		$iOrderID					= $oPassExistResult->order_id;
		$iOrderlineID				= $oPassExistResult->order_line_id;
		$oOrder 					= wc_get_order($iOrderID);
		$sCurrentDatetime 			= current_time('mysql');

		// Read before the UPDATE below cancels them, so the guest images can be cleaned up with
		// the pass they hang off rather than being left behind in the guest folder.
		$sGuestNanoIDsSQL = $wpdb->prepare('SELECT nano_id FROM %i WHERE parent_nano_id_fk = %s', $sPassTableName, $sNanoID);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sGuestNanoIDsSQL is the return value of $wpdb->prepare() above.
		$aGuestNanoIDs = (array) $wpdb->get_col($sGuestNanoIDsSQL);

		$sUpdatePassSQL 						= $wpdb->prepare('	UPDATE %i
																	SET deleted = %s, updated = %s
																	WHERE nano_id = %s OR parent_nano_id_fk = %s', $sPassTableName, $sCurrentDatetime, $sCurrentDatetime, $sNanoID, $sNanoID);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sUpdatePassSQL is the return value of $wpdb->prepare() above.
		if(TPFW_Db_Write::failed($wpdb->query($sUpdatePassSQL)))
		{
			return array(
				'bSuccess' => false,
				'sMessage' => __('Could not cancel the pass because the database write failed.', 'tickets-passes-for-woocommerce'),
			);
		}
		
		$sPassStatisticUpdateSQL 			= $wpdb->prepare('	UPDATE %i
																	SET deleted = %s, updated = %s
																	WHERE nano_id_fk = %s', $sPassStatisticTableName, $sCurrentDatetime, $sCurrentDatetime, $sNanoID);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sPassStatisticUpdateSQL is the return value of $wpdb->prepare() above.
		$wpdb->query($sPassStatisticUpdateSQL);

		$sGuestPassStatisticUpdateSQL 			= $wpdb->prepare('	UPDATE %i
																	SET deleted = %s, updated = %s
																	WHERE nano_id_fk IN (SELECT nano_id FROM %i WHERE parent_nano_id_fk = %s)', $sPassStatisticTableName, $sCurrentDatetime, $sCurrentDatetime, $sPassTableName, $sNanoID);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sGuestPassStatisticUpdateSQL is the return value of $wpdb->prepare() above.
		$wpdb->query($sGuestPassStatisticUpdateSQL);
															
		// wc_get_order() returns false when the order has since been deleted. The ticket/pass
		// rows still have to be reset or cancelled in that case, so skip the order bookkeeping
		// rather than fatalling on it.
		if($oOrder && !empty($oOrder->get_items()))
		{
			foreach($oOrder->get_items() as $iOrderLineKey => $oOrderLine)
			{
				if($oOrderLine->get_id() == (int)$iOrderlineID)
				{
					$oOrderLine->delete_meta_data('tpfw_pass_id_1');
					$oOrderLine->save();
					$oOrder->add_order_note(__('Cancelled Pass with ID', 'tickets-passes-for-woocommerce') . ': ' . $sNanoID);
					$oOrder->save();
				}
			}
		}

		$this->delete_qr_code($sNanoID);
		foreach($aGuestNanoIDs as $sGuestNanoID)
		{
			$this->delete_guest_qr_code($sGuestNanoID);
		}

		return array(
			'bSuccess'  => true,
			'sMessage' => __('Cancelled Pass with Nano ID', 'tickets-passes-for-woocommerce') . ': ' . $sNanoID,
		);
	}
	/**
	 * Single source of truth for the display format chosen in General Settings.
	 *
	 * The settings dropdown only offers "<date> <time>" values, so splitting on the first space is
	 * safe; the fallbacks cover an option saved before a format was ever picked.
	 *
	 * @param string $sType 'datetime' (whole string), 'date' (before the space) or 'time' (after).
	 * @return string PHP date() format string.
	 */
	public function get_datetime_format($sType = 'datetime')
	{
		$settingsOptions = get_option('tpfw_general_settings_options');
		$sDateTimeFormat = 'Y-m-d H:i:s';
		if(isset($settingsOptions['sDateTimeformat']) && !empty($settingsOptions['sDateTimeformat']) && $settingsOptions['sDateTimeformat'] != null)
		{
			$sDateTimeFormat = $settingsOptions['sDateTimeformat'];
		}

		if($sType === 'datetime')
		{
			return $sDateTimeFormat;
		}

		$aParts = explode(' ', $sDateTimeFormat, 2);
		if($sType === 'time')
		{
			return isset($aParts[1]) ? $aParts[1] : 'H:i:s';
		}
		return $aParts[0];
	}
	/**
	 * The configured date format translated into the JS format string the date pickers take.
	 *
	 * The date pickers (event calendar block and the timeslot product page) take a JS format
	 * string rather than a PHP one, so the configured date format is translated once here instead
	 * of being re-derived on each screen - the two used to drift independently.
	 *
	 * @return string e.g. 'yyyy-MM-dd'.
	 */
	public function get_datepicker_format()
	{
		$sFormat = strtolower($this->get_datetime_format('date'));
		return str_replace(array('d', 'm', 'y'), array('dd', 'MM', 'yyyy'), $sFormat);
	}

	/**
	 * The Air Datepicker locale object as JSON, using WordPress's own weekday and month names.
	 *
	 * @return string A JS object literal, safe to interpolate into an inline script.
	 */
	public function get_datepicker_locale_js()
	{
		global $wp_locale;
		return wp_json_encode(TPFW_Datepicker_Locale::from_wp_locale(
			$wp_locale,
			__('Today', 'tickets-passes-for-woocommerce'),
			__('Clear', 'tickets-passes-for-woocommerce')
		));
	}

	/**
	 * Loads the shared front end product page assets, and the date picker where one is needed.
	 *
	 * The stylesheet carries the product info list and the customer start date picker, so it is
	 * only loaded when the product shows at least one of them. The picker library is only pulled
	 * in for a Ticket/Pass whose start date the customer picks themselves, so a product without
	 * that option costs nothing extra.
	 *
	 * @return void
	 */
	public function enqueue_front_product_assets()
	{
		global $post;
		if(!is_product() || !is_singular('product') || empty($post)) return;

		$oProduct = wc_get_product($post->ID);
		if(empty($oProduct)) return;
		if(!is_a($oProduct, 'TPFW_Product_Ticket') && !is_a($oProduct, 'TPFW_Product_Pass') && !is_a($oProduct, 'TPFW_Product_Timeslot_Ticket')) return;

		$sMetaPrefix = $this->get_customer_start_date_meta_prefix($oProduct);
		$bPicker     = $sMetaPrefix !== '' && get_post_meta($post->ID, $sMetaPrefix.'_user_start_date_enable', true) == 'yes';

		if(!$bPicker && !$this->product_shows_info_rows($post->ID, is_a($oProduct, 'TPFW_Product_Timeslot_Ticket') ? '_tpfw_timeslot' : $sMetaPrefix)) return;

		wp_enqueue_style($this->sPrefix.'front-product', plugins_url('css/front-product.css', __FILE__), array(), filemtime(dirname(__FILE__).'/css/front-product.css'));

		if(!$bPicker) return;

		wp_enqueue_style($this->sPrefix.'air-datepicker', TPFW_PLUGIN_URL.'lib/air-datepicker/css/air-datepicker.min.css', array(), filemtime(TPFW_PLUGIN_DIR.'lib/air-datepicker/css/air-datepicker.min.css'));
		wp_register_script($this->sPrefix.'air-datepicker', TPFW_PLUGIN_URL.'lib/air-datepicker/js/air-datepicker.min.js', array(), filemtime(TPFW_PLUGIN_DIR.'lib/air-datepicker/js/air-datepicker.min.js'), true);
		// src = false: this handle exists only to carry the init that
		// render_customer_start_date_picker() builds, which needs jQuery and the library first.
		wp_register_script($this->sPrefix.'front-datepicker', false, array('jquery', $this->sPrefix.'air-datepicker'), TPFW_VERSION, true);
		wp_enqueue_script($this->sPrefix.'front-datepicker');
	}

	/**
	 * Whether any of the product's opt-in "show on product page" rows is switched on.
	 *
	 * The toggles alone: a row may still come out empty (a "Date" row with no fixed date set),
	 * in which case the stylesheet loads for nothing, but that is the rare edge and cheaper than
	 * running the full row logic twice per page.
	 *
	 * @param int    $iProductID  Product being viewed.
	 * @param string $sMetaPrefix '_tpfw_ticket', '_tpfw_pass' or '_tpfw_timeslot'.
	 * @return bool
	 */
	private function product_shows_info_rows($iProductID, $sMetaPrefix)
	{
		foreach(array('show_max_uses', 'show_predefined_date', 'show_valid_from', 'show_valid_to', 'show_sales_window') as $sToggle)
		{
			if(get_post_meta($iProductID, $sMetaPrefix.'_'.$sToggle, true) == 'yes') return true;
		}
		return false;
	}

	/**
	 * The meta key prefix of the product type's customer-chosen start date option.
	 *
	 * @param WC_Product $oProduct Product being viewed.
	 * @return string '_tpfw_ticket', '_tpfw_pass', or '' for a type that has no such option.
	 */
	public function get_customer_start_date_meta_prefix($oProduct)
	{
		if(is_a($oProduct, 'TPFW_Product_Ticket')) return '_tpfw_ticket';
		if(is_a($oProduct, 'TPFW_Product_Pass'))   return '_tpfw_pass';
		return '';
	}

	/**
	 * Renders the customer-chosen start date picker shown above the add-to-cart button.
	 *
	 * Ticket and Pass both offer it, with identical markup, calendar options and locale, so it
	 * lives here rather than being written twice. The visible calendar is the same air-datepicker
	 * the Timeslot Ticket product uses, so the two read as one picker across the shop.
	 *
	 * The card also answers back: a pill next to the label repeats the chosen date. The details
	 * list above can only ever show the shop's own dates, so without it nothing on the page
	 * confirms what the customer just picked.
	 *
	 * @param string $sColorStyle      Inline CSS custom properties for the card, built by the caller
	 *                                 from the product type's colours in the plugin's general settings.
	 * @param string $sLabel           Field label.
	 * @param string $sHint            Short explanation shown under the label.
	 * @param string $sMinDate         Earliest selectable date as Y-m-d. Empty falls back to today.
	 * @param string $sMaxDate         Latest selectable date as Y-m-d. Empty leaves the far end open.
	 * @return void
	 */
	public function render_customer_start_date_picker($sColorStyle, $sLabel, $sHint, $sMinDate = '', $sMaxDate = '')
	{
		// Clamped to today so a product whose range was set last season does not offer dates the
		// add-to-cart validation would then refuse. Matches the same clamp on the server side.
		$sToday = gmdate('Y-m-d', current_time('timestamp'));
		if($sMinDate === '' || $sMinDate < $sToday) $sMinDate = $sToday;
		?>
		<div class="tpfw-startdate-picker" style="<?php echo esc_attr($sColorStyle); ?>">
			<div class="tpfw-startdate-picker-label">
				<div>
					<label for="tpfw-start-date-picker"><?php echo esc_html($sLabel); ?></label>
					<p class="tpfw-startdate-picker-hint"><?php echo esc_html($sHint); ?></p>
				</div>
				<span class="tpfw-startdate-chosen"><?php echo esc_html__('No date yet', 'tickets-passes-for-woocommerce'); ?></span>
			</div>
			<div class="tpfw-startdate-calendar">
				<input type="text" id="tpfw-start-date-picker" readonly placeholder="<?php echo esc_attr__('Select a date', 'tickets-passes-for-woocommerce'); ?>">
			</div>
			<p class="tpfw-startdate-error" role="alert"><?php echo esc_html__('Pick a start date before adding this to the cart.', 'tickets-passes-for-woocommerce'); ?></p>
			<input type="hidden" name="tpfw-start-date" class="tpfw-start-date-value" value="">
		</div>
		<?php
		ob_start();
		?>
		jQuery(document).ready(function()
		{
			var sFormat   = "<?php echo esc_js($this->get_datepicker_format()); ?>";
			var $oCard    = jQuery('.tpfw-startdate-picker');

			new AirDatepicker('#tpfw-start-date-picker', {
				dateFormat: sFormat,
				inline    : true,
				locale    : <?php echo $this->get_datepicker_locale_js(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() output is already a safe JS literal; escaping it would break the syntax. ?>,
				timepicker: false,
				// Parsed from the parts rather than new Date('Y-m-d'), which the browser reads as
				// UTC midnight and so lands on the previous day anywhere west of it.
				minDate   : new Date(<?php echo (int)substr($sMinDate, 0, 4).', '.((int)substr($sMinDate, 5, 2) - 1).', '.(int)substr($sMinDate, 8, 2); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integer-cast date parts only. ?>),
				<?php if($sMaxDate !== '') : ?>
				maxDate   : new Date(<?php echo (int)substr($sMaxDate, 0, 4).', '.((int)substr($sMaxDate, 5, 2) - 1).', '.(int)substr($sMaxDate, 8, 2); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integer-cast date parts only. ?>),
				<?php endif; ?>
				onSelect({ date, formattedDate })
				{
					// Built from the local date parts rather than toISOString(), which converts to
					// UTC first and so hands back the previous day for anyone west of it.
					var sMonth = ('0' + (date.getMonth() + 1)).slice(-2);
					var sDay   = ('0' + date.getDate()).slice(-2);
					jQuery('.tpfw-start-date-value').val(date.getFullYear() + '-' + sMonth + '-' + sDay);

					jQuery('.tpfw-startdate-chosen').text(formattedDate);

					$oCard.addClass('is-chosen').removeClass('is-invalid');
				},
			});

			// Add-to-cart with no date is already refused server side, but that costs a page load
			// to say so. Saying it here keeps the answer next to the calendar that has to give it.
			jQuery('.tpfw-start-date-value').closest('form').on('submit', function(oEvent)
			{
				if(jQuery('.tpfw-start-date-value').val() !== '') return;

				oEvent.preventDefault();
				$oCard.addClass('is-invalid');
				$oCard[0].scrollIntoView({ block: 'center' });
			});
		});
		<?php
		$this->stash_front_inline_script(ob_get_clean());
	}

	/**
	 * Keeps a "Sales Timespan" window pointing forwards.
	 *
	 * Y-m-d sorts lexically, so the later of the two strings is always the end of the window.
	 * Unlike the customer date range there is no clamp to today - a sales window that opened
	 * last month is perfectly normal, it just may not close before it opened.
	 *
	 * @param string $sStart Posted start date.
	 * @param string $sEnd   Posted end date.
	 * @return string The end date as it may be stored.
	 */
	public function clamp_sales_timespan_end($sStart, $sEnd)
	{
		return ($sEnd < $sStart) ? $sStart : $sEnd;
	}

	/**
	 * Stores the "Selectable From"/"Selectable To" pair a customer-picked date is chosen within.
	 *
	 * The pair is only kept while the customer owns the date, and only as a range that can
	 * actually be picked in: a near end behind today is pulled forward to today, and a far end
	 * behind the near end is pushed out to meet it rather than saved as a window nothing fits in.
	 * Ticket and Pass store the same pair under their own key names, hence the parameters.
	 *
	 * @param int    $iPostID        Product id being saved.
	 * @param bool   $bUserStartDate Whether the customer picks the date on this product.
	 * @param string $sMinKey        Meta key for the near end, which is also its field name.
	 * @param string $sMaxKey        Meta key for the far end, which is also its field name.
	 * @return void
	 */
	public function save_customer_date_range($iPostID, $bUserStartDate, $sMinKey, $sMaxKey)
	{
		if(!$bUserStartDate)
		{
			delete_post_meta($iPostID, $sMinKey);
			delete_post_meta($iPostID, $sMaxKey);
			return;
		}

		$sToday = gmdate('Y-m-d', current_time('timestamp'));
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
		$sMin = sanitize_text_field(wp_unslash($_POST[$sMinKey] ?? ''));
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
		$sMax = sanitize_text_field(wp_unslash($_POST[$sMaxKey] ?? ''));

		if($sMin === '' || $sMin < $sToday) $sMin = $sToday;
		if($sMax === '' || $sMax < $sMin)   $sMax = gmdate('Y-m-d', strtotime('+1 year', strtotime($sMin)));

		update_post_meta($iPostID, $sMinKey, $sMin);
		update_post_meta($iPostID, $sMaxKey, $sMax);
	}

	/**
	 * Stores the far end of an order line's validity window, so the order can show it as a window.
	 *
	 * The line only ever carries the date it starts from; the duration lives on the product and
	 * could be re-set afterwards, so the end date is worked out once, here, and kept with the line.
	 *
	 * @param WC_Order_Item_Product $oItem         Order line being built.
	 * @param string                $sStartDate    Start date, in whatever format the line stores it.
	 * @param string                $sDurationKey  Product meta key holding the duration in seconds.
	 * @return void
	 */
	public function add_valid_to_order_item_meta($oItem, $sStartDate, $sDurationKey)
	{
		$iDuration = (int)get_post_meta($oItem->get_product_id(), $sDurationKey, true);
		$oItem->update_meta_data(
			'tpfw_valid_to',
			gmdate($this->get_datetime_format('date'), strtotime($sStartDate) + $iDuration)
		);
	}

	/**
	 * Queues an inline script to be attached to the shared front end handle in the footer.
	 *
	 * Not attached straight away: with a block theme WordPress renders the product template before
	 * 'wp_enqueue_scripts' fires, so the handle is not registered yet and wp_add_inline_script()
	 * would silently drop the script.
	 *
	 * @param string $sJS Script body.
	 * @return void
	 */
	private function stash_front_inline_script($sJS)
	{
		$this->sFrontInlineJS .= $sJS;
		add_action('wp_footer', array($this, 'print_front_inline_script'), 5);
	}

	/**
	 * Attaches whatever render_customer_start_date_picker() queued, before the footer prints it.
	 *
	 * @return void
	 */
	public function print_front_inline_script()
	{
		if($this->sFrontInlineJS === '') return;
		wp_add_inline_script($this->sPrefix.'front-datepicker', $this->sFrontInlineJS);
		$this->sFrontInlineJS = '';
	}

	/**
	 * Renders the optional "product details" list shown just above the add-to-cart button.
	 *
	 * Rows rather than a table: these are label and value pairs with no column headers, so
	 * a table was announcing tabular data that is not there.
	 *
	 * Every row is opt-in per product through its own toggle on the product edit screen, so a shop
	 * that would rather not publish its max-uses or sales window simply leaves them off, and a
	 * product with every toggle off renders nothing at all. Ticket, Timeslot Ticket and Pass all
	 * show the same table and only their meta key names differ, so the names are passed in rather
	 * than the renderer being written out three times.
	 *
	 * @param int    $iProductID  Product being viewed.
	 * @param array  $aKeys       Meta key names. A key left out of the array drops the row it belongs
	 *                            to - a Timeslot Ticket has no validity window of its own, because
	 *                            the slot it is booked for is the window.
	 * @param string $sColorStyle Inline custom properties from get_front_color_style(), so the list
	 *                            wears the same palette as the picker it sits next to.
	 * @return void
	 */
	public function render_product_info_table($iProductID, $aKeys, $sColorStyle = '')
	{
		$aRows       = array();
		$sDateFormat = $this->get_datetime_format('date');

		if(!empty($aKeys['show_max_uses']) && get_post_meta($iProductID, $aKeys['show_max_uses'], true) == 'yes')
		{
			$iMaxUses = (int)get_post_meta($iProductID, $aKeys['max_uses'], true);
			if($iMaxUses > 0)
			{
				$aRows[] = array(__('Maximum uses', 'tickets-passes-for-woocommerce'), number_format_i18n($iMaxUses));
			}
		}

		// A fixed start date is the event date, so it is published under its own name instead of
		// as the near end of a validity window. Only meaningful while that date exists.
		if(!empty($aKeys['show_date'])
			&& get_post_meta($iProductID, $aKeys['show_date'], true) == 'yes'
			&& get_post_meta($iProductID, $aKeys['start_enable'], true) == 'yes')
		{
			$aRows[] = array(
				__('Date', 'tickets-passes-for-woocommerce'),
				gmdate($sDateFormat, strtotime(get_post_meta($iProductID, $aKeys['start_date'], true)))
			);
		}

		// Mirrors what the cart line already shows for the same product: a fixed start date where
		// the shop set one, otherwise today, since no fixed date means "valid from purchase".
		$bShowFrom = !empty($aKeys['show_valid_from']) && get_post_meta($iProductID, $aKeys['show_valid_from'], true) == 'yes';
		$bShowTo   = !empty($aKeys['show_valid_to'])   && get_post_meta($iProductID, $aKeys['show_valid_to'], true) == 'yes';
		if($bShowFrom || $bShowTo)
		{
			$iStart = current_time('timestamp');
			if(get_post_meta($iProductID, $aKeys['start_enable'], true) == 'yes')
			{
				$iStart = strtotime(get_post_meta($iProductID, $aKeys['start_date'], true));
			}

			if($bShowFrom)
			{
				$aRows[] = array(__('Valid from', 'tickets-passes-for-woocommerce'), gmdate($sDateFormat, $iStart));
			}
			if($bShowTo)
			{
				$aRows[] = array(__('Valid to', 'tickets-passes-for-woocommerce'), gmdate($sDateFormat, $iStart + (int)get_post_meta($iProductID, $aKeys['duration'], true)));
			}
		}

		if(!empty($aKeys['show_sales'])
			&& get_post_meta($iProductID, $aKeys['show_sales'], true) == 'yes'
			&& get_post_meta($iProductID, $aKeys['sales_enable'], true) == 'yes')
		{
			$aRows[] = array(
				// Not "On sale" - in a WooCommerce shop that reads as a discount, not a window.
				__('Purchasable between', 'tickets-passes-for-woocommerce'),
				sprintf(
					/* translators: 1: first date the product can be bought. 2: last date it can be bought. */
					__('%1$s - %2$s', 'tickets-passes-for-woocommerce'),
					gmdate($sDateFormat, strtotime(get_post_meta($iProductID, $aKeys['sales_start'], true))),
					gmdate($sDateFormat, strtotime(get_post_meta($iProductID, $aKeys['sales_end'], true)))
				)
			);
		}

		if(empty($aRows)) return;
		?>
		<div class="tpfw-product-info" style="<?php echo esc_attr($sColorStyle); ?>">
			<?php foreach($aRows as $aRow) : ?>
			<div class="tpfw-product-info-row">
				<span class="tpfw-product-info-label"><?php echo esc_html($aRow[0]); ?></span>
				<span class="tpfw-product-info-value"><?php echo esc_html($aRow[1]); ?></span>
			</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
	/**
	 * Stores a product settings checkbox, or clears it.
	 *
	 * An unticked checkbox is simply absent from the POST, so there is nothing to store and
	 * the meta has to be deleted instead - the same six lines every one of these toggles
	 * would otherwise repeat. $bAllowed lets a caller force a toggle off when another
	 * setting rules it out, so a stale POST cannot store a combination the UI forbids.
	 *
	 * @param int    $iPostID  Product id being saved.
	 * @param string $sKey     Meta key, which is also the checkbox's field name.
	 * @param bool   $bAllowed False to clear the meta regardless of what was posted.
	 * @return bool Whether the toggle ended up enabled.
	 */
	public function save_checkbox_meta($iPostID, $sKey, $bAllowed = true)
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce_meta_nonce in WC_Admin_Meta_Boxes::save_meta_boxes() before firing the woocommerce_process_product_meta_* hook this runs on.
		if($bAllowed && sanitize_text_field(wp_unslash($_POST[$sKey] ?? '')) == 'yes')
		{
			update_post_meta($iPostID, $sKey, 'yes');
			return true;
		}

		delete_post_meta($iPostID, $sKey);
		return false;
	}

	/**
	 * Shared status-pill markup for the admin dashboard tables (Tickets, Timeslot Tickets, Pass).
	 *
	 * @param string $sStatusLabel Translated status; unknown labels fall back to the Unused style.
	 * @param string $sTitleAttr   Tooltip, used for "Cancelled" to show the cancellation date.
	 * @return string Escaped markup.
	 */
	public function get_status_pill_html($sStatusLabel, $sTitleAttr = '')
	{
		$aModifiers = array(
			__('Unused', 'tickets-passes-for-woocommerce')        => 'unused',
			__('Partial', 'tickets-passes-for-woocommerce')       => 'partial',
			__('Used', 'tickets-passes-for-woocommerce')          => 'used',
			__('Active', 'tickets-passes-for-woocommerce')        => 'active',
			__('Expired', 'tickets-passes-for-woocommerce')       => 'expired',
			__('Not valid yet', 'tickets-passes-for-woocommerce') => 'pending',
			__('Cancelled', 'tickets-passes-for-woocommerce')     => 'cancelled',
		);
		$sModifier   = isset($aModifiers[$sStatusLabel]) ? $aModifiers[$sStatusLabel] : 'unused';
		$sTitleAttr  = $sTitleAttr != '' ? ' title="'.esc_attr($sTitleAttr).'"' : '';

		return '<span class="tpfw-status-pill tpfw-status-pill--'.esc_attr($sModifier).'"'.$sTitleAttr.'>'.esc_html($sStatusLabel).'</span>';
	}
	/**
	 * Shared truncated-ID markup for the admin dashboard tables (Tickets, Timeslot Tickets, Pass).
	 *
	 * Nano IDs are long random strings with no meaningful content to scan, so only the edges are
	 * shown; the full ID is still available via the title tooltip.
	 *
	 * @param string $sID    Full nano id.
	 * @param string $sClass CSS class on the <code> element.
	 * @param int    $iEdge  Characters kept at each end.
	 * @return string Escaped markup.
	 */
	public function get_truncated_id_html($sID, $sClass = 'tpfw-ticket-id', $iEdge = 4)
	{
		// Escaped per fragment rather than over the whole string, so the &hellip; entity
		// joining the two halves survives instead of being turned into &amp;hellip;.
		$sDisplay = esc_html($sID);
		if(mb_strlen($sID) > ($iEdge * 2) + 3)
		{
			$sDisplay = esc_html(mb_substr($sID, 0, $iEdge)) . '&hellip;' . esc_html(mb_substr($sID, -$iEdge));
		}

		return '<code class="'.esc_attr($sClass).'" title="'.esc_attr($sID).'">'.$sDisplay.'</code>';
	}
	/**
	 * Shared stacked date/time markup for the admin dashboard tables (Tickets, Timeslot Tickets,
	 * Pass) - date on one line, time on the next, instead of one wide "date time" string.
	 *
	 * @param string $sDateOnlyFormat Date format, see get_datetime_format('date').
	 * @param string $sTimeOnlyFormat Time format, see get_datetime_format('time').
	 * @param string $sDateTimeString The value to render, anything strtotime() understands.
	 * @param string $sTitleAttr      Optional extra info (e.g. the "Valid to" date) as a tooltip.
	 * @return string Escaped markup.
	 */
	public function get_stacked_datetime_html($sDateOnlyFormat, $sTimeOnlyFormat, $sDateTimeString, $sTitleAttr = '')
	{
		$iTimestamp = strtotime($sDateTimeString);
		$sClass     = 'tpfw-datetime' . ($sTitleAttr != '' ? ' tpfw-datetime--hint' : '');
		$sTitleHtml = $sTitleAttr != '' ? ' title="'.esc_attr($sTitleAttr).'"' : '';

		return '<span class="'.esc_attr($sClass).'"'.$sTitleHtml.'><span class="tpfw-datetime-date">'.esc_html(gmdate($sDateOnlyFormat, $iTimestamp)).'</span><span class="tpfw-datetime-time">'.esc_html(gmdate($sTimeOnlyFormat, $iTimestamp)).'</span></span>';
	}
}

	/**
	 * Writes a QR code .webp to disk via the Endroid library.
	 *
	 * Lives at file scope, outside TPFW_Functions, and is called by name from several places -
	 * leave it here. Call TPFW_Functions::create_qr_code() rather than this directly: that picks
	 * the target folder for the QR type and catches generation failures.
	 *
	 * @param string $sQRData          Payload encoded in the QR code.
	 * @param int    $iSize            Image size in pixels.
	 * @param int    $iMargin          Quiet-zone margin in pixels.
	 * @param string $sLabelText       Caption printed under the code; empty for none.
	 * @param string $sFullLogoPath    Absolute path to the centre logo; null for none.
	 * @param Color  $oBackgroundColor Background colour.
	 * @param Color  $oForegroundColor Module colour.
	 * @param Color  $oLabelColor      Caption colour.
	 * @param string $sUploadFileDir   Destination folder, created when missing.
	 * @param string $sFileName        Filename without extension.
	 * @return bool Always true; failures throw and are caught by the caller.
	 */
	function tpfw_create_qr_code_function($sQRData, $iSize, $iMargin, $sLabelText, $sFullLogoPath, $oBackgroundColor, $oForegroundColor, $oLabelColor, $sUploadFileDir, $sFileName)
	{
		include_once dirname(__FILE__).'/lib/qrcodegen/autoload.php';    

		$aWriter  = new WebPWriter();
		$aLogoBox = TPFW_Qr_Render::logo_box((int) $iSize, $sFullLogoPath);

		// Low (~7%) is enough for a plain code. A centre logo destroys modules; High (~30%)
		// is the level Endroid documents for that case, and the only one that stays scannable
		// once a product photo is punched into the middle.
		$qrCode = new QrCode(
			data                : $sQRData,
			encoding            : new Encoding('UTF-8'),
			errorCorrectionLevel: ($aLogoBox !== null) ? new ErrorCorrectionLevelHigh() : new ErrorCorrectionLevelLow(),
			size                : $iSize,
			margin              : $iMargin,
			roundBlockSizeMode  : new RoundBlockSizeModeMargin(),
			foregroundColor     : $oForegroundColor,//new Color(0, 0, 0),
			backgroundColor     : $oBackgroundColor//new Color(255, 255, 255)
		);
		
		// Create generic label
		$aLabel = null;
		if($sLabelText != null && $sLabelText != "")
		{
			// The font is passed explicitly. Endroid's Label defaults to noto_sans.otf, which
			// is a 15.7 MB font shipped for its full Unicode coverage - on its own it was more
			// than a third of this plugin's download. open_sans.ttf ships in the same library,
			// is 217 KB, and covers the Latin/Greek/Cyrillic range these short QR captions use.
			// Sites needing CJK or similar can restore the larger font here.
			$aLabel = new Label(
				text     : $sLabelText,
				font     : new OpenSans(),
				textColor: $oLabelColor//new Color(255, 0, 0)
			);
		}

		$aLogo = null;
		if($aLogoBox !== null)
		{
			$aLogo = new Logo(
				path              : $sFullLogoPath,
				resizeToWidth     : $aLogoBox[0],
				resizeToHeight    : $aLogoBox[1],
				punchoutBackground: true
			);
		}

		if(!file_exists($sUploadFileDir))
		{
			wp_mkdir_p($sUploadFileDir);
		}

		$aQRCode = $aWriter->write($qrCode, $aLogo, $aLabel);
		$aQRCode->saveToFile($sUploadFileDir . $sFileName.'.webp');
		return true;
	}