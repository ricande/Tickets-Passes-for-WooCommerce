const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const parse = require('../../inc/scanner/js/parse-checkin-url.js');
const sBase = 'https://woocommerce.local/wp-json/tpfw/v1/scanner/checkin/';

describe('parse-checkin-url', () => {
	it('extracts a nano id from the REST path', () => {
		const o = parse(sBase, sBase + 'V1StGXR8Z5jdHi6BmyTxq');
		assert.equal(o.sNanoID, 'V1StGXR8Z5jdHi6BmyTxq');
		assert.equal(o.bGuest, false);
	});

	it('detects a guest suffix', () => {
		const o = parse(sBase, sBase + 'V1StGXR8Z5jdHi6BmyTxq/guest');
		assert.equal(o.bGuest, true);
	});

	it('refuses a foreign URL', () => {
		assert.equal(parse(sBase, 'https://example.com/foo'), null);
	});
});

describe('scanner check-in method', () => {
	it('sends POST', () => {
		const sJs = fs.readFileSync(path.join(__dirname, '../../inc/scanner/js/scanner.js'), 'utf8');
		assert.match(sJs, /method:\s*'POST'/);
		const iCheckin = sJs.indexOf('var sURL = cfg.sCheckinBase');
		const iFetch = sJs.indexOf("method: 'POST'", iCheckin);
		assert.ok(iFetch > iCheckin);
		assert.equal(sJs.includes("method: 'GET'"), true); // history stays GET
		const iHistory = sJs.indexOf('function loadHistory');
		assert.ok(sJs.indexOf("method: 'GET'", iHistory) > iHistory);
	});
});
