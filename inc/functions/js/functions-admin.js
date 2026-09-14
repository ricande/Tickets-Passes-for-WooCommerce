/**
 * Reset button on every colour row: the product-type settings tabs (.tpfw-colorpicker), the
 * API tab (.tpfw-color-field) and the QR tabs on the product edit screen (.tpfw-color-control).
 *
 * Delegated and colour-key agnostic: the shipped default travels in data-tpfw-default from
 * the same PHP array the field's own fallback comes from, so this file never learns what
 * any product type's colours are. Both inputs in the row are written, since each screen's
 * own script only syncs them on a user change event.
 */
jQuery(document).on('click', '.tpfw-color-reset, .tpfw-colorpicker-reset', function()
{
    var sDefault = jQuery(this).attr('data-tpfw-default');
    var oRow     = jQuery(this).closest('.tpfw-colorpicker, .tpfw-color-field, .tpfw-color-control');
    oRow.find('input[type="color"]').val(sDefault);
    // change on the hex field is what each screen's own script listens to, so firing it here
    // repaints the live preview card as well as the input.
    oRow.find('input[type="text"]').val(sDefault).trigger('change');
});

/**
 * Picks black or white text for a given background colour.
 *
 * Perceived-brightness weighting rather than a plain average, because the eye is far more
 * sensitive to green than to blue - a plain average calls a saturated blue "light" and puts
 * dark text on it.
 *
 * @param {string} sHex Background colour as #rrggbb (the leading # is optional).
 * @returns {string} '#1d2327' on light backgrounds, '#ffffff' otherwise - also the fallback
 *          for anything that is not a six-digit hex colour.
 */
function tpfwGetContrastTextColor(sHex)
{
    let oMatch = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(sHex || '');
    if(!oMatch) return '#ffffff';
    let iRed        = parseInt(oMatch[1], 16);
    let iGreen      = parseInt(oMatch[2], 16);
    let iBlue       = parseInt(oMatch[3], 16);
    let fLuminance  = (0.299 * iRed + 0.587 * iGreen + 0.114 * iBlue) / 255;
    return fLuminance > 0.6 ? '#1d2327' : '#ffffff';
}

/**
 * Repaints a live preview card from the current colour fields.
 *
 * Writes CSS custom properties rather than concrete rules, so the card's own stylesheet keeps
 * ownership of what each colour is actually used for.
 *
 * @param {string} sCardSelector       The preview card.
 * @param {string} sBackgroundSelector Hex field holding the card background.
 * @param {string} sForegroundSelector Hex field holding the card foreground.
 * @returns {void}
 */
function tpfwUpdatePassCardColors(sCardSelector, sBackgroundSelector, sForegroundSelector)
{
    let elCard        = jQuery(sCardSelector);
    if(elCard.length < 1) return;
    let sForeground   = jQuery(sForegroundSelector).val();
    elCard.css('--tpfw-pass-bg', jQuery(sBackgroundSelector).val());
    elCard.css('--tpfw-pass-fg', sForeground);
    // The header sits on the foreground colour, so it needs whichever of black/white stays
    // readable against it.
    elCard.css('--tpfw-pass-header-text', tpfwGetContrastTextColor(sForeground));
}

/**
 * Wires up one complete QR design section - colours, preview card, server preview and logo.
 *
 * Every product type's QR section follows the same tpfw-<key>-qr-* naming, so pass, guest pass,
 * ticket and timeslot all go through this one binder. What differs is only which preview card to
 * repaint, which admin-ajax action renders the preview, and which localized parameter object
 * carries the nonces and translations; everything else is derived from sKey.
 *
 * @param {object} oParams       The script's localized parameters: aNonces and aTranslations.
 * @param {string} sKey          Section key: 'pass', 'guestpass', 'ticket' or 'timeslot'.
 * @param {string} sCardSelector The live preview card belonging to this section.
 * @param {string} sAction       admin-ajax action that renders the QR preview.
 * @returns {void}
 */
