/**
 * Timeslot picking on the single product page.
 *
 * Add to cart stays disabled until a slot is chosen - the server rejects a timeslot product
 * added without one, so this keeps the customer from finding that out at the cart. Selecting
 * a slot fills the hidden inputs the add-to-cart handler reads and caps the quantity field at
 * that slot's remaining places. Sold-out slots are inert.
 *
 * The stepper and the summary line are a second face on WooCommerce's own quantity input, not
 * a replacement for it: that field is still what the form posts, so nothing here changes the
 * add-to-cart contract.
 */
jQuery(document).ready(function($)
{
    const $picker  = $('.tpfw-timeslot-picker');
    const $qty     = $('.quantity > input[name="quantity"]');
    const $stepper = $picker.find('.tpfw-timeslot-stepper');
    const $value   = $stepper.find('.tpfw-timeslot-stepper-value');
    const $summary = $picker.find('.tpfw-timeslot-picker-summary');
    const $meter   = $picker.find('.tpfw-timeslot-picker-meter > i');

    /**
     * Places left on the currently selected slot.
     *
     * @returns {number} 0 while nothing is selected yet.
     */
    function selectedCapacity()
    {
        const $selected = $('.single-product-timeslot.selected');

        return $selected.length ? (parseInt($selected.attr('data-timeslot-qty'), 10) || 0) : 0;
    }

    /**
     * Keeps the stepper label, its two buttons and the fill bar in step with the quantity field.
     *
     * The value may have come from the stepper, the theme's own +/- buttons or someone typing
     * into the field, so this reads the field rather than tracking clicks.
     *
     * @returns {void}
     */
    function syncQuantity()
    {
        const iMax = selectedCapacity();
        const iVal = parseInt($qty.val(), 10) || 0;
        const sLabel = $value.attr(iVal === 1 ? 'data-tpfw-one' : 'data-tpfw-many') || '%d';

        $value.text(sLabel.replace('%d', iVal));
        $stepper.find('[data-tpfw-step="-1"]').prop('disabled', iVal <= 1);
        $stepper.find('[data-tpfw-step="1"]').prop('disabled', iMax <= 0 || iVal >= iMax);
        $meter.css('width', iMax > 0 ? Math.min(100, (iVal / iMax) * 100) + '%' : 0);
    }

    $('.single-product-timeslot').on("click", function()
    {
        if($(this).hasClass("tpfw-timeslot-option--soldout")) return;

        $(".single-product-timeslot").removeClass("selected");

        $(this).addClass("selected");

        const selectedTime    = $(this).attr("data-start-time");
        const selectedEndTime = $(this).attr("data-end-time");
        const selectedDate    = $(this).attr("data-date");
        const selectedEndDate = $(this).attr("data-end-date");
        const selectedID      = $(this).attr("data-timeslot-id");
        const selectedQty     = $(this).attr("data-timeslot-qty");

        $(".input-single-product-timeslot-id").val(selectedID);
        $(".input-single-product-timeslot-start").val(selectedDate + " " + selectedTime);
        $(".input-single-product-timeslot-end").val(selectedEndDate + " " + selectedEndTime);

        $qty.attr('min', 1);
        $qty.attr('max', selectedQty);
        $qty.val(1);

        // Built from the row's own text rather than a template string, so the summary repeats
        // the customer's choice back in exactly the wording the list already used.
        $summary.empty()
            .append($('<strong>').text(selectedTime + '–' + selectedEndTime))
            .append(document.createTextNode(' · ' + $(this).find('.tpfw-timeslot-option-spots').text().trim()));

        syncQuantity();

        $(".single_add_to_cart_button").css("pointer-events", "auto").fadeTo("fast", 1);
        $qty.css("pointer-events", "auto").fadeTo("fast", 1);
    });

    $stepper.on('click', 'button', function()
    {
        const iMax = selectedCapacity();
        if(iMax <= 0) return;

        const iStep = parseInt($(this).attr('data-tpfw-step'), 10) || 0;

        $qty.val(Math.min(Math.max(1, (parseInt($qty.val(), 10) || 0) + iStep), iMax)).trigger('change');
    });

    $qty.on('change input', syncQuantity);

    // Only dim the controls when the date picker is actually on the page: without a picker
    // there are no slots to choose, and disabling add to cart would leave no way to buy.
    if($('#timeslot-date-picker').length >= 1)
    {
        $(".single_add_to_cart_button").css("pointer-events", "none").fadeTo("fast", 0.2);
        $qty.css("pointer-events", "none").fadeTo("fast", 0.2);
        $qty.val(0);
        $qty.attr('max', 0);
        $qty.attr('min', 0);

        // A product sold individually still renders a quantity field, just a hidden one holding
        // a fixed 1 - showing a stepper for it would offer a choice that cannot be made.
        if($qty.length && $qty.attr('type') !== 'hidden')
        {
            $stepper.removeAttr('hidden');
            syncQuantity();
        }
    }
});
