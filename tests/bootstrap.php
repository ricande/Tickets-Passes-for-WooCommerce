<?php
/**
 * PHPUnit bootstrap. No wp-load.php, no root, no writing to /var/www.
 *
 * WordPress functions used by the support classes are stubbed. Database tests open MariaDB
 * through credentials loaded inside PHP (never on the argv).
 */
define('ABSPATH', sys_get_temp_dir().'/tpfw-tests/');
define('TPFW_PLUGIN_DIR', dirname(__DIR__).'/');
define('TPFW_TEST_ROOT', __DIR__);

if(!function_exists('__'))
{
	function __($sText, $sDomain = '')
	{
		return $sText;
	}
}
if(!function_exists('current_time'))
{
	function current_time($sType)
	{
		return $sType === 'mysql' ? gmdate('Y-m-d H:i:s') : time();
	}
}
if(!function_exists('esc_html'))
{
	function esc_html($s)
	{
		return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
	}
}

require_once TPFW_PLUGIN_DIR.'inc/support/load.php';
require_once __DIR__.'/lib/Credentials.php';
require_once __DIR__.'/lib/TestWpdb.php';
require_once __DIR__.'/lib/FailingWpdb.php';
require_once __DIR__.'/lib/TestSchema.php';
require_once __DIR__.'/lib/PhpScopeScan.php';
require_once __DIR__.'/lib/QrGenerate.php';
