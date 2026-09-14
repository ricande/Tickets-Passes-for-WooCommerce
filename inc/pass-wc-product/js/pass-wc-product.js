/**
 * The Pass panel on the WooCommerce product edit screen.
 *
 * A pass product carries two QR designs, not one: the holder's own pass and the guest passes it
 * can hand out. Both are configured the same way - three colour rows, a live preview card, a
 * server-rendered QR preview and a logo picker - so they are wired by one shared binder rather
 * than two copies of the same 130 lines. The guest pass half only applies while guest passes are
 * enabled, which is what tpfwToggleGuestpassQRTab() is watching.
 */
jQuery(document).ready(function()
{

    let aTranslations = tpfwParamsPassWcProduct.aTranslations

    jQuery('#inventory_product_data ._manage_stock_field').addClass('show_if_tpfw-pass');
    jQuery('#inventory_product_data .stock_fields').addClass('show_if_tpfw-pass');

    /**
     * Turns each "duration in seconds" hidden field into a number box plus a unit dropdown.
     *
     * The value is stored as plain seconds so the PHP side never has to know about units, but
     * asking a shop owner to type 604800 for a week is not reasonable, so the stored value is
     * split into the largest unit that divides it exactly and recombined on every edit.
     *
     * @returns {void}
     */
    function tpfwInitDurationFields()
    {
        // Week, day, hour, minute, second - largest first, so the field opens on the coarsest
        // unit the stored value divides into cleanly.
        let aUnits = [604800, 86400, 3600, 60, 1];

        jQuery('.tpfw-duration-field').each(function()
        {
            let elField  = jQuery(this);
            // Ticket/Timeslot/Pass/Guest Pass JS all load together on every product
            // edit screen and all touch this generic selector - guard against double-binding.
            if(elField.data('tpfwDurationInit')) return;
            elField.data('tpfwDurationInit', true);

            let elHidden = elField.find('.tpfw-duration-seconds');
            let elNumber = elField.find('.tpfw-duration-number');
            let elUnit   = elField.find('.tpfw-duration-unit');
            let iSeconds = parseInt(elHidden.val()) || 0;

            let iChosenUnit = 1;
            for(let i = 0; i < aUnits.length; i++)
            {
                if(iSeconds > 0 && iSeconds % aUnits[i] === 0)
                {
                    iChosenUnit = aUnits[i];
                    break;
                }
            }
            elUnit.val(iChosenUnit);
            elNumber.val(iSeconds > 0 ? (iSeconds / iChosenUnit) : '');

            // Writes number x unit back into the hidden seconds field that actually submits.
            let fnRecompute = function()
            {
                let iNumberVal = parseFloat(elNumber.val()) || 0;
                let iUnitVal   = parseInt(elUnit.val()) || 1;
                elHidden.val(Math.round(iNumberVal * iUnitVal));
            }

            elNumber.on('input change', fnRecompute);
            elUnit.on('change', fnRecompute);
        });
    }
    tpfwInitDurationFields();

    // Keeps the "x / 350" counter under the Product Note textarea in sync as the shop owner types.
    jQuery('#_tpfw_pass_note').on('input', function()
    {
        jQuery('#tpfw-pass-note-counter').text(jQuery(this).val().length + ' / 350');
    });


    tpfwBindQRSection(tpfwParamsPassWcProduct, 'pass', '.tpfw-pass-own-card', 'ajax_settings_preview_pass_qr');
    tpfwBindQRSection(tpfwParamsPassWcProduct, 'guestpass', '.tpfw-guestpass-pass-card', 'ajax_settings_preview_guestpass_qr');

    // A fixed start date only applies when the shop owner has opted into one - otherwise the pass
    // becomes valid at purchase - so the date field follows its own toggle.
    jQuery('#_tpfw_pass_predefined_start_date_enable').on('change', function()
    {
        if(jQuery('#_tpfw_pass_predefined_start_date_enable').is(':checked'))
        {
            jQuery('#_tpfw_pass_predefined_start_date').closest('p').removeClass('hide')
            // A fixed date and a customer-picked one are two answers to the same question,
            // so switching one on switches the other off rather than refusing the click.
            jQuery('#_tpfw_pass_user_start_date_enable').prop('checked', false).trigger('change')
            // A fixed date publishes itself, so it replaces the validity window rows rather
            // than being listed alongside them.
            jQuery('#_tpfw_pass_show_predefined_date').closest('p').removeClass('hide')
            jQuery('#_tpfw_pass_show_valid_from').prop('checked', false).closest('p').addClass('hide')
            jQuery('#_tpfw_pass_show_valid_to').prop('checked', false).closest('p').addClass('hide')
        }
        else
        {
            jQuery('#_tpfw_pass_predefined_start_date').closest('p').addClass('hide')
            jQuery('#_tpfw_pass_show_predefined_date').prop('checked', false).closest('p').addClass('hide')
            if(!jQuery('#_tpfw_pass_user_start_date_enable').is(':checked'))
            {
                jQuery('#_tpfw_pass_show_valid_from').closest('p').removeClass('hide')
                jQuery('#_tpfw_pass_show_valid_to').closest('p').removeClass('hide')
            }
        }
    })

    // The two validity dates are only worth publishing while the shop owns them - a
    // customer-picked start date is not known until the pass is bought.
    jQuery('#_tpfw_pass_user_start_date_enable').on('change', function()
    {
        if(jQuery('#_tpfw_pass_user_start_date_enable').is(':checked'))
        {
            jQuery('#_tpfw_pass_predefined_start_date_enable').prop('checked', false).trigger('change')
            jQuery('#_tpfw_pass_show_valid_from').prop('checked', false).closest('p').addClass('hide')
            jQuery('#_tpfw_pass_show_valid_to').prop('checked', false).closest('p').addClass('hide')
            // The window the calendar on the product page offers is only a question worth
            // asking while the customer is the one answering it.
            jQuery('#_tpfw_pass_user_start_date_min').closest('p').removeClass('hide')
            jQuery('#_tpfw_pass_user_start_date_max').closest('p').removeClass('hide')
        }
        else
        {
            jQuery('#_tpfw_pass_user_start_date_min').closest('p').addClass('hide')
            jQuery('#_tpfw_pass_user_start_date_max').closest('p').addClass('hide')
            if(!jQuery('#_tpfw_pass_predefined_start_date_enable').is(':checked'))
            {
                jQuery('#_tpfw_pass_show_valid_from').closest('p').removeClass('hide')
                jQuery('#_tpfw_pass_show_valid_to').closest('p').removeClass('hide')
            }
        }
    })

    // The far end of the offered range can never sit behind the near end. The value is dragged
    // along rather than just floored, so the field never holds a date its own min forbids - an
    // invalid control that is then hidden by the toggle above would block the whole product save.
    jQuery('#_tpfw_pass_user_start_date_min').on('change', function()
    {
        let elMax = jQuery('#_tpfw_pass_user_start_date_max')
        elMax.attr('min', jQuery(this).val())
        if(elMax.val() < jQuery(this).val()) elMax.val(jQuery(this).val())
    })

    // The compression and size limits only mean anything once holders are allowed to upload a
    // profile photo at all.
    jQuery('#_tpfw_pass_profile_image_upload_enable').on('change', function()
    {                                               
        if(jQuery('#_tpfw_pass_profile_image_upload_enable').is(':checked'))
        {                                                                     
            jQuery('#_tpfw_pass_profile_image_upload_compression_algo').closest('p').removeClass('hide')                                    
            jQuery('#_tpfw_pass_profile_image_upload_max_size').closest('p').removeClass('hide')                                    
        }
        else
        {                                                                    
            jQuery('#_tpfw_pass_profile_image_upload_compression_algo').closest('p').addClass('hide')                                                                                
            jQuery('#_tpfw_pass_profile_image_upload_max_size').closest('p').addClass('hide')                                                                                
        }
    })

    // Same for the sales window: both ends of it are hidden until it is switched on.

    // The sales window may not close before it opens. The value is dragged along rather than
    // just floored, so the field never holds a date its own min forbids - an invalid control
    // that the toggle above then hides would block the whole product save.
    jQuery('#_tpfw_pass_sales_timespan_start').on('change', function()
    {
        let elEnd = jQuery('#_tpfw_pass_sales_timespan_end')
        elEnd.attr('min', jQuery(this).val())
        if(elEnd.val() < jQuery(this).val()) elEnd.val(jQuery(this).val())
    })
    jQuery('#_tpfw_pass_sales_timespan_enable').on('change', function()
    {
        if(jQuery('#_tpfw_pass_sales_timespan_enable').is(':checked'))
        {
            jQuery('#_tpfw_pass_sales_timespan_start').closest('p').removeClass('hide')
            jQuery('#_tpfw_pass_sales_timespan_end').closest('p').removeClass('hide')
            jQuery('#_tpfw_pass_show_sales_window').closest('p').removeClass('hide')
        }
        else
        {
            jQuery('#_tpfw_pass_sales_timespan_start').closest('p').addClass('hide')
            jQuery('#_tpfw_pass_sales_timespan_end').closest('p').addClass('hide')
            jQuery('#_tpfw_pass_show_sales_window').prop('checked', false).closest('p').addClass('hide')
        }
    })

    /**
     * Shows the Guest Pass QR tab only on a pass product that actually issues guest passes.
     *
     * @returns {void}
     */
    function tpfwToggleGuestpassQRTab()
    {
        if(jQuery('#product-type').val() != 'tpfw-pass' || (jQuery('#_tpfw_pass_guest_pass_enable').length >= 1 && !jQuery('#_tpfw_pass_guest_pass_enable').is(':checked')))
        {
            jQuery('.guestpass-settings-qr_tab').hide()
        }
        else
        {
            jQuery('.guestpass-settings-qr_tab').show()
        }
    }
    tpfwToggleGuestpassQRTab();

    // Re-check whenever the product type dropdown changes - this tab's own "show_if_tpfw-pass"
    // class only covers the product-type half of the rule, not "and guest pass is enabled", so
    // WooCommerce's own type-change handling isn't enough on its own; without this, switching the
    // dropdown away from Pass (without a full page reload) left the tab visible for every type.
    jQuery(document.body).on('woocommerce-product-type-change', tpfwToggleGuestpassQRTab);

    // Turning guest passes on or off takes the whole guest pass QR tab and its three settings
    // with it.
    jQuery('#_tpfw_pass_guest_pass_enable').on('change', function()
    {                            
        if(jQuery('#_tpfw_pass_guest_pass_enable').is(':checked'))
        {                
            jQuery('.guestpass-settings-qr_tab').show()
            jQuery('#_tpfw_pass_guest_pass_quantity').closest('p').removeClass('hide')
            jQuery('#_tpfw_pass_guest_pass_valid_duration').closest('p').removeClass('hide')                    
            jQuery('#_tpfw_guestpass_cooldown_sec').closest('p').removeClass('hide')
        }
        else
        {                
            jQuery('.guestpass-settings-qr_tab').hide();
            jQuery('#_tpfw_pass_guest_pass_quantity').closest('p').addClass('hide')
            jQuery('#_tpfw_pass_guest_pass_valid_duration').closest('p').addClass('hide')
            jQuery('#_tpfw_guestpass_cooldown_sec').closest('p').addClass('hide')
        }
    })

    /**
     * Forces the General tab and pricing fields back on for a pass product.
     *
     * WooCommerce hides pricing for unknown product types, so this runs both when the type
     * dropdown changes and on initial load of an existing product.
     *
     * @returns {void}
     */
    function tpfwShowPassPanels()
    {
        jQuery('.general_tab').show();
        jQuery('.pricing').show();
        jQuery('#inventory_product_data ._manage_stock_field').addClass('show_if_tpfw-pass').show();
        jQuery('#inventory_product_data .stock_fields').addClass('show_if_tpfw-pass');
        jQuery('#inventory_product_data input#_manage_stock').trigger('change');
    }

    jQuery(document.body).on('woocommerce-product-type-change', function(event, type)
    {
        if(type == 'tpfw-pass')
        {
            tpfwShowPassPanels();
        }
    });

    if(jQuery('#product-type').val() == 'tpfw-pass')
    {
        tpfwShowPassPanels();
    }
})