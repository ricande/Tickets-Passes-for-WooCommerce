/**
 * Activates WooCommerce's tipTip tooltips on this plugin's settings screens.
 *
 * WooCommerce only initialises them on screens it recognises as its own, so the help icons
 * rendered by wc_help_tip() would otherwise be inert here. Same options WooCommerce uses.
 */
jQuery(function($) {
	$('.woocommerce-help-tip').tipTip({
		attribute: 'data-tip',
		fadeIn: 50,
		fadeOut: 50,
		delay: 200,
	});
});
