/**
 * The Analytics screen: a year-at-a-glance check-in heatmap, and an hourly bar chart for whichever
 * day of it is selected.
 *
 * Both charts are ApexCharts instances destroyed and rebuilt on every fetch rather than updated,
 * because the series count changes with the product filter. The CSV exports live in the panel
 * headers and ask the server for the file rather than serialising the rendered series client side,
 * so an export covers the full range and not just what fitted on the chart.
 *
 * Every user-facing string is printed by page-content.php and read back out of the DOM here, so
 * translators meet the screen's copy in one file only.
 */

/** Localised month names and the AJAX nonces, from wp_localize_script(). */
let aTranslations = tpfwParamsAnalyticsSingle;

/** Month names in calendar order, as the heatmap and the readout both need them. */
const aMonthNames = [
	aTranslations['January'], aTranslations['February'], aTranslations['March'],
	aTranslations['April'],   aTranslations['May'],      aTranslations['June'],
	aTranslations['July'],    aTranslations['August'],   aTranslations['September'],
	aTranslations['October'], aTranslations['November'], aTranslations['December'],
];

/** Six steps of attendance ink, quiet to busy. The fifth is the green the screen has always used. */
const aHeatRamp = ['#eceff0', '#cfe3d4', '#93c5a1', '#4e9a68', '#2e7d32', '#14532d'];

/** Users who asked for less motion get charts that appear rather than draw themselves in. */
const bReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** The live chart instances, kept only so they can be destroyed before the next render. */
let oYearlyChart = null;
let oDailyChart  = null;

/** Y-m-d of the day the hourly panel is showing, or an empty string when no day is selected. */
let sSelectedDay = '';

/**
 * The product IDs currently ticked in the filter rail.
 *
 * An empty array means "no filter" to the server, not "no products", so the charts still fill in
 * before anything has been ticked.
 *
 * @returns {string[]} Product IDs as strings, in DOM order.
 */
function getSelectedAnalyticsProductIds()
{
	return jQuery('.tpfw-product-checkbox:checked').map(function() { return this.value; }).get();
}

/**
 * Shows a failure in the page instead of in an alert() the reader has to dismiss before they can
 * look at what went wrong.
 *
 * @param {string} sMessage What failed, in the plugin's own words.
 * @returns {void}
 */
function showAnalyticsError(sMessage)
{
	jQuery('.tpfw-notice__text').text(sMessage || aTranslations.sLoadFailed);
	jQuery('.tpfw-notice').prop('hidden', false);
}

/**
 * Clears the error banner, called at the start of every request so a fixed problem stops shouting.
 *
 * @returns {void}
 */
function clearAnalyticsError()
{
	jQuery('.tpfw-notice').prop('hidden', true);
}

/**
 * Puts a panel into or out of its loading state: skeleton in, chart out, controls inert.
 *
 * @param {string}  sPanel  Panel selector, '.tpfw-panel--year' or '.tpfw-panel--day'.
 * @param {boolean} bBusy   True while the request is in flight.
 * @returns {void}
 */
function setPanelBusy(sPanel, bBusy)
{
	jQuery(sPanel).attr('aria-busy', bBusy ? 'true' : 'false').toggleClass('is-busy', bBusy);
	jQuery(sPanel).find('.tpfw-skeleton').prop('hidden', !bBusy);
}

/**
 * Turns a CSV payload from the server into a download, without leaving the blob URL behind.
 *
 * Both export buttons hit the same endpoint and only differ in date range and filename, so they
 * share this. The object URL is revoked once the click has been dispatched - the two copies this
 * replaced never revoked theirs, so every export held its CSV in memory until the page was left.
 *
 * @param {string} sCSV      Raw CSV text.
 * @param {string} sFilename Filename to offer, including the extension.
 * @returns {void}
 */
function downloadAnalyticsCSV(sCSV, sFilename)
{
	var sUrl       = window.URL.createObjectURL(new Blob([sCSV]));
	var oLink      = document.createElement('a');
	oLink.href     = sUrl;
	oLink.download = sFilename;
	oLink.click();
	window.URL.revokeObjectURL(sUrl);
}

