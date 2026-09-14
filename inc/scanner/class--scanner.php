<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Standalone full-screen check-in scanner, served at /check-in/.
 *
 * Deliberately NOT a wp-admin screen: door staff hold a phone one-handed in the dark and
 * must not be one mis-tap away from Plugins or Posts. This renders its own document - no
 * theme, no admin bar, no menus - and exits before WP loads either of them.
 *
 * It does still run inside WP, so login, roles and the REST nonce all work normally. The
 * page itself does no check-in logic; it only decodes the QR and calls the existing
 * tpfw/v1/scanner/checkin endpoint, so there is exactly one implementation of check-in.
 */
class TPFW_Scanner
{
	protected $sPrefix;
	protected $oFunctions;

	/**
	 * @param string        $sPrefix        Option/query-var prefix for the plugin ('tpfw').
	 * @param TPFW_Functions $oFunctions     Shared helper instance.
	 */
	public function __construct($sPrefix, $oFunctions)
	{
		$this->sPrefix 			= $sPrefix;
		$this->oFunctions 		= $oFunctions;
		$this->load_public_dependencies();
	}

	/**
	 * Wires the query var, the front-end route and the wp-admin shortcut.
	 *
	 * @return void
	 */
	private function load_public_dependencies()
	{
		add_filter('query_vars', array($this, 'register_query_var'));
		add_action('template_redirect', array($this, 'maybe_render_scanner'));
		// Priority 99, not the default 10: the parent 'tpfw' menu has no callback of its own, so
		// WordPress sends a click on it to whichever submenu was registered first. This class is
		// constructed before the settings pages, so at the default priority the scanner became
		// that first entry and clicking "Ticket & Passes" opened the camera instead of Settings.
		add_action('admin_menu', array($this, 'add_scanner_menu_link'), 99);
		$this->register_rewrite_rule();
	}



	/**
	 * Adds the "Scanner" entry under the plugin's admin menu, pointing at the front-end page.
	 *
	 * This whole class is only constructed when the scanner is enabled, so the link needs no
	 * condition of its own - turning the scanner off removes the class, the route and this
	 * entry together.
	 *
	 * Passing the URL as the menu slug with no callback is how WordPress renders a submenu
	 * item that points somewhere off the admin - the scanner is a front-end page by design
	 * (see the class docblock), so there is no admin screen to point at.
	 *
	 * @return void
	 */
	public function add_scanner_menu_link()
	{
		add_submenu_page(
			'tpfw',
			__('Scanner', 'tickets-passes-for-woocommerce'),
			__('Scanner', 'tickets-passes-for-woocommerce'),
			'manage_woocommerce',
			$this->get_scanner_url(),
			''
		);
	}



	/**
	 * Whitelists the query var the rewrite rule sets, so get_query_var() can read it back.
	 *
	 * @param array $aQueryVars Public query vars.
	 * @return array
	 */
	public function register_query_var($aQueryVars)
	{
		$aQueryVars[] = 'tpfw_scanner';
		return $aQueryVars;
	}



	/**
	 * Registers /check-in/ as a route.
	 *
	 * Registration only. Persisting the rule is tpfw_maybe_flush_rewrites() in
	 * tickets-passes-for-woocommerce.php, which runs on 'wp_loaded' - after every class here
	 * has registered its rules and endpoints. Flushing from inside this constructor dropped
	 * the My Account endpoints, because the classes that add them are constructed after this
	 * one.
	 *
	 * @return void
	 */
	private function register_rewrite_rule()
	{
		add_rewrite_rule('^check-in/?$', 'index.php?tpfw_scanner=1', 'top');
		// Alias: phones and typed URLs often drop the hyphen (/checkin).
		add_rewrite_rule('^checkin/?$', 'index.php?tpfw_scanner=1', 'top');
	}



	/**
	 * @return string Absolute URL of the scanner page.
	 */
	public function get_scanner_url()
	{
		return home_url('/check-in/');
	}



	/**
	 * Renders the scanner in place of the theme when the request is for /check-in/.
	 *
	 * Runs on 'template_redirect' and exits, so no theme template, admin bar or menu is ever
	 * loaded. Guests are sent to wp-login; logged-in users without the capability get a plain
	 * 403 page rather than a redirect loop.
	 *
	 * @return void
	 */
	public function maybe_render_scanner()
	{
		if(!get_query_var('tpfw_scanner'))
		{
			return;
		}

		// auth_redirect() sends guests to wp-login with redirect_to pointing back here, so
		// staff land on the scanner straight after logging in rather than on the dashboard.
		if(!is_user_logged_in())
		{
			auth_redirect();
			exit;
		}

		if(!$this->oFunctions->user_can_scan(wp_get_current_user()))
		{
			status_header(403);
			nocache_headers();
			$this->render_denied();
			exit;
		}

		status_header(200);
		nocache_headers();
		$this->render_scanner();
		exit;
	}



