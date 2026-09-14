<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this template is include()d inside a class method, so every variable in it is method-scope, not global.
/**
 * Markup for the Timeslot Ticket settings page.
 *
 * Included from TPFW_Timeslot_Ticket_Settings with $settingsOptions already loaded. Disabled in
 * full when the timeslot product type is switched off in the general settings.
 */

$bEnableTimeslotProduct = false;
if(isset($settingsOptions['bEnableTimeslotProduct']) && $settingsOptions['bEnableTimeslotProduct'] != "")
{
    $bEnableTimeslotProduct = $settingsOptions['bEnableTimeslotProduct'];
}

$sTimeslotHintText = __('Pick an available date below, then choose a time.', 'tickets-passes-for-woocommerce');
if(isset($settingsOptions['sTimeslotHintText']) && $settingsOptions['sTimeslotHintText'] != "")
{
    $sTimeslotHintText = $settingsOptions['sTimeslotHintText'];
}

?>
<div class="tpfw-settings-page">
    <div class="tpfw-timeslot-ticket-settings-intro">
        <span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
        <div>
            <p class="tpfw-timeslot-ticket-settings-intro-lead"><?php echo esc_html__('Timeslot tickets are tied to a specific date and time slot, with a set capacity per slot.', 'tickets-passes-for-woocommerce'); ?></p>
            <p class="tpfw-timeslot-ticket-settings-intro-sub"><?php echo esc_html__('Turn the product type on below, then customize how it looks and behaves for customers.', 'tickets-passes-for-woocommerce'); ?></p>
        </div>
    </div>

    <div class="tpfw-appearance">
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php echo esc_html__('Enable Timeslot Ticket Product', 'tickets-passes-for-woocommerce'); ?> <?php echo wp_kses_post(wc_help_tip(__('Lets shop admins create tickets tied to a specific date and time slot, with a set capacity per slot.', 'tickets-passes-for-woocommerce'))); ?></th>
            <td>
                <label class="tpfw-toggle">
                    <input type="checkbox" class="tpfw-timeslot-enable" <?php checked($bEnableTimeslotProduct); ?>>
                    <span class="tpfw-toggle-slider" aria-hidden="true"></span>
                </label>
            </td>
        </tr>
        <tr class="tpfw-timeslot-ticket-extra-row<?php echo esc_attr($bEnableTimeslotProduct ? '' : ' hide'); ?>">
            <th scope="row"><?php echo esc_html__('Date Picker Hint Text', 'tickets-passes-for-woocommerce'); ?> <?php echo wp_kses_post(wc_help_tip(__('Shown to customers just above the calendar on the timeslot ticket product page, explaining what to do.', 'tickets-passes-for-woocommerce'))); ?></th>
            <td>
                <textarea class="tpfw-timeslot-hint-text" rows="2"><?php echo esc_textarea($sTimeslotHintText); ?></textarea>
            </td>
        </tr>
        <?php
        $this->print_color_rows(array(
            'accent'     => array(__('Accent Color', 'tickets-passes-for-woocommerce'), __('Used for the date picker and the selected/available time slot pills on the timeslot ticket product page.', 'tickets-passes-for-woocommerce')),
            'text'       => array(__('Text Color', 'tickets-passes-for-woocommerce'), __('Main text color used for labels and time slot text on the timeslot ticket product page.', 'tickets-passes-for-woocommerce')),
            'border'     => array(__('Border Color', 'tickets-passes-for-woocommerce'), __('Used for the calendar and time slot pill borders on the timeslot ticket product page.', 'tickets-passes-for-woocommerce')),
            'background' => array(__('Background Color', 'tickets-passes-for-woocommerce'), __('Background color of the calendar and the available time slot pills on the timeslot ticket product page.', 'tickets-passes-for-woocommerce')),
            'hint'       => array(__('Hint Text Color', 'tickets-passes-for-woocommerce'), __('Color of the hint text shown above the calendar on the timeslot ticket product page.', 'tickets-passes-for-woocommerce')),
            'dayname'    => array(__('Day Name Color', 'tickets-passes-for-woocommerce'), __('Color of the weekday header row (MO, TU, WE...) in the calendar on the timeslot ticket product page.', 'tickets-passes-for-woocommerce')),
            'navtitle'   => array(__('Month/Year Title Color', 'tickets-passes-for-woocommerce'), __('Color of the calendar\'s month/year heading (e.g. "August, 2026") on the timeslot ticket product page.', 'tickets-passes-for-woocommerce')),
        ), $settingsOptions, $bEnableTimeslotProduct);
        ?>
    </table>
    <?php $this->print_color_preview($bEnableTimeslotProduct); ?>
    </div>
    <p class="submit">
        <input type="button" class="button button-primary tpfw-admin-timeslot-ticket-settings-save" value="<?php echo esc_attr__('Save Settings', 'tickets-passes-for-woocommerce'); ?>">
    </p>
</div>
</div><!-- .wrap opened in print_settings_navigation() -->
