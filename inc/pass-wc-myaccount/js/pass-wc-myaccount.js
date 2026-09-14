/**
 * The Passes tab in WooCommerce My Account.
 *
 * Three jobs: the guest pass modal, the pass PDF download, and the profile photo the holder
 * uploads onto their own pass. The photo is picked and previewed locally first and only sent when
 * Upload is pressed, because it cannot be changed once it has been submitted.
 *
 * ajaxurl is not defined on the front end the way it is in wp-admin, so it comes through
 * wp_localize_script() with the nonce and the translated strings.
 */
jQuery(document).ready(function()
{
    let translations    = tpfwParamsPassWcMyaccount.translations;
    let ajaxurl         = tpfwParamsPassWcMyaccount.ajaxurl;

    // Closing the modal also empties it, so the next pass's guest tabs are not briefly showing
    // the previous pass's while the new ones are fetched.
    jQuery('.modal-guest-wrapper .modal-guest-close').on('click', function()
    {
        jQuery('.modal-guest-wrapper').fadeOut('fast');
        jQuery('.modal-tabs-header').html('');
        jQuery('.modal-tabs-content').html('');
    });

    // One tab per guest pass. Delegated, because the tabs are built after this runs.
    jQuery(document).on('click', '.modal-tab', function()
    {
        const tab = jQuery(this).attr('data-tab');

        jQuery('.modal-tab').removeClass('active');
        jQuery(this).addClass('active');

        jQuery('.modal-tab-content').hide();
        jQuery(`.modal-tab-content[data-tab="${tab}"]`).fadeIn('fast');
    });

    // Opens the guest pass modal for one pass. The guest QR codes are fetched on demand rather
    // than rendered with the page, so a holder with several passes is not made to wait for QR
    // images they may never look at.
    if (jQuery('.guesspass-btn').length >= 1)
    {
        jQuery(document).on('click', '.guesspass-btn', function ()
        {
            // No nano id means no pass to ask about - the button is decoration on a broken row.
            if (jQuery(this).closest('.pass-container').attr('data-attr-nanoid') == undefined ||
                jQuery(this).closest('.pass-container').attr('data-attr-nanoid') == "" ||
                jQuery(this).closest('.pass-container').attr('data-attr-nanoid') == false) 
            {
                return;
            }

            var oThis      = jQuery(this);

            var data = 
            {
                action        : 'tpfw_ajax_tpfw_get_myaccount_guestpass',
                security      : tpfwParamsPassWcMyaccount.aNonces.ajax_tpfw_get_myaccount_guestpass,
                parent_nano_id: jQuery(this).closest('.pass-container').attr('data-attr-nanoid'),
                row_id        : parseInt(jQuery(this).closest('.pass-container').attr('data-attr-rowid')),
            };

            jQuery(oThis).css('pointer-events', 'none');
            jQuery(oThis).fadeTo('fast', 0.2);

            jQuery.ajax({
                url: ajaxurl,
                data: data,
                dataType: 'JSON',
                method: 'POST',
            })
                .done(function (oResult) 
                {
                    if(oResult.success == true) 
                    {
                        jQuery('.modal-guest-wrapper').fadeIn('fast');
                        if(oResult.data.aGuestPassURL) 
                        {
                            let tabsHtml = '';
                            let contentHtml = '';

                            oResult.data.aGuestPassURL.forEach((url, index) => 
                            {
                                let isActive = index === 0 ? 'active' : '';
                                tabsHtml += `
                                    <div class="modal-tab ${isActive}" data-tab="${index}">
                                        Pass ${index + 1}
                                    </div>
                                `;
                                contentHtml += `
                                    <div class="modal-tab-content ${isActive}" data-tab="${index}" style="display: ${index === 0 ? 'block' : 'none'};">
                                        <div class="modal-pass-card">
                                            <div class="modal-pass-card-header">
                                                <span class="modal-pass-card-kicker">${translations.sGuestPass}</span>
                                                <span class="modal-pass-card-name">Pass ${index + 1}</span>
                                            </div>
                                            <div class="modal-pass-card-qr">
                                                <img class="modal-guest-qrcode" src="${url}" alt="Guest Pass QR ${index + 1}">
                                            </div>
                                            <div class="modal-pass-card-id">${translations.sGuestPassID}: ${oResult.data.aGuestPassID[index]}</div>
                                        </div>
                                    </div>
                                `;
                            });

                            jQuery('.modal-tabs-header').html(tabsHtml);
                            jQuery('.modal-tabs-content').html(contentHtml);
                        }
                    }
                    else
                    {
                        alert(oResult.data.sMessage || translations.sFailedGuestFetch);
                    }
                })
                .fail(function () 
                {
                    alert(translations.sFailedGuestFetch);
                })
                .always(function () 
                {
                    jQuery(oThis).css('pointer-events', 'auto');
                    jQuery(oThis).fadeTo('450', 1);
                });
        });
    }
    


    // Downloads the pass as a PDF. The server renders it and answers with a URL rather than the
    // file, so the request stays a normal JSON call and the errors can be reported like any other.
    jQuery('.download-pass-pdf-btn').on('click', function()
    {
        var oThis = jQuery(this);
        var data  = 
        {
            action   : 'tpfw_ajax_fetch_pass_pdf',
            security : tpfwParamsPassWcMyaccount.aNonces.ajax_fetch_pass_pdf,
            nano_id  : jQuery(this).closest('.pass-container').attr('data-attr-nanoid'),
            row_id   : jQuery(this).closest('.pass-container').attr('data-attr-rowid'),
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
                const a         = document.createElement('a');
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
                alert(oResult.data.sMessage || translations.sDownloadFailed);
            }
        })
        .fail(function()
        {
            alert(translations.sDownloadFailed);
        })
        .always(function()
        {
            jQuery(oThis).css('pointer-events', 'auto');
            jQuery(oThis).fadeTo('450', 1);
        });
    })

    // Sends the previewed photo to the server. This is the point of no return - the pass keeps
    // whatever is uploaded here - hence the confirmation before anything is sent.
    jQuery(document).on('click', '.upload-btn', function()
    {
        if(confirm(translations.sImageGuidelines) == false)
        {
            return;
        }

        let oThis           = jQuery(this)
        let oThisWrapper    = jQuery(oThis).closest('.pass-container');
        let oFileInput      = jQuery(oThisWrapper).find('.fileinput-image').first()

        let nano_id    = jQuery(oThisWrapper).attr('data-attr-nanoid');
        let row_id     = jQuery(oThisWrapper).attr('data-attr-rowid');

        // Everything below returns without sending anything, so the pass container is only
        // dimmed and made click-proof further down, once the request is actually going out.
        // Dimming first meant that any one of these bailouts left the whole pass greyed out and
        // unclickable until the page was reloaded.
        if(!nano_id)
        {
            alert(translations.sMissedNanoID);
            return;
        }
        if(!row_id)
        {
            // This used to be a bare alert(), which pops up the word "undefined".
            alert(translations.sMissedRowID);
            return;
        }

        let aFiles = oFileInput.prop('files');

        if(aFiles.length > 1)
        {
            alert(translations.sOneFileMax);
            return;
        }

        if(aFiles.length < 1)
        {
            alert(translations.sMissingFile);
            return;
        }

        let oFile         = aFiles[0];
        let iFileSize     = oFile.size;
        let sFileType     = oFile.type;
        let aAllowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        // Matches the server's default cap (8 MB); the product can set its own, which the server
        // enforces. Convenience only - the server re-checks the size and the real mime type,
        // since both of these come from the browser and a crafted request never runs this code.
        let iAllowedSize  = 8 * 1024 * 1024;

        if(iFileSize > iAllowedSize)
        {
            alert(translations.sFilesizeError);
            return;
        }

        if(aAllowedTypes.indexOf(sFileType) == -1)
        {
            alert(translations.sFileTypeError);
            return;
        }

        jQuery(oThis).css('pointer-events', 'none');
        jQuery(oThis).fadeTo('450', 0.5);
        jQuery(oThisWrapper).css('pointer-events', 'none');
        jQuery(oThisWrapper).fadeTo('fast', 0.2);

        // FormData rather than a serialised object, so the file goes up as a real multipart
        // upload - hence processData/contentType being switched off below.
        let formData = new FormData();
        formData.append('action',           'tpfw_ajax_tpfw_profile_image_upload');
        formData.append('security',         tpfwParamsPassWcMyaccount.aNonces.ajax_tpfw_profile_image_upload);
        formData.append('profile_image',    oFile);
        formData.append('nano_id',          nano_id);            
        formData.append('row_id',           row_id);

        jQuery.ajax({
            url        : ajaxurl,
            data       : formData,
            dataType   : 'JSON',
            method     : 'POST',
            processData: false,
            contentType: false,
        })
        .done(function(oResult)
        {
            if(oResult.success == true)
            {
                // The photo is now fixed, so the pick/clear/upload controls go away. These were
                // looking for .select-button/.remove-button/.upload-button, which nothing in the
                // template is called - the buttons are .select-btn/.remove-btn/.upload-btn, as
                // every other handler in this file already assumes - so they never hid and the
                // holder was left free to try uploading over a photo the server would refuse.
                jQuery(oThisWrapper).find('.select-btn').hide();
                jQuery(oThisWrapper).find('.remove-btn').hide();
                jQuery(oThisWrapper).find('.upload-btn').hide();
            }
            else
            {
                alert(oResult.data.sMessage || translations.sUploadFailed);
            }
        })
        .fail(function()
        {
            alert(translations.sUploadFailed);
        })
        .always(function()
        {
            jQuery(oThis).css('pointer-events', 'auto');
            jQuery(oThisWrapper).css('pointer-events', 'auto');
            jQuery(oThis).fadeTo('450', 1);
            jQuery(oThisWrapper).fadeTo('450', 1);
        });
    });

    // Opens the file picker. The real <input type="file"> is hidden so the page can style a
    // button instead of the browser's default control.
    jQuery(document).on('click', '.select-btn', function()
    {
        let oFileInput = jQuery(this).closest('.pass-container').find('.fileinput-image').first()
        if(!oFileInput.length)
        {
            alert(translations.sMissingFileInput);
            return;
        }
        oFileInput.trigger('click');        
    });

    // Previews the picked file locally with a FileReader - nothing is sent until Upload is
    // pressed - and swaps Select for Remove/Upload now that there is something to act on.
    jQuery(document).on('change', '.fileinput-image', function()
    {
        let oThis           = jQuery(this);
        let oThisWrapper    = jQuery(oThis).closest('.pass-container');
        let oFileInput      = jQuery(oThisWrapper).find('.fileinput-image').first()
        let oImageContainer = jQuery(oThisWrapper).find('.pass-image').find('img').first()                
        let aFiles          = oFileInput.prop('files');
        if (aFiles && aFiles[0]) 
        {                                
            var reader = new FileReader();
            reader.onload = function (e) 
            {                                      
                jQuery(oImageContainer).attr('src', e.target.result);
                    jQuery(oThisWrapper).find('.select-btn').hide()
                    jQuery(oThisWrapper).find('.remove-btn').show()
                    jQuery(oThisWrapper).find('.upload-btn').show()
            };

            reader.readAsDataURL(aFiles[0]);
        }
    });

    // Discards the local preview and puts the placeholder avatar back. This only undoes the
    // pick - an already uploaded photo cannot be removed from here.
    jQuery(document).on('click', '.remove-btn', function()
    {
        let oThis           = jQuery(this)
        let oThisWrapper    = jQuery(oThis).closest('.pass-container');
        let oFileInput      = jQuery(oThisWrapper).find('.fileinput-image').first();
        let oImageContainer = jQuery(oThisWrapper).find('.pass-image').find('img').first();
        jQuery(oImageContainer).attr('src', tpfwParamsPassWcMyaccount.sAVatarPlaceholder);
        jQuery(oThisWrapper).find('.select-btn').show();
        jQuery(oThisWrapper).find('.remove-btn').hide();
        jQuery(oThisWrapper).find('.upload-btn').hide();
        jQuery(oFileInput).val("");
    });            
});