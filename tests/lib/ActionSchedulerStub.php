<?php
/**
 * Test stand-in for WooCommerce Action Scheduler. Lets tests hit TPFW_Qr_Rewrite::schedule()
 * without going through $fnSchedule.
 */
class TPFW_As_Action_Stub
{
	/** @var int|false */
	public static $mEnqueue = 1;

	/** @var int */
	public static $iCalls = 0;

	/** @var array<int,array{0:string,1:array,2:string}> */
	public static $aCalls = array();

	/**
	 * @return void
	 */
	public static function reset()
	{
		self::$mEnqueue = 1;
		self::$iCalls   = 0;
		self::$aCalls   = array();
	}
}

if(!function_exists('as_enqueue_async_action'))
{
	/**
	 * @param string $sHook
	 * @param array  $aArgs
	 * @param string $sGroup
	 * @return int
	 */
	function as_enqueue_async_action($sHook, $aArgs = array(), $sGroup = '')
	{
		TPFW_As_Action_Stub::$iCalls++;
		TPFW_As_Action_Stub::$aCalls[] = array((string) $sHook, (array) $aArgs, (string) $sGroup);
		return TPFW_As_Action_Stub::$mEnqueue;
	}
}
