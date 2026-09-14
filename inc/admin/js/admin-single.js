/**
 * Ticket & Pass metabox on the WooCommerce order screen.
 *
 * Two buttons - issue the tickets/passes for this order, or cancel the ones already issued.
 * Both reload on success, because the metabox is rendered server side from the ticket rows.
 */
jQuery(document).ready(function()
{
	/**
	 * Wires one metabox button to its admin-ajax action.
	 *
	 * The two buttons differed only in selector and action, so they share this instead of two
	 * copies that had to be edited together. The whole metabox is dimmed and made click-proof
	 * while the request is in flight - issuing tickets twice would double-issue them.
	 *
	 * @param {string} sSelector Button class, including the leading dot.
	 * @param {string} sAction   admin-ajax action name.
	 * @returns {void}
	 */
	function bindOrderAction(sSelector, sAction)
	{
		jQuery(sSelector).on('click', function()
		{
			var oThis        = jQuery(this);
			var oThisMetaBox = jQuery(oThis).closest('.order-tpfw-attribution-metabox');

			var data = {
				action  : 'tpfw_' + sAction,
				security: tpfwParamsAdminSingle.aNonces[sAction],
				order_id: jQuery(oThis).attr('data-attr-orderid'),
			}

			jQuery(oThis).css('pointer-events', 'none');
			jQuery(oThis).fadeTo('fast', 0.2);
			jQuery(oThisMetaBox).css('pointer-events', 'none');
			jQuery(oThisMetaBox).fadeTo('fast', 0.2);

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
					location.reload();
				}
				else
				{
					alert(oResult.data.sMessage || tpfwParamsAdminSingle.translations.sActionFailed);
				}
			})
			.fail(function()
			{
				alert(tpfwParamsAdminSingle.translations.sActionFailed);
			})
			.always(function()
			{
				jQuery(oThis).css('pointer-events', 'auto');
				jQuery(oThis).fadeTo('450', 1);
				jQuery(oThisMetaBox).css('pointer-events', 'auto');
				jQuery(oThisMetaBox).fadeTo('450', 1);
			});
		})
	}

	bindOrderAction('.create-admin-order-tpfw', 'ajax_admin_create_tpfw_order');
	bindOrderAction('.cancel-admin-order-tpfw', 'ajax_admin_cancel_tpfw_order');
});
