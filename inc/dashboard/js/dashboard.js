/**
 * Row actions shared by the Tickets, Timeslot Tickets and Passes dashboards.
 *
 * Every action button carries data-kind (checkin/resend/reset/cancel) and data-action (the
 * admin-ajax action), so this one file serves all three screens without knowing their names.
 * Handlers are delegated from document because the table is re-rendered by filter/paging
 * reloads. After a successful request the row is patched in place and a 'tpfw:row-action'
 * event is triggered on document, which the Passes screen uses to cascade to guest rows.
 */
jQuery(document).ready(function()
{
    var oParams = window.tpfwParamsDashboard || { aNonces: {}, translations: {} };
    var T       = oParams.translations;

    /**
     * Writes HTML into one cell of a row, whether or not that row wraps its cells in
     * .tpfw-row-inner (guest pass rows do, so they can slide open and closed).
     *
     * @param {jQuery} oRow   The <tr>.
     * @param {string} sClass Class on the target cell, without the leading dot.
     * @param {string} sHtml  Markup to write.
     * @returns {void}
     */
    function setCellHtml(oRow, sClass, sHtml)
    {
        var oCell  = oRow.find('td.' + sClass + ', th.' + sClass);
        var oInner = oCell.find('.tpfw-row-inner');
        (oInner.length ? oInner : oCell).html(sHtml);
    }

    /**
     * Dims or restores a button and its row while a request is in flight.
     *
     * @param {jQuery}  oButton
     * @param {jQuery}  oRow
     * @param {boolean} bBusy
     * @returns {void}
     */
    function setBusy(oButton, oRow, bBusy)
    {
        oButton.css('pointer-events', bBusy ? 'none' : 'auto');
        oRow.css('pointer-events', bBusy ? 'none' : 'auto');
        if(bBusy) { oButton.fadeTo('fast', 0.2); oRow.fadeTo('fast', 0.2); }
        else      { oRow.fadeTo('450', 1); }
    }

    /**
     * Applies the DOM changes a successful action implies.
     *
     * @param {string} sKind   Action kind.
     * @param {jQuery} oButton Button that was pressed.
     * @param {jQuery} oRow    Its row.
     * @param {object} oData   oResult.data from the server.
     * @returns {void}
     */
    function applyResult(sKind, oButton, oRow, oData)
    {
        var oActions = oButton.closest('.tpfw-actions');

        if(sKind === 'resend')
        {
            var sLabel = oButton.text();
            oButton.text(T.sResent);
            setTimeout(function() { oButton.text(sLabel); }, 2500);
        }
        else if(sKind === 'cancel')
        {
            setCellHtml(oRow, 'column-status', oData.sNewStatus);
            oActions.find('[data-kind="cancel"], [data-kind="resend"], [data-kind="checkin"]').hide();
        }
        else if(sKind === 'reset')
        {
            setCellHtml(oRow, 'column-status', oData.sNewStatus);
            oActions.find('[data-kind="cancel"], [data-kind="resend"], [data-kind="checkin"], [data-kind="reset"]').show();
            var sUses = oData.sNewUses || ('0 / ' + oActions.find('[data-kind="checkin"]').attr('data-attr-maxuses'));
            setCellHtml(oRow, 'column-checkins', sUses);
            setCellHtml(oRow, 'column-uses', sUses);
        }
        else if(sKind === 'transfer')
        {
            setCellHtml(oRow, 'column-user_id', oData.sNewUserHtml);
            oActions.find('.tpfw-row-action').attr('data-attr-userid', oData.iNewUserID);
            var sTransferLabel = oButton.text();
            oButton.text(T.sTransferred);
            setTimeout(function() { oButton.text(sTransferLabel); }, 2500);
        }
        else if(sKind === 'checkin')
        {
            if(oData.sNewStatus) { setCellHtml(oRow, 'column-status', oData.sNewStatus); }
            setCellHtml(oRow, 'column-checkins', oData.sNewUses);
            setCellHtml(oRow, 'column-uses', oData.sNewUses);
            if(oData.bMaxUsesReached) { oActions.find('[data-kind="checkin"]').hide(); }
        }

        jQuery(document).trigger('tpfw:row-action', [sKind, oButton, oRow, oData]);
    }

    /**
     * Posts one row action and patches the row from the answer.
     *
     * @param {jQuery} oButton Button that was pressed.
     * @param {object} oExtra  Extra POST fields beyond the button's data attributes.
     * @param {function} [fnDone] Called with (bSuccess, oResult) after the row is patched.
     * @returns {void}
     */
    function runAction(oButton, oExtra, fnDone)
    {
        var oRow    = oButton.closest('tr');
        var sKind   = oButton.attr('data-kind');
        var sAction = oButton.attr('data-action');

        var data = jQuery.extend({
            action  : 'tpfw_' + sAction,
            security: oParams.aNonces[sAction],
        }, oExtra || {});
        jQuery.each(oButton[0].attributes, function(i, oAttr)
        {
            // data-attr-nanoid -> nano_id, data-attr-orderlineid -> orderline_id, etc.
            if(oAttr.name.indexOf('data-attr-') !== 0) return;
            var sKey = oAttr.name.substring(10);
            var aMap = { nanoid: 'nano_id', orderid: 'order_id', orderlineid: 'orderline_id', userid: 'user_id', maxuses: 'max_uses', rowid: 'row_id', imagetype: 'profile_image_type' };
            data[aMap[sKey] || sKey] = oAttr.value;
        });

        setBusy(oButton, oRow, true);

        jQuery.ajax({ url: ajaxurl, data: data, dataType: 'JSON', method: 'POST' })
        .done(function(oResult)
        {
            if(oResult.success == true)
            {
                applyResult(sKind, oButton, oRow, oResult.data || {});
            }
            else if(!fnDone)
            {
                alert((oResult.data && oResult.data.sMessage) || T.sActionFailed);
            }
            if(fnDone) { fnDone(oResult.success == true, oResult); }
        })
        .fail(function()
        {
            if(fnDone) { fnDone(false, null); }
            else       { alert(T.sActionFailed); }
        })
        .always(function(oResult)
        {
            setBusy(oButton, oRow, false);
            // A button hidden by a successful cancel/check-in must not be faded back in; and a
            // check-in writes to a stats row that briefly locks, so its button stays disabled a
            // moment longer.
            // ponytail: fixed 2s cooldown, switch to polling row-lock state if 2s ever proves too short/long.
            var bHidden = oButton.css('display') === 'none';
            var iDelay  = sKind === 'checkin' ? 2000 : 0;
            setTimeout(function()
            {
                if(!bHidden && oButton.css('display') !== 'none') { oButton.css('pointer-events', 'auto').fadeTo('450', 1); }
            }, iDelay);
        });
    }

    var oPendingTransfer = null;

    jQuery(document).on('click', '.tpfw-row-action', function()
    {
        var oButton = jQuery(this);
        var sKind   = oButton.attr('data-kind');

        // Transfer needs a recipient first: open the dialog and let its submit run the action.
        if(sKind === 'transfer')
        {
            var elDialog = document.getElementById('tpfw-transfer-dialog');
            if(!elDialog || typeof elDialog.showModal !== 'function') { return; }
            oPendingTransfer = oButton;
            jQuery(elDialog).find('.tpfw-dialog-error').prop('hidden', true).text('');
            jQuery('#tpfw-transfer-recipient').val('');
            elDialog.showModal();
            return;
        }

        var aConfirm = { cancel: T.sConfirmCancel, reset: T.sConfirmReset, 'delete-image': T.sConfirmDeleteImage };
        if(aConfirm[sKind] && !confirm(aConfirm[sKind])) return;

        runAction(oButton);
    });

    jQuery(document).on('click', '#tpfw-transfer-dialog button[value="cancel"]', function()
    {
        document.getElementById('tpfw-transfer-dialog').close();
    });

    jQuery(document).on('submit', '#tpfw-transfer-form', function(e)
    {
        e.preventDefault();
        if(!oPendingTransfer) return;
        var elDialog = document.getElementById('tpfw-transfer-dialog');
        var oError   = jQuery(elDialog).find('.tpfw-dialog-error');
        var oSubmit  = jQuery(this).find('button[type="submit"]').prop('disabled', true);

        runAction(oPendingTransfer, { recipient: jQuery('#tpfw-transfer-recipient').val() }, function(bSuccess, oResult)
        {
            oSubmit.prop('disabled', false);
            if(bSuccess)
            {
                elDialog.close();
                oPendingTransfer = null;
                return;
            }
            // The dialog stays open with the reason, so a typo can be corrected in place.
            oError.text((oResult && oResult.data && oResult.data.sMessage) || T.sActionFailed).prop('hidden', false);
        });
    });

    // Bulk actions run server side on submit; only the confirmation lives here.
    jQuery(document).on('submit', '.tpfw-dashboard-table', function()
    {
        var sAction = jQuery(this).find('select[name="action"]').val();
        if(sAction && sAction !== '-1' && !confirm(T.sConfirmBulk)) return false;
        return true;
    });
});
