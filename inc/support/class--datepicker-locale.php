<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Datepicker labels from WordPress's locale object, not this plugin's text domain.
 */
class TPFW_Datepicker_Locale
{
	/**
	 * @param object $oLocale WP_Locale (or a test double with the same properties).
	 * @param string $sToday
	 * @param string $sClear
	 * @return array
	 */
	public static function from_wp_locale($oLocale, $sToday = 'Today', $sClear = 'Clear')
	{
		return array(
			'days'        => array_values((array)$oLocale->weekday),
			'daysShort'   => array_values((array)$oLocale->weekday_abbrev),
			'daysMin'     => array_values((array)$oLocale->weekday_initial),
			'months'      => array_values((array)$oLocale->month),
			'monthsShort' => array_values((array)$oLocale->month_abbrev),
			'today'       => $sToday,
			'clear'       => $sClear,
		);
	}
}
