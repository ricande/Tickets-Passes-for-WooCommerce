<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this template is include()d inside a class method, so every variable in it is method-scope, not global.
/**
 * Markup for the API settings page.
 *
 * Included from TPFW_API_Settings with $settingsOptions already loaded. The two toggles are independent:
 * the scanner one serves /check-in/, the API one lets outside callers authenticate against the
 * endpoints. The scan-result colors are used by both, so their rows show when either is on.
 */
$bEnableAPI = true;
if(isset($settingsOptions['bEnableAPI']) && !$settingsOptions['bEnableAPI'])
{
    $bEnableAPI = false;
}

// A missing key means "on": see TPFW_Functions::is_scanner_enabled() - sites that saved these
// settings before the scanner had its own toggle must not lose their scanner on update.
$bEnableScanner = true;
if(isset($settingsOptions['bEnableScanner']) && !$settingsOptions['bEnableScanner'])
{
    $bEnableScanner = false;
}

// Reset-button values for the three colour rows. A blank field is stored as the shipped
// default by TPFW_API_Settings::ajax_save_tpfw_api_settings_callback(), and the scanner
// page falls back to its own tone when the option is missing altogether.
$aColorDefaults = array(
    '200' => '#2e7d32',
    '202' => '#b8860b',
    '406' => '#c0392b',
);

$sStatus200Hex = '';
if(isset($settingsOptions['status_200']) && $settingsOptions['status_200'] != "") { $sStatus200Hex = $settingsOptions['status_200']; }

$sStatus202Hex = '';
if(isset($settingsOptions['status_202']) && $settingsOptions['status_202'] != "") { $sStatus202Hex = $settingsOptions['status_202']; }

$sStatus406Hex = '';
if(isset($settingsOptions['status_406']) && $settingsOptions['status_406'] != "") { $sStatus406Hex = $settingsOptions['status_406']; }

// Endpoint reference. A <details> element rather than a modal or a separate admin page: it
// sits next to the toggle that turns these routes on, costs no JavaScript, and a developer
// can expand it, select it and paste it into a chat with whoever is writing the app.
$sAPIBase = esc_url(rest_url('tpfw/v1'));

