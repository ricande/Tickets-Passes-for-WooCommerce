<?php
defined('ABSPATH') or die('No script kiddies please!');
/**
 * The gate in front of every file this plugin stores.
 *
 * QR images, pass profile photos and generated ticket PDFs all live in wp_upload_dir(), and
 * none of them is reachable as a static URL: TPFW_Functions writes them into a folder whose
 * name carries a random suffix, and every link the plugin emits points here instead. Access is
 * therefore decided in PHP for every `?tpfw_file=` link. Apache may also honour the folder
 * `.htaccess`; nginx and IIS ignore it and need a server deny on `/wp-content/uploads/tpfw-*`
 * (see docs/server-config/). That deny does not replace this PHP route.
 *
 * Loaded unconditionally, unlike TPFW_API - the scanner and REST toggles govern who may check
 * people in, and must never be able to switch off the delivery of a customer's own ticket.
 *
 * @package Tickets_Passes_For_WooCommerce
 */
class TPFW_File_Access
{
	protected $oFunctions;

	/**
	 * @param TPFW_Functions $oFunctions Shared helper instance.
	 */
	public function __construct($oFunctions)
	{
		$this->oFunctions = $oFunctions;

		// Called straight out, not hooked. The plugin itself is booted from an init callback at
		// priority 20, so anything this class adds to init is registered after the priority it
		// would need to run at and never fires at all. Running here is also the right moment:
		// the current user is resolved by now, and a file request has no page to render, so
		// there is nothing later to wait for.
		$this->serve_file();
	}

	/**
	 * The tables a nano id may live in, for resolving who a stored file belongs to.
	 *
	 * @var array<int,string> Table names without the site's prefix.
	 */
	const OWNER_TABLES = array('tpfw_tickets', 'tpfw_timeslot_tickets', 'tpfw_pass');

	/**
	 * Extension => mime type for the file kinds this plugin stores. An extension that is not a
	 * key here is not served at all, so a stray upload can never be handed back as, say, HTML.
	 *
	 * @var array<string,string>
	 */
	const SERVABLE_MIMES = array(
		'webp' => 'image/webp',
		'png'  => 'image/png',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif'  => 'image/gif',
		'pdf'  => 'application/pdf',
	);

	/**
	 * File types whose stored file is rewritten behind an unchanged URL.
	 *
	 * A qr, guest or pdf file is named after the nano id it belongs to, written once when that
	 * row is created and never touched again, so a year of immutable caching is exactly right
	 * for it. The other two are not: a preview is rewritten every time the shop owner saves new
	 * QR colours, and a profile photo every time the pass holder uploads a new one - both under
	 * the same URL as before. Advertising those as immutable means the browser never asks again,
	 * and the owner keeps seeing last week's preview while the customer keeps seeing their old
	 * photo, with no reload that can clear it. They still get an ETag, so a revalidation that
	 * finds nothing changed is a 304 and costs no bandwidth.
	 *
	 * @var array<int,string> Keys of TPFW_Functions::FILE_TYPE_FOLDERS.
	 */
	const REWRITABLE_TYPES = array('preview', 'profile');

