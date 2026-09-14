jQuery(document).ready(function()
{

    /**
     * Scanner / API settings screen: the two enable toggles, the three scan-result colours and
     * the AJAX save.
     *
     * The colours are what the scanner paints its result banner with - one per outcome, keyed
     * by the HTTP status the check-in endpoint answers with (200 accepted, 202 already checked
     * in / locked, 406 rejected).
     */

    // Each colour is a native colour input plus a hex text field writing the same option - the
    // text field is there so a brand colour can be pasted rather than eyeballed.
    ['406', '202', '200'].forEach(function(sStatus)
    {
        var sPicker = '.tpfw-status-' + sStatus + '-colorpicker';
        var sHex    = '.tpfw-status-' + sStatus + '-hexcolor';

        jQuery(sPicker).on('change', function() { jQuery(sHex).val(jQuery(this).val()); });
        jQuery(sHex).on('change', function() { jQuery(sPicker).val(jQuery(this).val()); });
    });

    /**
     * Shows the colour rows while either toggle is on.
     *
     * The two toggles are independent - the scanner authenticates with its own login session,
     * not through the API - but both outcomes are painted with the colours below.
     *
     * @returns {void}
     */
    function toggleStatusRows()
    {
        var bEither = jQuery('.tpfw-api-enable-api').is(':checked') || jQuery('.tpfw-api-enable-scanner').is(':checked');
        jQuery('.tpfw-status-row').toggleClass('hide', !bEither);
    }

    jQuery('.tpfw-api-enable-api').on('change', function()
    {
        jQuery('.tpfw-api-docs-row').toggleClass('hide', !jQuery(this).is(':checked'));
        toggleStatusRows();
    });

    jQuery('.tpfw-api-enable-scanner').on('change', function()
    {
        jQuery('.tpfw-scanner-url').toggleClass('hide', !jQuery(this).is(':checked'));
        toggleStatusRows();
    })

    jQuery('.tpfw-admin-api-settings-save').on('click', function()
    {
        var oThis            = jQuery(this);
        var oThisPageContent = jQuery(oThis).closest('.tpfw-settings-page');

        // var, not bare assignments - these two were implicit globals on window.
        var bEnableAPI = 0;
        if(jQuery('.tpfw-api-enable-api').is(':checked'))
        {
            bEnableAPI = 1;
        }

        var bEnableScanner = 0;
        if(jQuery('.tpfw-api-enable-scanner').is(':checked'))
        {
            bEnableScanner = 1;
        }

        var data = {
            action        : 'tpfw_ajax_save_tpfw_api_settings',
            security      : tpfwParamsApiSettings.aNonces.ajax_save_tpfw_api_settings,
            bEnableAPI    : bEnableAPI,
            bEnableScanner: bEnableScanner,
            status_406: jQuery('.tpfw-status-406-hexcolor').val(),
            status_202: jQuery('.tpfw-status-202-hexcolor').val(),
            status_200: jQuery('.tpfw-status-200-hexcolor').val(),
        }
        
        jQuery(oThis).css('pointer-events', 'none');
        jQuery(oThisPageContent).css('pointer-events', 'none');
        jQuery(oThis).fadeTo('fast', 0.2);
        jQuery(oThisPageContent).fadeTo('fast', 0.2);
        
        jQuery.ajax({
            url: ajaxurl,
            data: data,
            dataType: 'JSON',
            method: 'POST',
        })
        .done(function(oResult)
        {                            
            // Reload like every other settings screen: the scanner URL row and the API-dependent
            // rows are rendered server side from these options, so without this the panel kept
            // showing the pre-save state until the admin refreshed by hand. (It also used to
            // console.dir() the whole response on every save.)
            if(oResult.success == true)
            {
                window.location.reload();
            }
            else
            {
                alert(oResult.data.sMessage || tpfwParamsApiSettings.translations.sSaveFailed);
            }
        })
        .fail(function()
        {
            alert(tpfwParamsApiSettings.translations.sSaveFailed);
        })
        .always(function()
        {
            jQuery(oThis).css('pointer-events', 'auto');
            jQuery(oThisPageContent).css('pointer-events', 'auto');
            jQuery(oThis).fadeTo('450', 1);
            jQuery(oThisPageContent).fadeTo('450', 1);
        });
    })
})