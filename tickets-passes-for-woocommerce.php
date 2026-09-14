<?php 
defined('ABSPATH') or die('No script kiddies please!'); 
    /*
     *  Plugin Name: Tickets & Passes for WooCommerce
     *  Description: Sell tickets, timeslot bookings and passes with WooCommerce, and check visitors in at the door with a built-in QR scanner.
     *  Version: 1.3.0
     *  Requires at least: 6.5
     *  Tested up to: 7.1
     *  Requires PHP: 8.0
     *  Requires Plugins: woocommerce
     *  Author: Magnus V.
     *  Text Domain: tickets-passes-for-woocommerce
     *  Domain Path: /languages
     *  License: GPLv2 or later
     *  License URI: https://www.gnu.org/licenses/gpl-2.0.html
    */

    // Derived from __FILE__, never from a hardcoded folder name: a site owner (or
    // wordpress.org) can install this under any directory. DIR and URL carry a trailing slash.
    define('TPFW_PLUGIN_FILE', __FILE__);
    define('TPFW_PLUGIN_DIR',  plugin_dir_path(__FILE__));
    define('TPFW_PLUGIN_URL',  plugin_dir_url(__FILE__));

    // Keep in step with the Version: header above - asset cache-busting keys off it.
    define('TPFW_VERSION', '1.3.0');

    // Bump to force a one-time rewrite flush on existing sites. See tpfw_maybe_flush_rewrites().
    // 4: the My Account endpoints gained the plugin prefix (/pass -> /tpfw-pass).
    // 5: /checkin alias for /check-in/.
    define('TPFW_REWRITE_VERSION', '5');

    add_action('plugins_loaded', 'tpfw_load_textdomain', 0);
    /**
     * Loads translations for the current WordPress locale.
     *
     * WordPress still checks WP_LANG_DIR/plugins/ first, so language packs win.
     * Bundled {domain}-{locale}.mo files under languages/ are the fallback — sv_SE
     * ships in the zip; other locales can be added there later with no PHP change.
     *
     * @return void
     */
    function tpfw_load_textdomain()
    {
        load_plugin_textdomain(
            'tickets-passes-for-woocommerce',
            false,
            dirname(plugin_basename(TPFW_PLUGIN_FILE)).'/languages'
        );
    }

    /**
     * Whether WooCommerce is loaded.
     *
     * WooCommerce loads on 'plugins_loaded', so by 'init' - where this is checked - its
     * class exists if and only if WooCommerce is active. Unlike is_plugin_active() this
     * works identically on the front end and in wp-admin, and needs no extra include.
     *
     * @return bool
     */
    function tpfw_is_woocommerce_active()
    {
        return class_exists('WooCommerce');
    }

    add_action('before_woocommerce_init', 'tpfw_declare_woocommerce_compatibility');
    /**
     * Declares HPOS and Cart/Checkout Blocks compatibility.
     *
     * The plugin already is HPOS-safe - orders are only ever touched through wc_get_order()
     * and $oOrder->get_meta(), never get_post_meta() or a wp_posts query - but without this
     * declaration WooCommerce lists it as incompatible and refuses to let the site owner
     * turn HPOS on. 'before_woocommerce_init' is the only point the declaration is read.
     *
     * @return void
     */
    function tpfw_declare_woocommerce_compatibility()
    {
        if(!class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class))
        {
            return;
        }

        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', TPFW_PLUGIN_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', TPFW_PLUGIN_FILE, true);
    }

    add_action('admin_init', 'tpfw_check_woocommerce_still_active');
    /**
     * Safety net for WooCommerce being deactivated after this plugin was already running.
     *
     * Deactivates this plugin and explains why, rather than leaving it active to fatal on
     * the next WC_Product or WC_Order reference.
     *
     * @return void
     */
    function tpfw_check_woocommerce_still_active()
    {
        if(!is_plugin_active(plugin_basename(__FILE__)) || tpfw_is_woocommerce_active())
        {
            return;
        }

        deactivate_plugins(plugin_basename(__FILE__));
        add_action('admin_notices', 'tpfw_woocommerce_missing_notice');

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only input used to decide what to display; no state is changed.
        if(isset($_GET['activate']))
        {
            unset($_GET['activate']);
        }
    }

    /**
     * Admin notice explaining the automatic deactivation above.
     *
     * @return void
     */
    function tpfw_woocommerce_missing_notice()
    {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('Tickets & Passes for WooCommerce requires WooCommerce to be installed and active. It has been deactivated.', 'tickets-passes-for-woocommerce'); ?></p>
        </div>
        <?php
    }

    require_once dirname(__FILE__).'/class--main.php';
    require_once dirname(__FILE__).'/inc/db-installer/class--db-installer.php';

    register_activation_hook(__FILE__, 'tpfw_activate_plugin');
    /**
     * Activation hook: verifies WooCommerce and installs the database tables.
     *
     * Deliberately does NOT flush rewrite rules. Activation runs from the plugins.php
     * request, where WordPress only includes this file long after 'init' has fired - so none
     * of the plugin's rules or endpoints exist yet and flushing here persisted a rule set
     * with /check-in/ missing, leaving the scanner 404ing until permalinks were resaved by
     * hand. Clearing the version option instead defers the flush to the next request, where
     * 'init' has run. See tpfw_maybe_flush_rewrites().
     *
     * @return void
     */
    function tpfw_activate_plugin()
    {
        if(!tpfw_is_woocommerce_active())
        {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                esc_html__('Tickets & Passes for WooCommerce requires WooCommerce to be installed and active.', 'tickets-passes-for-woocommerce'),
                esc_html__('Plugin activation error', 'tickets-passes-for-woocommerce'),
                ['back_link' => true]
            );
        }

        $oDBInstaller = new TPFW_DB_Installer();
        $oDBInstaller->maybe_install();

        // Defers the rewrite flush to the next request - see the note above.
        delete_option('tpfw_rewrite_version');

        // Creates the randomised upload base and its guard files up front, so the first customer
        // order is not the thing that discovers the folder cannot be written.
        require_once dirname(__FILE__).'/inc/functions/class--functions.php';
        $oFunctions = new TPFW_Functions('tpfw');
        foreach(array_keys(TPFW_Functions::FILE_TYPE_FOLDERS) as $sFileType)
        {
        	$oFunctions->get_upload_dir_for_type($sFileType);
        }
    }

    register_deactivation_hook(__FILE__, 'tpfw_deactivate_plugin');
    /**
     * Deactivation hook: clears the recurring cron events and drops the rewrite rules.
     *
     * Both events recur, so without this they stay in the cron array firing at a hook
     * nothing listens to, and start up again on reactivation regardless of settings.
     *
     * @return void
     */
    function tpfw_deactivate_plugin()
    {
        wp_clear_scheduled_hook('tpfw_hourly_create_recurring_timeslots_cronjob');
        wp_clear_scheduled_hook('tpfw_minut_delete_expired_reservations_cronjob');

        // The My Account endpoints and /check-in/ are gone now; drop the rules that point
        // at them so those URLs 404 cleanly instead of resolving to nothing.
        flush_rewrite_rules();
    }

    add_action('wp_loaded', 'tpfw_maybe_flush_rewrites', 99);
    /**
     * Flushes rewrite rules once per TPFW_REWRITE_VERSION bump.
     *
     * Hooked late on 'wp_loaded' - after every 'init' callback - so both the scanner's
     * add_rewrite_rule() and the My Account add_rewrite_endpoint() calls have already run.
     * Doing this inside any one class would flush before the classes constructed after it
     * had registered anything.
     *
     * @return void
     */
    function tpfw_maybe_flush_rewrites()
    {
        if(get_option('tpfw_rewrite_version') === TPFW_REWRITE_VERSION)
        {
            return;
        }

        flush_rewrite_rules();
        update_option('tpfw_rewrite_version', TPFW_REWRITE_VERSION);
    }

    global $tpfw_oMain;
    add_action('init', 'tpfw_init_plugin', 20);
    /**
     * Boots the plugin on 'init', once WooCommerce is known to be present.
     *
     * @return void
     */
    function tpfw_init_plugin()
    {
        if(!tpfw_is_woocommerce_active())
        {
            return;
        }

        global $tpfw_oMain;
        $tpfw_oMain = new TPFW_Main();
    }