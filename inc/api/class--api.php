<?php
defined('ABSPATH') or die('No script kiddies please!');
/**
 * REST API surface under the tpfw/v1 namespace.
 *
 * Serves the door scanner (check-in and history) and the calendar export for timeslot
 * tickets. See register_routes() for the trust model behind each route.
 *
 * @package Tickets_Passes_For_WooCommerce
 */
class TPFW_API
{
	protected $oFunctions;

	/**
	 * The tables one nano id namespace spans, in the order the check-in route probes them.
	 *
	 * @var array<string,string> Check-in type => table name without the site's table prefix.
	 */
	const CHECKIN_TABLES = array(
		'pass'     => 'tpfw_pass',
		'timeslot' => 'tpfw_timeslot_tickets',
		'ticket'   => 'tpfw_tickets',
	);

	/**
	 * @param TPFW_Functions $oFunctions     Shared helper instance.
	 */
	public function __construct($oFunctions) 
	{
		$this->oFunctions 		= $oFunctions;
		$this->load_public_dependencies();
	}

	/**
	 * Defers route registration to rest_api_init.
	 *
	 * @return void
	 */
	private function load_public_dependencies()
	{
		add_action('rest_api_init', array($this, 'register_routes'));
	}

	

	/**
	 * Registers the tpfw/v1 namespace.
	 *
	 * The .ics route is deliberately open (permission_callback returns true) because the nano
	 * id in the URL is itself the secret: the QR code and the confirmation email already hand
	 * it to whoever is meant to have it. Everything that writes - both check-in routes - and
	 * /scanner/history, which exposes other people's scans, gate on a permission callback.
	 *
	 * @return void
	 */
	public function register_routes()
	{
		// The route regex alone accepts any length of \w, so a multi-kilobyte "nano id" reached
		// the database on every request. Declaring it as an arg lets the REST layer reject
		// anything that is not shaped like a real nano id (generateNanoId() emits 21 chars from
		// [A-Za-z0-9]; the route's \w+ already excludes "-") with a 400, before a single query runs.
		$aNanoIDArgs = array(
			'nano_id' => array(
				'required'          => true,
				'validate_callback' => static function($sValue) { return is_string($sValue) && preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $sValue) === 1; },
				'sanitize_callback' => 'sanitize_text_field',
			),
		);

