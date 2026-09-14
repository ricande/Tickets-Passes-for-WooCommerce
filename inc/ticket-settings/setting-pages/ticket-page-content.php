<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this template is include()d inside a class method, so every variable in it is method-scope, not global.
/**
 * Markup for the Ticket settings page.
 *
 * Included from TPFW_Ticket_Settings with $settingsOptions already loaded. Disabled in full when
 * the ticket product type is switched off in the general settings.
 *
 * The hint text and the seven colour rows mirror the Timeslot Ticket tab one for one: the Ticket
 * product page now renders the same start date calendar, so it needs the same knobs.
 */

$bEnableTicketProduct = false;
if(isset($settingsOptions['bEnableTicketProduct']) && $settingsOptions['bEnableTicketProduct'] != "")
{
    $bEnableTicketProduct = $settingsOptions['bEnableTicketProduct'];
}

$sTicketHintText = __('Pick the date this ticket should be valid from.', 'tickets-passes-for-woocommerce');
if(isset($settingsOptions['sTicketHintText']) && $settingsOptions['sTicketHintText'] != "")
{
    $sTicketHintText = $settingsOptions['sTicketHintText'];
}

?>
<div class="tpfw-settings-page">
    <div class="tpfw-ticket-settings-intro">
        <span class="dashicons dashicons-tickets" aria-hidden="true"></span>
        <div>
            <p class="tpfw-ticket-settings-intro-lead"><?php echo esc_html__('Tickets are simple, non-timed entry products - one product, one QR code, no time slot attached.', 'tickets-passes-for-woocommerce'); ?></p>
            <p class="tpfw-ticket-settings-intro-sub"><?php echo esc_html__('Turn the product type on below, then customize how it looks and behaves for customers.', 'tickets-passes-for-woocommerce'); ?></p>
        </div>
    </div>

    <div class="tpfw-appearance">
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php echo esc_html__('Enable Ticket Product', 'tickets-passes-for-woocommerce'); ?> <?php echo wp_kses_post(wc_help_tip(__('Lets shop admins create simple, non-timed entry tickets as a WooCommerce product type.', 'tickets-passes-for-woocommerce'))); ?></th>
            <td>
                <label class="tpfw-toggle">
                    <input type="checkbox" class="tpfw-ticket-enable" <?php checked($bEnableTicketProduct); ?>>
                    <span class="tpfw-toggle-slider" aria-hidden="true"></span>
                </label>
            </td>
        </tr>
        <tr class="tpfw-ticket-extra-row<?php echo esc_attr($bEnableTicketProduct ? '' : ' hide'); ?>">
            <th scope="row"><?php echo esc_html__('Date Picker Hint Text', 'tickets-passes-for-woocommerce'); ?> <?php echo wp_kses_post(wc_help_tip(__('Shown to customers just above the calendar on the ticket product page, explaining what to do. Only visible when the ticket lets the customer choose a start date.', 'tickets-passes-for-woocommerce'))); ?></th>
            <td>
                <textarea class="tpfw-ticket-hint-text" rows="2"><?php echo esc_textarea($sTicketHintText); ?></textarea>
            </td>
        </tr>
        <?php
        $this->print_color_rows(array(
            'accent'     => array(__('Accent Color', 'tickets-passes-for-woocommerce'), __('Used for the selected date in the calendar and the chosen date pill on the ticket product page, and for the card border and buttons on My Account -> Tickets.', 'tickets-passes-for-woocommerce')),
            'text'       => array(__('Text Color', 'tickets-passes-for-woocommerce'), __('Main text color used for the ticket details list and the calendar dates on the ticket product page.', 'tickets-passes-for-woocommerce')),
            'border'     => array(__('Border Color', 'tickets-passes-for-woocommerce'), __('Used for the card outlines and row separators on the ticket product page.', 'tickets-passes-for-woocommerce')),
            'background' => array(__('Background Color', 'tickets-passes-for-woocommerce'), __('Background color of the ticket details card and the calendar on the ticket product page.', 'tickets-passes-for-woocommerce')),
            'hint'       => array(__('Hint Text Color', 'tickets-passes-for-woocommerce'), __('Color of the hint text above the calendar, the detail labels and the validity summary on the ticket product page.', 'tickets-passes-for-woocommerce')),
            'dayname'    => array(__('Day Name Color', 'tickets-passes-for-woocommerce'), __('Color of the weekday header row (MO, TU, WE...) in the calendar on the ticket product page.', 'tickets-passes-for-woocommerce')),
            'navtitle'   => array(__('Month/Year Title Color', 'tickets-passes-for-woocommerce'), __('Color of the calendar\'s month/year heading (e.g. "August, 2026") on the ticket product page.', 'tickets-passes-for-woocommerce')),
        ), $settingsOptions, $bEnableTicketProduct);
        ?>
    </table>
    <?php $this->print_color_preview($bEnableTicketProduct); ?>
    </div>
    <p class="submit">
        <input type="button" class="button button-primary tpfw-admin-ticket-settings-save" value="<?php echo esc_attr__('Save Settings', 'tickets-passes-for-woocommerce'); ?>">
    </p>
</div>
</div><!-- .wrap opened in print_settings_navigation() -->
