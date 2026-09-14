<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this template is include()d inside a class method, so every variable in it is method-scope, not global.
    // Every entry below matches the exact option keys / editor ids email-settings.js already
    // posts and reads - the label and description text are safe to reword freely, but 'id_suffix'
    // must stay in sync with the class/id names hardcoded in that JS file.
    $aEmailSettings = array(
        'wc_confirmation_email' => array(
            'id_suffix'   => 'wc-confirmation',
            'label'       => __('WooCommerce Order Confirmation', 'tickets-passes-for-woocommerce'),
            'description' => __('Appended to the standard WooCommerce order confirmation email whenever the order contains a ticket, timeslot ticket or pass. The QR codes are not issued yet at this point, so this text cannot show them.', 'tickets-passes-for-woocommerce'),
            'has_subject' => false,
        ),
        'wc_completed_email' => array(
            'id_suffix'   => 'wc-completed',
            'label'       => __('WooCommerce Completed Order', 'tickets-passes-for-woocommerce'),
            'description' => __('Appended to the WooCommerce completed order email whenever the order contains a ticket, timeslot ticket or pass. This is the first email that carries the QR codes, which are listed below this text.', 'tickets-passes-for-woocommerce'),
            'has_subject' => false,
        ),
        'resend_ticket_email' => array(
            'id_suffix'   => 'resend-ticket',
            'label'       => __('Resend Ticket', 'tickets-passes-for-woocommerce'),
            'description' => __('Sent when a customer or admin manually resends a ticket\'s QR code from the order or account dashboard.', 'tickets-passes-for-woocommerce'),
            'has_subject' => true,
        ),
        'resend_pass_email' => array(
            'id_suffix'   => 'resend-pass',
            'label'       => __('Resend Pass', 'tickets-passes-for-woocommerce'),
            'description' => __('Sent when a customer or admin manually resends a pass\'s QR code from the order or account dashboard.', 'tickets-passes-for-woocommerce'),
            'has_subject' => true,
        ),
        'gifted_pass_email_new_user' => array(
            'id_suffix'   => 'gifted-pass-new-user',
            'label'       => __('Guest Pass Invite - New User', 'tickets-passes-for-woocommerce'),
            'description' => __('Sent to a guest pass recipient who doesn\'t have an account on this site yet, inviting them to create one.', 'tickets-passes-for-woocommerce'),
            'has_subject' => true,
        ),
        'gifted_pass_email_existing_user' => array(
            'id_suffix'   => 'gifted-pass-existing-user',
            'label'       => __('Guest Pass Invite - Existing User', 'tickets-passes-for-woocommerce'),
            'description' => __('Sent to a guest pass recipient who already has an account on this site.', 'tickets-passes-for-woocommerce'),
            'has_subject' => true,
        ),
    );

    $aEditorTinyMCEArgs = array(
        'wpautop'       => true,
        'media_buttons' => false,
        'textarea_rows' => 7,
    );

    $aEmailTextDescriptions = $this->oFunctions->get_email_text_replacements_descriptions();

    // Every panel starts closed until an admin opens one - after that, WP's own postbox
    // mechanism persists exactly what they left open/closed per-user via the
    // 'closed-postboxes' ajax action core already handles.
    $sClosedPostboxesMetaKey = 'closedpostboxes_tpfw-email-settings';
    $bHasSavedPostboxState   = metadata_exists('user', get_current_user_id(), $sClosedPostboxesMetaKey);
    $aClosedPostboxes        = array();
    if($bHasSavedPostboxState)
    {
        $mSavedValue      = get_user_meta(get_current_user_id(), $sClosedPostboxesMetaKey, true);
        $aClosedPostboxes = is_array($mSavedValue) ? $mSavedValue : array_filter(explode(',', (string)$mSavedValue));
    }