	/**
	 * Serves one stored file, if the caller is allowed it.
	 *
	 * Everything this plugin writes into wp_upload_dir() is served through here rather than as a
	 * static URL, so that access is decided in PHP. nginx and IIS ignore .htaccess and still
	 * need a server deny on the physical tpfw-* path. Runs on init, before the query is parsed,
	 * because there is no template to render and no reason to boot the rest of the request.
	 *
	 * Access differs per type, and each one is the loosest rule that still works:
	 *
	 * - qr / guest: signed. These end up in emails and in QR codes handed to guests, where
	 *   there is no session to check - the mail client has no cookies. The HMAC is the secret.
	 * - preview: capability. Settings-screen artefacts, only ever rendered into wp-admin.
	 * - pdf / profile: ownership. Only ever linked from My Account and the admin list, both of
	 *   which have a logged-in user, so a forwarded link buys nothing. A signature is accepted
	 *   too, for the scanner's profile photo - see TPFW_Functions::get_profile_photo_url().
	 *
	 * Every refusal is the same bare 404. Distinguishing "no such ticket" from "not yours"
	 * would turn this into an oracle for which nano ids exist.
	 *
	 * @return void
	 */
	public function serve_file()
	{
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a read-only file GET carrying its own HMAC or resolved against the caller's own session; a nonce would break the emailed links this exists to serve.
		if(empty($_GET['tpfw_file']))
		{
			return;
		}

		$sType = sanitize_key(wp_unslash($_GET['tpfw_file']));
		$sName = isset($_GET['id']) ? sanitize_text_field(wp_unslash($_GET['id'])) : '';
		$sExt  = isset($_GET['ext']) ? strtolower(sanitize_text_field(wp_unslash($_GET['ext']))) : 'webp';
		$sTok  = isset($_GET['t']) ? sanitize_text_field(wp_unslash($_GET['t'])) : '';
		$iExp  = isset($_GET['exp']) ? (int)$_GET['exp'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Both parts of the filename are rebuilt from an allowlist rather than taken as given,
		// so nothing shaped like a path separator or a traversal can reach the filesystem.
		if(!isset(TPFW_Functions::FILE_TYPE_FOLDERS[$sType])
			|| !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $sName)
			|| !isset(self::SERVABLE_MIMES[$sExt]))
		{
			$this->file_not_found();
		}

		if(!$this->may_access_file($sType, $sName, $sExt, $iExp, $sTok))
		{
			$this->file_not_found();
		}

		$sDir  = $this->oFunctions->get_upload_dir_for_type($sType);
		$sPath = $sDir.$sName.'.'.$sExt;

		// Belt and braces after the allowlist above: resolve the real path and confirm it did
		// not land outside the folder it is supposed to be in.
		$sReal = realpath($sPath);
		if($sReal === false || strpos($sReal, realpath($sDir)) !== 0 || !is_file($sReal))
		{
			$this->file_not_found();
		}

		$this->stream_file($sReal, $sName.'.'.$sExt, self::SERVABLE_MIMES[$sExt], $sType);
	}

	/**
	 * Decides whether the caller may have this file. See serve_file() for the reasoning.
	 *
	 * @param string $sType File type key.
	 * @param string $sName Base filename.
	 * @param string $sExt  Extension.
	 * @param int    $iExp  Expiry from the URL.
	 * @param string $sTok  Token from the URL.
	 * @return bool
	 */
	private function may_access_file($sType, $sName, $sExt, $iExp, $sTok)
	{
		if($sType === 'qr' || $sType === 'guest')
		{
			return $this->oFunctions->verify_file_token($sType, $sName, $iExp, $sTok);
		}

		if($sType === 'preview')
		{
			return current_user_can('edit_products') || current_user_can('manage_woocommerce');
		}

		// pdf and profile. A valid signature stands in for a session, which is what lets an
		// external scanner app render a pass holder's photo over Basic Auth.
		if($sTok !== '' && $this->oFunctions->verify_file_token($sType, $sName, $iExp, $sTok))
		{
			return true;
		}

		if(!is_user_logged_in())
		{
			return false;
		}

		if(current_user_can('manage_woocommerce'))
		{
			return true;
		}

		return $this->file_owner_id($sName) === get_current_user_id();
	}

	/**
	 * Resolves the user a nano id belongs to.
	 *
	 * A guest pass row carries the parent holder's user_id, so a pass holder reaches their own
	 * guests' files and nobody else's without a special case here.
	 *
	 * @param string $sNanoID Nano id, already validated against the allowlist in serve_file().
	 * @return int User id, or 0 when the id matches nothing.
	 */
	private function file_owner_id($sNanoID)
	{
		global $wpdb;

		foreach(self::OWNER_TABLES as $sTable)
		{
			$sSQL = $wpdb->prepare('SELECT user_id FROM %i WHERE nano_id = %s LIMIT 1', $wpdb->prefix.$sTable, $sNanoID);
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sSQL is the return value of $wpdb->prepare() above.
			$iUserID = $wpdb->get_var($sSQL);
			if($iUserID !== null)
			{
				return (int)$iUserID;
			}
		}

		return 0;
	}