/**
 * Requests a check-in CSV for a date range and hands it to the browser as a download.
 *
 * @param {string} sStartDate Inclusive start, Y-m-d.
 * @param {string} sEndDate   End of the range, Y-m-d.
 * @param {string} sFilename  Filename to offer, including the extension.
 * @returns {void}
 */
function requestAnalyticsCSV(sStartDate, sEndDate, sFilename)
{
	clearAnalyticsError();

	jQuery.ajax({
		url     : ajaxurl,
		data    : {
			action    : 'tpfw_ajax_fetch_yearly_checkin_stats_csv',
			security  : tpfwParamsAnalyticsSingle.aNonces.ajax_fetch_yearly_checkin_stats_csv,
			sStartDate: sStartDate,
			sEndDate  : sEndDate,
			aProductID: getSelectedAnalyticsProductIds(),
		},
		dataType: 'JSON',
		method  : 'POST',
	})
	.done(function(oResult)
	{
		if(oResult.success == true)
		{
			downloadAnalyticsCSV(oResult.data.sCSV, sFilename);
		}
		else
		{
			showAnalyticsError(oResult.data.sMessage);
		}
	})
	.fail(function()
	{
		showAnalyticsError(aTranslations.sLoadFailed);
	});
}

jQuery(document).ready(function()
{
	/**
	 * Keeps the "n of m selected" line and the year bounds on the date picker true.
	 *
	 * @returns {void}
	 */
	function refreshFilterState()
	{
		let oCount     = jQuery('.tpfw-products__count');
		let iSelected  = jQuery('.tpfw-product-checkbox:checked').length;
		let iTotal     = jQuery('.tpfw-product-checkbox').length;

		oCount.text(oCount.attr('data-count-template').replace('%1$s', iSelected).replace('%2$s', iTotal));

		let sYear = jQuery('#analytics-year').val();
		jQuery('#analytics-day').attr('min', sYear+'-01-01').attr('max', sYear+'-12-31');
	}

	/**
	 * Empties the hourly panel back to its starting state, used whenever the day it was showing
	 * stops being part of the selection.
	 *
	 * @returns {void}
	 */
	function resetDailyPanel()
	{
		if(oDailyChart) { oDailyChart.destroy(); oDailyChart = null; }
		sSelectedDay = '';
		jQuery('#apex-daily-chart').empty();
		jQuery('[data-day-label]').prop('hidden', true).text('');
		jQuery('[data-empty="day"]').prop('hidden', false);
		jQuery('[data-empty="day-none"]').prop('hidden', true);
		jQuery('[data-tpfw-export="day"]').prop('disabled', true);
	}

	/**
	 * Writes the three figures above the heatmap: the year's total, its busiest day, and how many
	 * days saw anyone at all.
	 *
	 * The server already sends every day of the year, so these are counted here rather than asked
	 * for separately.
	 *
	 * @param {Array<Array<{x: number, y: number}>>} aData Twelve day-arrays, January first.
	 * @returns {number} The year's total check-ins.
	 */
	function updateReadout(aData)
	{
		let iTotal      = 0;
		let iActiveDays = 0;
		let iPeakValue  = 0;
		let sPeakDate   = '';
		let sYear       = jQuery('#analytics-year').val();

		aData.forEach(function(aMonth, iMonthIndex)
		{
			aMonth.forEach(function(oDay)
			{
				iTotal += oDay.y;
				if(oDay.y > 0) { iActiveDays++; }
				if(oDay.y > iPeakValue)
				{
					iPeakValue = oDay.y;
					// Browser locale rather than the site's date format: this is a label inside a
					// figure, and the reader's own short date reads faster than a full one.
					sPeakDate  = new Date(parseInt(sYear), iMonthIndex, oDay.x)
						.toLocaleDateString(undefined, { day: 'numeric', month: 'long' });
				}
			});
		});

		jQuery('[data-readout="total"]').text(iTotal.toLocaleString());
		jQuery('[data-readout="active"]').text(iActiveDays.toLocaleString());

		// The date leads and its count follows in parentheses - the dt above already says what is
		// being counted, and parentheses need no translating.
		let oPeak = jQuery('[data-readout="peak"]').text(iPeakValue > 0 ? sPeakDate : '—');
		if(iPeakValue > 0)
		{
			oPeak.append(jQuery('<span class="tpfw-readout__sub"></span>').text('(' + iPeakValue.toLocaleString() + ')'));
		}

		return iTotal;
	}

	/**
	 * Splits the ramp across the year's own range, so a venue that counts in tens and one that
	 * counts in thousands both get a heatmap that uses every step.
	 *
	 * @param {number} iMax The busiest day's count.
	 * @returns {Array<{from: number, to: number, color: string}>} ApexCharts colour ranges.
	 */
	function buildHeatRanges(iMax)
	{
		let aRanges = [{ from: 0, to: 0, color: aHeatRamp[0] }];
		let iSteps  = aHeatRamp.length - 1;

		for(let i = 1; i <= iSteps; i++)
		{
			// Each band ends on its share of the busiest day, and the first one starts at 1 so a
			// single check-in never reads as an empty day.
			let iFrom = Math.floor((iMax / iSteps) * (i - 1)) + 1;
			let iTo   = (i === iSteps) ? iMax : Math.floor((iMax / iSteps) * i);
			if(iTo < iFrom) { continue; }
			aRanges.push({ from: iFrom, to: iTo, color: aHeatRamp[i] });
		}

		return aRanges;
	}

	/**
	 * Renders the year heatmap: one row per month, one cell per day of that month.
	 *
	 * Clicking a cell with a non-zero count drills into the hourly panel - the handler lives in the
	 * chart config because ApexCharts only exposes the clicked cell through its own event, not
	 * through the DOM.
	 *
	 * @param {Array<Array<{x: number, y: number}>>} aData Twelve day-arrays, January first.
	 * @returns {void}
	 */
	function insert_yearly_data(aData)
	{
		let iMax = 0;
		aData.forEach(function(aMonth) { aMonth.forEach(function(oDay) { if(oDay.y > iMax) { iMax = oDay.y; } }); });

		// ApexCharts draws the first series along the bottom, so the months go in reversed and
		// January ends up where a calendar puts it. The click handler undoes the reversal.
		let aSeries = aMonthNames.map(function(sName, iIndex)
		{
			return { name: sName, data: aData[iIndex] || [] };
		}).reverse();

		var options =
		{
			series: aSeries,
			chart:
			{
				height    : 430,
				type      : 'heatmap',
				fontFamily: 'inherit',
				toolbar   : { show: false },
				animations: { enabled: !bReducedMotion, speed: 220 },
				events    :
				{
					click(event, chartContext, config)
					{
						// ApexCharts also fires this for clicks on the axes and the legend, where
						// there is no data point at all - bail on anything incomplete rather
						// than reading through undefined.
						if(config == undefined) return;
						if(config.config == undefined) return;
						if(config.config.series == undefined) return;
						if(config.seriesIndex == undefined) return;
						if(config.dataPointIndex == undefined) return;
						if(config.config.series[config.seriesIndex] == undefined) return;

						let oPoint = config.config.series[config.seriesIndex].data[config.dataPointIndex];
						if(oPoint == undefined) return;
						// A day with no check-ins has nothing to drill into.
						if(oPoint.y <= 0) return;

						// Series are reversed for display, so December sits at index 0.
						let iMonth = aMonthNames.length - config.seriesIndex;

						loadDailyStats(jQuery('#analytics-year').val(), iMonth, oPoint.x);
					}
				}
			},
			plotOptions:
			{
				heatmap:
				{
					radius      : 3,
					enableShades: false,
					colorScale  : { ranges: buildHeatRanges(iMax) },
				},
			},
			dataLabels: { enabled: false },
			stroke    : { width: 2, colors: ['#fff'] },
			legend    : { show: false },
			grid      : { padding: { left: 4, right: 8, top: 0 } },
			xaxis     :
			{
				type      : 'category',
				tickAmount: 15,
				axisBorder: { show: false },
				axisTicks : { show: false },
				labels    : { style: { colors: '#6b7477', fontSize: '11px' } },
			},
			yaxis: { labels: { style: { colors: '#6b7477', fontSize: '12px' } } },
			tooltip:
			{
				y:
				{
					formatter: function(val) { return aTranslations['Checkin'] + ' ' + val; },
					title    : { formatter: function() { return ''; } }
				}
			}
		};

		oYearlyChart = new ApexCharts(document.querySelector('#apex-yearly-chart'), options);
		oYearlyChart.render();
	}

	/**
	 * Renders the hourly bar chart for one day, one series per product.
	 *
	 * @param {Array<{name: string, data: number[], color: string}>} aData One series per product,
	 *        each with 24 hourly counts. `color` is the product type's own QR foreground colour, so
	 *        a bar matches the colour that product is shown in everywhere else.
	 * @returns {void}
	 */
	function insert_daily_data(aData)
	{
		// Check-ins are whole people, so the axis is pinned to the day's own busiest hour and given
		// at most one tick per unit - ApexCharts otherwise offers to count them in halves.
		let iMax = 1;
		aData.forEach(function(oSeries) { oSeries.data.forEach(function(iCount) { if(iCount > iMax) { iMax = iCount; } }); });

		var options =
		{
			series: aData,
			// Falls back to the heatmap green for a product with no configured colour, rather
			// than letting ApexCharts pick from its own palette and clash with the rest.
			colors: aData.map(function(oSeries) { return oSeries.color || '#2e7d32'; }),
			chart :
			{
				type      : 'bar',
				height    : 320,
				fontFamily: 'inherit',
				toolbar   : { show: false },
				animations: { enabled: !bReducedMotion, speed: 220 },
			},
			plotOptions:
			{
				bar:
				{
					horizontal             : false,
					columnWidth            : '60%',
					borderRadius           : 3,
					borderRadiusApplication: 'end'
				},
			},
			dataLabels: { enabled: false },
			stroke    : { show: true, width: 2, colors: ['transparent'] },
			legend    : { position: 'bottom', markers: { radius: 3 }, fontSize: '12px' },
			grid      : { borderColor: '#e6e9ea', strokeDashArray: 3 },
			xaxis:
			{
				categories:
				[
					'00', '01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11',
					'12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '23',
				],
				axisBorder: { show: false },
				axisTicks : { show: false },
				labels    : { style: { colors: '#6b7477', fontSize: '11px' } },
			},
			yaxis:
			{
				min       : 0,
				max       : iMax,
				tickAmount: Math.min(iMax, 6),
				labels    :
				{
					formatter: function(iValue) { return String(Math.round(iValue)); },
					style    : { colors: '#6b7477', fontSize: '11px' },
				},
			},
			fill : { opacity: 1 },
			tooltip: { shared: true, intersect: false },
		};

		oDailyChart = new ApexCharts(document.querySelector('#apex-daily-chart'), options);
		oDailyChart.render();
	}

	/**
	 * Fetches and draws one day's hours, from either a heatmap cell or the date picker.
	 *
	 * @param {string|number} sYear  Four digit year.
	 * @param {number}        iMonth Month, 1-12.
	 * @param {number}        iDay   Day of month, 1-31.
	 * @returns {void}
	 */
	function loadDailyStats(sYear, iMonth, iDay)
	{
		let sISODate = sYear + '-' + String(iMonth).padStart(2, '0') + '-' + String(iDay).padStart(2, '0');

		clearAnalyticsError();
		setPanelBusy('.tpfw-panel--day', true);
		jQuery('.tpfw-panel--day .tpfw-empty').prop('hidden', true);

		jQuery.ajax({
			url     : ajaxurl,
			data    : {
				action    : 'tpfw_ajax_fetch_daily_checkin_stats',
				security  : tpfwParamsAnalyticsSingle.aNonces.ajax_fetch_daily_checkin_stats,
				sDateYear : sYear,
				sDateMonth: iMonth,
				sDateDay  : iDay,
				aProductID: getSelectedAnalyticsProductIds(),
			},
			dataType: 'JSON',
			method  : 'POST',
		})
		.done(function(oResult)
		{
			// Out of the loading state before rendering, not after: ApexCharts measures its
			// container, and a chart built inside the hidden one comes out empty.
			setPanelBusy('.tpfw-panel--day', false);

			if(oResult.success != true)
			{
				showAnalyticsError(oResult.data.sMessage);
				resetDailyPanel();
				return;
			}

			if(oDailyChart) { oDailyChart.destroy(); oDailyChart = null; }
			jQuery('#apex-daily-chart').empty();

			sSelectedDay = sISODate;
			jQuery('#analytics-day').val(sISODate);
			jQuery('[data-day-label]').text(oResult.data.sFulleDate).prop('hidden', false);
			jQuery('[data-tpfw-export="day"]').prop('disabled', false);

			if(oResult.data.aData.length === 0)
			{
				jQuery('[data-empty="day-none"]').prop('hidden', false);
				return;
			}

			insert_daily_data(oResult.data.aData);
		})
		.fail(function()
		{
			showAnalyticsError(aTranslations.sLoadFailed);
			resetDailyPanel();
		})
		.always(function()
		{
			setPanelBusy('.tpfw-panel--day', false);
		});
	}

	/**
	 * Fetches and draws the selected year for the selected products, and clears the hourly panel
	 * with it: it belongs to a day that may not even be in the new selection.
	 *
	 * @returns {void}
	 */
	function loadYearlyStats()
	{
		clearAnalyticsError();
		setPanelBusy('.tpfw-panel--year', true);
		jQuery('[data-empty="year"]').prop('hidden', true);
		resetDailyPanel();

		jQuery.ajax({
			url     : ajaxurl,
			data    : {
				action    : 'tpfw_ajax_fetch_yearly_checkin_stats',
				security  : tpfwParamsAnalyticsSingle.aNonces.ajax_fetch_yearly_checkin_stats,
				sStartDate: jQuery('#analytics-year').val()+'-01-01',
				// Inclusive end: the server widens it by a day itself, so next year's 1 January
				// used to be counted into this year's 1 January bucket.
				sEndDate  : jQuery('#analytics-year').val()+'-12-31',
				aProductID: getSelectedAnalyticsProductIds(),
			},
			dataType: 'JSON',
			method  : 'POST',
		})
		.done(function(oResult)
		{
			// Out of the loading state before rendering, not after: ApexCharts measures its
			// container, and a chart built inside the hidden one comes out empty.
			setPanelBusy('.tpfw-panel--year', false);

			if(oResult.success != true)
			{
				showAnalyticsError(oResult.data.sMessage);
				return;
			}

			if(oYearlyChart) { oYearlyChart.destroy(); oYearlyChart = null; }
			jQuery('#apex-yearly-chart').empty();

			if(updateReadout(oResult.data.aData) === 0)
			{
				jQuery('[data-empty="year"]').prop('hidden', false);
				return;
			}

			insert_yearly_data(oResult.data.aData);
		})
		.fail(function()
		{
			showAnalyticsError(aTranslations.sLoadFailed);
		})
		.always(function()
		{
			setPanelBusy('.tpfw-panel--year', false);
		});
	}

	if(jQuery('#apex-yearly-chart').length < 1) { return; }

	refreshFilterState();

	jQuery('.tpfw-filters').on('submit', function(event)
	{
		event.preventDefault();
		loadYearlyStats();
	});

	jQuery('#analytics-year').on('change', refreshFilterState);
	jQuery('#analytics-product').on('change', '.tpfw-product-checkbox', refreshFilterState);

	jQuery('[data-tpfw-select]').on('click', function()
	{
		jQuery('.tpfw-product-checkbox').prop('checked', jQuery(this).attr('data-tpfw-select') === 'all');
		refreshFilterState();
	});

	// The date picker is the keyboard route into a day: heatmap cells are SVG rectangles that
	// ApexCharts gives no focus or key handling of its own.
	jQuery('#analytics-day').on('change', function()
	{
		let aParts = jQuery(this).val().split('-');
		if(aParts.length !== 3) { return; }

		loadDailyStats(aParts[0], parseInt(aParts[1], 10), parseInt(aParts[2], 10));
	});

	jQuery('[data-tpfw-export="year"]').on('click', function()
	{
		let sYear = jQuery('#analytics-year').val();

		requestAnalyticsCSV(sYear+'-01-01', (parseInt(sYear)+1)+'-01-01', sYear+'-'+aTranslations.Checkin+'.csv');
	});

	jQuery('[data-tpfw-export="day"]').on('click', function()
	{
		if(sSelectedDay === '') { return; }

		requestAnalyticsCSV(sSelectedDay, sSelectedDay, sSelectedDay+'-'+aTranslations.Checkin+'.csv');
	});

	// Load the current year straight away rather than showing an empty screen until the button is
	// pressed. Deferred a tick so ApexCharts has finished registering itself before we render.
	setTimeout(loadYearlyStats, 100);
});
