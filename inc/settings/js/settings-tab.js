/**
 * Shared script for the General, Ticket, Timeslot Ticket and Pass settings tabs.
 *
 * Everything screen-specific arrives in the localized tpfwParamsSettingsTab object: which
 * fields to post (aFields), which toggle hides which rows, and which colour pickers mirror
 * which hex inputs. Saving reloads on success so the page re-renders from the stored options.
 */
jQuery(document).ready(function()
{
    var oParams = window.tpfwParamsSettingsTab;
    if(!oParams) return;

    // The rows below the enable toggle only mean anything once the type is switched on.
    if(oParams.sEnableSelector && oParams.sExtraRowSelector)
    {
        jQuery(oParams.sEnableSelector).on('change', function()
        {
            jQuery(oParams.sExtraRowSelector).toggleClass('hide', !jQuery(this).is(':checked'));
        });
    }

    // Each colour row is a native colour input plus a hex text field writing the same option -
    // the text field is there so a brand colour can be pasted rather than eyeballed.
    // 'input' as well as 'change' on the picker, so the hex keeps up while the OS colour dialog
    // is still open rather than jumping once it closes.
    (oParams.aColorRows || []).forEach(function(oRow)
    {
        jQuery(oRow.sPicker).on('input change', function() { jQuery(oRow.sHex).val(jQuery(this).val()); syncColorRows(); });
        jQuery(oRow.sHex).on('input change', function() { jQuery(oRow.sPicker).val(jQuery(this).val()); syncColorRows(); });
    });

    /** Repaints the reset links and the preview from whatever the rows currently hold. */
    function syncColorRows()
    {
        syncColorResets();
        syncPreview();
    }

    // The preview is themed the way the product page is themed: by writing the same custom
    // properties onto one wrapper. Which property a row feeds travels in data-tpfw-var from
    // PHP, so the mapping is stated once, next to the front end's own.
    var oStage = jQuery('.tpfw-preview-stage');

    function syncPreview()
    {
        if(oStage.length < 1) return;

        jQuery('.tpfw-colorpicker').each(function()
        {
            var sVar = jQuery(this).attr('data-tpfw-var');
            var sHex = (jQuery(this).find('.tpfw-colorpicker-hex').val() || '').trim();
            if(!sVar || !/^#[0-9a-f]{6}$/i.test(sHex)) return;

            oStage[0].style.setProperty(sVar, sHex);
            // Text on the accent is picked, not set: there is no setting for it, and the front
            // end derives it the same way through get_contrast_text_color().
            if(sVar === '--tpfw-accent' && typeof tpfwGetContrastTextColor === 'function')
            {
                oStage[0].style.setProperty('--tpfw-accent-fg', tpfwGetContrastTextColor(sHex));
            }
        });
    }

    jQuery('.tpfw-preview-theme button').on('click', function()
    {
        jQuery('.tpfw-preview-theme button').removeClass('is-on');
        jQuery(this).addClass('is-on');
        oStage.attr('data-theme', jQuery(this).attr('data-tpfw-theme'));
    });

    // The reset only has something to do while the row differs from the colour it shipped with,
    // so it says so by being dead rather than by silently doing nothing.
    function syncColorResets()
    {
        jQuery('.tpfw-colorpicker').each(function()
        {
            var oReset = jQuery(this).find('.tpfw-colorpicker-reset');
            var sValue = (jQuery(this).find('.tpfw-colorpicker-hex').val() || '').trim().toLowerCase();
            oReset.prop('disabled', sValue === (oReset.attr('data-tpfw-default') || '').toLowerCase());
        });
    }
    syncColorResets();

    // Preset swatches. Written to both inputs the same way the shared reset button does it,
    // then 'change' on the hex field so anything else listening on the row still hears about it.
    jQuery(document).on('click', '.tpfw-colorpicker-palette button', function()
    {
        var sHex   = jQuery(this).attr('data-tpfw-color');
        var oField = jQuery(this).closest('td').find('.tpfw-colorpicker');
        oField.find('.tpfw-colorpicker-swatch').val(sHex);
        oField.find('.tpfw-colorpicker-hex').val(sHex).trigger('change');
    });

    jQuery(oParams.sSaveButton).on('click', function()
    {
        var oThis = jQuery(this);
        var oPage = oThis.closest('.tpfw-settings-page');

        var data = { action: 'tpfw_' + oParams.sAction, security: oParams.aNonces[oParams.sAction] };
        (oParams.aFields || []).forEach(function(oField)
        {
            var oInput = jQuery(oField.sSelector);
            data[oField.sKey] = oField.sType === 'checkbox' ? (oInput.is(':checked') ? 1 : 0) : oInput.val();
        });

        oThis.css('pointer-events', 'none').fadeTo('fast', 0.2);
        oPage.css('pointer-events', 'none').fadeTo('fast', 0.2);

        jQuery.ajax({ url: ajaxurl, data: data, dataType: 'JSON', method: 'POST' })
        .done(function(oResult)
        {
            if(oResult.success == true)
            {
                window.location.reload();
            }
            else
            {
                alert((oResult.data && oResult.data.sMessage) || oParams.translations.sSaveFailed);
            }
        })
        .fail(function()
        {
            alert(oParams.translations.sSaveFailed);
        })
        .always(function()
        {
            oThis.css('pointer-events', 'auto').fadeTo('450', 1);
            oPage.css('pointer-events', 'auto').fadeTo('450', 1);
        });
    });
});