?>
<div class="tpfw-settings-page tpfw-email-settings-page">
    <div class="tpfw-email-settings-intro">
        <span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
        <div>
            <p class="tpfw-email-settings-intro-lead"><?php echo esc_html__('These templates control the emails sent automatically alongside your regular WooCommerce order emails.', 'tickets-passes-for-woocommerce'); ?></p>
            <p class="tpfw-email-settings-intro-sub"><?php echo esc_html__('Expand a template below to edit its subject and message, and use the tags to insert live details like the customer\'s name or QR code.', 'tickets-passes-for-woocommerce'); ?></p>
        </div>
    </div>

    <?php wp_nonce_field('closedpostboxes', 'closedpostboxesnonce'); ?>

    <div class="tpfw-email-settings-list">
        <?php
            foreach($aEmailSettings as $sKey => $aMeta):
                $sBoxId  = 'tpfw-email-' . $aMeta['id_suffix'];
                $bClosed = $bHasSavedPostboxState ? in_array($sBoxId, $aClosedPostboxes, true) : true;

                // Untouched templates prefill with the same default text the senders fall back to,
                // so what the admin sees here is always what actually goes out.
                $sSubjectValue = $aMeta['has_subject'] ? $this->oFunctions->get_email_setting($sKey, 'subject') : '';
                $sMessageValue = $this->oFunctions->get_email_setting($sKey, 'message');
            ?>
            <div id="<?php echo esc_attr($sBoxId); ?>" data-attr-key="<?php echo esc_attr($sKey); ?>" class="email-setting-form postbox<?php echo esc_attr($bClosed ? ' closed' : ''); ?>">
                <div class="postbox-header">
                    <h2 class="hndle">
                        <span><?php echo esc_html($aMeta['label']); ?></span>
                        <?php echo wp_kses_post(wc_help_tip($aMeta['description'])); ?>
                    </h2>
                    <div class="handle-actions hide-if-no-js">
                        <button type="button" class="handlediv" aria-expanded="<?php echo esc_attr($bClosed ? 'false' : 'true'); ?>">
                            <?php /* translators: %s: name of the email settings panel being toggled open or closed. */ ?>
                            <span class="screen-reader-text"><?php echo esc_attr(sprintf(__('Toggle panel: %s', 'tickets-passes-for-woocommerce'), $aMeta['label'])); ?></span>
                            <span class="toggle-indicator" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
                <div class="inside">
                    <div class="tpfw-email-panel-body">
                        <div class="tpfw-email-panel-fields">
                            <?php if($aMeta['has_subject']): ?>
                            <label><?php echo esc_html__('Subject', 'tickets-passes-for-woocommerce'); ?></label>
                            <input class="email-input email-text regular-text email-setting-<?php echo esc_attr($aMeta['id_suffix']); ?>-subject" type="text" value="<?php echo esc_attr($sSubjectValue); ?>">
                            <?php endif; ?>

                            <div class="tpfw-email-message-row">
                                <label><?php echo esc_html__('Message', 'tickets-passes-for-woocommerce'); ?></label>
                                <?php wp_editor($sMessageValue, 'email-setting-'.$aMeta['id_suffix'].'-message', $aEditorTinyMCEArgs); ?>
                            </div>
                        </div>

                        <?php if(!empty($aEmailTextDescriptions[$sKey])): ?>
                        <div class="tpfw-merge-tags">
                            <span class="tpfw-merge-tags-label"><?php echo esc_html__('Available tags - click to insert at your cursor:', 'tickets-passes-for-woocommerce'); ?></span>
                            <?php foreach($aEmailTextDescriptions[$sKey] as $sTag => $sTagDescription): ?>
                            <button type="button" class="tpfw-merge-tag" data-tag="<?php echo esc_attr($sTag); ?>" title="<?php echo esc_attr($sTagDescription); ?>"><?php echo esc_html($sTag); ?></button>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
    </div>

    <p class="submit">
        <input type="button" class="button button-primary tpfw-admin-email-settings-save" value="<?php echo esc_attr__('Save Settings', 'tickets-passes-for-woocommerce'); ?>">
        <span class="tpfw-email-settings-save-notice" role="status" aria-live="polite"></span>
    </p>
</div>
</div><!-- .wrap opened in print_settings_navigation() -->
