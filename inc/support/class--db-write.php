<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * WordPress $wpdb write results: false is an error; 0 is “nothing changed”, not a failure.
 */
class TPFW_Db_Write
{
	/**
	 * @param mixed $mResult $wpdb->query()/insert/update/delete return value.
	 * @return bool
	 */
	public static function failed($mResult)
	{
		return $mResult === false;
	}

	/**
	 * @param mixed $mResult
	 * @return bool
	 */
	public static function succeeded($mResult)
	{
		return $mResult !== false;
	}

	/**
	 * INSERT that must create exactly one row. 0 affected rows is not success.
	 *
	 * @param mixed $mResult
	 * @return bool
	 */
	public static function inserted_row($mResult)
	{
		return $mResult !== false && (int)$mResult > 0;
	}
}
