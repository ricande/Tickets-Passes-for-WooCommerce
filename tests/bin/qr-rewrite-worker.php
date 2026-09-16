<?php
/**
 * Worker: mutate QR rewrite jobs under GET_LOCK with a shared kv row.
 *
 * Usage:
 *   php qr-rewrite-worker.php <prefix> enqueue <product_id> <type> <target>
 *   php qr-rewrite-worker.php <prefix> batch <product_id> <type> <generation> [batch_size]
 *   php qr-rewrite-worker.php <prefix> tmp <dest> <payload>
 */
if($argc < 3)
{
	fwrite(STDERR, "usage\n");
	exit(2);
}

define('ABSPATH', sys_get_temp_dir().'/tpfw-tests/');
define('TPFW_PLUGIN_DIR', dirname(__DIR__, 2).'/');
define('TPFW_TEST_ROOT', dirname(__DIR__));

require_once TPFW_PLUGIN_DIR.'inc/support/load.php';
require_once TPFW_TEST_ROOT.'/lib/Credentials.php';
require_once TPFW_TEST_ROOT.'/lib/TestWpdb.php';

$mysqli = TPFW_Test_Credentials::mysqli();
if(!$mysqli)
{
	fwrite(STDERR, "no db\n");
	exit(3);
}

$sPrefix = (string) $argv[1];
$sCmd    = (string) $argv[2];
$wpdb    = new TPFW_Test_Wpdb($mysqli, $sPrefix);

TPFW_Qr_Rewrite::reset_test_state();
TPFW_Qr_Rewrite::$wpdbOverride = $wpdb;
TPFW_Qr_Rewrite::$bKvStore     = true;
TPFW_Qr_Rewrite::$fnSchedule   = function() {
	return true;
};
TPFW_Qr_Rewrite::$fnSetIssuedKey = function() {};

if($sCmd === 'enqueue')
{
	if($argc < 6)
	{
		fwrite(STDERR, "usage enqueue\n");
		exit(2);
	}
	$aJob = TPFW_Qr_Rewrite::enqueue((int) $argv[3], (string) $argv[4], (string) $argv[5]);
	echo ($aJob === null ? 'fail' : 'ok')."\n";
	exit($aJob === null ? 1 : 0);
}

if($sCmd === 'batch')
{
	if($argc < 6)
	{
		fwrite(STDERR, "usage batch\n");
		exit(2);
	}
	if(isset($argv[6]) && (int) $argv[6] > 0)
	{
		TPFW_Qr_Rewrite::$iBatchSizeOverride = (int) $argv[6];
	}
	$a = TPFW_Qr_Rewrite::process_batch(
		(int) $argv[3],
		(string) $argv[4],
		(int) $argv[5],
		$wpdb,
		function() {
			return true;
		}
	);
	echo json_encode($a)."\n";
	exit(!empty($a['ok']) ? 0 : 1);
}

if($sCmd === 'tmp')
{
	if($argc < 5)
	{
		fwrite(STDERR, "usage tmp\n");
		exit(2);
	}
	$sDest = (string) $argv[3];
	$sTmp  = TPFW_Qr_Rewrite::unique_temp_path($sDest);
	file_put_contents($sTmp, (string) $argv[4]);
	echo $sTmp."\n";
	exit(0);
}

fwrite(STDERR, "unknown command\n");
exit(2);