$aEndpoints = array(
    array(
        'sMethod'    => 'POST',
        'sPath'      => '/scanner/checkin/{nano_id}',
        'sSummary'   => __('Checks in a ticket, timeslot ticket or pass. One nano id namespace covers all three - the endpoint works out which type the scanned code belongs to.', 'tickets-passes-for-woocommerce'),
        'sAuth'      => __('Scanner role or manage_woocommerce. External: WordPress Application Password (HTTP Basic) or X-TPFW-Scanner-Token. Not the account password.', 'tickets-passes-for-woocommerce'),
        'aResponses' => array(
            '200' => __('Checked in. Body carries sMessage, sType, sHolderName, valid_from, valid_to, max_uses, iUsesRemaining, sPhotoURL (passes) and sHexColor. It does not include the raw database row.', 'tickets-passes-for-woocommerce'),
            '202' => __('Refused for a reason the door staff needs to read: outside its validity window, on cooldown after a previous scan (iCooldown, iCooldownOver), already at its maximum uses, guest pass before the holder checked in, or being scanned at another door at the same moment. Body carries sMessage and sHexColor.', 'tickets-passes-for-woocommerce'),
            '401' => __('No valid credentials, or no ticket, timeslot ticket or pass matches the id.', 'tickets-passes-for-woocommerce'),
            '400' => __('The nano id in the path is not shaped like one, so nothing was looked up.', 'tickets-passes-for-woocommerce'),
            '405' => __('GET is refused and does not check anyone in. Use POST.', 'tickets-passes-for-woocommerce'),
        ),
    ),
    array(
        'sMethod'    => 'POST',
        'sPath'      => '/scanner/checkin/{nano_id}/guest',
        'sSummary'   => __('Checks in a guest pass - a pass issued under a parent pass. Same responses as the endpoint above, plus sPhotoURL on success when the guest has a profile photo.', 'tickets-passes-for-woocommerce'),
        'sAuth'      => __('Scanner role or manage_woocommerce. External: WordPress Application Password (HTTP Basic) or X-TPFW-Scanner-Token. Not the account password.', 'tickets-passes-for-woocommerce'),
        'aResponses' => array(
            '200' => __('Checked in. Body carries sMessage, sPhotoURL and sHexColor.', 'tickets-passes-for-woocommerce'),
            '202' => __('Outside its validity window, on cooldown, at its maximum uses, not yet activated by the holder\'s check-in, or being scanned at another door at the same moment.', 'tickets-passes-for-woocommerce'),
            '401' => __('No valid credentials, or the guest pass (or its parent pass) could not be found.', 'tickets-passes-for-woocommerce'),
            '400' => __('The nano id in the path is not shaped like one, so nothing was looked up.', 'tickets-passes-for-woocommerce'),
            '405' => __('GET is refused and does not check anyone in. Use POST.', 'tickets-passes-for-woocommerce'),
        ),
    ),
    array(
        'sMethod'    => 'GET',
        'sPath'      => '/scanner/history',
        'sSummary'   => __('The 50 most recent check-ins performed by the authenticated scanner account, newest first. Use it to fill a history list in your app. Each entry has sTypeKey (ticket, timeslot, pass or guestpass), sHolderName and sCreatedUTC, an ISO-8601 timestamp carrying the site timezone offset.', 'tickets-passes-for-woocommerce'),
        'sAuth'      => __('Scanner role or manage_woocommerce. External: Application Password or X-TPFW-Scanner-Token.', 'tickets-passes-for-woocommerce'),
        'aResponses' => array(
            '200' => __('Body carries aHistory, an array of entries. An account that has scanned nothing gets an empty array.', 'tickets-passes-for-woocommerce'),
            '401' => __('No valid credentials.', 'tickets-passes-for-woocommerce'),
        ),
    ),
    array(
        'sMethod'    => 'GET',
        'sPath'      => '/timeslot-ticket/ics/{nano_id}',
        'sSummary'   => __('Returns a timeslot ticket as a calendar file. This one is deliberately open: the nano id in the URL is itself the secret, and the confirmation email hands it to the customer so they can add their booking to a calendar on any device.', 'tickets-passes-for-woocommerce'),
        'sAuth'      => __('None - the nano id is the secret', 'tickets-passes-for-woocommerce'),
        'aResponses' => array(
            '200' => __('A text/calendar file, not JSON.', 'tickets-passes-for-woocommerce'),
            '404' => __('No timeslot ticket matches the id.', 'tickets-passes-for-woocommerce'),
        ),
    ),
);

