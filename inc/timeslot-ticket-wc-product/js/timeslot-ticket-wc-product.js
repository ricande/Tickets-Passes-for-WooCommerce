/**
 * The Timeslot Ticket panel on the WooCommerce product edit screen.
 *
 * Most of this file is the timeslot table. A timeslot product sells entry to a specific window,
 * and those windows are edited as rows that can be added, deleted and - for a recurring rule -
 * expanded into the individual timeslots it generates. Rows are cloned from hidden templates in
 * the markup rather than built in JS, so the fields stay defined in one place, in PHP.
 *
 * The rest is the QR design section, which works the same way as the one on the Ticket and Pass
 * panels: colour rows, a live preview card and a server-rendered QR preview.
 */
jQuery(document).ready(function()
{

    let aTranslations       = tpfwParamsTimeslotTicketWcProduct.aTranslations
    let oDatepickerLocale   = tpfwParamsTimeslotTicketWcProduct.aDatepickerLocale

    /**
     * Renumbers every timeslot row's field names so they post as one contiguous array.
     *
     * The rows carry their index inside the field name (timeslots[3][start]), so deleting or
     * inserting a row anywhere in the table leaves a gap or a collision unless every row is
     * relabelled afterwards. Hence the call at the end of every add and delete path.
     *
     * @returns {void}
     */
    function relabel_timeslot_rows()
    {
        let count = 1;
        jQuery('.timeslot-row-wrapper .row').each(function()
        {
            if(jQuery(this).hasClass('timeslot-row'))
            {
                jQuery(this).find('.timeslot-start').attr("name", 'timeslots['+count+'][start]')
                jQuery(this).find('.timeslot-end').attr("name", 'timeslots['+count+'][end]')
                jQuery(this).find('.timeslot-qty').attr("name", 'timeslots['+count+'][qty]')                        
                jQuery(this).find('.timeslot-id').attr("name", 'timeslots['+count+'][id]')                        
                jQuery(this).find('.timeslot-recurring-id').attr("name", 'timeslots['+count+'][recurring-id]')                        
            }
            else if(jQuery(this).hasClass('timeslot-row-recurring'))
            {
                jQuery(this).find('.timeslot-recurring-weekday').attr("name", 'timeslots['+count+'][weekday]')
                jQuery(this).find('.timeslot-recurring-time-start').attr("name", 'timeslots['+count+'][time-start]')
                jQuery(this).find('.timeslot-recurring-time-end').attr("name", 'timeslots['+count+'][time-end]')                        
                jQuery(this).find('.timeslot-recurring-week-number-start').attr("name", 'timeslots['+count+'][week-number-start]')                        
                jQuery(this).find('.timeslot-recurring-week-number-end').attr("name", 'timeslots['+count+'][week-number-end]')                        
                jQuery(this).find('.timeslot-recurring-qty').attr("name", 'timeslots['+count+'][qty]')                        
                jQuery(this).find('.timeslot-recurring-id').attr("name", 'timeslots['+count+'][id]')      
            }
            count++;
        })
    }

    /**
     * Fills in a %1$s-style translated string.
     *
     * @param {string} sTemplate Translated string carrying %1$s .. %9$s placeholders.
     * @param {Array} aValues    Values, in placeholder order.
     * @returns {string}
     */
    function tpfwFormat(sTemplate, aValues)
    {
        return String(sTemplate || '').replace(/%(\d)\$[sd]/g, function(sMatch, sIndex)
        {
            let mValue = aValues[parseInt(sIndex) - 1];
            return (mValue === undefined || mValue === null) ? '' : mValue;
        }).replace(/%[sd]/, aValues[0]);
    }

    /**
     * The day heading a timeslot belongs under, in the browser's own locale.
     *
     * @param {Date} oDate Start of the timeslot.
     * @returns {string}
     */
    function tpfwTimeslotDayLabel(oDate)
    {
        return oDate.toLocaleDateString(undefined, {weekday: 'long', day: 'numeric', month: 'long'});
    }

    /**
     * Rereads one timeslot row and rewrites everything the row reports about itself: how long it
     * runs, how full it is and whether it is still sellable.
     *
     * The row is the unit of meaning in this card, so the numbers live on it rather than in a
     * column header - a shop owner scanning the list is looking for the nearly-full and the
     * sold-out ones, not for a field layout.
     *
     * @param {Element|jQuery} elRow The timeslot row.
     * @returns {void}
     */
    function tpfwRefreshTimeslotRow(elRow)
    {
        let oRow   = jQuery(elRow);
        let oStart = tpfwParseTimeslotStorageValue(oRow.find('.timeslot-start').val());
        let oEnd   = tpfwParseTimeslotStorageValue(oRow.find('.timeslot-end').val());
        let iQty   = parseInt(oRow.find('.timeslot-qty').val()) || 0;
        let iUsed  = parseInt(oRow.find('.timeslot-current-used').text()) || 0;
        let bPast  = oStart ? (oStart.getTime() < Date.now()) : false;
        let bFull  = iQty > 0 && iUsed >= iQty;

        let sDuration = '';
        if(oStart && oEnd && oEnd > oStart)
        {
            let iMinutes = Math.round((oEnd - oStart) / 60000);
            sDuration    = (iMinutes >= 60 ? Math.floor(iMinutes / 60) + 'h ' : '') + ((iMinutes % 60) || iMinutes < 60 ? (iMinutes % 60) + 'm' : '');
        }
        oRow.find('.timeslot-duration').text(sDuration.trim());

        oRow.find('.timeslot-meter i').css('width', (iQty > 0 ? Math.min(100, Math.round((iUsed / iQty) * 100)) : 0) + '%');

        let sState = bPast ? aTranslations.sPast : (bFull ? aTranslations.sSoldOut : aTranslations.sOnSale);
        let sNote  = (!bPast && !bFull && iQty > 0) ? tpfwFormat(aTranslations.sPlacesLeft, [iQty - iUsed]) : '';
        oRow.find('.timeslot-state > b').text(sState);
        oRow.find('.timeslot-state > span').text(sNote);

        oRow.toggleClass('is-past', bPast).toggleClass('is-full', !bPast && bFull);
        oRow.attr('data-timeslot-when', bPast ? 'past' : 'upcoming');
    }

    /**
     * Regroups the one-off rows under a heading per day.
     *
     * A date repeated on every row is noise: the same day is normally sold in several slots, so the
     * date is lifted into a heading and the rows underneath carry only their times. Rows are moved,
     * never re-created, so their pickers survive the regrouping.
     *
     * @returns {void}
     */
    function tpfwGroupTimeslotRows()
    {
        let elWrapper = jQuery('.timeslot-row-wrapper');
        if(elWrapper.length < 1) return;

        let aRows = elWrapper.find('.timeslot-row').not('.recurring-timeslot-row-child').detach().get();
        elWrapper.children('.timeslot-day').remove();
        if(aRows.length < 1) return;

        // Undated rows are the ones just added, and belong at the top where they were dropped.
        aRows.sort(function(elA, elB)
        {
            let oA = tpfwParseTimeslotStorageValue(jQuery(elA).find('.timeslot-start').val());
            let oB = tpfwParseTimeslotStorageValue(jQuery(elB).find('.timeslot-start').val());
            if(!oA) return -1;
            if(!oB) return 1;
            return oA - oB;
        });

        let sCurrentKey = null;
        let elGroup     = null;
        aRows.forEach(function(elRow)
        {
            let oDate = tpfwParseTimeslotStorageValue(jQuery(elRow).find('.timeslot-start').val());
            let sKey  = oDate ? oDate.toDateString() : 'new';
            if(sKey !== sCurrentKey)
            {
                sCurrentKey = sKey;
                elGroup     = jQuery('<div class="timeslot-day"><div class="timeslot-day-head"><span class="timeslot-day-label"></span><span class="timeslot-day-count"></span></div></div>').appendTo(elWrapper);
                elGroup.find('.timeslot-day-label').text(oDate ? tpfwTimeslotDayLabel(oDate) : aTranslations.sNewTimeslot);
            }
            elGroup.append(elRow);
        });
    }

    /**
     * Applies the Upcoming/Past/All filter and rewrites every count the card shows.
     *
     * @returns {void}
     */
    function tpfwRefreshTimeslotCard()
    {
        let sFilter    = jQuery('.timeslot-filter-btn[aria-pressed="true"]').attr('data-timeslot-filter') || 'all';
        let elSeries   = jQuery('.timeslot-row-wrapper .timeslot-row-recurring');
        let bRecurring = elSeries.length > 0;
        let iShown     = 0;
        let iHidden    = 0;
        let iUsed      = 0;
        let iPlaces    = 0;

        jQuery('.timeslot-row-wrapper').find('.timeslot-row').not('.recurring-timeslot-row-child').each(function()
        {
            let oRow     = jQuery(this);
            let sWhen    = oRow.attr('data-timeslot-when') || 'upcoming';
            // A row with no date yet is the one being added right now, so no filter hides it.
            let bVisible = (sFilter === 'all') || (sFilter === sWhen) || !oRow.find('.timeslot-start').val();
            oRow.toggleClass('hide', !bVisible);
            if(bVisible)
            {
                iShown++;
                iUsed   += parseInt(oRow.find('.timeslot-current-used').text()) || 0;
                iPlaces += parseInt(oRow.find('.timeslot-qty').val()) || 0;
            }
            else
            {
                iHidden++;
            }
        });

        jQuery('.timeslot-day').each(function()
        {
            let elRowsInDay = jQuery(this).children('.timeslot-row').not('.hide');
            let iInDay      = elRowsInDay.length;
            let iDayUsed    = 0;
            let iDayPlaces  = 0;

            elRowsInDay.each(function()
            {
                iDayUsed   += parseInt(jQuery(this).find('.timeslot-current-used').text()) || 0;
                iDayPlaces += parseInt(jQuery(this).find('.timeslot-qty').val()) || 0;
            });

            jQuery(this).toggleClass('hide', iInDay < 1);
            jQuery(this).find('.timeslot-day-count').text(tpfwFormat(aTranslations.sDayMeta, [
                tpfwFormat(iInDay === 1 ? aTranslations.sTimeslotCount : aTranslations.sTimeslotCountPlural, [iInDay]),
                iDayUsed,
                iDayPlaces
            ]));
        });

        let iTotal = bRecurring ? elSeries.length : iShown;
        jQuery('.timeslot-card-tag').text(bRecurring
            ? tpfwFormat(elSeries.length === 1 ? aTranslations.sSeriesCount : aTranslations.sSeriesCountPlural, [elSeries.length])
            : tpfwFormat(iShown === 1 ? aTranslations.sTimeslotCount : aTranslations.sTimeslotCountPlural, [iShown])
        ).prop('hidden', iTotal < 1);

        jQuery('.timeslot-toolbar-count').text(iHidden > 0 ? tpfwFormat(aTranslations.sHiddenByFilter, [iHidden]) : '');

        jQuery('.timeslot-foot').prop('hidden', bRecurring || iShown < 1);
        jQuery('.timeslot-foot-summary').text(tpfwFormat(aTranslations.sSoldSummary, [iUsed, iPlaces]));
        jQuery('.timeslot-foot-note').text(iPlaces > iUsed ? tpfwFormat(aTranslations.sPlacesLeft, [iPlaces - iUsed]) : '');

        jQuery('.timeslot-empty').prop('hidden', jQuery('.timeslot-row-wrapper').find('.timeslot-row, .timeslot-row-recurring').length > 0);
    }

    /**
     * Refreshes every one-off row, regroups them by day and rewrites the counts.
     *
     * @returns {void}
     */
    function tpfwRefreshTimeslots()
    {
        jQuery('.timeslot-row-wrapper .timeslot-row').each(function() { tpfwRefreshTimeslotRow(this); });
        tpfwGroupTimeslotRows();
        tpfwRefreshTimeslotCard();
    }

    /**
     * Writes a recurring rule back out as the sentence it actually means.
     *
     * The six fields are the storage format, not the decision - the decision is "every Monday at
     * ten" - so the sentence leads the row and the fields sit behind the disclosure.
     *
     * @param {Element|jQuery} elRow The series row.
     * @returns {void}
     */
    function tpfwRefreshSeriesRow(elRow)
    {
        let oRow = jQuery(elRow);
        // Weekday and time lead the sentence; the range and size it runs at are detail, so they
        // are appended in their own muted span rather than said in the same voice.
        oRow.find('.timeslot-series-rule').text(tpfwFormat(aTranslations.sSeriesRule,
        [
            oRow.find('.timeslot-recurring-weekday option:selected').text(),
            oRow.find('.timeslot-recurring-time-start').val(),
            oRow.find('.timeslot-recurring-time-end').val(),
        ])).append(jQuery('<span class="timeslot-series-rule-muted"></span>').text(' ' + tpfwFormat(aTranslations.sSeriesRuleMeta,
        [
            oRow.find('.timeslot-recurring-week-number-start').val(),
            oRow.find('.timeslot-recurring-week-number-end').val(),
            oRow.find('.timeslot-recurring-qty').val(),
        ])));
    }

    /**
     * Loads the timeslots a recurring rule has generated, on demand.
     *
     * A rule running weekly for a year is several hundred rows nobody wants rendered with the page,
     * so they are fetched the first time the disclosure is opened.
     *
     * @param {jQuery} oThisRow The series row.
     * @param {boolean} bForce  True to refetch a list that is already on screen.
     * @returns {void}
     */
    function tpfwLoadSeriesChildren(oThisRow, bForce)
    {
        // .children(), not .find(): once a series is expanded its child rows carry hidden
        // recurring-id fields of their own, and .find() would read the first of those instead.
        let sSeriesID = oThisRow.children('.timeslot-recurring-id').val();

        // An unsaved rule has generated nothing yet.
        if(sSeriesID == "" || sSeriesID == undefined) return;
        if(!bForce && oThisRow.find('.recurring-timeslot-children-wrapper').length > 0) return;

        let data = {
            action: 'tpfw_ajax_get_recurring_timeslot_children',
            security: tpfwParamsTimeslotTicketWcProduct.aNonces.ajax_get_recurring_timeslot_children,
            recurring_timeslot_id: sSeriesID,
        }

        oThisRow.addClass('is-loading');

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
                oThisRow.find('.recurring-timeslot-children-wrapper').remove();
                let elChildrenWrapper = jQuery(oResult.data.sHTMLContent).appendTo(oThisRow);
                jQuery(elChildrenWrapper).find('.timeslot-row').each(function()
                {
                    tpfwInitTimeslotDatePicker(this, false);
                    tpfwRefreshTimeslotRow(this);
                })
                relabel_timeslot_rows()
            }
            else
            {
                alert(oResult.data.sMessage || aTranslations.sActionFailed);
            }
        })
        .fail(function()
        {
            alert(aTranslations.sActionFailed);
        })
        .always(function()
        {
            oThisRow.removeClass('is-loading');
        });
    }

    /**
     * Wires one series row: its sentence follows its fields, and opening it loads its timeslots.
     *
     * @param {Element|jQuery} elRow The series row.
     * @returns {void}
     */
    function tpfwBindSeriesRow(elRow)
    {
        let oRow = jQuery(elRow);
        if(oRow.data('tpfwSeriesBound')) return;
        oRow.data('tpfwSeriesBound', true);

        // 'toggle' does not bubble, so it is bound per row rather than delegated.
        oRow.on('toggle', function()
        {
            if(oRow.prop('open')) tpfwLoadSeriesChildren(oRow, false);
        });
        tpfwRefreshSeriesRow(oRow);
    }

    // The summary is the disclosure control, so a click on a button inside it would also open or
    // close the row. preventDefault cancels only that toggle - the click still reaches the
    // delegated handlers further down.
    jQuery(document).on('click', '.timeslot-series-head .timeslot-row-actions', function(oEvent)
    {
        oEvent.preventDefault();
    })

    jQuery(document).on('change input', '.timeslot-row-recurring .timeslot-row-fields', function()
    {
        tpfwRefreshSeriesRow(jQuery(this).closest('.timeslot-row-recurring'));
    })

    jQuery(document).on('change input', '.timeslot-qty', function()
    {
        tpfwRefreshTimeslotRow(jQuery(this).closest('.row'));
        tpfwRefreshTimeslotCard();
    })

    // A start date decides which day heading the row lives under, so picking one regroups the list.
    jQuery(document).on('change', '.timeslot-start, .timeslot-end', function()
    {
        let oRow = jQuery(this).closest('.row');
        tpfwRefreshTimeslotRow(oRow);
        if(!oRow.hasClass('recurring-timeslot-row-child')) tpfwRefreshTimeslots();
    })

    jQuery(document).on('click', '.timeslot-filter-btn', function()
    {
        jQuery('.timeslot-filter-btn').attr('aria-pressed', 'false');
        jQuery(this).attr('aria-pressed', 'true');
        tpfwRefreshTimeslotCard();
    })

    /**
     * Formats a Date as the 'Y-m-d H:i:s' string the hidden field submits and MySQL stores.
     *
     * Built by hand from the local getters rather than through toISOString(), which would
     * convert to UTC and shift every timeslot by the site's offset.
     *
     * @param {Date} oDate The date to format.
     * @returns {string} 'YYYY-MM-DD HH:MM:00' in local time.
     */
    function tpfwFormatTimeslotStorageValue(oDate)
    {
        // Zero-pads to two digits.
        let fnPad = function(iNum) { return (iNum < 10 ? '0' : '') + iNum; }
        return oDate.getFullYear() + '-' + fnPad(oDate.getMonth() + 1) + '-' + fnPad(oDate.getDate()) + ' ' + fnPad(oDate.getHours()) + ':' + fnPad(oDate.getMinutes()) + ':00';
    }

    /**
     * Reads a stored 'Y-m-d H:i:s' value back into a Date.
     *
     * @param {string} sValue Stored value, or an empty string for a row with no date yet.
     * @returns {Date|null} Null for an empty or unparseable value.
     */
    function tpfwParseTimeslotStorageValue(sValue)
    {
        if(!sValue) return null;
        let oDate = new Date(sValue.replace(' ', 'T'));
        return isNaN(oDate.getTime()) ? null : oDate;
    }

    /**
     * Attaches the Start and End date pickers to one timeslot row.
     *
     * A native datetime-local control can't be given a border or a redesigned popup - it's the
     * browser's own OS-level chrome, not stylable via CSS. So Start and End are each a readonly
     * text field (".timeslot-*-picker", what the admin sees and clicks) paired with a hidden
     * field (".timeslot-*", what actually submits) driven by air-datepicker, the same library the
     * frontend single-product picker already uses. Readonly also closes off typing an
     * out-of-range value directly, which a bare min="" attribute never fully did.
     *
     * End can never precede Start: the End picker's minimum follows whatever Start is set to, and
     * an End already selected before that point is dragged forward with it.
     *
     * @param {Element|jQuery} elRow    The timeslot row.
     * @param {boolean} bIsNewRow True only for a row the admin just added in this session (the
     *        "Add Timeslot" and manual-add flows). Only those get a minDate of "now", so
     *        resubmitting a product that already has past timeslots on it is never blocked.
     * @returns {void}
     */
    /**
     * Scrolls a just-opened picker into view.
     *
     * air-datepicker only ever opens downwards, so on a Product Data box sitting at the bottom of
     * the edit screen the popup starts below the fold. It is rendered into <body>, which grows the
     * document, so bringing it into view is all that is needed.
     *
     * @param {AirDatepicker} oPicker   The picker that was just shown.
     * @param {boolean}        bFinished True once the show transition has completed.
     * @returns {void}
     */
    function tpfwKeepDatepickerOnScreen(oPicker, bFinished)
    {
        if(!bFinished || !oPicker || !oPicker.$datepicker) return;

        let oRect = oPicker.$datepicker.getBoundingClientRect();
        if(oRect.bottom > window.innerHeight || oRect.top < 0)
        {
            oPicker.$datepicker.scrollIntoView({block: 'nearest', behavior: 'smooth'});
        }
    }


    /**
     * Attaches the linked start/end AirDatepicker pair to one timeslot row.
     *
     * Idempotent: rows are re-scanned after every add and after a recurring rule's children
     * load, so a row already carrying a picker is skipped. Picking a start date pushes the
     * end picker's minimum forward so an end before the start cannot be chosen.
     *
     * @param {HTMLElement} elRow     The .timeslot-row (or recurring child row) to wire up.
     * @param {boolean}     bIsNewRow True for a row just added, which opens with no dates set.
     * @returns {void}
     */
    function tpfwInitTimeslotDatePicker(elRow, bIsNewRow)
    {
        let elRowJQ       = jQuery(elRow);
        let bDatedRow     = elRowJQ.hasClass('recurring-timeslot-row-child');
        // Visible-input label: time only for a plain row, date + time for the dated child of a recurring rule.
        let fnLabel       = function(oDate)
        {
            // Zero-pads to two digits.
            let fnPad = function(iNum) { return (iNum < 10 ? '0' : '') + iNum; }
            let sTime = fnPad(oDate.getHours()) + ':' + fnPad(oDate.getMinutes());
            return bDatedRow ? (oDate.toLocaleDateString(undefined, {day: 'numeric', month: 'short'}) + ' ' + sTime) : sTime;
        };
        let elStartPicker = elRowJQ.find('.timeslot-start-picker');
        let elStartHidden = elRowJQ.find('.timeslot-start');
        let elEndPicker   = elRowJQ.find('.timeslot-end-picker');
        let elEndHidden   = elRowJQ.find('.timeslot-end');

        // Rows are re-scanned after every add and after the children of a recurring rule are
        // loaded, so skip anything already carrying a picker instead of stacking a second one.
        if(elStartPicker.length < 1 || elEndPicker.length < 1 || elStartPicker.data('tpfwAirDatepicker')) return;

        let oStartDate = tpfwParseTimeslotStorageValue(elStartHidden.val());
        let oEndDate   = tpfwParseTimeslotStorageValue(elEndHidden.val());

        let oEndPicker = new AirDatepicker(elEndPicker[0],
        {
            // The Product Data metabox and its panel-wrap are both overflow:hidden, so a picker
            // rendered next to its input gets clipped whenever the box is the last one on the
            // page. Rendering into <body> lets it position freely and grow the document instead.
            container     : document.body,
            onShow        : function(bFinished) { tpfwKeepDatepickerOnScreen(oEndPicker, bFinished); },
            locale        : oDatepickerLocale,
            timepicker    : true,
            dateFormat    : fnLabel,
            timeFormat    : 'HH:mm',
            selectedDates : oEndDate ? [oEndDate] : undefined,
            minDate       : oStartDate || undefined,
            onSelect({date})
            {
                if(!date) return;
                elEndHidden.val(tpfwFormatTimeslotStorageValue(date)).trigger('change');
            }
        });
        elEndPicker.data('tpfwAirDatepicker', oEndPicker);

        let oStartPicker = new AirDatepicker(elStartPicker[0],
        {
            // The Product Data metabox and its panel-wrap are both overflow:hidden, so a picker
            // rendered next to its input gets clipped whenever the box is the last one on the
            // page. Rendering into <body> lets it position freely and grow the document instead.
            container     : document.body,
            onShow        : function(bFinished) { tpfwKeepDatepickerOnScreen(oStartPicker, bFinished); },
            locale        : oDatepickerLocale,
            timepicker    : true,
            dateFormat    : fnLabel,
            timeFormat    : 'HH:mm',
            selectedDates : oStartDate ? [oStartDate] : undefined,
            minDate       : bIsNewRow ? new Date() : undefined,
            onSelect({date})
            {
                if(!date) return;
                elStartHidden.val(tpfwFormatTimeslotStorageValue(date)).trigger('change');

                // Keep End at or after Start, and drag an already-chosen End forward if the new
                // Start has overtaken it.
                oEndPicker.update({minDate: date});
                let aEndSelected = oEndPicker.selectedDates;
                if(aEndSelected && aEndSelected.length && aEndSelected[0] < date)
                {
                    oEndPicker.selectDate(date);
                    elEndHidden.val(tpfwFormatTimeslotStorageValue(date)).trigger('change');
                }
            }
        });
        elStartPicker.data('tpfwAirDatepicker', oStartPicker);
    }

    // Existing rows, i.e. timeslots already saved on this product - false, so their pickers are
    // not given a "not before now" minimum they would already violate.
    jQuery('.timeslot-row-wrapper .timeslot-row').each(function()
    {
        tpfwInitTimeslotDatePicker(this, false);
    })

    jQuery('.timeslot-row-wrapper .timeslot-row-recurring').each(function()
    {
        tpfwBindSeriesRow(this);
    })

    tpfwRefreshTimeslots();

    // Adds an extra one-off timeslot underneath a recurring rule, for the case where the rule
    // covers most of the schedule but one date needs a slot it would not have generated.
    jQuery(document).on('click', '.timeslot-action-manual-add-timeslot-tickets', function()
    {
        let elChildWrapper = jQuery(this).closest('.recurring-timeslot-children-wrapper');
        jQuery(elChildWrapper).find('.recurring-timeslot-children-text').remove();                        
        let elTemplate          = jQuery('.timeslot-row-template').first();
        let elTemplateClonedRow = jQuery(elTemplate).clone();
        jQuery(elTemplateClonedRow).removeClass('timeslot-row-template').removeClass('template').addClass('timeslot-row')                                                                        
        jQuery(elTemplateClonedRow).find('.timeslot-recurring-id').val(jQuery(this).attr('data-attr-reccuring-parent-id')).trigger('change');
        jQuery(elTemplateClonedRow).insertBefore(jQuery(elChildWrapper).find('.timeslot-action-manual-add-timeslot-tickets'));
        jQuery(elChildWrapper).find('input').attr('disabled', false);
        tpfwInitTimeslotDatePicker(elTemplateClonedRow, true);
        tpfwRefreshTimeslotRow(elTemplateClonedRow);
        relabel_timeslot_rows()
    })
    


    // Generates a recurring rule's timeslots now rather than waiting for the scheduled job that
    // normally rolls them forward - used when the rule has just been edited and the shop owner
    // wants to see and adjust what it produces before saving the product.
    jQuery(document).on('click', '.timeslot-action-force-create', function()
    {
        var oThis       = jQuery(this);
        var oThisRow    = jQuery(oThis).closest('.row');

        // An unsaved rule has no id yet, so there is nothing for the server to expand.
        // .children(), not .find(): an expanded series also holds its child rows' own hidden ids.
        if(jQuery(oThisRow).children('.timeslot-recurring-id').val() == "" || jQuery(oThisRow).children('.timeslot-recurring-id').val() == undefined)
        {
            return;
        }

        var data = {
            action: 'tpfw_ajax_force_recurring_timeslot_children',
            security: tpfwParamsTimeslotTicketWcProduct.aNonces.ajax_force_recurring_timeslot_children,
            recurring_timeslot_id: jQuery(oThisRow).children('.timeslot-recurring-id').val(),
        }

        jQuery(oThisRow).css('pointer-events', 'none');
        jQuery(oThisRow).fadeTo('fast', 0.2);
        
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
                // Refetch, so the newly created timeslots are shown rather than the
                // pre-expansion state.
                jQuery(oThisRow).prop('open', true);
                tpfwLoadSeriesChildren(jQuery(oThisRow), true);
            }
            else
            {
                alert(oResult.data.sMessage || aTranslations.sActionFailed);
            }
        })
        .fail(function()
        {
            alert(aTranslations.sActionFailed);
        })
        .always(function()
        {
            jQuery(oThisRow).css('pointer-events', 'auto');
            jQuery(oThisRow).fadeTo('450', 1);
        });                        
    })


    
    // Opens the Timeslot Tickets dashboard filtered to this row, in a new tab so the half-edited
    // product is not navigated away from. A row is either a plain timeslot or a recurring rule,
    // so whichever id it carries is the one to search on.
    jQuery(document).on('click', '.timeslot-action-see-tickets',function()
    {
        let oRow        = jQuery(this).closest('.row');
        let sTimeslotID = "";
        if(oRow.children('.timeslot-id').val() != "" && oRow.children('.timeslot-id').val() != undefined)
        {
            sTimeslotID = oRow.children('.timeslot-id').val();
        }
        else if(oRow.children('.timeslot-recurring-id').val() != "" && oRow.children('.timeslot-recurring-id').val() != undefined)
        {
            sTimeslotID = oRow.children('.timeslot-recurring-id').val();
        }
        else if(jQuery(this).closest('.row').find('.timeslot-recurring-id').val() != "" && jQuery(this).closest('.row').find('.timeslot-recurring-id').val() != undefined)
        {
            sTimeslotID = jQuery(this).closest('.row').find('.timeslot-recurring-id').val();                                
        }
        if(sTimeslotID == "" || sTimeslotID == undefined) return;
        let sURL = tpfwParamsTimeslotTicketWcProduct.sSiteURL+"/wp-admin/admin.php?page=tpfw-timeslot-tickets&s="+sTimeslotID;
        if(sURL == "" || sTimeslotID == sURL) return;
                                    
        window.open(sURL, '_blank').focus();                            
    })
    

        
    // Recurring and one-off timeslots are edited with different rows, so the two Add buttons are
    // mutually exclusive - the product is either on a schedule or it is a list of fixed dates.
    jQuery('#_tpfw_timeslot_ticket_recurring_enable').on('change', function()
    {
        if(jQuery('#_tpfw_timeslot_ticket_recurring_enable').is(':checked'))
        {                                                                     
            jQuery('#_tpfw_timeslot_ticket_recurring_future').closest('p').removeClass('hide')        
            jQuery('.timeslot-action-add').hide();
            jQuery('.timeslot-recurring-action-add').show()
        }
        else
        {                                                                    
            jQuery('#_tpfw_timeslot_ticket_recurring_future').closest('p').addClass('hide')                                                    
            jQuery('.timeslot-action-add').show();
            jQuery('.timeslot-recurring-action-add').hide()
        }
    })


    // Both ends of the sales window are hidden until it is switched on.

    // The sales window may not close before it opens. The value is dragged along rather than
    // just floored, so the field never holds a date its own min forbids - an invalid control
    // that the toggle above then hides would block the whole product save.
    jQuery('#_tpfw_timeslot_sales_timespan_start').on('change', function()
    {
        let elEnd = jQuery('#_tpfw_timeslot_sales_timespan_end')
        elEnd.attr('min', jQuery(this).val())
        if(elEnd.val() < jQuery(this).val()) elEnd.val(jQuery(this).val())
    })
    jQuery('#_tpfw_timeslot_sales_timespan_enable').on('change', function()
    {
        if(jQuery('#_tpfw_timeslot_sales_timespan_enable').is(':checked'))
        {                                                                     
            jQuery('#_tpfw_timeslot_sales_timespan_start').closest('p').removeClass('hide')                                    
            jQuery('#_tpfw_timeslot_sales_timespan_end').closest('p').removeClass('hide')
            jQuery('#_tpfw_timeslot_show_sales_window').closest('p').removeClass('hide')                                                                      
        }
        else
        {                                                                    
            jQuery('#_tpfw_timeslot_sales_timespan_start').closest('p').addClass('hide')                                                                                
            jQuery('#_tpfw_timeslot_sales_timespan_end').closest('p').addClass('hide')
            jQuery('#_tpfw_timeslot_show_sales_window').prop('checked', false).closest('p').addClass('hide')                                                                                                                                                            
        }
    })



    // Reservations hold a seat while the customer is still in checkout. The durations and the
    // two "which order status releases the hold" settings only apply when that is switched on.
    jQuery('#_tpfw_timeslot_ticket_reservation_enable').on('change', function()
    {
        if(jQuery('#_tpfw_timeslot_ticket_reservation_enable').is(':checked'))
        {                                            
            jQuery('#_tpfw_timeslot_ticket_reservation_duration').closest('p').removeClass('hide')
            jQuery('#_tpfw_timeslot_ticket_reservation_order_duration').closest('p').removeClass('hide')
            jQuery('#_tpfw_timeslot_ticket_reservation_status_delete').closest('p').removeClass('hide')
            jQuery('#_tpfw_timeslot_ticket_orderline_status_delete').closest('p').removeClass('hide')
        }
        else
        {                                                                    
            jQuery('#_tpfw_timeslot_ticket_reservation_duration').closest('p').addClass('hide')                        
            jQuery('#_tpfw_timeslot_ticket_reservation_order_duration').closest('p').addClass('hide')                        
            jQuery('#_tpfw_timeslot_ticket_reservation_status_delete').closest('p').addClass('hide')                        
            jQuery('#_tpfw_timeslot_ticket_orderline_status_delete').closest('p').addClass('hide')                        
        }
    });



    // Deletes a row. Two cases, hence the split: a recurring rule takes its generated timeslots
    // with it, a plain timeslot only takes itself. Either way the server also cancels the tickets
    // already sold against what is being deleted, which is what the confirmations are warning
    // about. A row that has never been saved has no id, so it is just dropped from the DOM.
    jQuery(document).on('click', '.timeslot-action-trash', function()
    {
        if(jQuery(this).closest('.row').hasClass('parent'))
        {
            let bResult = confirm(aTranslations.sConfirmCancelRecurringTimeslot);
            if (bResult !== true) 
            {
                return;
            } 

            let oThis                = jQuery(this);
            let oThisRow             = jQuery(oThis).closest('.row');
            let sRecurringTimeslotID = jQuery(this).closest('.row').children('.timeslot-recurring-id').val();

            if(sRecurringTimeslotID == "" || sRecurringTimeslotID == undefined || sRecurringTimeslotID == null)
            {
                jQuery(oThisRow).remove();
                relabel_timeslot_rows();  
                return;
            }
            
            let oThisRowChildren = jQuery('[data-attr-reccuring-parent-id="'+sRecurringTimeslotID+'"]');
            let data             = 
            {
                action               : 'tpfw_ajax_delete_recurring_timeslot_children',
                security             : tpfwParamsTimeslotTicketWcProduct.aNonces.ajax_delete_recurring_timeslot_children,
                recurring_timeslot_id: sRecurringTimeslotID,
            }

            jQuery(oThis).css('pointer-events', 'none');
            jQuery(oThis).fadeTo('fast', 0.2);
            jQuery(oThisRow).css('pointer-events', 'none');
            jQuery(oThisRow).fadeTo('fast', 0.2);
            jQuery(oThisRowChildren).fadeTo('fast', 0.2);

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
                    jQuery(oThisRow).remove();
                    jQuery(oThisRowChildren).remove();
                    relabel_timeslot_rows();
                    tpfwRefreshTimeslots();
                }
                else
                {
                    alert(oResult.data.sMessage || aTranslations.sActionFailed);
                }
            })
            .fail(function()
            {
                alert(aTranslations.sActionFailed);
            })
            .always(function()
            {
                jQuery(oThis).css('pointer-events', 'auto');
                jQuery(oThis).fadeTo('450', 1);
                jQuery(oThisRow).css('pointer-events', 'auto');
                jQuery(oThisRow).fadeTo('450', 1);
                jQuery(oThisRowChildren).css('pointer-events', 'auto');
                jQuery(oThisRowChildren).fadeTo('450', 1);
            });
        }                
        else
        {
            let bResult = confirm(aTranslations.sConfirmCancelTimeslot);
            if (bResult !== true) 
            {
                return;
            }

            let oThis       = jQuery(this);
            let oThisRow    = jQuery(oThis).closest('.row');
            let sTimeslotID = jQuery(this).closest('.row').children('.timeslot-id').val();
            
            if(sTimeslotID == "" || sTimeslotID == undefined || sTimeslotID == null)
            {
                jQuery(oThisRow).remove();
                relabel_timeslot_rows();  
                return;
            }

            let data = 
            {
                action     : 'tpfw_ajax_delete_timeslot',
                security   : tpfwParamsTimeslotTicketWcProduct.aNonces.ajax_delete_timeslot,
                timeslot_id: sTimeslotID,
            }

            jQuery(oThis).css('pointer-events', 'none');
            jQuery(oThis).fadeTo('fast', 0.2);
            jQuery(oThisRow).css('pointer-events', 'none');
            jQuery(oThisRow).fadeTo('fast', 0.2);
            
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
                    jQuery(oThisRow).remove();
                    relabel_timeslot_rows();
                    tpfwRefreshTimeslots();
                }
                else
                {
                    alert(oResult.data.sMessage || aTranslations.sActionFailed);
                }
            })
            .fail(function()
            {
                alert(aTranslations.sActionFailed);
            })
            .always(function()
            {
                jQuery(oThis).css('pointer-events', 'auto');
                jQuery(oThis).fadeTo('450', 1);
                jQuery(oThisRow).css('pointer-events', 'auto');
                jQuery(oThisRow).fadeTo('450', 1);
            });
        }                            
    });


    
    jQuery('.timeslot-recurring-action-add').on('click', function()
    {
        let elFirstRow        = jQuery('.timeslot-row-recurring-template').first();
        let elClonedRow       = jQuery(elFirstRow).clone();
        jQuery(elClonedRow).removeClass('timeslot-row-recurring-template').removeClass('template').addClass('timeslot-row-recurring')
        let elTimeslotWrapper = jQuery('.timeslot-row-wrapper');
        jQuery(elTimeslotWrapper).append(elClonedRow);                        
        jQuery('.timeslot-row-recurring').last().find('input').attr('disabled', false);                        
        jQuery('.timeslot-row-recurring').last().find('select').attr('disabled', false);                                                
        tpfwBindSeriesRow(elClonedRow);
        jQuery(elClonedRow).prop('open', true);

        relabel_timeslot_rows();
        tpfwRefreshTimeslotCard();
    })



    jQuery('.timeslot-action-add').on('click', function()
    {
        let elFirstRow        = jQuery('.timeslot-row-template').first();
        let elClonedRow       = jQuery(elFirstRow).clone();
        // The template also doubles as the base for the recurring "manual add" flow below,
        // which legitimately wants the child (indented, soft-accent) styling - a plain new
        // top-level timeslot doesn't, or it renders with a near-invisible border instead of
        // the normal card look every other row has.
        jQuery(elClonedRow).removeClass('timeslot-row-template').removeClass('template').removeClass('recurring-timeslot-row-child').addClass('timeslot-row')
        let elTimeslotWrapper = jQuery('.timeslot-row-wrapper');
        jQuery(elTimeslotWrapper).append(elClonedRow);
        jQuery(elClonedRow).find('input').attr('disabled', false);
        tpfwInitTimeslotDatePicker(elClonedRow, true);
        relabel_timeslot_rows()
        tpfwRefreshTimeslots();
    })

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

    // The QR design section - colours, live preview card, server preview and logo picker -
    // is wired by the shared binder in functions-admin.js.
    tpfwBindQRSection(tpfwParamsTimeslotTicketWcProduct, 'timeslot', '.tpfw-timeslot-pass-card', 'ajax_settings_preview_timeslot_qr');

    /**
     * Forces the General tab and pricing fields back on for a timeslot ticket product.
     *
     * WooCommerce hides pricing for unknown product types, so this runs both when the type
     * dropdown changes and on initial load of an existing product.
     *
     * @returns {void}
     */
    function tpfwShowTimeslotTicketPanels()
    {
        jQuery('.general_tab').show();
        jQuery('.pricing').show();
    }

    jQuery(document.body).on('woocommerce-product-type-change', function(event, type)
    {
        if(type == 'tpfw-timeslot-ticket')
        {
            tpfwShowTimeslotTicketPanels();
        }
    });

    if(jQuery('#product-type').val() == 'tpfw-timeslot-ticket')
    {
        tpfwShowTimeslotTicketPanels();
        jQuery('#inventory_product_data ._manage_stock_field').addClass('hide_if_tpfw-timeslot-ticket').hide();
    }
})