	/**
	 * Writes the file to the client with caching that never crosses users.
	 *
	 * A file named after a nano id never changes, so the browser is told to keep it for a year
	 * and the request cost is paid once; the two types that do get rewritten revalidate instead
	 * (see REWRITABLE_TYPES). "private" bars shared caches by
	 * spec, Vary: Cookie keys anything that ignores that, and DONOTCACHEPAGE is what the host
	 * and plugin page caches (LiteSpeed, WP Rocket, W3TC, WP Super Cache) actually read. All
	 * three are needed: one customer's pass must never be handed to the next visitor.
	 *
	 * @param string $sPath     Verified absolute path.
	 * @param string $sFilename Filename to advertise.
	 * @param string $sMime     Content type.
	 * @param string $sType     File type key, to pick inline vs attachment.
	 * @return void
	 */
	private function stream_file($sPath, $sFilename, $sMime, $sType)
	{
		// ponytail: every file hit boots WordPress. The immutable/ETag pair means a browser pays
		// that once per file, so it only matters for a cold cache with hundreds of QR codes on
		// one page. Move to a tiny front-controller that short-circuits before wp-load if it does.
		if(!defined('DONOTCACHEPAGE'))
		{
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the name is the page caches' own contract; a tpfw_ prefix would define a constant none of them read.
			define('DONOTCACHEPAGE', true);
		}

		$iSize  = filesize($sPath);
		$iTime  = filemtime($sPath);
		$sETag  = '"'.md5($sFilename.'|'.$iSize.'|'.$iTime).'"';

		// "private" bars shared caches by spec; see the class docblock on REWRITABLE_TYPES for
		// why only some of these may also claim to be immutable.
		$sFreshness = in_array($sType, self::REWRITABLE_TYPES, true) ? 'no-cache, max-age=0' : 'max-age=31536000, immutable';

		header('Content-Type: '.$sMime);
		header('Cache-Control: private, '.$sFreshness);
		header('Vary: Cookie');
		header('X-Content-Type-Options: nosniff');
		header('X-Robots-Tag: noindex, nofollow');
		header('ETag: '.$sETag);
		header('Last-Modified: '.gmdate('D, d M Y H:i:s', $iTime).' GMT');

		// wp_unslash() is not optional here: WordPress runs add_magic_quotes() over $_SERVER, so the
		// quotes an ETag is wrapped in arrive as \" and no browser revalidation would ever match.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared, not used; only ever matched against an ETag this method generated.
		$sNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim(wp_unslash($_SERVER['HTTP_IF_NONE_MATCH'])) : '';
		if($sNoneMatch !== '' && $sNoneMatch === $sETag)
		{
			status_header(304);
			exit;
		}

		// A PDF is something the customer asked to download; an image is being rendered into a
		// page they are already looking at.
		$sDisposition = ($sType === 'pdf') ? 'attachment' : 'inline';
		header('Content-Disposition: '.$sDisposition.'; filename="'.sanitize_file_name($sFilename).'"');
		header('Content-Length: '.$iSize);

		// Anything already buffered would be prepended to the file body and corrupt it.
		while(ob_get_level() > 0)
		{
			ob_end_clean();
		}

		readfile($sPath); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a plugin-generated file to the browser; WP_Filesystem has no streaming equivalent and would load the whole PDF into memory.
		exit;
	}

	/**
	 * The single response every refusal and every miss shares.
	 *
	 * @return void
	 */
	private function file_not_found()
	{
		status_header(404);
		nocache_headers();
		exit;
	}

}
