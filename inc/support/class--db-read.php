<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * WordPress $wpdb read results: false is an error, and so is []/null together with last_error.
 *
 * get_results()/get_col() return an empty array on a SQL error; get_row()/get_var() return null.
 * A legitimate empty result has last_error === ''. Call these immediately after the read,
 * before the next query overwrites last_error.
 */
class TPFW_Db_Read
{
	/**
	 * @param object $wpdb
	 * @return string
	 */
	public static function error_text($wpdb)
	{
		if(!is_object($wpdb) || !isset($wpdb->last_error))
		{
			return '';
		}
		return (string)$wpdb->last_error;
	}

	/**
	 * @param object $wpdb
	 * @param mixed  $mResults get_results()/get_col() return value.
	 * @return bool
	 */
	public static function results_failed($wpdb, $mResults)
	{
		$sErr = self::error_text($wpdb);
		if($mResults === false || !is_array($mResults))
		{
			return true;
		}
		return $mResults === array() && $sErr !== '';
	}

	/**
	 * @param object $wpdb
	 * @param mixed  $mRow get_row() return value.
	 * @return bool
	 */
	public static function row_failed($wpdb, $mRow)
	{
		return $mRow === false || ($mRow === null && self::error_text($wpdb) !== '');
	}

	/**
	 * @param object $wpdb
	 * @param mixed  $mVar get_var() return value.
	 * @return bool
	 */
	public static function var_failed($wpdb, $mVar)
	{
		return $mVar === false || ($mVar === null && self::error_text($wpdb) !== '');
	}
}
