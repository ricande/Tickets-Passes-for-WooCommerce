/**
 * The Ticket panel on the WooCommerce product edit screen.
 *
 * Covers the duration fields, the QR colour pickers and their live preview card, the QR logo
 * media picker, and the two date toggles. The QR preview itself is rendered server side - the
 * same generator that produces the real ticket - so what the shop owner sees here is what gets
 * emailed, rather than a CSS approximation of it.
 */
jQuery(document).ready(function()
{

    let aTranslations = tpfwParamsTicketWcProduct.aTranslations;

    // WooCommerce only paints the stock-management checkbox for simple/variable. Tag the
    // fields first so a later error in the QR preview cannot leave them missing, and so
    // switching the type dropdown to Ticket still shows them.
    jQuery('#inventory_product_data ._manage_stock_field').addClass('show_if_tpfw-ticket');
    jQuery('#inventory_product_data .stock_fields').addClass('show_if_tpfw-ticket');

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
    jQuery('#_tpfw_ticket_note').on('input', function()
    {
        jQuery('#tpfw-ticket-note-counter').text(jQuery(this).val().length + ' / 350');
    });

    // The QR design section - colours, live preview card, server preview and logo picker -
    // is wired by the shared binder in functions-admin.js.
    tpfwBindQRSection(tpfwParamsTicketWcProduct, 'ticket', '.tpfw-ticket-pass-card', 'ajax_settings_preview_ticket_qr');

    // A fixed start date only applies when the shop owner has opted into one - otherwise the
    // ticket becomes valid at purchase - so the date field follows its own toggle.
    jQuery('#_tpfw_ticket_predefined_start_date_enable').on('change', function()
    {
        if(jQuery('#_tpfw_ticket_predefined_start_date_enable').is(':checked'))
        {
            jQuery('#_tpfw_ticket_predefined_start_date').closest('p').removeClass('hide')
            // A fixed date and a customer-picked one are two answers to the same question,
            // so switching one on switches the other off rather than refusing the click.
            jQuery('#_tpfw_ticket_user_start_date_enable').prop('checked', false).trigger('change')
            // A fixed date publishes itself, so it replaces the validity window rows rather
            // than being listed alongside them.
            jQuery('#_tpfw_ticket_show_predefined_date').closest('p').removeClass('hide')
            jQuery('#_tpfw_ticket_show_valid_from').prop('checked', false).closest('p').addClass('hide')
            jQuery('#_tpfw_ticket_show_valid_to').prop('checked', false).closest('p').addClass('hide')
        }
        else
        {
            jQuery('#_tpfw_ticket_predefined_start_date').closest('p').addClass('hide')
            jQuery('#_tpfw_ticket_show_predefined_date').prop('checked', false).closest('p').addClass('hide')
            if(!jQuery('#_tpfw_ticket_user_start_date_enable').is(':checked'))
            {
                jQuery('#_tpfw_ticket_show_valid_from').closest('p').removeClass('hide')
                jQuery('#_tpfw_ticket_show_valid_to').closest('p').removeClass('hide')
            }
        }
    })

    // The two validity dates are only worth publishing while the shop owns them - a
    // customer-picked start date is not known until the ticket is bought.
    jQuery('#_tpfw_ticket_user_start_date_enable').on('change', function()
    {
        if(jQuery('#_tpfw_ticket_user_start_date_enable').is(':checked'))
        {
            jQuery('#_tpfw_ticket_predefined_start_date_enable').prop('checked', false).trigger('change')
            jQuery('#_tpfw_ticket_show_valid_from').prop('checked', false).closest('p').addClass('hide')
            jQuery('#_tpfw_ticket_show_valid_to').prop('checked', false).closest('p').addClass('hide')
            // The window the calendar on the product page offers is only a question worth
            // asking while the customer is the one answering it.
            jQuery('#_tpfw_ticket_user_start_date_min').closest('p').removeClass('hide')
            jQuery('#_tpfw_ticket_user_start_date_max').closest('p').removeClass('hide')
        }
        else
        {
            jQuery('#_tpfw_ticket_user_start_date_min').closest('p').addClass('hide')
            jQuery('#_tpfw_ticket_user_start_date_max').closest('p').addClass('hide')
            if(!jQuery('#_tpfw_ticket_predefined_start_date_enable').is(':checked'))
            {
                jQuery('#_tpfw_ticket_show_valid_from').closest('p').removeClass('hide')
                jQuery('#_tpfw_ticket_show_valid_to').closest('p').removeClass('hide')
            }
        }
    })

    // The far end of the offered range can never sit behind the near end. The value is dragged
    // along rather than just floored, so the field never holds a date its own min forbids - an
    // invalid control that is then hidden by the toggle above would block the whole product save.
    jQuery('#_tpfw_ticket_user_start_date_min').on('change', function()
    {
        let elMax = jQuery('#_tpfw_ticket_user_start_date_max')
        elMax.attr('min', jQuery(this).val())
        if(elMax.val() < jQuery(this).val()) elMax.val(jQuery(this).val())
    })

    // Same for the sales window: both ends of it are hidden until it is switched on.

    // The sales window may not close before it opens. The value is dragged along rather than
    // just floored, so the field never holds a date its own min forbids - an invalid control
    // that the toggle above then hides would block the whole product save.
    jQuery('#_tpfw_ticket_sales_timespan_start').on('change', function()
    {
        let elEnd = jQuery('#_tpfw_ticket_sales_timespan_end')
        elEnd.attr('min', jQuery(this).val())
        if(elEnd.val() < jQuery(this).val()) elEnd.val(jQuery(this).val())
    })
    jQuery('#_tpfw_ticket_sales_timespan_enable').on('change', function()
    {
        if(jQuery('#_tpfw_ticket_sales_timespan_enable').is(':checked'))
        {
            jQuery('#_tpfw_ticket_sales_timespan_start').closest('p').removeClass('hide')
            jQuery('#_tpfw_ticket_sales_timespan_end').closest('p').removeClass('hide')
            jQuery('#_tpfw_ticket_show_sales_window').closest('p').removeClass('hide')
        }
        else
        {
            jQuery('#_tpfw_ticket_sales_timespan_start').closest('p').addClass('hide')
            jQuery('#_tpfw_ticket_sales_timespan_end').closest('p').addClass('hide')
            jQuery('#_tpfw_ticket_show_sales_window').prop('checked', false).closest('p').addClass('hide')
        }
    })

    /**
     * Forces the General tab and pricing fields back on for a ticket product.
     *
     * WooCommerce hides pricing for unknown product types, so this runs both when the type
     * dropdown changes and on initial load of an existing product.
     *
     * @returns {void}
     */
    function tpfwShowTicketPanels()
    {
        jQuery('.general_tab').show();
        jQuery('.pricing').show();
        // WC's type-change handler has already hidden these (no show_if_tpfw-ticket in core).
        jQuery('#inventory_product_data ._manage_stock_field').addClass('show_if_tpfw-ticket').show();
        jQuery('#inventory_product_data .stock_fields').addClass('show_if_tpfw-ticket');
        jQuery('#inventory_product_data input#_manage_stock').trigger('change');
    }

    jQuery(document.body).on('woocommerce-product-type-change', function(event, type)
    {
        if(type == 'tpfw-ticket')
        {
            tpfwShowTicketPanels();
        }
    });

    if(jQuery('#product-type').val() == 'tpfw-ticket')
    {
        tpfwShowTicketPanels();
    }
})