<?php
/**
 * Runs when the plugin is DELETED from the Plugins screen - not on deactivation.
 *
 * Two tiers, deliberately:
 *
 *  - Always removed: this plugin's own settings, the Scanner role it adds, and its scheduled
 *    events. All of that is configuration, it is ours alone, and reactivating rebuilds it.
 *
 *  - Only on explicit opt-in: the nine data tables and the generated QR/PDF/photo files.
 *    Those are sales and admission records. Someone clicking "Delete" to troubleshoot a
 *    plugin conflict must not silently destroy a season's ticket history, and a venue may
 *    still be legally required to hold it. This mirrors WooCommerce's own WC_REMOVE_ALL_DATA
 *    convention: define TPFW_REMOVE_ALL_DATA as true in wp-config.php to wipe everything.
 */

defined('WP_UNINSTALL_PLUGIN') or die('No script kiddies please!');

/**
 * Configuration-only cleanup. Safe to run unconditionally.
 *
 * @return void
 */
function tpfw_uninstall_site()
{
	global $wpdb;

	// Read before the delete loop below: the upload folder is named after this, so losing
	// it first would leave the files behind with no way to find them.
	$sUploadSlug = get_option('tpfw_upload_slug');

	$aOptions = array(
		'tpfw_general_settings_options',
		'tpfw_email_settings_options',
		'tpfw_api_settings_options',
		'tpfw_db_version',
		'tpfw_rewrite_version',
		'tpfw_scanner_rewrite_version', // legacy, superseded by tpfw_rewrite_version
		'tpfw_scanner_role_version',
		'tpfw_upload_slug',
		'tpfw_file_secret',
	);
	foreach($aOptions as $sOption)
	{
		delete_option($sOption);
	}

	// Both are recurring, so they survive deletion of the code that handled them and keep
	// firing a hook nothing listens to until someone clears them by hand.
	wp_clear_scheduled_hook('tpfw_hourly_create_recurring_timeslots_cronjob');
	wp_clear_scheduled_hook('tpfw_minut_delete_expired_reservations_cronjob');

	// Added by the plugin, so it goes with it. Users keep their accounts; they simply lose
	// this role. remove_role() is a no-op if it was never created.
	remove_role('tpfw_scanner');

	if(!defined('TPFW_REMOVE_ALL_DATA') || TPFW_REMOVE_ALL_DATA !== true)
	{
		return;
	}

	// ---- opt-in destructive section below this line ----

	// Child rows first is irrelevant for DROP, but the order keeps the list readable and
	// matches the installer. Names are hardcoded literals, never user input.
	$aTables = array(
		'tpfw_tickets',
		'tpfw_tickets_stats',
		'tpfw_timeslot_tickets',
		'tpfw_timeslot_tickets_stats',
		'tpfw_timeslots',
		'tpfw_timeslots_recurring',
		'tpfw_timeslot_reservations',
		'tpfw_pass',
		'tpfw_pass_stats',
	);
	foreach($aTables as $sTable)
	{
		$wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix.$sTable));
	}

	// Product settings (_tpfw_* post meta) and the per-unit ids on order lines (tpfw_* order
	// item meta) are this plugin's too. Products and orders themselves are left alone.
	$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->postmeta, $wpdb->esc_like('_tpfw_') . '%'));
	$wpdb->query($wpdb->prepare('DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->prefix . 'woocommerce_order_itemmeta', $wpdb->esc_like('tpfw_') . '%'));

	tpfw_uninstall_delete_uploads($sUploadSlug);
}

/**
 * Removes the generated QR codes, guest passes, PDFs, previews and pass photos.
 *
 * Everything the plugin writes lives under uploads/tpfw/, except pass photos when a custom
 * folder was configured - that path is read back out of the settings before they are deleted.
 *
 * @return void
 */
function tpfw_uninstall_delete_uploads($sUploadSlug = '')
{
	$aUpload = wp_upload_dir();
	if(!empty($aUpload['error']) || empty($aUpload['basedir']))
	{
		return;
	}

	// 'tpfw' is the pre-randomisation folder name, kept here so a site that ran an early
	// build does not get files orphaned in it.
	$aDirs = array(trailingslashit($aUpload['basedir']).'tpfw');
	if(is_string($sUploadSlug) && preg_match('/^[a-f0-9]{10}$/', $sUploadSlug))
	{
		$aDirs[] = trailingslashit($aUpload['basedir']).'tpfw-'.$sUploadSlug;
	}

	$aSettings = get_option('tpfw_general_settings_options');
	if(is_array($aSettings) && !empty($aSettings['sProfileUploadPath']))
	{
		// Only ever a folder name under uploads/ - basename() keeps a stored "../.." out of it.
		$sCustom = basename($aSettings['sProfileUploadPath']);
		if($sCustom !== '' && $sCustom !== '.' && $sCustom !== '..')
		{
			$aDirs[] = trailingslashit($aUpload['basedir']).$sCustom;
		}
	}

	foreach($aDirs as $sDir)
	{
		tpfw_uninstall_rmdir($sDir, trailingslashit($aUpload['basedir']));
	}
}

/**
 * Recursive delete, refusing to act outside the uploads directory.
 *
 * realpath() resolves symlinks and traversal before the prefix test, so a tampered settings
 * value cannot walk this out of uploads/ and into the rest of the site.
 *
 * @param string $sDir     Directory to remove.
 * @param string $sBaseDir Uploads base directory; nothing outside it is touched.
 * @return void
 */
function tpfw_uninstall_rmdir($sDir, $sBaseDir)
{
	$sReal = realpath($sDir);
	$sBase = realpath($sBaseDir);
	if($sReal === false || $sBase === false)
	{
		return;
	}

	$sReal = wp_normalize_path($sReal);
	$sBase = trailingslashit(wp_normalize_path($sBase));
	if(strpos($sReal, $sBase) !== 0 || !is_dir($sReal))
	{
		return;
	}

	global $wp_filesystem;
	if(!function_exists('WP_Filesystem'))
	{
		require_once ABSPATH.'wp-admin/includes/file.php';
	}
	// Returns false on hosts that need FTP/SSH credentials, which uninstall cannot prompt for;
	// leaving the files behind is the only safe outcome there.
	if(WP_Filesystem())
	{
		$wp_filesystem->delete($sReal, true);
	}
}

// On multisite WordPress runs this file once, not once per site, so every site's tables and
// options would otherwise survive on all but the one that happened to be current.
if(is_multisite())
{
	$tpfw_aSiteIDs = get_sites(array('fields' => 'ids', 'number' => 0));
	foreach($tpfw_aSiteIDs as $tpfw_iSiteID)
	{
		switch_to_blog($tpfw_iSiteID);
		tpfw_uninstall_site();
		restore_current_blog();
	}
}
else
{
	tpfw_uninstall_site();
}
