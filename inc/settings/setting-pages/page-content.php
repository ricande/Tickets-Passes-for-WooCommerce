<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this template is include()d inside a class method, so every variable in it is method-scope, not global.
/**
 * Markup for the General settings page.
 *
 * Included from TPFW_Settings with $settingsOptions already loaded. The switches here decide which
 * product types and dashboards exist at all, which is why every other settings page reads the
 * same option array before rendering.
 */

$bEnableAnalytics = false;
if(isset($settingsOptions['bEnableAnalytics']) && $settingsOptions['bEnableAnalytics'] != "")
{
    $bEnableAnalytics = $settingsOptions['bEnableAnalytics'];
}

$sDateTimeFormat = 'Y-m-d H:i:s';
if(isset($settingsOptions['sDateTimeformat']) && $settingsOptions['sDateTimeformat'] != "")
{
    $sDateTimeFormat = $settingsOptions['sDateTimeformat'];
}

?>
<div class="tpfw-settings-page">
    <div class="tpfw-general-settings-intro">
        <span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
        <div>
            <p class="tpfw-general-settings-intro-lead"><?php echo esc_html__('General settings for the Ticket & Passes plugin.', 'tickets-passes-for-woocommerce'); ?></p>
            <p class="tpfw-general-settings-intro-sub"><?php echo esc_html__('These apply across your whole store.', 'tickets-passes-for-woocommerce'); ?></p>
        </div>
    </div>

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="tpfw-general-datetime-format"><?php echo esc_html__('Display Date Format', 'tickets-passes-for-woocommerce'); ?></label> <?php echo wp_kses_post(wc_help_tip(__('Controls how dates and times are displayed across the plugin\'s dashboards and customer-facing pages.', 'tickets-passes-for-woocommerce'))); ?></th>
            <td>
                <select id="tpfw-general-datetime-format" class="tpfw-general-datetime-format">
                    <optgroup label="">
                        <option <?php selected($sDateTimeFormat, 'Y-m-d H:i:s'); ?> value="Y-m-d H:i:s">Y-m-d H:i:s</option>
                        <option <?php selected($sDateTimeFormat, 'Y-m-d H:i'); ?> value="Y-m-d H:i">Y-m-d H:i</option>
                        <option <?php selected($sDateTimeFormat, 'd-m-Y H:i:s'); ?> value="d-m-Y H:i:s">d-m-Y H:i:s</option>
                        <option <?php selected($sDateTimeFormat, 'd-m-Y H:i'); ?> value="d-m-Y H:i">d-m-Y H:i</option>
                    </optgroup>
                    <optgroup label="">
                        <option <?php selected($sDateTimeFormat, 'Y.m.d H:i:s'); ?> value="Y.m.d H:i:s">Y.m.d H:i:s</option>
                        <option <?php selected($sDateTimeFormat, 'Y.m.d H:i'); ?> value="Y.m.d H:i">Y.m.d H:i</option>
                        <option <?php selected($sDateTimeFormat, 'd.m.Y H:i:s'); ?> value="d.m.Y H:i:s">d.m.Y H:i:s</option>
                        <option <?php selected($sDateTimeFormat, 'd.m.Y H:i'); ?> value="d.m.Y H:i">d.m.Y H:i</option>
                    </optgroup>
                    <optgroup label="">
                        <option <?php selected($sDateTimeFormat, 'Y/m/d H:i:s'); ?> value="Y/m/d H:i:s">Y/m/d H:i:s</option>
                        <option <?php selected($sDateTimeFormat, 'Y/m/d H:i'); ?> value="Y/m/d H:i">Y/m/d H:i</option>
                        <option <?php selected($sDateTimeFormat, 'd/m/Y H:i:s'); ?> value="d/m/Y H:i:s">d/m/Y H:i:s</option>
                        <option <?php selected($sDateTimeFormat, 'd/m/Y H:i'); ?> value="d/m/Y H:i">d/m/Y H:i</option>
                    </optgroup>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php echo esc_html__('Enable Analytics Dashboard', 'tickets-passes-for-woocommerce'); ?> <?php echo wp_kses_post(wc_help_tip(__('Adds a dashboard showing check-in and sales statistics for tickets, timeslot tickets and passes.', 'tickets-passes-for-woocommerce'))); ?></th>
            <td>
                <label class="tpfw-toggle">
                    <input type="checkbox" class="tpfw-general-enable-analytics" <?php checked($bEnableAnalytics); ?>>
                    <span class="tpfw-toggle-slider" aria-hidden="true"></span>
                </label>
            </td>
        </tr>
    </table>
    <p class="submit">
        <input type="button" class="button button-primary tpfw-admin-general-settings-save" value="<?php echo esc_attr__('Save Settings', 'tickets-passes-for-woocommerce'); ?>">
    </p>
</div>
</div><!-- .wrap opened in print_settings_navigation() -->
