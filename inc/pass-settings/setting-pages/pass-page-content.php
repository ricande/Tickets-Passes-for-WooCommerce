<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this template is include()d inside a class method, so every variable in it is method-scope, not global.
/**
 * Markup for the Pass settings page.
 *
 * Included from TPFW_Pass_Settings with $settingsOptions already loaded. Everything is disabled
 * when the pass product type is switched off in the general settings, so the fields cannot be
 * edited into a state the rest of the plugin never reads.
 */

$bEnablePassProduct = false;
if(isset($settingsOptions['bEnablePassProduct']) && $settingsOptions['bEnablePassProduct'] != "")
{
    $bEnablePassProduct = $settingsOptions['bEnablePassProduct'];
}

$sPassHintText = __('Add a name for each person this pass covers. You can optionally email someone their own copy of the pass.', 'tickets-passes-for-woocommerce');
if(isset($settingsOptions['sPassHintText']) && $settingsOptions['sPassHintText'] != "")
{
    $sPassHintText = $settingsOptions['sPassHintText'];
}

?>
<div class="tpfw-settings-page">
    <div class="tpfw-pass-settings-intro">
        <span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span>
        <div>
            <p class="tpfw-pass-settings-intro-lead"><?php echo esc_html__('Passes are one long-validity product that covers several people, each with their own name and optional pass copy.', 'tickets-passes-for-woocommerce'); ?></p>
            <p class="tpfw-pass-settings-intro-sub"><?php echo esc_html__('Turn the product type on below, then customize how it looks and behaves for customers.', 'tickets-passes-for-woocommerce'); ?></p>
        </div>
    </div>

    <div class="tpfw-appearance">
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php echo esc_html__('Enable Pass Product', 'tickets-passes-for-woocommerce'); ?> <?php echo wp_kses_post(wc_help_tip(__('Lets shop admins create long-validity passes, optionally including guest passes for other people.', 'tickets-passes-for-woocommerce'))); ?></th>
            <td>
                <label class="tpfw-toggle">
                    <input type="checkbox" class="tpfw-pass-enable" <?php checked($bEnablePassProduct); ?>>
                    <span class="tpfw-toggle-slider" aria-hidden="true"></span>
                </label>
            </td>
        </tr>
        <tr class="tpfw-pass-extra-row<?php echo esc_attr($bEnablePassProduct ? '' : ' hide'); ?>">
            <th scope="row"><?php echo esc_html__('Persons Section Hint Text', 'tickets-passes-for-woocommerce'); ?> <?php echo wp_kses_post(wc_help_tip(__('Shown to customers just above the person fields on the pass product page, explaining what to fill in.', 'tickets-passes-for-woocommerce'))); ?></th>
            <td>
                <textarea class="tpfw-pass-hint-text" rows="2"><?php echo esc_textarea($sPassHintText); ?></textarea>
            </td>
        </tr>
        <?php
        $this->print_color_rows(array(
            'accent'     => array(__('Accent Color', 'tickets-passes-for-woocommerce'), __('Used for the "Person" label, the card\'s left border, and focused input fields on the pass product page.', 'tickets-passes-for-woocommerce')),
            'text'       => array(__('Text Color', 'tickets-passes-for-woocommerce'), __('Main text color used for labels and hint text on the pass product page.', 'tickets-passes-for-woocommerce')),
            'border'     => array(__('Border Color', 'tickets-passes-for-woocommerce'), __('Used for the card border and the divider line above the email field on the pass product page.', 'tickets-passes-for-woocommerce')),
            'background' => array(__('Background Color', 'tickets-passes-for-woocommerce'), __('Background color of each person\'s card on the pass product page.', 'tickets-passes-for-woocommerce')),
            'hint'       => array(__('Hint Text Color', 'tickets-passes-for-woocommerce'), __('Color of the hint text shown above the person fields on the pass product page.', 'tickets-passes-for-woocommerce')),
        ), $settingsOptions, $bEnablePassProduct);
        ?>
    </table>
    <?php $this->print_color_preview($bEnablePassProduct); ?>
    </div>
    <p class="submit">
        <input type="button" class="button button-primary tpfw-admin-pass-settings-save" value="<?php echo esc_attr__('Save Settings', 'tickets-passes-for-woocommerce'); ?>">
    </p>
</div>
</div><!-- .wrap opened in print_settings_navigation() -->