		register_rest_route( 'tpfw/v1', '/scanner/checkin/(?P<nano_id>\w+)', array(
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'scanner_checkin'),
				'permission_callback' => array($this, 'scanner_permission'),
				'args'                => $aNanoIDArgs,
			),
			array(
				'methods'             => 'GET',
				'callback'            => array($this, 'scanner_checkin_not_allowed'),
				'permission_callback' => array($this, 'scanner_permission'),
				'args'                => $aNanoIDArgs,
			),
		  ));

		  register_rest_route( 'tpfw/v1', '/scanner/checkin/(?P<nano_id>\w+)/guest', array(
			array(
				'methods'             => 'POST',
				'callback'            => array($this, 'scanner_checkin_guest'),
				'permission_callback' => array($this, 'scanner_permission'),
				'args'                => $aNanoIDArgs,
			),
			array(
				'methods'             => 'GET',
				'callback'            => array($this, 'scanner_checkin_not_allowed'),
				'permission_callback' => array($this, 'scanner_permission'),
				'args'                => $aNanoIDArgs,
			),
		  ));

		  register_rest_route( 'tpfw/v1', '/scanner/history', array(
			'methods'             => 'GET',
			'callback'            => array($this, 'scanner_history'),
			'permission_callback' => array($this, 'scanner_permission'),
		  ));

		  // Same trust model as the checkin routes above: the nano_id is the unguessable
		  // secret (it is what the QR code and the emailed link both already expose), so no
		  // login is required - a customer opening this link from their confirmation email on
		  // a different device, not logged in, must still get their calendar file.
		  register_rest_route( 'tpfw/v1', '/timeslot-ticket/ics/(?P<nano_id>\w+)', array(
			'methods'             => 'GET',
			'callback'            => array($this, 'timeslot_ticket_ics'),
			'permission_callback' => '__return_true',
			'args'                => $aNanoIDArgs,
		  ));
	}



	/**
	 * GET /tpfw/v1/timeslot-ticket/ics/<nano_id> - returns the ticket as a calendar file.
	 *
	 * Writes the body directly and exits rather than returning a response, since the REST
	 * server would otherwise JSON-encode it.
	 *
	 * @param WP_REST_Request $request Carries the validated nano_id.
	 * @return WP_REST_Response|void 404 response when the ticket does not exist, otherwise exits.
	 */
	public function timeslot_ticket_ics(WP_REST_Request $request)
	{
		$sNanoID = $request->get_param('nano_id');
		$sICS    = $this->oFunctions->generate_timeslot_ticket_ics($sNanoID);
		if($sICS === false)
		{
			return new WP_REST_Response(array('sMessage' => __('Timeslot ticket could not be found', 'tickets-passes-for-woocommerce')), 404);
		}

		// A calendar file is per-ticket and lives behind an unguessable id - it must never be
		// held by a proxy or a page cache and handed to the next visitor asking for a .ics.
		nocache_headers();
		header('Content-Type: text/calendar; charset=utf-8');
		header('Content-Disposition: attachment; filename="'.sanitize_file_name($sNanoID).'.ics"');
		header('Content-Length: '.strlen($sICS));

		// Raw output + exit rather than a WP_REST_Response: the response has to be a bare .ics
		// body, and anything returned from here would be JSON-encoded by the REST server.
		echo $sICS; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar body assembled by generate_timeslot_ticket_ics(), not HTML; escaping would corrupt it.
		exit;
	}



	/**
	 * Permission callback for all three scanner routes.
	 *
	 * Cookie + X-WP-Nonce (built-in /check-in/) and Application Passwords are authenticated
	 * by WordPress before this runs. X-TPFW-Scanner-Token is the plugin token path.
	 * get_basic_auth_user() only checks capability (and Enable API for external callers).
	 * The resolved user is set current so stats rows record who scanned.
	 *
	 * This lives here rather than at the top of each callback so the REST server rejects an
	 * unauthenticated request before the callback (and its database work) is ever reached,
	 * and so the route registration states its own access rule.
	 *
	 * History used to gate on the login cookie alone, so an external app authenticating exactly
	 * as the settings screen documents could check people in but never read back what it had
	 * scanned. One callback for all three routes is what stops that drifting again.
	 *
	 * @param WP_REST_Request $request Carries the Authorization header, when there is one.
	 * @return true|WP_Error True when the caller may check people in.
	 */
	public function scanner_permission(WP_REST_Request $request)
	{
		$oScannerUser = $this->oFunctions->get_basic_auth_user(
			$request->get_header('authorization'),
			(string)$request->get_header('x-tpfw-scanner-token')
		);

		if(!$oScannerUser)
		{
			$aSettingsOptions = $this->oFunctions->get_api_settings_options();

			// sHexColor rides along in the error data so the scanner UI paints a rejected
			// login in the same configured colour as every other refusal.
			return new WP_Error(
				'tpfw_scanner_forbidden',
				__('Access denied', 'tickets-passes-for-woocommerce'),
				array('status' => 401, 'sMessage' => __('Access denied', 'tickets-passes-for-woocommerce'), 'sHexColor' => $aSettingsOptions['status_406'])
			);
		}

		wp_set_current_user($oScannerUser->ID);

		return true;
	}



	/**
	 * GET /tpfw/v1/scanner/history - the 50 most recent check-ins performed by this scanner.
	 *
	 * @param WP_REST_Request $request Unused.
	 * @return WP_REST_Response
	 */
	public function scanner_history(WP_REST_Request $request)
	{
		$aData = array(
			'aHistory' => $this->oFunctions->get_scanner_checkin_history(get_current_user_id(), 50),
		);
		return new WP_REST_Response($aData, 200);
	}




	/**
	 * Looks one nano id up in a table.
	 *
	 * @param wpdb   $wpdb    Database handle.
	 * @param string $sTable  Table name without the site's table prefix.
	 * @param string $sNanoID Validated nano id from the route.
	 * @return object|null The row, or null when this table does not have it.
	 */
	private function get_row_by_nano_id($wpdb, $sTable, $sNanoID)
	{
		$oPrepared = $wpdb->prepare(
			'SELECT * FROM %i WHERE nano_id = %s AND deleted IS NULL LIMIT 1;',
			array(
				$wpdb->prefix . $sTable,
				$sNanoID,
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $oPrepared is the return value of $wpdb->prepare() above.
		return $wpdb->get_row($oPrepared);
	}



	/**
	 * Wraps whatever TPFW_Functions::checkin() returned into a response.
	 *
	 * Refusals already carry their own status (202 for something staff must read, 401 for a code
	 * that does not add up), so only the success array needs wrapping - doing it unconditionally
	 * is what used to bury a refusal inside a hardcoded 200.
	 *
	 * @param WP_REST_Response|array $mResult Return value of TPFW_Functions::checkin().
	 * @return WP_REST_Response
	 */
	private function checkin_response($mResult)
	{
		return ($mResult instanceof WP_REST_Response) ? $mResult : new WP_REST_Response($mResult, 200);
	}



	/**
	 * GET /scanner/checkin/... used to mutate. It now refuses without writing a stats row.
	 *
	 * @param WP_REST_Request $request Unused.
	 * @return WP_REST_Response
	 */
	public function scanner_checkin_not_allowed(WP_REST_Request $request)
	{
		$aSettingsOptions = $this->oFunctions->get_api_settings_options();
		$oResponse = new WP_REST_Response(array(
			'sMessage'  => __('Check-in must use POST', 'tickets-passes-for-woocommerce'),
			'sHexColor' => $aSettingsOptions['status_406'],
		), 405);
		$oResponse->header('Allow', 'POST');
		return $oResponse;
	}



	/**
	 * POST /tpfw/v1/scanner/checkin/<nano_id>/guest - checks in a guest pass.
	 *
	 * Resolves the guest pass and insists its parent still exists; everything after that -
	 * validity window, max uses, cooldown and the locking that stops one QR admitting two people
	 * scanned at once - is TPFW_Functions::checkin_guest_pass(), the same implementation the
	 * other three types use.
	 *
	 * @param WP_REST_Request $request Carries the validated nano_id.
	 * @return WP_REST_Response
	 */
	public function scanner_checkin_guest(WP_REST_Request $request)
	{
		global $wpdb;

		$sNanoID          = $request->get_param('nano_id');
		$aSettingsOptions = $this->oFunctions->get_api_settings_options();

		$oGuestPass = $this->get_row_by_nano_id($wpdb, 'tpfw_pass', $sNanoID);
		if(!$oGuestPass || empty($oGuestPass->parent_nano_id_fk))
		{
			return new WP_REST_Response(array(
				'sMessage'  => __('Guest Pass could not be found with the given id', 'tickets-passes-for-woocommerce'),
				'sHexColor' => $aSettingsOptions['status_406'],
			), 401);
		}

		if(!$this->get_row_by_nano_id($wpdb, 'tpfw_pass', $oGuestPass->parent_nano_id_fk))
		{
			return new WP_REST_Response(array(
				'sMessage'  => __('Parent Pass could not be found with the given id', 'tickets-passes-for-woocommerce'),
				'sHexColor' => $aSettingsOptions['status_406'],
			), 401);
		}

		return $this->checkin_response($this->oFunctions->checkin_guest_pass($wpdb, $oGuestPass, get_current_user_id()));
	}



	/**
	 * POST /tpfw/v1/scanner/checkin/<nano_id> - checks in a pass, timeslot ticket or ticket.
	 *
	 * One nano id namespace covers all three types, so this probes each table in turn and hands
	 * the row it finds to TPFW_Functions::checkin(), which does the locking, the validity window,
	 * max uses and the cooldown. The nano id is unique per table and validated by the route, so
	 * there is nothing to check here beyond "which table is it in".
	 *
	 * @param WP_REST_Request $request Carries the validated nano_id.
	 * @return WP_REST_Response
	 */
	public function scanner_checkin(WP_REST_Request $request)
	{
		global $wpdb;

		$sNanoID = $request->get_param('nano_id');

		foreach(self::CHECKIN_TABLES as $sType => $sTable)
		{
			$oRow = $this->get_row_by_nano_id($wpdb, $sTable, $sNanoID);
			if(!$oRow) continue;

			// The pass table holds guest passes too, and they have their own cooldown setting -
			// scanning one at the plain route used to apply the parent product's pass cooldown.
			// The parent must still be live, exactly as the /guest route insists, so dropping
			// the "/guest" suffix cannot dodge that rule.
			if($sType === 'pass' && !empty($oRow->parent_nano_id_fk))
			{
				$sType = 'guestpass';
				if(!$this->get_row_by_nano_id($wpdb, 'tpfw_pass', $oRow->parent_nano_id_fk))
				{
					$aSettingsOptions = $this->oFunctions->get_api_settings_options();
					return new WP_REST_Response(array(
						'sMessage'  => __('Parent Pass could not be found with the given id', 'tickets-passes-for-woocommerce'),
						'sHexColor' => $aSettingsOptions['status_406'],
					), 401);
				}
			}

			return $this->checkin_response($this->oFunctions->checkin($wpdb, $sType, $oRow, get_current_user_id()));
		}

		$aSettingsOptions = $this->oFunctions->get_api_settings_options();

		return new WP_REST_Response(array(
			'sMessage'  => __('Timeslot / Ticket / Pass could not be found with the given id', 'tickets-passes-for-woocommerce'),
			'sHexColor' => $aSettingsOptions['status_406'],
		), 401);
	}


}
