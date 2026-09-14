/**
 * "Download ticket" button on the My Account > Tickets list.
 *
 * The PDF is rendered on demand rather than stored per order, so the click asks the server to
 * build (or reuse) it and then triggers the browser download from the URL that comes back.
 */
jQuery(document).ready(function()
{
    // ajaxurl is not defined on the front end the way it is in wp-admin, so it is localized
    // in with the rest of this script's data. It used to be read off tpfwParamsTicketWcMyaccount, which belongs
    // to the Passes script - on a shop with passes switched off that global does not exist
    // and the ReferenceError took the whole file down with it, download button included.
    const ajaxurl = tpfwParamsTicketWcMyaccount.ajaxurl;
    jQuery('.download-ticket-pdf-btn').on('click', function()
    {
        var oThis = jQuery(this);
        var data  = 
        {
            action   : 'tpfw_ajax_fetch_downloabable_ticket_pdf',
            security : tpfwParamsTicketWcMyaccount.aNonces.ajax_fetch_downloabable_ticket_pdf,
            nano_id  : jQuery(this).closest('.ticket-container').attr('data-attr-nanoid'),
            row_id   : jQuery(this).closest('.ticket-container').attr('data-attr-rowid'),
        }       

        jQuery(oThis).css('pointer-events', 'none');
        jQuery(oThis).fadeTo('fast', 0.2);
        
        jQuery.ajax({
            url: ajaxurl,
            data: data,
            dataType: 'JSON',
            method: 'POST',
        })
        .done(function(oResult)
        {
            if(oResult.success == true)
            {                
                const   a               = document.createElement('a');
                        a.style.display = 'none';
                        a.href          = oResult.data.sDownloadURL;
                        a.download      = oResult.data.sFilename;
                document.body.appendChild(a);
                a.click();
                // Every download left its anchor behind in the body until the next page load.
                a.remove();
            }
            else
            {
                alert(oResult.data.sMessage || tpfwParamsTicketWcMyaccount.translations.sDownloadFailed);
            }
        })
        .fail(function()
        {
            alert(tpfwParamsTicketWcMyaccount.translations.sDownloadFailed);
        })
        .always(function()
        {
            jQuery(oThis).css('pointer-events', 'auto');
            jQuery(oThis).fadeTo('450', 1);
        });
    })
});