<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Analytics screen: check-in charts and the CSV export behind them.
 *
 * Reads the three *_stats tables rather than the ticket/pass tables - the question the
 * screen answers is how many people came through the door and when, not how many were sold.
 *
 * @package Tickets_Passes_For_WooCommerce
 */
class TPFW_Analytics_Dashboard
{
	/**
	 * Where a check-in of each product type is recorded.
	 *
	 * @var array<string,array{sStatsTable:string,sRowTable:string,bHasGuestPasses:bool}> Product
	 *      class name => table names without the site's prefix, and whether that table also holds
	 *      guest passes that must be kept out of per-product counts.
	 */
	const CHECKIN_SOURCES = array(
		'TPFW_Product_Ticket'          => array('sStatsTable' => 'tpfw_tickets_stats',          'sRowTable' => 'tpfw_tickets',          'bHasGuestPasses' => false),
		'TPFW_Product_Timeslot_Ticket' => array('sStatsTable' => 'tpfw_timeslot_tickets_stats', 'sRowTable' => 'tpfw_timeslot_tickets', 'bHasGuestPasses' => false),
		'TPFW_Product_Pass'            => array('sStatsTable' => 'tpfw_pass_stats',             'sRowTable' => 'tpfw_pass',             'bHasGuestPasses' => true),
	);

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
	 * Registers the Analytics screen, its assets and the three chart endpoints.
	 *
	 * @return void
	 */
	public function run()
	{

		add_action('admin_enqueue_scripts', array($this, 'enqueue_script_admin'));
		
		add_action('admin_menu', array($this, 'add_settings_page'));
		
		add_action('wp_ajax_tpfw_ajax_fetch_yearly_checkin_stats', array($this, 'ajax_fetch_yearly_checkin_stats_callback'));

		add_action('wp_ajax_tpfw_ajax_fetch_daily_checkin_stats', array($this, 'ajax_fetch_daily_checkin_stats_callback'));

		add_action('wp_ajax_tpfw_ajax_fetch_yearly_checkin_stats_csv', array($this, 'ajax_fetch_yearly_checkin_stats_csv_callback'));
	}
		
		

	

	/**
	 * Adds the Analytics entry to the plugin menu.
	 *
	 * @return void
	 */
	public function add_settings_page()
	{
		add_submenu_page(
			'tpfw',
			__('Analytics', 'tickets-passes-for-woocommerce'),
			__('Analytics', 'tickets-passes-for-woocommerce'),
			'manage_woocommerce',
			'tpfw-analytics',
			array($this, 'theme_settings_page'),
		);
	}

	/**
	 * Renders the Analytics screen.
	 *
	 * @return void
	 */
	public function theme_settings_page() 
	{
		$this->page_content();
	}

	/**
	 * Includes the screen markup.
	 *
	 * @return void
	 */
	public function page_content()
	{				
		include('pages/page-content.php');		
	}