function tpfwBindQRSection(oParams, sKey, sCardSelector, sAction)
{
    var aTranslations = oParams.aTranslations;
    var sBase    = '.tpfw-' + sKey + '-qr-';
    var sLogoID  = sBase + 'logo-id';
    var sPreview = sBase + 'preview';

    /** Repaints this section's preview card from its own colour fields. */
    function syncCard()
    {
        tpfwUpdatePassCardColors(sCardSelector, sBase + 'background-hexcolor', sBase + 'foreground-hexcolor');
    }

    // Each colour row is a native colour input plus a hex text field writing the same value -
    // the text field is there so a brand colour can be pasted rather than eyeballed.
    // Whichever is edited updates the other and repaints the preview card.
    ['label', 'background', 'foreground'].forEach(function(sRow)
    {
        var sPicker = sBase + sRow + '-colorpicker';
        var sHex    = sBase + sRow + '-hexcolor';

        jQuery(sPicker).on('change', function()
        {
            jQuery(sHex).val(jQuery(this).val());
            syncCard();
        });

        jQuery(sHex).on('change', function()
        {
            jQuery(sPicker).val(jQuery(this).val());
            syncCard();
        });
    });

    syncCard();

    // Asks the server for a fresh QR image using the colours currently in the fields. The
    // whole options panel is dimmed while it renders, since changing a field mid-render would
    // produce a preview that no longer matches what the panel shows.
    jQuery(sPreview).on('click', function()
    {
        var oThis            = jQuery(this);
        var oThisPageContent = jQuery(oThis).closest('.woocommerce_options_panel');

        var data = {
            action                        : 'tpfw_' + sAction,
            security                      : oParams.aNonces[sAction],
            post_id                       : parseInt(jQuery(this).attr('data-attr-postid')),
        };
        data[sKey + '_qr_text']                 = jQuery(sBase + 'text').val();
        data[sKey + '_qr_hex_background_color'] = jQuery(sBase + 'background-hexcolor').val();
        data[sKey + '_qr_hex_foreground_color'] = jQuery(sBase + 'foreground-hexcolor').val();
        data[sKey + '_qr_hex_label_color']      = jQuery(sBase + 'label-hexcolor').val();
        data[sKey + '_qr_image_id']             = jQuery(sLogoID).val();

        jQuery(oThis).css('pointer-events', 'none');
        jQuery(oThisPageContent).css('pointer-events', 'none');
        jQuery(oThis).fadeTo('fast', 0.2);
        jQuery(oThisPageContent).fadeTo('fast', 0.2);

        jQuery.ajax({
            url     : ajaxurl,
            data    : data,
            dataType: 'JSON',
            method  : 'POST',
        })
        .done(function(oResult)
        {
            if(oResult.success == true)
            {
                // The file is overwritten in place, so the URL never changes - blanking the
                // src and appending a timestamp is what forces the browser past its cache.
                // Set through URL rather than string concatenation: sPreviewURL carries its own
                // query string now that files are served through ?tpfw_file=, and pasting a '?'
                // onto the end of that folded the timestamp into the ext parameter, which then
                // failed the server's extension allowlist and 404'd.
                var oPreviewURL = new URL(oResult.data.sPreviewURL, window.location.href);
                oPreviewURL.searchParams.set('v', new Date().getTime());
                jQuery(sBase + 'preview-image').attr('src', "");
                jQuery(sBase + 'preview-image').attr('src', oPreviewURL.href);
            }
            else
            {
                alert(oResult.data.sMessage || aTranslations.sPreviewFailed);
            }
        })
        .fail(function()
        {
            alert(aTranslations.sPreviewFailed);
        })
        .always(function()
        {
            jQuery(oThis).css('pointer-events', 'auto');
            jQuery(oThisPageContent).css('pointer-events', 'auto');
            jQuery(oThis).fadeTo('450', 1);
            jQuery(oThisPageContent).fadeTo('450', 1);
        });
    });

    // Picks the logo shown in the middle of the QR code, through the standard WordPress media
    // modal rather than a bespoke uploader, so it can reuse images already in the library.
    jQuery(document).on('click', sBase + 'upload', function()
    {
        const oCustomUploader = wp.media({
            title  : aTranslations.sInsertQRLogo,
            library:
            {
                // Deliberately not scoped to this post: the same logo is normally reused
                // across every product, so the whole image library is offered.
                type: 'image'
            },
            button :
            {
                text: aTranslations.sUseSelected
            },
            multiple: false
        })
        .on('select', function()
        {
            const oAttachment = oCustomUploader.state().get('selection').first().toJSON();
            jQuery(sLogoID).val(oAttachment.id).trigger('change');
            jQuery(sBase + 'remove').show();
            jQuery(sBase + 'upload').hide();
            // Re-render the preview with the new logo. Deferred so the hidden field's change
            // event has settled before the preview reads it back.
            setTimeout(function() { jQuery(sPreview).trigger('click'); }, 200);
        });

        // Pre-select the logo already attached to this product, so re-opening the picker
        // shows the current choice rather than an empty library.
        oCustomUploader.on('open', function()
        {
            const iImageID = parseInt(jQuery(sLogoID).val());

            if(iImageID)
            {
                const oSelection  = oCustomUploader.state().get('selection');
                const oAttachment = wp.media.attachment(iImageID);
                oAttachment.fetch();
                oSelection.add(oAttachment ? [oAttachment] : []);
            }
        });

        oCustomUploader.open();
    });

    // Clears the logo and re-renders the preview without it.
    jQuery(document).on('click', sBase + 'remove', function()
    {
        jQuery(sLogoID).val("").trigger('change');
        jQuery(sBase + 'remove').hide();
        jQuery(sBase + 'upload').show();
        setTimeout(function() { jQuery(sPreview).trigger('click'); }, 200);
    });
}
