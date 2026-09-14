/**
 * Turns the raw text decoded from a QR code into a check-in target, or null.
 *
 * This is the scanner's security boundary: without it the page would fetch whatever URL any
 * random QR code in the wild happens to contain. Kept in its own file so tests can exercise
 * it in Node - the rest of scanner.js needs a camera and a DOM.
 *
 * The scanned URL is never fetched. scanner.js only takes the nano id and requests
 * sCheckinBase + id on the page's own origin. Scheme is ignored (http codes still work after
 * a move to https). Host is ignored too: a ticket issued at woocommerce.local must still
 * check in from 192.168.1.222 (or after a domain change). Path must still be this plugin's
 * check-in route, so a random https://example.com/foo QR is refused.
 */
(function (root, factory) {
	if (typeof module === 'object' && module.exports) { module.exports = factory(); }
	else { root.TPFW_parseCheckinURL = factory(); }
}(typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	var RX_PRETTY = /\/wp-json\/tpfw\/v1\/scanner\/checkin\/(\w+)(\/guest)?\/?$/;
	var RX_REST_ROUTE = /(?:\?|&)rest_route=\/tpfw\/v1\/scanner\/checkin\/(\w+)(\/guest)?\/?/;

	function fromMatch(m) {
		return m ? { sNanoID: m[1], bGuest: !!m[2] } : null;
	}

	/**
	 * @param {string} sCheckinBase Check-in endpoint URL the QR codes are built from.
	 * @param {string} sText        Raw text decoded from the QR code.
	 * @returns {{sNanoID: string, bGuest: boolean}|null} Null when the code is not ours.
	 */
	return function parseCheckinURL(sCheckinBase, sText) {
		if (!sCheckinBase || !sText) { return null; }

		var sTrimmed = String(sText).trim();
		var sBase = String(sCheckinBase).replace(/^https?:\/\//, '');
		var rxExact = new RegExp('^' + sBase.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(\\w+)(/guest)?/?$');
		var mExact = rxExact.exec(sTrimmed.replace(/^https?:\/\//, ''));
		if (mExact) {
			return fromMatch(mExact);
		}

		return fromMatch(RX_PRETTY.exec(sTrimmed.split('?')[0]))
			|| fromMatch(RX_REST_ROUTE.exec(sTrimmed));
	};
}));
