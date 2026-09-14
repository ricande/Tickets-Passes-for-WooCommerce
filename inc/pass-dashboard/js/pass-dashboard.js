/**
 * Passes dashboard extras on top of the shared dashboard.js: the guest pass expander, and
 * mirroring a parent's cancel/reset onto its guest rows so neither needs a page reload.
 *
 * The row actions themselves are handled by dashboard.js, which triggers 'tpfw:row-action'
 * on document after each success; this file only listens.
 */
jQuery(document).ready(function()
{
    /**
     * Guest rows belonging to the pass a button sits on.
     *
     * @param {jQuery} oButton Pressed row action.
     * @returns {jQuery}
     */
    function guestRowsOf(oButton)
    {
        return jQuery('.tpfw-guestpass-row[data-parent-nanoid="' + oButton.attr('data-attr-nanoid') + '"]');
    }

    jQuery(document).on('tpfw:row-action', function(e, sKind, oButton, oRow, oData)
    {
        if(sKind === 'delete-image')
        {
            oButton.hide();
            oRow.find('img.tpfw-profile-thumb').remove();
            return;
        }

        // Only a parent row has guest rows; a guest row's own actions never cascade.
        if(oRow.hasClass('tpfw-guestpass-row')) return;
        var oGuestRows = guestRowsOf(oButton);
        if(!oGuestRows.length) return;

        if(sKind === 'cancel')
        {
            oGuestRows.find('td.column-status .tpfw-row-inner').html(oData.sNewStatus);
            oGuestRows.find('.tpfw-row-action').hide();
        }
        else if(sKind === 'reset')
        {
            oGuestRows.find('td.column-status .tpfw-row-inner').html(oData.sNewStatus);
            oGuestRows.find('.tpfw-row-action').not('[data-kind="delete-image"]').show();
            // Each guest pass reports its own max_uses, so each row is patched by its own id.
            jQuery.each(oData.aGuestUses || [], function(iIndex, oGuestUse)
            {
                oGuestRows.filter('[data-nanoid="' + oGuestUse.sNanoId + '"]').find('td.column-uses .tpfw-row-inner').text(oGuestUse.sNewUses);
            });
        }
    });

    // Expands or collapses the guest pass rows belonging to one pass. Only one group is open at
    // a time so a different pass's guests never show alongside.
    jQuery(document).on('click', '.btn-admin-toggle-guestpass', function()
    {
        var oOwnRows = guestRowsOf(jQuery(this));
        jQuery('.tpfw-guestpass-row.is-open').not(oOwnRows).removeClass('is-open').find('.tpfw-row-inner').slideUp('fast');
        oOwnRows.toggleClass('is-open');
        oOwnRows.find('.tpfw-row-inner').slideToggle('fast');
    });
});
