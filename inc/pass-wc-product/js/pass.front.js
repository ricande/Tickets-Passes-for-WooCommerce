/**
 * The per-person roster on a Pass product's single product page.
 *
 * The block's own stepper writes the WooCommerce quantity input and lets its change
 * event do the rest, so the quantity field stays the single source of truth for how
 * many people are on the pass and the posted shape (persons[n][...]) is unchanged.
 */
jQuery(document).ready(function()
{
	let elRoster = jQuery('.season-passes').first();
	if(elRoster.length === 0) return;

	let elList     = elRoster.find('.tpfw-roster-list');
	let elQuantity = jQuery('div.quantity > input.qty[name="quantity"]').first();

	// Sold individually, or a theme without a quantity field: one person, and no controls
	// that would promise otherwise.
	if(elQuantity.length === 0)
	{
		elRoster.find('.tpfw-stepper, .tpfw-roster-add').hide();
	}

	/**
	 * Adds or removes rows until there is one per unit of quantity, then refreshes the counters.
	 *
	 * @returns {void}
	 */
	function render()
	{
		let iWanted = Math.max(1, parseInt(elQuantity.val(), 10) || 1);
		while(elList.children().length < iWanted) addRow();
		while(elList.children().length > iWanted) elList.children().last().remove();
		sync();
	}

	/**
	 * Clones the first row, emptied and renamed for the next position.
	 *
	 * @returns {void}
	 */
	function addRow()
	{
		let iIndex = elList.children().length + 1;
		let elRow  = elList.children().first().clone();
		elRow.removeClass('is-open is-named is-invalid is-invalid-email');
		elRow.find('input').val('').prop('checked', false).removeAttr('checked');
		elRow.find('.tpfw-iconbtn').removeClass('is-on').attr('aria-pressed', 'false');
		elRow.find('input.season-pass-person-firstname').attr('name', 'persons['+iIndex+'][firstname]');
		elRow.find('input.season-pass-person-lastname').attr('name', 'persons['+iIndex+'][lastname]');
		elRow.find('input.season-pass-person-email').attr('name', 'persons['+iIndex+'][email]');
		elRow.find('input.season-pass-email-checkbox').attr('name', 'persons['+iIndex+'][emailchecked]');
		elList.append(elRow);
	}

	/**
	 * Refreshes everything that reports on the roster: the badges, the party size, the
	 * named counter and its progress bar.
	 *
	 * @returns {void}
	 */
	function sync()
	{
		let iNamed = 0;
		let elRows = elList.children();
		elRows.each(function(iPosition)
		{
			let elRow      = jQuery(this);
			let sFirstname = jQuery.trim(elRow.find('.season-pass-person-firstname').val() || '');
			let sLastname  = jQuery.trim(elRow.find('.season-pass-person-lastname').val() || '');
			let bNamed     = sFirstname !== '' && sLastname !== '';
			elRow.toggleClass('is-named', bNamed);
			elRow.find('[data-tpfw-avatar]').text(bNamed
				? (sFirstname.charAt(0) + sLastname.charAt(0)).toUpperCase()
				: (iPosition + 1));
			if(bNamed) iNamed++;
		})

		let iTotal   = elRows.length;
		let sCountTpl = iTotal === 1 ? elRoster.attr('data-count-one') : elRoster.attr('data-count-many');
		elRoster.find('[data-tpfw-count]').text(String(sCountTpl).replace('%d', iTotal));
		elRoster.find('[data-tpfw-named]').text(iNamed);
		elRoster.find('[data-tpfw-total]').text(iTotal);
		elRoster.find('[data-tpfw-bar]').css('width', (iTotal ? (iNamed / iTotal) * 100 : 0) + '%');
		elRoster.find('[data-step="-1"]').prop('disabled', iTotal <= 1);
	}

	// The stepper and the "add another person" button only move the quantity field; the
	// change handler below is the one place rows are built.
	elRoster.on('click', '[data-step]', function()
	{
		let iStep = parseInt(jQuery(this).attr('data-step'), 10);
		elQuantity.val(Math.max(1, (parseInt(elQuantity.val(), 10) || 1) + iStep)).trigger('change');
	})

	elQuantity.on('change', render);

	// The checkbox stays in the form so the server sees what it always saw - the button
	// is only a nicer label for it.
	elRoster.on('click', '.season-pass-email-toggle', function()
	{
		let elButton   = jQuery(this);
		let elRow      = elButton.closest('.season-pass-person');
		let bOn        = !elRow.hasClass('is-open');
		elRow.toggleClass('is-open', bOn).removeClass('is-invalid-email');
		elButton.toggleClass('is-on', bOn).attr('aria-pressed', bOn ? 'true' : 'false');
		elRow.find('.season-pass-email-checkbox').prop('checked', bOn);
		if(bOn) elRow.find('.season-pass-person-email').trigger('focus');
	})

	elRoster.on('input', 'input[type="text"]', function()
	{
		jQuery(this).closest('.season-pass-person').removeClass('is-invalid is-invalid-email');
		sync();
	})

	// Server side validation still runs in tpfw_annual_pass_add_to_cart_action(); this is
	// only here to save a round trip.
	jQuery('.single_add_to_cart_button').on('click', function(e)
	{
		let elFirstInvalid = null;
		elList.children().each(function()
		{
			let elRow       = jQuery(this);
			let elFirstname = elRow.find('.season-pass-person-firstname');
			let elLastname  = elRow.find('.season-pass-person-lastname');
			let elEmail     = elRow.find('.season-pass-person-email');
			let bNoName     = jQuery.trim(elFirstname.val() || '') === '' || jQuery.trim(elLastname.val() || '') === '';
			let bBadEmail   = elRow.find('.season-pass-email-checkbox').is(':checked') && !validateEmail(jQuery.trim(elEmail.val() || ''));

			elRow.toggleClass('is-invalid', bNoName).toggleClass('is-invalid-email', bBadEmail);
			if(bNoName)   elFirstInvalid = elFirstInvalid || (jQuery.trim(elFirstname.val() || '') === '' ? elFirstname : elLastname);
			if(bBadEmail) elFirstInvalid = elFirstInvalid || elEmail;
		})

		if(elFirstInvalid)
		{
			e.preventDefault();
			jQuery('html, body').animate({ scrollTop: elFirstInvalid.offset().top - 120 }, 300);
			elFirstInvalid.trigger('focus');
		}
	})

	render();
});

/**
 * Rough "looks like an email" check for the guest-pass rows.
 *
 * Deliberately loose - it is only here to catch typing mistakes before the
 * form is submitted; the address is validated properly on the server.
 *
 * @param {string} email Address as typed.
 * @returns {boolean} True when it is plausibly an address.
 */
function validateEmail(email)
{
	var re = /\S+@\S+\.\S+/;
	return re.test(email);
}