	/**
	 * Loads ApexCharts, the dashboard script and its stylesheet, and only on this screen.
	 *
	 * Month names are localised here rather than in JavaScript so they follow the site language
	 * like every other string in the plugin. The screen's own copy is printed by page-content.php
	 * and read back out of the DOM, so only the handful of strings the script has to build at
	 * runtime - month names, the export filename, the failure message - are passed through here.
	 *
	 * @return void
	 */
	public function enqueue_script_admin()
    {
		global $pagenow;
        $aParams = array
        (
			'aNonces'       => $this->oFunctions->get_ajax_nonces(array('ajax_fetch_daily_checkin_stats', 'ajax_fetch_yearly_checkin_stats', 'ajax_fetch_yearly_checkin_stats_csv')),
			// A failed fetch or export used to leave the charts blank with no explanation, which
			// reads as "no check-ins this year" rather than "the request did not come back".
			'sLoadFailed'    => __('The statistics could not be loaded. Please try again.', 'tickets-passes-for-woocommerce'),
			'Checkin'        => __('Checkin', 'tickets-passes-for-woocommerce'),

			'January'   => __('January', 'tickets-passes-for-woocommerce'),
			'February'  => __('February', 'tickets-passes-for-woocommerce'),
			'March'     => __('March', 'tickets-passes-for-woocommerce'),
			'April'     => __('April', 'tickets-passes-for-woocommerce'),
			'May'       => __('May', 'tickets-passes-for-woocommerce'),
			'June'      => __('June', 'tickets-passes-for-woocommerce'),
			'July'      => __('July', 'tickets-passes-for-woocommerce'),
			'August'    => __('August', 'tickets-passes-for-woocommerce'),
			'September' => __('September', 'tickets-passes-for-woocommerce'),
			'October'   => __('October', 'tickets-passes-for-woocommerce'),
			'November'  => __('November', 'tickets-passes-for-woocommerce'),
			'December'  => __('December', 'tickets-passes-for-woocommerce'),
        );
		
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of the current admin screen to decide whether to enqueue assets; no state is changed.
		if(isset($pagenow) && $pagenow == 'admin.php' && isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'] ?? '')) == 'tpfw-analytics')
    	{
	        wp_register_script($this->sPrefix.'apexcharts', plugins_url('', __FILE__).'/lib/apexcharts.min.js', array(), '6.9.0', true);
	        wp_enqueue_script($this->sPrefix.'apexcharts');

	        wp_register_script($this->sPrefix.'analytics-single', plugins_url('', __FILE__).'/js/analytics-single.js', array('jquery', $this->sPrefix.'apexcharts'), filemtime(dirname(__FILE__).'/js/analytics-single.js'), true);
	        wp_enqueue_script($this->sPrefix.'analytics-single');
	        wp_localize_script($this->sPrefix.'analytics-single', 'tpfwParamsAnalyticsSingle', $aParams);

            wp_enqueue_style($this->sPrefix.'analytics.module', plugins_url('', __FILE__).'/css/analytics.module.css', array(), filemtime(dirname(__FILE__).'/css/analytics.module.css'));
    	}
    }

	/**
	 * Exports the selected products' check-ins over a date range as CSV.
	 *
	 * One row per check-in, not per ticket: the point of the export is attendance, so a pass
	 * used twelve times is twelve lines. Requires the admin nonce and manage_woocommerce.
	 *
	 * @return void
	 */
	public function ajax_fetch_yearly_checkin_stats_csv_callback()
	{
		$response = array();
		check_ajax_referer('tpfw_ajax_fetch_yearly_checkin_stats_csv', 'security');
		if(!$this->oFunctions->user_can_manage())
		{
			$response['sMessage'] = __('Current user does not have admin privileges', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}

		if(!isset($_POST['sStartDate']) || empty($_POST['sStartDate']) || sanitize_text_field(wp_unslash($_POST['sStartDate'] ?? '')) == "")
		{
			$response['sMessage'] = __('The start date seems not to be set', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}

		if(!isset($_POST['sEndDate']) || empty($_POST['sEndDate']) || sanitize_text_field(wp_unslash($_POST['sEndDate'] ?? '')) == "")
		{
			$response['sMessage'] = __('The start date seems not to be set', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}

		if(!isset($_POST['aProductID']) ||empty($_POST['aProductID']) || map_deep(wp_unslash($_POST['aProductID'] ?? array()), 'sanitize_text_field') == "" || !is_array(map_deep(wp_unslash($_POST['aProductID'] ?? array()), 'sanitize_text_field')))
		{
			$response['sMessage'] = __('The product id seems not to be set, or not an array', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}
		
		$sStartDate = sanitize_text_field(wp_unslash($_POST['sStartDate'] ?? ''));
		$sEndDate   = sanitize_text_field(wp_unslash($_POST['sEndDate'] ?? ''));
		$aProductID = map_deep(wp_unslash($_POST['aProductID'] ?? array()), 'sanitize_text_field');				
		$aData 		= array();
				
		foreach($aProductID as $iProductKey => $iProductID)
		{
			$iProductID = sanitize_text_field($iProductID);
			$oProduct   = wc_get_product($iProductID);
			if(empty($oProduct) || $oProduct == null || $oProduct == false) { continue; }
			$sProductType     = get_class($oProduct);
			$aCheckingResults = $this->get_checkin_rows($sProductType, $sStartDate, $sEndDate, array($iProductID), null);
			if(!empty($aCheckingResults))
			{
				// One query for every holder in the export rather than one per row.
				cache_users(array_unique(array_column($aCheckingResults, 'user_id')));

				foreach($aCheckingResults as $iResultKey => $oCheckinResult)
				{
					// A deleted user still has check-ins in the stats table, and dereferencing
					// the false get_user_by() returns took the whole export down with a fatal -
					// the row is history and belongs in the file either way.
					$oUser = get_user_by('ID', $oCheckinResult->user_id);
					$aTemp = array(
						__('Customer ID', 'tickets-passes-for-woocommerce') => $oCheckinResult->user_id,
						__('Customer Email', 'tickets-passes-for-woocommerce') => $oUser ? $oUser->user_email : '',
						__('Customer Name', 'tickets-passes-for-woocommerce') => $oUser ? $oUser->first_name . ' ' . $oUser->last_name : '',
						__('Product ID', 'tickets-passes-for-woocommerce') => $oCheckinResult->product_id,
						__('Product Name', 'tickets-passes-for-woocommerce') => $oProduct->get_name(),
						__('Checkin Date', 'tickets-passes-for-woocommerce') => gmdate($this->oFunctions->get_datetime_format('date'), strtotime($oCheckinResult->created)),
					);
					$aData[] = $aTemp;					
				}
			}
		}

		if(empty($aData))
		{
			$response['sMessage'] = __('There are no check-ins in the selected range to export', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}

		$response['sMessage']              = __('Successfully fetched all available data, and transformed to a CSV file', 'tickets-passes-for-woocommerce');
		$response['sCSV']                  = $this->oFunctions->str_putcsv($aData);
		wp_send_json_success($response);
	}
	/**
	 * Returns one day's check-ins per product, bucketed into 24 hourly counts.
	 *
	 * Each series is coloured with the QR "Foreground Color" already chosen on that product's
	 * own edit screen, so a line matches the colour the shop manager picked for that exact
	 * product rather than a page-wide default.
	 *
	 * @return void
	 */
	public function ajax_fetch_daily_checkin_stats_callback()
	{
		$response = array();
		check_ajax_referer('tpfw_ajax_fetch_daily_checkin_stats', 'security');
		if(!$this->oFunctions->user_can_manage())
		{
			$response['sMessage'] = __('Current user does not have admin privileges', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}

		if(!isset($_POST['sDateYear']) || empty($_POST['sDateYear']) || sanitize_text_field(wp_unslash($_POST['sDateYear'] ?? '')) == "")
		{
			$response['sMessage'] = __('The year date seems not to be set', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}

		if(!isset($_POST['sDateMonth']) || empty($_POST['sDateMonth']) || sanitize_text_field(wp_unslash($_POST['sDateMonth'] ?? '')) == "")
		{
			$response['sMessage'] = __('The month date seems not to be set', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}

		if(!isset($_POST['sDateDay']) || empty($_POST['sDateDay']) || sanitize_text_field(wp_unslash($_POST['sDateDay'] ?? '')) == "")
		{
			$response['sMessage'] = __('The day date seems not to be set', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}		

		if(!isset($_POST['aProductID']) || empty($_POST['aProductID']) || map_deep(wp_unslash($_POST['aProductID'] ?? array()), 'sanitize_text_field') == "" || !is_array(map_deep(wp_unslash($_POST['aProductID'] ?? array()), 'sanitize_text_field')))
		{
			$response['sMessage'] = __('The product id seems not to be set or not an array', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}
		
		$sDateYear  = sanitize_text_field(wp_unslash($_POST['sDateYear'] ?? ''));
		$sDateMonth = str_pad(sanitize_text_field(wp_unslash($_POST['sDateMonth'] ?? '')), 2, '0', STR_PAD_LEFT);
		$sDateDay   = str_pad(sanitize_text_field(wp_unslash($_POST['sDateDay'] ?? '')), 2, '0', STR_PAD_LEFT);
		$sStartDate = $sDateYear . '-' . $sDateMonth . '-' . $sDateDay.' 00:00:00';		
		$aProductID = map_deep(wp_unslash($_POST['aProductID'] ?? array()), 'sanitize_text_field');
		// Same per-product QR "Foreground Color" already picked on the product's own edit
		// screen (each product type keeps its own meta key), so a series matches the color
		// the shop manager chose for that exact product rather than a page-wide default.
		$aForegroundMetaKeys = array(
			'TPFW_Product_Ticket'          => '_tpfw_ticket_qr_foreground_color',
			'TPFW_Product_Timeslot_Ticket' => '_tpfw_timeslot_qr_foreground_color',
			'TPFW_Product_Pass'            => '_tpfw_pass_qr_foreground_color',
		);

		$aSeries = [];
		foreach($aProductID as $iProductkey => $iProductID)
		{
			$iProductID = sanitize_text_field($iProductID);
			$oProduct   = wc_get_product($iProductID);
			if(empty($oProduct) || $oProduct == null || $oProduct == false) { continue; }
			$sProductType     = get_class($oProduct);
			$aCheckingResults = $this->get_checkin_rows($sProductType, $sStartDate, $sStartDate, array($iProductID), null);
			$aData            = array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,);
			if(!empty($aCheckingResults))
			{
				foreach($aCheckingResults as $iCheckingKey => $oCheckingResult)
				{
					$iHour = (int)gmdate('H', strtotime($oCheckingResult->created));
					$aData[($iHour)] = $aData[($iHour)]+1;
				}
				$sMetaKey      = $aForegroundMetaKeys[$sProductType] ?? '';
				$sProductColor = $sMetaKey ? get_post_meta($oProduct->get_id(), $sMetaKey, true) : '';
				$aSeries[] = array(
						'name'  => '(#'.$oProduct->get_id().')'.' '.$oProduct->get_name(),
						'data'  => $aData,
						'color' => !empty($sProductColor) ? $sProductColor : '#2e7d32',
				);
			}
		}

		$response['sMessage']              = __('Succesful fetched all available data', 'tickets-passes-for-woocommerce');
		$response['sFulleDate']            = gmdate($this->oFunctions->get_datetime_format('date'), strtotime($sStartDate));
		$response['aData']                 = $aSeries;
		wp_send_json_success($response);
	}
	/**
	 * Returns a year's check-ins as twelve month arrays of per-day totals.
	 *
	 * The full year is pre-filled with zeros before counting, so the chart draws a continuous
	 * line through days that had no admissions instead of skipping them.
	 *
	 * @return void
	 */
	public function ajax_fetch_yearly_checkin_stats_callback()
	{
		$response = array();
		check_ajax_referer('tpfw_ajax_fetch_yearly_checkin_stats', 'security');
		if(!$this->oFunctions->user_can_manage())
		{
			$response['sMessage'] = __('Current user does not have admin privileges', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}

		if(!isset($_POST['sStartDate']) || empty($_POST['sStartDate']) || sanitize_text_field(wp_unslash($_POST['sStartDate'] ?? '')) == "")
		{
			$response['sMessage'] = __('The start date seems not to be set', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}

		if(!isset($_POST['sEndDate']) || empty($_POST['sEndDate']) || sanitize_text_field(wp_unslash($_POST['sEndDate'] ?? '')) == "")
		{
			$response['sMessage'] = __('The start date seems not to be set', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}

		if(!isset($_POST['aProductID']) ||empty($_POST['aProductID']) || map_deep(wp_unslash($_POST['aProductID'] ?? array()), 'sanitize_text_field') == "" || !is_array(map_deep(wp_unslash($_POST['aProductID'] ?? array()), 'sanitize_text_field')))
		{
			$response['sMessage'] = __('The product id seems not to be set, or not an array', 'tickets-passes-for-woocommerce');
			wp_send_json_error($response);
		}
		
		$sStartDate = sanitize_text_field(wp_unslash($_POST['sStartDate'] ?? ''));
		$sEndDate   = sanitize_text_field(wp_unslash($_POST['sEndDate'] ?? ''));
		$aProductID = map_deep(wp_unslash($_POST['aProductID'] ?? array()), 'sanitize_text_field');		
		$aData      = array([], [], [], [], [], [], [], [], [], [], [], []);

		foreach($aData as $iDataKey => $oData)
		{
			$iStartDateYear = gmdate('Y', strtotime($sStartDate));
			$iMonth         = ($iDataKey+1);
			// gmdate('t') rather than cal_days_in_month(): the latter needs ext/calendar, which
			// is not compiled in by default and is missing on plenty of shared hosts - the
			// Analytics screen fatalled outright there.
			$iMonthDays 	= (int)gmdate('t', gmmktime(0, 0, 0, $iMonth, 1, (int)$iStartDateYear));
			for ($iDayDate = 1; $iDayDate <= $iMonthDays; $iDayDate++) 
			{
				$aData[$iMonth-1][$iDayDate-1] = array(
					'x' => $iDayDate,
					'y' => 0,
				);				
			}
		}
		
		foreach($aProductID as $iProductKey => $iProductID)
		{
			$iProductID = sanitize_text_field($iProductID);
			$oProduct   = wc_get_product($iProductID);
			if(empty($oProduct) || $oProduct == null || $oProduct == false) { continue; }
			$sProductType     = get_class($oProduct);
			$aCheckingResults = $this->get_checkin_rows($sProductType, $sStartDate, $sEndDate, array($iProductID), null);
			if(!empty($aCheckingResults))
			{
				foreach($aCheckingResults as $iResultKey => $oCheckinResult)
				{
					$iCreatedTimestamp = strtotime($oCheckinResult->created);
					$iMonth            = (int)gmdate('m', $iCreatedTimestamp);
					$iDay              = (int)gmdate('d', $iCreatedTimestamp);				
					if(isset($aData[$iMonth-1]) && isset($aData[$iMonth-1][$iDay-1]) && isset($aData[$iMonth-1][$iDay-1]['y']))
					{
						$aData[$iMonth-1][$iDay-1]['y'] = $aData[$iMonth-1][$iDay-1]['y']+1;
					}
				}
			}
		}

		$response['sMessage']              = __('Succesful fetched all available data', 'tickets-passes-for-woocommerce');
		$response['aData']                 = $aData;
		wp_send_json_success($response);
	}

	/**
	 * Reads check-ins of one product type in a date range.
	 *
	 * Joins the stats table back to the row table so each check-in carries the product and user
	 * it belongs to. Cancelled check-ins (deleted set) are excluded. The end date is widened by a
	 * day because the caller passes a date, not an instant, and the whole of that day counts.
	 *
	 * This was three near-identical methods, one per product type - the only things that ever
	 * differed are the two table names and whether guest passes have to be kept out.
	 *
	 * @param string $sProductType Product class name; anything not in CHECKIN_SOURCES reads nothing.
	 * @param string $sStartDate   Range start, anything strtotime() accepts.
	 * @param string $sEndDate     Range end, inclusive.
	 * @param array  $aProductID   Restrict to these product ids; empty means all.
	 * @param int    $iUserID      Restrict to one user, or null for all.
	 * @return array Rows of product_id, user_id, nano_id, created, nano_id_fk.
	 */
	public function get_checkin_rows($sProductType, $sStartDate, $sEndDate, $aProductID = array(), $iUserID = NULL)
	{
		if(!isset(self::CHECKIN_SOURCES[$sProductType])) { return array(); }

		global $wpdb;

		$aSource     = self::CHECKIN_SOURCES[$sProductType];
		$sStatsTable = $wpdb->prefix . $aSource['sStatsTable'];
		$sRowTable   = $wpdb->prefix . $aSource['sRowTable'];

		// [start 00:00:00, end + 1 day 00:00:00): the end is a date whose whole day counts, and
		// the exclusive upper bound keeps the first second of the following day out.
		$aSQLData = array(
			gmdate('Y-m-d H:i:s', strtotime($sStartDate)),
			gmdate('Y-m-d H:i:s', strtotime($sEndDate) + 86400),
		);

		$sSQL  = 'SELECT `'.$sRowTable.'`.product_id, `'.$sRowTable.'`.user_id, `'.$sRowTable.'`.nano_id, `'.$sStatsTable.'`.created, `'.$sStatsTable.'`.nano_id_fk';
		$sSQL .= ' FROM `'.$sStatsTable.'`';
		$sSQL .= ' INNER JOIN `'.$sRowTable.'` ON `'.$sRowTable.'`.nano_id = `'.$sStatsTable.'`.nano_id_fk';
		$sSQL .= ' WHERE `'.$sStatsTable.'`.deleted IS NULL';
		$sSQL .= ' AND `'.$sStatsTable.'`.created >= %s AND `'.$sStatsTable.'`.created < %s';

		if(!empty($aProductID))
		{
			// The join already has the row table in hand, so this filters it directly - the old
			// subquery re-read that same table just to collect the nano ids of the rows the join
			// had already matched.
			$sSQL     .= ' AND `'.$sRowTable.'`.product_id IN ('.implode(', ', array_fill(0, count($aProductID), '%d')).')';
			$aSQLData  = array_merge($aSQLData, array_values($aProductID));

			// A guest pass is issued off a parent pass rather than sold, so counting it against
			// the product would double-count the sale.
			if($aSource['bHasGuestPasses']) { $sSQL .= ' AND `'.$sRowTable.'`.parent_nano_id_fk IS NULL'; }
		}

		// This used to be appended to a $sSQL/$aSQLData that existed nowhere in the method, so
		// passing a user id built no filter at all and quietly returned every user's check-ins.
		if($iUserID != null && is_int($iUserID))
		{
			$sSQL      .= ' AND `'.$sRowTable.'`.user_id = %d';
			$aSQLData[] = $iUserID;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- query text is assembled from literal fragments and table names built off $wpdb->prefix; the placeholders live inside $sSQL where the sniff cannot count them, and every value goes through prepare().
		$oPrepared = $wpdb->prepare($sSQL . ';', $aSQLData);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oPrepared is the return value of $wpdb->prepare() above.
		return $wpdb->get_results($oPrepared);
	}
}