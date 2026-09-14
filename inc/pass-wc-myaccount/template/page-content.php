<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- this template is include()d inside a class method, so every variable in it is method-scope, not global.
/**
 * The Passes tab on the WooCommerce My Account page.
 *
 * Rendered from TPFW_Pass_WC_MyAccount, so $this is that class and the variables used below
 * ($aUserPasses, $sMyAccountColorStyle, ...) are the ones it sets up before including this file.
 *
 * pass-wc-myaccount.js drives everything interactive here by class name - the photo picker
 * (.select-btn / .remove-btn / .upload-btn), the guest-pass modal and the PDF download - so
 * renaming a class in this template silently disables the matching handler.
 */
?>
<div class="tpfw-pass-myaccount-wrap" style="<?php echo esc_attr($sMyAccountColorStyle); ?>">
<div class="tpfw-pass-page">
    <div class="tpfw-pass-header">
        <h1><?php echo esc_html__("Passes", 'tickets-passes-for-woocommerce'); ?></h1>
        <?php if(!empty($aUserPass)) : ?>
            <p class="tpfw-pass-lede"><?php echo esc_html__('Show the QR code at the door, or download a printable copy.', 'tickets-passes-for-woocommerce'); ?></p>
        <?php endif; ?>
    </div>

    <?php if(empty($aUserPass)) : ?>

        <div class="tpfw-pass-empty">
            <svg class="tpfw-pass-empty__icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path d="M3 8.5A1.5 1.5 0 0 1 4.5 7h15A1.5 1.5 0 0 1 21 8.5v1.75a1.75 1.75 0 0 0 0 3.5v1.75a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 15.5v-1.75a1.75 1.75 0 0 0 0-3.5V8.5Z"/>
                <path d="M9.5 7v10" stroke-dasharray="2.5 2.5"/>
            </svg>
            <p class="tpfw-pass-empty__text"><?php echo esc_html__("You don't have any passes yet.", 'tickets-passes-for-woocommerce'); ?></p>
            <?php if(function_exists('wc_get_page_permalink')) : ?>
                <a class="tpfw-pass-empty__cta" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php echo esc_html__('Browse passes', 'tickets-passes-for-woocommerce'); ?></a>
            <?php endif; ?>
        </div>

    <?php else : ?>

        <ul class="tpfw-pass-grid">
            <?php
                foreach ($aUserPass as $iUserPassKey => $oUserUserPass)
                {
                    $sCustomerName     = $oUserUserPass->firstname . ' ' . $oUserUserPass->lastname;
                    $oProduct          = wc_get_product($oUserUserPass->product_id);
                    if(empty($oProduct)) continue;
                    $sProductName      = $oProduct ? $oProduct->get_name() : __('Product Not Found', 'tickets-passes-for-woocommerce');
                    $sDateTimeFormat   = $this->oFunctions->get_datetime_format('datetime');
                    $sValidFrom        = gmdate($sDateTimeFormat, strtotime($oUserUserPass->valid_from));
                    $sValidTo          = gmdate($sDateTimeFormat, strtotime($oUserUserPass->valid_to));
                    $sPlaceholderImage = TPFW_PLUGIN_URL . 'images/avatar-placeholder.webp';

                    $sStatusText 		= "";
                    $sStatusModifier	= "";
                    if($oUserUserPass->deleted != null && $oUserUserPass->deleted != "")
                    {
                        $sStatusText 		= __('Cancelled', 'tickets-passes-for-woocommerce');
                        $sStatusModifier	= 'cancelled';
                    }
                    else if(strtotime($oUserUserPass->valid_to) < current_time('timestamp'))
                    {
                        $sStatusText 		= __('Expired', 'tickets-passes-for-woocommerce');
                        $sStatusModifier	= 'expired';
                    }
                    else if(strtotime($oUserUserPass->valid_from) > current_time('timestamp'))
                    {
                        $sStatusText 		= __('Not valid yet', 'tickets-passes-for-woocommerce');
                        $sStatusModifier	= 'pending';
                    }
                    else
                    {
                        $sStatusText 		= __('Active', 'tickets-passes-for-woocommerce');
                        $sStatusModifier	= 'active';
                    }

                    $profileImageType = $oUserUserPass->profile_image_type ?? '';
                    $sAvatarSrc        = !empty($profileImageType) ? $this->oFunctions->get_file_url('profile', $oUserUserPass->nano_id, $profileImageType) : $sPlaceholderImage;
                    ?>
                    <li class="pass-container tpfw-pass-card" data-attr-rowid="<?php echo esc_attr($oUserUserPass->id);?>" data-attr-nanoid="<?php echo esc_attr($oUserUserPass->nano_id);?>">
                        <div class="tpfw-pass-card-stub">
                            <div class="tpfw-pass-card-qr">
                                <?php /* translators: %s: name of the product the QR code belongs to. */ ?>
                                <img src="<?php echo esc_url($this->oFunctions->get_file_url('qr', $oUserUserPass->nano_id, 'webp', true)); ?>" alt="<?php echo esc_attr(sprintf(__('QR code for %s', 'tickets-passes-for-woocommerce'), $sProductName)); ?>" width="112" height="112">
                            </div>
                            <span class="tpfw-status-pill tpfw-status-pill--<?php echo esc_attr($sStatusModifier); ?>"><?php echo esc_html($sStatusText); ?></span>
                        </div>

                        <div class="tpfw-pass-card-divider" aria-hidden="true"></div>

                        <div class="tpfw-pass-card-body">
                            <div class="tpfw-pass-card-top">
                                <div class="pass-image">
                                    <img src="<?php echo esc_url($sAvatarSrc); ?>" alt="" width="40" height="40">
                                </div>
                                <div class="tpfw-pass-card-top-text">
                                    <span class="variation-name"><?php echo esc_html($sProductName); ?></span>
                                    <span class="customer-name"><?php echo esc_html($sCustomerName); ?></span>
                                </div>
                            </div>

                            <dl class="valid-dates">
                                <div class="valid-from">
                                    <dt><?php echo esc_html__('Valid from', 'tickets-passes-for-woocommerce'); ?></dt>
                                    <dd><?php echo esc_html($sValidFrom); ?></dd>
                                </div>
                                <div class="valid-to">
                                    <dt><?php echo esc_html__('Valid to', 'tickets-passes-for-woocommerce'); ?></dt>
                                    <dd><?php echo esc_html($sValidTo); ?></dd>
                                </div>
                            </dl>

                            <?php $sProductNote = $this->oFunctions->get_product_note($oUserUserPass->product_id); ?>
                            <?php if($sProductNote != '') : ?>
                                <p class="tpfw-pass-card-note"><?php echo nl2br(esc_html($sProductNote)); ?></p>
                            <?php endif; ?>

                            <div class="tpfw-pass-card-actions">
                                <?php
                                    if(get_post_meta($oUserUserPass->product_id, '_tpfw_pass_profile_image_upload_enable', true) == 'yes' && empty($profileImageType) && (class_exists('Imagick') || function_exists('imagewebp')))
                                    {
                                        ?>
                                            <button type="button" class="select-btn">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                    <path d="M4 8.5A1.5 1.5 0 0 1 5.5 7h1.6l1-1.8A1 1 0 0 1 9 4.7h6a1 1 0 0 1 .9.5l1 1.8h1.6A1.5 1.5 0 0 1 20 8.5v9A1.5 1.5 0 0 1 18.5 19h-13A1.5 1.5 0 0 1 4 17.5v-9Z"/>
                                                    <circle cx="12" cy="13" r="3.2"/>
                                                </svg>
                                                <?php echo esc_html__('Add Profile Photo', 'tickets-passes-for-woocommerce'); ?>
                                            </button>
                                            <button type="button" style="display: none;" class="remove-btn"><?php echo esc_html__('Cancel', 'tickets-passes-for-woocommerce'); ?></button>
                                            <button type="button" style="display: none;" class="upload-btn"><?php echo esc_html__('Save Photo', 'tickets-passes-for-woocommerce'); ?></button>
                                            <input type="file" class="fileinput-image" accept="image/jpeg, image/png, image/jpg, image/webp" style="display: none;">
                                        <?php
                                    }
                                ?>
                                <?php
                                    // Only when the product actually grants some: with a quantity of 0 the button
                                    // could only ever answer with an error.
                                    if(get_post_meta($oProduct->get_id(), '_tpfw_pass_guest_pass_enable', true) == 'yes' && (int) get_post_meta($oProduct->get_id(), '_tpfw_pass_guest_pass_quantity', true) > 0)
                                    {
                                        ?>
                                            <button type="button" class="guesspass-btn">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                                    <circle cx="9" cy="8.5" r="2.6"/>
                                                    <path d="M3.8 18c.5-3 2.6-4.7 5.2-4.7s4.7 1.7 5.2 4.7"/>
                                                    <path d="M15.8 7.5a2.4 2.4 0 1 1 0 4.8M18.6 18c-.4-2.3-1.6-3.8-3.4-4.4"/>
                                                </svg>
                                                <?php echo esc_html__('Guest Pass', 'tickets-passes-for-woocommerce'); ?>
                                            </button>
                                            <?php
                                    }
                                ?>
                                <?php
                                    if(extension_loaded('mbstring'))
                                    {
                                        ?>
                                            <button type="button" class="download-pass-pdf-btn">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                                    <path d="M12 4v11m0 0 4-4m-4 4-4-4M5 19h14"/>
                                                </svg>
                                                <?php echo esc_html__('Download', 'tickets-passes-for-woocommerce'); ?>
                                            </button>
                                        <?php
                                    }
                                ?>
                            </div>
                        </div>
                    </li>
                <?php
                }
            ?>
        </ul>

    <?php endif; ?>
</div>

<div class="modal-guest-wrapper">
    <div class="modal-guest">
        <span class="modal-guest-close">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                <path d="M5 5l14 14M19 5 5 19"/>
            </svg>
        </span>
        <div class="modal-guest-top">
            <span class="modal-guest-title"><?php echo esc_html__('Guest Pass', 'tickets-passes-for-woocommerce'); ?></span>
            <p class="modal-guest-subtitle"><?php echo esc_html__('Scan one of these codes at check-in.', 'tickets-passes-for-woocommerce'); ?></p>
        </div>
        <div class="modal-tabs-header"></div>
        <div class="modal-tabs-content"></div>
    </div>
</div>
</div><!-- .tpfw-pass-myaccount-wrap -->