	/**
	 * Full 403 document for a logged-in user who may not check people in.
	 *
	 * Self-contained rather than wp_die(): the phone is already full-screen on the scanner URL,
	 * and the only useful action from here is switching account.
	 *
	 * @return void
	 */
	private function render_denied()
	{
		wp_register_style($this->sPrefix.'scanner-access-denied', plugins_url('', __FILE__).'/css/access-denied.css', array(), filemtime(__DIR__.'/css/access-denied.css'));
		wp_enqueue_style($this->sPrefix.'scanner-access-denied');
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title><?php echo esc_html__('Access denied', 'tickets-passes-for-woocommerce'); ?></title>
	<?php wp_print_styles($this->sPrefix.'scanner-access-denied'); ?>
</head>
<body>
	<div>
		<h1><?php echo esc_html__('Access denied', 'tickets-passes-for-woocommerce'); ?></h1>
		<p><?php echo esc_html__('Your account is not allowed to check people in. Ask an administrator for the Scanner role.', 'tickets-passes-for-woocommerce'); ?></p>
		<p><a style="color:#7fb3ff" href="<?php echo esc_url(wp_logout_url($this->get_scanner_url())); ?>"><?php echo esc_html__('Log in as someone else', 'tickets-passes-for-woocommerce'); ?></a></p>
	</div>
</body>
</html>
		<?php
	}



