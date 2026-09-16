#!/usr/bin/env node
'use strict';

/**
 * Test-only QR decoder. Reads a raw RGBA buffer (no header) and prints the payload.
 * Uses the same jsQR build the door scanner ships; this file must not enter the production zip.
 */
const fs = require('fs');
const path = require('path');
const jsQR = require(path.join(__dirname, '../../inc/scanner/js/jsqr.js'));

const sRgbaPath = process.argv[2];
const iWidth = parseInt(process.argv[3], 10);
const iHeight = parseInt(process.argv[4], 10);

if(!sRgbaPath || !iWidth || !iHeight)
{
	process.stderr.write('usage: decode-qr-rgba.js <rgba-file> <width> <height>\n');
	process.exit(2);
}

const oBuf = fs.readFileSync(sRgbaPath);
const oResult = jsQR(new Uint8ClampedArray(oBuf), iWidth, iHeight);
if(!oResult || typeof oResult.data !== 'string' || oResult.data === '')
{
	process.exit(1);
}
process.stdout.write(oResult.data);