?>
<div class="tpfw-settings-page">
    <div class="tpfw-api-settings-intro">
        <span class="dashicons dashicons-rest-api" aria-hidden="true"></span>
        <div>
            <p class="tpfw-api-settings-intro-lead"><?php echo esc_html__('This is the check-in API external scanner apps use to validate tickets, timeslot tickets and passes.', 'tickets-passes-for-woocommerce'); ?></p>
            <p class="tpfw-api-settings-intro-sub"><?php echo esc_html__('Turn the API and the built-in scanner page on or off below, and set the colors returned to the scanner for each scan result.', 'tickets-passes-for-woocommerce'); ?></p>
        </div>
    </div>

    <div class="tpfw-api-scanner-advice">
        <h2><?php echo esc_html__('A note on the built-in scanner', 'tickets-passes-for-woocommerce'); ?></h2>
        <p><?php echo esc_html__('The check-in page that ships with this plugin does the job: it opens the camera in a browser, decodes the QR code and checks the visitor in. For a small door, or a venue scanning a few hundred people a night, it is all you need.', 'tickets-passes-for-woocommerce'); ?></p>
        <p><?php echo esc_html__('It is not the optimal tool, though. A browser page cannot drive a phone the way a native app can: focus and decoding are slower, every single scan needs a live connection, and a locked screen or a switched tab interrupts the camera. If you scan at volume, or at a gate where the signal is poor, a standalone native scanner app will be faster and far more reliable.', 'tickets-passes-for-woocommerce'); ?></p>
        <p><?php echo esc_html__('That is what the API is for. Turn it on and any app - one your developer builds, or an existing scanner app that can call a URL - can check tickets, timeslot tickets and passes in against this site. The endpoints are documented below: check-in is an authenticated POST, history and the calendar file are GET.', 'tickets-passes-for-woocommerce'); ?></p>
        <p><?php
            printf(
                /* translators: %s: link to the plugin support forum. */
                esc_html__('If you would rather not build it yourself, get in touch on %s and we can see if we can figure something out.', 'tickets-passes-for-woocommerce'),
                '<a href="https://wordpress.org/support/plugin/tickets-passes-for-woocommerce/" target="_blank" rel="noopener noreferrer">' . esc_html__('the plugin support forum', 'tickets-passes-for-woocommerce') . '</a>'
            );
        ?></p>
    </div>

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php echo esc_html__('Enable API', 'tickets-passes-for-woocommerce'); ?> <?php echo wp_kses_post(wc_help_tip(__('An API is how an external app - like a scanner app on your door staff\'s phones - talks to this site to check tickets in and out. Turn this on only if something outside this site needs to call the check-in endpoints; the built-in scanner below works without it.', 'tickets-passes-for-woocommerce'))); ?></th>
            <td>
                <label class="tpfw-toggle">
                    <input type="checkbox" class="tpfw-api-enable-api" <?php checked($bEnableAPI); ?>>
                    <span class="tpfw-toggle-slider" aria-hidden="true"></span>
                </label>
            </td>
        </tr>
        <tr class="tpfw-api-docs-row<?php echo esc_attr($bEnableAPI ? '' : ' hide'); ?>">
            <td colspan="2">
                <details class="tpfw-api-docs">
                    <summary><?php echo esc_html__('API endpoint reference', 'tickets-passes-for-woocommerce'); ?></summary>
                    <div class="tpfw-api-docs-body">
                        <h3><?php echo esc_html__('Base URL', 'tickets-passes-for-woocommerce'); ?></h3>
                        <p><code><?php echo esc_html($sAPIBase); ?></code></p>
                        <p class="description"><?php echo esc_html__('Every path below is relative to that. Check-in is POST. History is GET. The calendar file is GET and is not JSON.', 'tickets-passes-for-woocommerce'); ?></p>

                        <h3><?php echo esc_html__('Authentication', 'tickets-passes-for-woocommerce'); ?></h3>
                        <p><?php echo esc_html__('External apps authenticate as a WordPress user who may scan (Scanner role or manage_woocommerce), using one of:', 'tickets-passes-for-woocommerce'); ?></p>
                        <ol>
                            <li><?php echo esc_html__('A WordPress Application Password (Users → Application Passwords) sent as HTTP Basic username + application password. WordPress authenticates the request; this plugin only checks that the current user may scan. Do not store or send the account login password.', 'tickets-passes-for-woocommerce'); ?></li>
                            <li><?php echo esc_html__('Header X-TPFW-Scanner-Token as {token_id}.{secret}. Tokens are revoked without changing the WordPress user password. Create one with TPFW_Scanner_Tokens::create() — the full token is shown once.', 'tickets-passes-for-woocommerce'); ?></li>
                        </ol>
                        <p><?php echo esc_html__('Send requests over HTTPS. Create one scanner user per device so the check-in history tells you which door did what.', 'tickets-passes-for-woocommerce'); ?></p>
                        <p><?php echo esc_html__('Outside callers are only accepted while Enable API is on above. The built-in scanner page authenticates with its own login session instead, so it keeps working either way.', 'tickets-passes-for-woocommerce'); ?></p>

                        <h3><?php echo esc_html__('Endpoints', 'tickets-passes-for-woocommerce'); ?></h3>
                        <?php foreach($aEndpoints as $aEndpoint): ?>
                            <div class="tpfw-api-endpoint">
                                <p class="tpfw-api-endpoint-path"><span class="tpfw-api-method"><?php echo esc_html($aEndpoint['sMethod'] ?? 'GET'); ?></span> <code><?php echo esc_html($aEndpoint['sPath']); ?></code></p>
                                <p><?php echo esc_html($aEndpoint['sSummary']); ?></p>
                                <p class="description"><strong><?php echo esc_html__('Requires:', 'tickets-passes-for-woocommerce'); ?></strong> <?php echo esc_html($aEndpoint['sAuth']); ?></p>
                                <ul>
                                    <?php foreach($aEndpoint['aResponses'] as $sCode => $sMeaning): ?>
                                        <li><code><?php echo esc_html($sCode); ?></code> <?php echo esc_html($sMeaning); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>

                        <h3><?php echo esc_html__('Result colors', 'tickets-passes-for-woocommerce'); ?></h3>
                        <p><?php echo esc_html__('Every JSON response carries sHexColor, the color configured above for that outcome. Painting your result screen with it means the colors your staff learn to read are set here, in wp-admin, and not baked into the app.', 'tickets-passes-for-woocommerce'); ?></p>

                        <h3><?php echo esc_html__('Example', 'tickets-passes-for-woocommerce'); ?></h3>
                        <pre><code>curl -u scanner:xxxx-xxxx-xxxx-xxxx -X POST <?php echo esc_html($sAPIBase); ?>/scanner/checkin/V1StGXR8Z5jdHi6BmyTxq</code></pre>
                        <p class="description"><?php echo esc_html__('A nano id is 21 characters of A-Z, a-z and 0-9. The QR code itself contains the full check-in URL for this site (…/scanner/checkin/<nano id>, with /guest appended for a guest pass), so your app can either request that URL directly or take the last path segment and build the request against this site\'s REST base.', 'tickets-passes-for-woocommerce'); ?></p>
                    </div>
                </details>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php echo esc_html__('Enable built-in scanner', 'tickets-passes-for-woocommerce'); ?> <?php echo wp_kses_post(wc_help_tip(__('Turns on the check-in page at /check-in/ - a full-screen camera scanner your door staff open on a phone, with no wp-admin around it. They sign in with a WordPress account that has the Scanner role, and a link to it appears in this plugin\'s menu. Leave it off if you check people in with a separate scanner app instead. This toggle is independent of the API above.', 'tickets-passes-for-woocommerce'))); ?></th>
            <td>
                <label class="tpfw-toggle">
                    <input type="checkbox" class="tpfw-api-enable-scanner" <?php checked($bEnableScanner); ?>>
                    <span class="tpfw-toggle-slider" aria-hidden="true"></span>
                </label>
                <p class="description tpfw-scanner-url<?php echo esc_attr($bEnableScanner ? '' : ' hide'); ?>">
                    <a href="<?php echo esc_url(home_url('/check-in/')); ?>"><?php echo esc_html(home_url('/check-in/')); ?></a>
                </p>
            </td>
        </tr>
        <tr class="tpfw-status-row<?php echo esc_attr($bEnableAPI || $bEnableScanner ? '' : ' hide'); ?>">
            <th scope="row"><?php echo esc_html__('Successful Check-in (200)', 'tickets-passes-for-woocommerce'); ?> <?php echo wp_kses_post(wc_help_tip(__('Color the scanner app should show when a ticket, timeslot ticket or pass is successfully checked in.', 'tickets-passes-for-woocommerce'))); ?></th>
            <td>
                <div class="tpfw-color-field">
                    <input type="text" class="tpfw-status-200-hexcolor regular-text" value="<?php echo esc_attr($sStatus200Hex); ?>">
                    <input type="color" class="tpfw-status-200-colorpicker colorpick-eyedropper-input-trigger" value="<?php echo esc_attr($sStatus200Hex); ?>">
                    <button type="button" class="button tpfw-color-reset" data-tpfw-default="<?php echo esc_attr($aColorDefaults['200']); ?>" title="<?php echo esc_attr__('Reset to the default color', 'tickets-passes-for-woocommerce'); ?>" aria-label="<?php echo esc_attr__('Reset to the default color', 'tickets-passes-for-woocommerce'); ?>"><span class="dashicons dashicons-image-rotate" aria-hidden="true"></span></button>
                </div>
            </td>
        </tr>
        <tr class="tpfw-status-row<?php echo esc_attr($bEnableAPI || $bEnableScanner ? '' : ' hide'); ?>">
            <th scope="row"><?php echo esc_html__('Not Valid or Already Used (202)', 'tickets-passes-for-woocommerce'); ?> <?php echo wp_kses_post(wc_help_tip(__('Color shown when a scan happens outside its valid time window, on cooldown after a previous scan, or after it has already reached its maximum uses.', 'tickets-passes-for-woocommerce'))); ?></th>
            <td>
                <div class="tpfw-color-field">
                    <input type="text" class="tpfw-status-202-hexcolor regular-text" value="<?php echo esc_attr($sStatus202Hex); ?>">
                    <input type="color" class="tpfw-status-202-colorpicker colorpick-eyedropper-input-trigger" value="<?php echo esc_attr($sStatus202Hex); ?>">
                    <button type="button" class="button tpfw-color-reset" data-tpfw-default="<?php echo esc_attr($aColorDefaults['202']); ?>" title="<?php echo esc_attr__('Reset to the default color', 'tickets-passes-for-woocommerce'); ?>" aria-label="<?php echo esc_attr__('Reset to the default color', 'tickets-passes-for-woocommerce'); ?>"><span class="dashicons dashicons-image-rotate" aria-hidden="true"></span></button>
                </div>
            </td>
        </tr>
        <tr class="tpfw-status-row<?php echo esc_attr($bEnableAPI || $bEnableScanner ? '' : ' hide'); ?>">
            <th scope="row"><?php echo esc_html__('Invalid Request (406)', 'tickets-passes-for-woocommerce'); ?> <?php echo wp_kses_post(wc_help_tip(__('Color shown when the request itself is invalid, e.g. the scanned code doesn\'t match any known ticket, timeslot ticket or pass.', 'tickets-passes-for-woocommerce'))); ?></th>
            <td>
                <div class="tpfw-color-field">
                    <input type="text" class="tpfw-status-406-hexcolor regular-text" value="<?php echo esc_attr($sStatus406Hex); ?>">
                    <input type="color" class="tpfw-status-406-colorpicker colorpick-eyedropper-input-trigger" value="<?php echo esc_attr($sStatus406Hex); ?>">
                    <button type="button" class="button tpfw-color-reset" data-tpfw-default="<?php echo esc_attr($aColorDefaults['406']); ?>" title="<?php echo esc_attr__('Reset to the default color', 'tickets-passes-for-woocommerce'); ?>" aria-label="<?php echo esc_attr__('Reset to the default color', 'tickets-passes-for-woocommerce'); ?>"><span class="dashicons dashicons-image-rotate" aria-hidden="true"></span></button>
                </div>
            </td>
        </tr>
    </table>
    <p class="submit">
        <input type="button" class="button button-primary tpfw-admin-api-settings-save" value="<?php echo esc_attr__('Save Settings', 'tickets-passes-for-woocommerce'); ?>">
    </p>

</div>
</div><!-- .wrap opened in print_settings_navigation() -->