	/**
	 * Full scanner document: camera view, check-in button, result notice and history drawer.
	 *
	 * All strings, colours and endpoints are handed to the JS through window.TPFW_SCANNER, so
	 * scanner.js contains no markup-facing translations and no hardcoded URLs.
	 *
	 * @return void
	 */
	private function render_scanner()
	{
		$sAssetsURL       = plugins_url('js/', __FILE__);
		$sCheckinBase     = get_rest_url(null, 'tpfw/v1/scanner/checkin/');
		$aSettingsOptions = $this->oFunctions->get_api_settings_options();
		$oUser            = wp_get_current_user();
		$sInitial         = mb_strtoupper(mb_substr($oUser->display_name, 0, 1));

		// Shown under the name in the menu header, so staff can see at a glance which account
		// the phone is signed in as. translate_user_role() is what wp-admin uses for this.
		$aUserRoles = array_values((array)$oUser->roles);
		$oWPRoles   = wp_roles();
		$sRoleLabel = (!empty($aUserRoles) && isset($oWPRoles->roles[$aUserRoles[0]]['name']))
		            ? translate_user_role($oWPRoles->roles[$aUserRoles[0]]['name'])
		            : '';

		// The scanner page always calls the endpoint on the site's *current* scheme and host.
		// QR codes carry whatever get_rest_url() returned when the ticket was issued, so a
		// site that later moved to https (or changed domain) still has old codes pointing at
		// the old URL. scanner.js therefore only reads the nano id out of the scanned text and
		// rebuilds the request from this base - which also keeps an https page from being
		// blocked for fetching an http URL.
		$aConfig = array(
			'sCheckinBase'          => $sCheckinBase,
			'sHistoryBase'          => get_rest_url(null, 'tpfw/v1/scanner/history'),
			'sNonce'                => wp_create_nonce('wp_rest'),
			'sErrorColor'           => !empty($aSettingsOptions['status_406']) ? $aSettingsOptions['status_406'] : '#c0392b',
			'sPlaceholderPhotoURL'  => TPFW_PLUGIN_URL . 'images/avatar-placeholder.webp',
			'aDummyResults' => array(
				array('iStatus' => 200, 'sMessage' => __('Ticket is valid and checked in', 'tickets-passes-for-woocommerce'), 'sHexColor' => '2e7d32'),
				array('iStatus' => 200, 'sMessage' => __('Pass is valid and checked in', 'tickets-passes-for-woocommerce'), 'sHexColor' => '2e7d32', 'sPhotoURL' => TPFW_PLUGIN_URL . 'images/avatar-placeholder.webp'),
				array('iStatus' => 202, 'sMessage' => __('Already checked in', 'tickets-passes-for-woocommerce'), 'sHexColor' => 'b8860b'),
				array('iStatus' => 401, 'sMessage' => __('Ticket not found', 'tickets-passes-for-woocommerce'), 'sHexColor' => 'c0392b'),
				array('iStatus' => 202, 'sMessage' => __('Not valid for today', 'tickets-passes-for-woocommerce'), 'sHexColor' => 'c0392b'),
			),
			'aStrings'     => array(
				'sStarting'       => __('Starting camera...', 'tickets-passes-for-woocommerce'),
				'sReady'          => __('Point the camera at a QR code', 'tickets-passes-for-woocommerce'),
				'sLocked'         => __('Code ready - tap Check In', 'tickets-passes-for-woocommerce'),
				'sChecking'       => __('Checking in...', 'tickets-passes-for-woocommerce'),
				'sDummyReady'     => __('No camera found - tap Check In to see a sample result', 'tickets-passes-for-woocommerce'),
				'sForeignCode'    => __('That QR code is not a ticket from this site.', 'tickets-passes-for-woocommerce'),
				'sNetworkError'   => __('No connection to the site. Check the signal and try again.', 'tickets-passes-for-woocommerce'),
				'sHistoryEmpty'   => __('No check-ins yet', 'tickets-passes-for-woocommerce'),
				'sHistoryError'   => __('Could not load history. Tap to try again.', 'tickets-passes-for-woocommerce'),
				'sSessionExpired' => __('Session expired - reload this page', 'tickets-passes-for-woocommerce'),
				'sManualInvalid'  => __('That is not a ticket code or check-in link from this site.', 'tickets-passes-for-woocommerce'),
				'sTypeTicket'     => __('Ticket', 'tickets-passes-for-woocommerce'),
				'sTypeTimeslot'   => __('Timeslot Ticket', 'tickets-passes-for-woocommerce'),
				'sTypePass' => __('Pass', 'tickets-passes-for-woocommerce'),
				'sTypeGuestpass'  => __('Guest Pass', 'tickets-passes-for-woocommerce'),
				'sJustNow'        => __('Just now', 'tickets-passes-for-woocommerce'),
				/* translators: %d: number of minutes since the check-in, e.g. "5m ago". */
				'sMinutesAgo'     => __('%dm ago', 'tickets-passes-for-woocommerce'),
				/* translators: %d: number of hours since the check-in, e.g. "3h ago". */
				'sHoursAgo'       => __('%dh ago', 'tickets-passes-for-woocommerce'),
				/* translators: %d: number of days since the check-in, e.g. "2d ago". */
				'sDaysAgo'        => __('%dd ago', 'tickets-passes-for-woocommerce'),
				'sToday'          => __('Today', 'tickets-passes-for-woocommerce'),
				'sYesterday'      => __('Yesterday', 'tickets-passes-for-woocommerce'),
			),
		);

		wp_register_style($this->sPrefix.'scanner', plugins_url('', __FILE__).'/css/scanner.css', array(), filemtime(__DIR__.'/css/scanner.css'));
		wp_enqueue_style($this->sPrefix.'scanner');

		wp_register_script($this->sPrefix.'jsqr', $sAssetsURL.'jsqr.js', array(), filemtime(__DIR__.'/js/jsqr.js'), true);
		wp_register_script($this->sPrefix.'scanner-parse-checkin-url', $sAssetsURL.'parse-checkin-url.js', array(), filemtime(__DIR__.'/js/parse-checkin-url.js'), true);
		wp_register_script($this->sPrefix.'scanner', $sAssetsURL.'scanner.js', array($this->sPrefix.'jsqr', $this->sPrefix.'scanner-parse-checkin-url'), filemtime(__DIR__.'/js/scanner.js'), true);
		wp_add_inline_script($this->sPrefix.'scanner', 'window.TPFW_SCANNER = ' . wp_json_encode($aConfig) . ';', 'before');
		wp_enqueue_script($this->sPrefix.'jsqr');
		wp_enqueue_script($this->sPrefix.'scanner-parse-checkin-url');
		wp_enqueue_script($this->sPrefix.'scanner');
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<?php // Handles are passed explicitly: the argument-less form fires the wp_print_styles /
	      // wp_print_scripts actions, which on this standalone document (no wp_enqueue_scripts,
	      // so wp_enqueue_emoji_styles() never unhooks its back-compat callback) triggers core's
	      // deprecated print_emoji_styles, plus every other plugin's frontend assets. ?>
	<meta charset="<?php bloginfo('charset'); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="theme-color" content="#101418">
	<title><?php echo esc_html__('Check-in scanner', 'tickets-passes-for-woocommerce'); ?></title>
	<?php wp_print_styles($this->sPrefix.'scanner'); ?>
</head>
<body>
	<div id="tpfw-scanner">
		<video id="tpfw-video" playsinline muted autoplay></video>
		<div id="tpfw-reticle"></div>

		<div id="tpfw-topbar">
			<button type="button" id="tpfw-menu-btn" aria-expanded="false" aria-controls="tpfw-menu" aria-label="<?php echo esc_attr__('Menu', 'tickets-passes-for-woocommerce'); ?>">
				<span></span><span></span><span></span>
			</button>
		</div>

		<div id="tpfw-bottom">
			<div id="tpfw-status"><?php echo esc_html__('Starting camera...', 'tickets-passes-for-woocommerce'); ?></div>
			<button type="button" id="tpfw-checkin-btn" disabled><?php echo esc_html__('Check In', 'tickets-passes-for-woocommerce'); ?></button>
			<button type="button" id="tpfw-cancel-btn" hidden><?php echo esc_html__('Cancel', 'tickets-passes-for-woocommerce'); ?></button>
		</div>

		<!-- Only shown once the camera reports torch support; low-light doors are the stated use case. -->
		<button type="button" id="tpfw-torch-btn" hidden aria-pressed="false" aria-label="<?php echo esc_attr__('Toggle flashlight', 'tickets-passes-for-woocommerce'); ?>" title="<?php echo esc_attr__('Toggle flashlight', 'tickets-passes-for-woocommerce'); ?>">
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h12v5l-3 3v12H9V10L6 7z"></path><line x1="12" y1="13" x2="12" y2="16"></line></svg>
		</button>

		<div id="tpfw-notice" role="alert" aria-live="assertive">
			<div id="tpfw-notice-icon"></div>
			<div id="tpfw-notice-body">
				<span id="tpfw-notice-message"></span>
			</div>
		</div>

		<div id="tpfw-menu-scrim"></div>
		<nav id="tpfw-menu" aria-label="<?php echo esc_attr__('Menu', 'tickets-passes-for-woocommerce'); ?>">
			<div class="tpfw-menu-view tpfw-menu-view--main">
				<div class="tpfw-menu-head">
					<span class="tpfw-menu-head-avatar" aria-hidden="true"><?php echo esc_html($sInitial); ?></span>
					<span class="tpfw-menu-head-text">
						<span class="tpfw-menu-head-name"><?php echo esc_html($oUser->display_name); ?></span>
						<?php if($sRoleLabel !== '') : ?>
						<span class="tpfw-menu-head-role"><?php echo esc_html($sRoleLabel); ?></span>
						<?php endif; ?>
					</span>
				</div>
				<ul>
					<li>
						<button type="button" id="tpfw-menu-history-btn">
							<svg class="tpfw-menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><polyline points="12 7 12 12 16 14"></polyline></svg>
							<?php echo esc_html__('History', 'tickets-passes-for-woocommerce'); ?>
						</button>
					</li>
					<li class="tpfw-menu-manual">
						<!-- Desk staff with no camera, or a code the camera cannot read: type or paste it. -->
						<form id="tpfw-manual-form" autocomplete="off">
							<label for="tpfw-manual-input"><?php echo esc_html__('Enter a code by hand', 'tickets-passes-for-woocommerce'); ?></label>
							<div class="tpfw-menu-manual-row">
								<input type="text" id="tpfw-manual-input" autocapitalize="off" autocorrect="off" spellcheck="false" placeholder="<?php echo esc_attr__('Ticket code or link', 'tickets-passes-for-woocommerce'); ?>">
								<button type="submit"><?php echo esc_html__('Check In', 'tickets-passes-for-woocommerce'); ?></button>
							</div>
						</form>
					</li>
				</ul>
				<div class="tpfw-menu-foot">
					<ul>
						<li>
							<a href="<?php echo esc_url(wp_logout_url($this->get_scanner_url())); ?>">
								<svg class="tpfw-menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
								<?php echo esc_html__('Log out', 'tickets-passes-for-woocommerce'); ?>
							</a>
						</li>
					</ul>
				</div>
			</div>
			<div class="tpfw-menu-view tpfw-menu-view--history">
				<div class="tpfw-menu-history-head">
					<button type="button" id="tpfw-menu-back-btn" aria-label="<?php echo esc_attr__('Back', 'tickets-passes-for-woocommerce'); ?>">&larr;</button>
					<span><?php echo esc_html__('Check-in history', 'tickets-passes-for-woocommerce'); ?></span>
					<button type="button" id="tpfw-history-refresh-btn" aria-label="<?php echo esc_attr__('Refresh history', 'tickets-passes-for-woocommerce'); ?>" title="<?php echo esc_attr__('Refresh history', 'tickets-passes-for-woocommerce'); ?>">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"></path><polyline points="21 3 21 9 15 9"></polyline></svg>
					</button>
				</div>
				<ul id="tpfw-history-list"></ul>
			</div>
		</nav>
	</div>

	<?php wp_print_scripts($this->sPrefix.'scanner'); ?>
</body>
</html>
		<?php
	}
}
