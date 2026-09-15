<?php
/**
 * Worker: issue tickets for one order line under TPFW_Issue_Lock.
 *
 * Usage: php issue-ticket-worker.php <product_id> <user_id> <order_id> <line_id> <qty> <prefix>
 */
if($argc < 7)
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

$wpdb        = new TPFW_Test_Wpdb($mysqli, $argv[6]);
$iProductId  = (int)$argv[1];
$iUserId     = (int)$argv[2];
$iOrderId    = (int)$argv[3];
$iLineId     = (int)$argv[4];
$iQty        = (int)$argv[5];
$sTable      = $wpdb->prefix.'tpfw_tickets';
$sNow        = gmdate('Y-m-d H:i:s');

$sSelect = $wpdb->prepare(
	'SELECT * FROM %i WHERE product_id = %d AND order_id = %d AND order_line_id = %d ORDER BY (deleted IS NULL) DESC, id ASC',
	$sTable,
	$iProductId,
	$iOrderId,
	$iLineId
);

$aSync = TPFW_Issue_Lock::sync_line($wpdb, 'ticket', $sTable, $sSelect, $iLineId, $iQty, $sNow, function() use ($wpdb, $sTable, $iProductId, $iUserId, $iOrderId, $iLineId, $sNow) {
	$sNano = 't'.bin2hex(random_bytes(8));
	$wpdb->query($wpdb->prepare(
		'INSERT INTO %i (nano_id, product_id, user_id, order_id, order_line_id, valid_duration, max_uses, created, updated)
		VALUES (%s, %d, %d, %d, %d, %d, %d, %s, %s)',
		array($sTable, $sNano, $iProductId, $iUserId, $iOrderId, $iLineId, 86400, 1, $sNow, $sNow)
	));
	return $sNano;
});

echo ($aSync === null ? 'lock' : 'ok')."\n";
