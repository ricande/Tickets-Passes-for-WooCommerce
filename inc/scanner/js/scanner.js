/**
 * Door-staff check-in scanner.
 *
 * Decodes a QR code from the camera, then waits for staff to tap Check In before calling
 * the existing tpfw/v1/scanner/checkin endpoint. All check-in logic stays server side - this
 * file only decides *what* to send, when, and paints whatever colour and message comes back.
 *
 * State machine: SCANNING -> LOCKED (valid code decoded, button enabled) -> CHECKING
 * (button tapped, request in flight) -> notification shown -> back to SCANNING automatically.
 *
 * Dummy mode: when no real camera is available (old browser, insecure context, no device,
 * permission denied - all realistic on a desktop browser used for testing), the Check-In
 * button stays always-enabled and cycles through fake results instead of calling fetch().
 */
(function () {
	'use strict';

	var cfg = window.TPFW_SCANNER;
	if (!cfg) { return; }

	var S = cfg.aStrings;
	var elVideo      = document.getElementById('tpfw-video');
	var elReticle    = document.getElementById('tpfw-reticle');
	var elStatus     = document.getElementById('tpfw-status');
	var elCheckin    = document.getElementById('tpfw-checkin-btn');
	var elNotice     = document.getElementById('tpfw-notice');
	var elNoticeIcon = document.getElementById('tpfw-notice-icon');
	var elNoticeMsg  = document.getElementById('tpfw-notice-message');
	var elMenuBtn      = document.getElementById('tpfw-menu-btn');
	var elMenu         = document.getElementById('tpfw-menu');
	var elMenuScrim    = document.getElementById('tpfw-menu-scrim');
	var elHistoryBtn   = document.getElementById('tpfw-menu-history-btn');
	var elHistoryBack  = document.getElementById('tpfw-menu-back-btn');
	var elHistoryList  = document.getElementById('tpfw-history-list');
	var elCancel          = document.getElementById('tpfw-cancel-btn');
	var elHistoryRefresh  = document.getElementById('tpfw-history-refresh-btn');
	var elManualForm      = document.getElementById('tpfw-manual-form');
	var elManualInput     = document.getElementById('tpfw-manual-input');
	var elTorch           = document.getElementById('tpfw-torch-btn');
	var oVideoTrack       = null;   // the live camera track, kept for the torch constraint
	var bTorchOn          = false;

	// Small inline glyphs so the notification reads at a glance without a bare status number
	// doing all the work. No icon library - two paths is not worth a dependency.
	var SVG_CHECK = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 12.5 9.5 18 20 6"></polyline></svg>';
	var SVG_ALERT = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="9"></circle><line x1="12" y1="7.5" x2="12" y2="13"></line><circle cx="12" cy="16.5" r="0.1" stroke-width="2.8"></circle></svg>';

	var oDetector = null;   // native BarcodeDetector, when the browser has one
	var oCanvas   = null;   // jsQR fallback scratch canvas
	var oCtx      = null;

	var STATE_SCANNING = 'scanning';
	var STATE_LOCKED   = 'locked';
	var STATE_CHECKING = 'checking';
	var STATE_RESULT   = 'result';   // a notification is on screen; decoding is paused

	var sState       = STATE_SCANNING;
	var bDummyMode   = false;
	var iDummyIndex  = 0;
	var oPending     = null;  // { sNanoID, bGuest } while LOCKED
	var iNoticeTimer = null;

	var RESULT_MS         = 2500;  // how long a success notification stays up before scanning resumes
	var RESULT_REFUSAL_MS = 6000;  // refusals carry a reason staff must read ("outside its window ..."), so they stay longer
	var FRAME_MS          = 100;   // jsQR decode budget - ~10fps is plenty and saves the battery

	// Same status codes the real API answers with: 200 admitted, 202 refused with a reason,
	// 401 unknown code.
	var aDummyResults = cfg.aDummyResults || [
		{ iStatus: 200, sMessage: 'Ticket valid and checked in',   sHexColor: '2e7d32' },
		{ iStatus: 200, sMessage: 'Pass successfully checked in',  sHexColor: '2e7d32', sPhotoURL: cfg.sPlaceholderPhotoURL },
		{ iStatus: 202, sMessage: 'Already checked in',            sHexColor: 'b8860b' },
		{ iStatus: 401, sMessage: 'Ticket not found',              sHexColor: 'c0392b' },
		{ iStatus: 202, sMessage: 'Not valid for today',           sHexColor: 'c0392b' }
	];

	/**
	 * Writes the one-line status text under the viewfinder.
	 *
	 * @param {string} sText Already translated status message.
	 * @returns {void}
	 */
	function status(sText) {
		elStatus.textContent = sText;
	}

	/**
	 * Enables or disables the Check In button.
	 *
	 * @param {boolean} bEnabled
	 * @returns {void}
	 */
	function setButtonEnabled(bEnabled) {
		elCheckin.disabled = !bEnabled;
	}
	/**
	 * Picks black or white body text for a result colour.
	 *
	 * White text on a light result colour is unreadable at arm's length, and the colours are
	 * admin-configurable, so pick the contrast from the actual luminance rather than assuming.
	 *
	 * @param {string} sHex Result colour, with or without the leading #.
	 * @returns {string} '#101418' or '#ffffff'.
	 */
	function readableText(sHex) {
		var m = /^#?([0-9a-f]{6})$/i.exec(String(sHex || ''));
		if (!m) { return '#ffffff'; }
		var i = parseInt(m[1], 16);
		var L = (0.299 * ((i >> 16) & 255) + 0.587 * ((i >> 8) & 255) + 0.114 * (i & 255)) / 255;
		return L > 0.6 ? '#101418' : '#ffffff';
	}

	/**
	 * Shows the full-width result notification and schedules the return to scanning.
	 *
	 * @param {number} iStatusCode HTTP status behind the result; drives the icon choice.
	 * @param {string} sMessage    Message to display.
	 * @param {string} sHexColor   Background colour from the server, or the error colour.
	 * @param {string} [sPhotoURL] Pass holder photo, shown instead of the icon when present.
	 * @returns {void}
	 */
	function showNotice(iStatusCode, sMessage, sHexColor, sPhotoURL) {
		// Freeze the state machine while a result is on screen. Without this a QR code that is
		// not a ticket from this site re-entered handleDecoded() on every decode frame, so the
		// phone buzzed ~10x a second and the notice never cleared while the code stayed in view.
		sState = STATE_RESULT;
		setButtonEnabled(false);

		var sColor = /^#?[0-9a-f]{6}$/i.test(String(sHexColor || '')) ? sHexColor : cfg.sErrorColor;
		if (sColor.charAt(0) !== '#') { sColor = '#' + sColor; }

		elNotice.style.background = sColor;
		elNotice.style.color      = readableText(sColor);

		if (sPhotoURL) {
			elNoticeIcon.innerHTML = '';
			var elPhoto = document.createElement('img');
			elPhoto.src = sPhotoURL;
			elPhoto.alt = '';
			elNoticeIcon.appendChild(elPhoto);
		} else {
			// Exactly 200, not "any 2xx": a refusal the door staff must read - already used, on
			// cooldown, outside its window, or being scanned at another door right now - answers
			// 202, and drawing a green tick on those told staff to let the person through.
			elNoticeIcon.innerHTML = (iStatusCode === 200) ? SVG_CHECK : SVG_ALERT;
		}

		elNoticeMsg.textContent   = sMessage;
		elNotice.classList.add('is-visible');

		if (navigator.vibrate) { navigator.vibrate(iStatusCode === 200 ? 60 : [60, 80, 60]); }

		clearTimeout(iNoticeTimer);
		iNoticeTimer = setTimeout(dismissNotice, iStatusCode === 200 ? RESULT_MS : RESULT_REFUSAL_MS);
	}

	/**
	 * Hides the result notification now and resumes scanning. Runs on the timer and on a tap.
	 *
	 * @returns {void}
	 */
	function dismissNotice() {
		clearTimeout(iNoticeTimer);
		elNotice.classList.remove('is-visible');
		if (sState === STATE_RESULT) { toScanning(); }
	}

	/**
	 * Checks in a code typed or pasted into the menu's manual field.
	 *
	 * Accepts either a full check-in link (the QR contents) or a bare nano id. A bare id is sent
	 * to the plain route, which resolves guest passes itself.
	 *
	 * @param {Event} e Submit event.
	 * @returns {void}
	 */
	function submitManual(e) {
		e.preventDefault();
		var sText = String(elManualInput.value || '').trim();
		if (!sText) { return; }

		var oTarget = window.TPFW_parseCheckinURL(cfg.sCheckinBase, sText);
		if (!oTarget && /^[A-Za-z0-9]{1,32}$/.test(sText)) {
			oTarget = { sNanoID: sText, bGuest: false };
		}

		closeMenu();
		if (!oTarget) {
			showNotice(0, S.sManualInvalid, cfg.sErrorColor);
			return;
		}

		elManualInput.value = '';
		dismissNotice();
		toScanning();
		toLocked(oTarget);
		fireCheckin();
	}

	/**
	 * Returns the state machine to SCANNING and repaints the controls for it.
	 *
	 * @returns {void}
	 */
	function toScanning() {
		sState   = STATE_SCANNING;
		oPending = null;
		elReticle.style.display = bDummyMode ? 'none' : '';
		setButtonEnabled(bDummyMode);
		elCancel.hidden = true;
		status(bDummyMode ? S.sDummyReady : S.sReady);
	}

	/**
	 * Moves to LOCKED: a valid code is decoded and staff can now tap Check In.
	 *
	 * @param {{sNanoID: string, bGuest: boolean}} oTarget Parsed check-in target.
	 * @returns {void}
	 */
	function toLocked(oTarget) {
		sState   = STATE_LOCKED;
		oPending = oTarget;
		elReticle.style.display = 'none';
		setButtonEnabled(true);
		elCancel.hidden = false;
		status(S.sLocked);
	}

	/**
	 * Handles one decoded QR payload, locking on it when it is a check-in URL for this site.
	 *
	 * @param {string} sText Raw decoded QR text.
	 * @returns {void}
	 */
	function handleDecoded(sText) {
		if (sState !== STATE_SCANNING) { return; }

		var oTarget = window.TPFW_parseCheckinURL(cfg.sCheckinBase, sText);
		if (!oTarget) {
			showNotice(0, S.sForeignCode, cfg.sErrorColor);
			return;
		}
		toLocked(oTarget);
	}

	/**
	 * Sends the check-in request for the locked code and shows whatever comes back.
	 *
	 * Dummy mode only answers when there is nothing real to check in - that is the
	 * "no camera found, tap Check In to see a sample result" demo, and it has no code to send.
	 * A code typed into the manual field is a real code with a real answer, so it always goes to
	 * the endpoint. Testing bDummyMode first meant a hand-typed code was parsed, locked onto and
	 * then thrown away for the next canned sample - on every device without a camera, which is
	 * exactly the desk manual entry exists for.
	 *
	 * @returns {void}
	 */
	function fireCheckin() {
		if (bDummyMode && !oPending) {
			var oFake = aDummyResults[iDummyIndex % aDummyResults.length];
			iDummyIndex += 1;
			showNotice(oFake.iStatus, oFake.sMessage, oFake.sHexColor, oFake.sPhotoURL);
			return;
		}

		if (sState !== STATE_LOCKED || !oPending) { return; }

		sState = STATE_CHECKING;
		setButtonEnabled(false);
		elCancel.hidden = true;
		status(S.sChecking);

		var sURL = cfg.sCheckinBase + oPending.sNanoID + (oPending.bGuest ? '/guest' : '');

		fetch(sURL, {
			method: 'POST',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'X-WP-Nonce': cfg.sNonce, 'Accept': 'application/json' }
		}).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (data) {
				// A WP nonce dies after ~12h, which lands mid-shift. Without this the scanner
				// would just start rejecting every valid ticket with no hint why.
				if (res.status === 403 && data.code === 'rest_cookie_invalid_nonce') {
					showNotice(403, S.sSessionExpired, cfg.sErrorColor);
					return;
				}
				// A refusal from the route's permission callback comes back in WP_Error shape,
				// which nests the payload one level down, so read through both.
				var oBody = data.sMessage ? data : (data.data || data);
				showNotice(res.status, oBody.sMessage || data.message || String(res.status), oBody.sHexColor, oBody.sPhotoURL);
			});
		}).catch(function () {
			showNotice(0, S.sNetworkError, cfg.sErrorColor);
		});
	}

	/**
	 * Decodes the current video frame with the browser's BarcodeDetector.
	 *
	 * @returns {Promise<void>} Always resolves; a dropped frame is not an error.
	 */
	function decodeNative() {
		if (sState !== STATE_SCANNING || elVideo.readyState < 2) { return Promise.resolve(); }
		return oDetector.detect(elVideo).then(function (aCodes) {
			if (aCodes.length && aCodes[0].rawValue) { handleDecoded(aCodes[0].rawValue); }
		}).catch(function () { /* a dropped frame is not worth reporting */ });
	}

	/**
	 * Decodes the current video frame with jsQR, via a downscaled scratch canvas.
	 *
	 * @returns {void}
	 */
	function decodeFallback() {
		if (sState !== STATE_SCANNING || elVideo.readyState < 2) { return; }

		// Decode at a capped width - jsQR is pure JS and full-resolution frames stall the
		// main thread on the mid-range Android phones this actually runs on.
		var iW = elVideo.videoWidth;
		var iH = elVideo.videoHeight;
		if (!iW || !iH) { return; }
		var fScale = Math.min(1, 640 / iW);
		var iCW    = Math.round(iW * fScale);
		var iCH    = Math.round(iH * fScale);

		// Assigning width/height reallocates the backing bitmap and clears it, so only do it when
		// the camera's frame size actually changes - not ten times a second for the same number.
		if (oCanvas.width !== iCW || oCanvas.height !== iCH) {
			oCanvas.width  = iCW;
			oCanvas.height = iCH;
		}

		oCtx.drawImage(elVideo, 0, 0, oCanvas.width, oCanvas.height);
		var oImage = oCtx.getImageData(0, 0, oCanvas.width, oCanvas.height);
		var oCode  = window.jsQR(oImage.data, oImage.width, oImage.height, { inversionAttempts: 'dontInvert' });
		if (oCode && oCode.data) { handleDecoded(oCode.data); }
	}

	/**
	 * Runs the decode loop at FRAME_MS intervals for as long as the page lives.
	 *
	 * @returns {void}
	 */
	function loop() {
		var fnDecode = oDetector ? decodeNative : function () { decodeFallback(); return Promise.resolve(); };
		Promise.resolve(fnDecode()).then(function () {
			setTimeout(loop, FRAME_MS);
		});
	}

	/**
	 * Switches to the no-camera mode used on desktop browsers and during testing.
	 *
	 * @returns {void}
	 */
	function enterDummyMode() {
		bDummyMode = true;
		toScanning();
	}

	/**
	 * Opens the rear camera and starts decoding, falling back to dummy mode when it cannot.
	 *
	 * @returns {void}
	 */
	function start() {
		// getUserMedia only exists in a secure context, and may still be missing on an old
		// browser. Either way there is no camera to test with - most commonly true right here,
		// on a desktop browser during development - so fall into dummy mode instead of a dead end.
		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
			enterDummyMode();
			return;
		}

		navigator.mediaDevices.getUserMedia({
			audio: false,
			video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }
		}).then(function (oStream) {
			elVideo.srcObject = oStream;
			oVideoTrack       = oStream.getVideoTracks()[0] || null;
			return elVideo.play();
		}).then(function () {
			toScanning();
			offerTorch();
			loop();
		}).catch(function () {
			// No camera device, permission denied, etc. - same reasoning as above.
			enterDummyMode();
		});
	}

	/**
	 * Shows the flashlight button when the active camera track advertises a torch.
	 *
	 * getCapabilities() is missing on some browsers and torch is absent on most front cameras
	 * and every desktop webcam, so the button stays hidden unless the track says otherwise.
	 *
	 * @returns {void}
	 */
	function offerTorch() {
		if (!elTorch || !oVideoTrack || typeof oVideoTrack.getCapabilities !== 'function') { return; }
		var oCaps = oVideoTrack.getCapabilities();
		if (!oCaps || !oCaps.torch) { return; }
		elTorch.hidden = false;
	}

	/**
	 * Toggles the camera torch through a track constraint.
	 *
	 * @returns {void}
	 */
	function toggleTorch() {
		if (!oVideoTrack) { return; }
		var bWant = !bTorchOn;
		oVideoTrack.applyConstraints({ advanced: [{ torch: bWant }] }).then(function () {
			bTorchOn = bWant;
			elTorch.setAttribute('aria-pressed', bTorchOn ? 'true' : 'false');
		}).catch(function () {
			// The device refused (some only allow torch while recording); hide the control
			// rather than leave a button that does nothing.
			elTorch.hidden = true;
		});
	}

	/**
	 * Opens the slide-in menu.
	 *
	 * @returns {void}
	 */
	function openMenu() {
		elMenu.classList.add('is-open');
		elMenuScrim.classList.add('is-visible');
		elMenuBtn.setAttribute('aria-expanded', 'true');
	}

	/**
	 * Closes the menu and resets it to its main view.
	 *
	 * @returns {void}
	 */
	function closeMenu() {
		elMenu.classList.remove('is-open');
		elMenu.classList.remove('is-history');
		elMenuScrim.classList.remove('is-visible');
		elMenuBtn.setAttribute('aria-expanded', 'false');
	}

	/**
	 * Returns the menu from the history subview to its main view.
	 *
	 * @returns {void}
	 */
	function backToMenuMain() {
		elMenu.classList.remove('is-history');
	}
	/**
	 * Steps back one menu level rather than closing outright: from the history subview this
	 * returns to the main menu, from the main menu it closes. Shared by the scrim tap and Esc.
	 *
	 * @returns {void}
	 */
	function dismissMenuLevel() {
		if (elMenu.classList.contains('is-history')) {
			backToMenuMain();
		} else {
			closeMenu();
		}
	}

	// Short badge letters + colour per type, keyed off the untranslated sTypeKey so this still
	// works on non-English sites. sLabel carries the translated type name: the row no longer
	// prints it (the colour says it at a glance), but it is what a screen reader announces and
	// what a long-press tooltip shows, so the letters are not the only clue to what a row is.
	var TYPE_BADGES = {
		ticket:     { sLetters: 'T',  sColor: '#2f7dfb', sLabel: S.sTypeTicket },
		timeslot:   { sLetters: 'TS', sColor: '#8e5cf0', sLabel: S.sTypeTimeslot },
		pass: { sLetters: 'P', sColor: '#2e7d32', sLabel: S.sTypePass },
		guestpass:  { sLetters: 'GP', sColor: '#b8860b', sLabel: S.sTypeGuestpass }
	};
	/**
	 * Parses a check-in timestamp from the history endpoint.
	 *
	 * sCreatedUTC is ISO-8601 with an explicit offset, so this is unambiguous regardless of where
	 * the phone thinks it is - which a bare "YYYY-MM-DD HH:MM:SS" was not.
	 *
	 * @param {string} sRaw ISO-8601 timestamp.
	 * @returns {Date}
	 */
	function parseCreated(sRaw) {
		return new Date(String(sRaw));
	}

	/**
	 * Formats a check-in time as "just now" / "5 minutes ago" / "2 hours ago" / "3 days ago".
	 *
	 * @param {Date} oDate
	 * @returns {string} Translated relative time.
	 */
	function relativeTime(oDate) {
		var iMin = Math.floor(Math.max(0, Date.now() - oDate.getTime()) / 60000);
		if (iMin < 1)  { return S.sJustNow; }
		if (iMin < 60) { return S.sMinutesAgo.replace('%d', iMin); }
		var iHr = Math.floor(iMin / 60);
		if (iHr < 24)  { return S.sHoursAgo.replace('%d', iHr); }
		return S.sDaysAgo.replace('%d', Math.floor(iHr / 24));
	}

	/**
	 * True when two dates fall on the same calendar day in the device's timezone.
	 *
	 * @param {Date} a
	 * @param {Date} b
	 * @returns {boolean}
	 */
	function isSameDay(a, b) {
		return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
	}

	/**
	 * Heading text for the history group a date belongs to.
	 *
	 * @param {Date} oDate
	 * @returns {string} 'Today', 'Yesterday' or a short localised date.
	 */
	function dateGroupLabel(oDate) {
		var oYesterday = new Date();
		oYesterday.setDate(oYesterday.getDate() - 1);
		if (isSameDay(oDate, new Date()))   { return S.sToday; }
		if (isSameDay(oDate, oYesterday))   { return S.sYesterday; }
		return oDate.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
	}

	/**
	 * Builds a date heading row for the history list.
	 *
	 * @param {string} sLabel Heading text.
	 * @returns {HTMLLIElement}
	 */
	function historyDateHeader(sLabel) {
		var elLi = document.createElement('li');
		elLi.className = 'tpfw-history-date-header';
		elLi.textContent = sLabel;
		return elLi;
	}
	/**
	 * Builds one history row: type badge, holder name and relative time.
	 *
	 * textContent, not string concatenation into innerHTML - holder names come from
	 * customer-entered profile fields and must not be interpreted as markup.
	 *
	 * @param {{sTypeKey: string, sHolderName: string, sCreatedUTC: string}} oEntry
	 * @returns {HTMLLIElement}
	 */
	function historyRow(oEntry) {
		var oBadge = TYPE_BADGES[oEntry.sTypeKey] || { sLetters: '?', sColor: '#4a5158', sLabel: '' };

		var elLi    = document.createElement('li');
		var elBadge = document.createElement('span');
		var elInfo  = document.createElement('span');
		var elName  = document.createElement('span');
		var elMeta  = document.createElement('span');

		elBadge.className = 'tpfw-history-badge';
		elBadge.style.background = oBadge.sColor;
		elBadge.textContent = oBadge.sLetters;
		elBadge.title = oBadge.sLabel;
		elBadge.setAttribute('aria-label', oBadge.sLabel);

		elInfo.className = 'tpfw-history-info';
		elName.className = 'tpfw-history-name';
		elMeta.className = 'tpfw-history-meta';
		elName.textContent = oEntry.sHolderName;
		elMeta.textContent = relativeTime(parseCreated(oEntry.sCreatedUTC));

		elInfo.appendChild(elName);
		elInfo.appendChild(elMeta);
		elLi.appendChild(elBadge);
		elLi.appendChild(elInfo);
		return elLi;
	}

	/**
	 * Fetches this scanner's recent check-ins and renders them, grouped by day.
	 *
	 * On failure the list shows a tappable retry row rather than an empty state.
	 *
	 * @returns {void}
	 */
	function loadHistory() {
		elHistoryList.textContent = '';

		fetch(cfg.sHistoryBase, {
			method: 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'X-WP-Nonce': cfg.sNonce, 'Accept': 'application/json' }
		}).then(function (res) {
			// An expired nonce answers 403 with a JSON error body, which has no aHistory - so
			// without this the list claimed "No check-ins yet" when the real problem was auth.
			if (!res.ok) { throw new Error(res.status); }
			return res.json();
		}).then(function (data) {
			var aHistory = (data && data.aHistory) || [];
			if (!aHistory.length) {
				var elEmpty = document.createElement('li');
				elEmpty.textContent = S.sHistoryEmpty;
				elHistoryList.appendChild(elEmpty);
				return;
			}
			var sLastGroup = null;
			aHistory.forEach(function (oEntry) {
				var sGroup = dateGroupLabel(parseCreated(oEntry.sCreatedUTC));
				if (sGroup !== sLastGroup) {
					elHistoryList.appendChild(historyDateHeader(sGroup));
					sLastGroup = sGroup;
				}
				elHistoryList.appendChild(historyRow(oEntry));
			});
		}).catch(function () {
			var elError = document.createElement('li');
			elError.textContent = S.sHistoryError;
			elError.style.cursor = 'pointer';
			elError.addEventListener('click', loadHistory);
			elHistoryList.appendChild(elError);
		});
	}
	/**
	 * Opens the history subview and refreshes it.
	 *
	 * Always refetches. The list was previously cached for the life of the page, so after the
	 * first open it never showed anything checked in since - and the relative times froze too.
	 *
	 * @returns {void}
	 */
	function openHistory() {
		elMenu.classList.add('is-history');
		loadHistory();
	}

	/**
	 * Binds the controls, picks the decoder the browser supports and starts the camera.
	 *
	 * @returns {void}
	 */
	function init() {
		elCheckin.addEventListener('click', fireCheckin);
		// Dropping the locked code is the only way back out of LOCKED without checking someone in.
		elCancel.addEventListener('click', toScanning);
		elHistoryRefresh.addEventListener('click', loadHistory);
		elMenuBtn.addEventListener('click', openMenu);
		elMenuScrim.addEventListener('click', dismissMenuLevel);
		elHistoryBtn.addEventListener('click', openHistory);
		elHistoryBack.addEventListener('click', backToMenuMain);
		// Tap to dismiss: staff should not have to wait out a six-second refusal they have read.
		elNotice.addEventListener('click', dismissNotice);
		if (elManualForm) { elManualForm.addEventListener('submit', submitManual); }
		if (elTorch) { elTorch.addEventListener('click', toggleTorch); }
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && elMenu.classList.contains('is-open')) { dismissMenuLevel(); }
		});

		// Safari has no BarcodeDetector, so iPhones fall back to jsQR. Chrome/Android has it
		// natively and it is both faster and easier on the battery, so prefer it where present.
		if (window.BarcodeDetector && window.BarcodeDetector.getSupportedFormats) {
			window.BarcodeDetector.getSupportedFormats().then(function (aFormats) {
				if (aFormats.indexOf('qr_code') !== -1) {
					oDetector = new window.BarcodeDetector({ formats: ['qr_code'] });
				}
				start();
			}).catch(start);
			return;
		}
		start();
	}

	oCanvas = document.createElement('canvas');
	oCtx    = oCanvas.getContext('2d', { willReadFrequently: true });
	init();
}());
