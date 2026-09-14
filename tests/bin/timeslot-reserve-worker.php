<?php
/**
 * Worker: try to insert a timeslot reservation of quantity 1 under the capacity lock.
 *
 * Usage: php timeslot-reserve-worker.php <timeslot_id> <prefix>
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

$wpdb         = new TPFW_Test_Wpdb($mysqli, $argv[2]);
$sTimeslotID  = $argv[1];
$sNow         = gmdate('Y-m-d H:i:s');
$sValidTo     = gmdate('Y-m-d H:i:s', time() + 600);

$m = TPFW_Timeslot_Capacity::with_lock($wpdb, $sTimeslotID, function() use ($wpdb, $sTimeslotID, $sNow, $sValidTo) {
	$oSlot = $wpdb->get_row($wpdb->prepare('SELECT id, available_slots FROM %i WHERE id = %s', $wpdb->prefix.'tpfw_timeslots', $sTimeslotID));
	if(!$oSlot)
	{
		return 'missing';
	}
	$iLeft = TPFW_Timeslot_Capacity::remaining($wpdb, $sTimeslotID, (int)$oSlot->available_slots, $sNow, true);
	if($iLeft < 1)
	{
		return 'full';
	}
	$sRes = bin2hex(random_bytes(8));
	$wpdb->query($wpdb->prepare(
		'INSERT INTO %i (timeslot_id, reservation_id, product_id, quantity, user_id, valid_from, valid_to, created, updated)
		VALUES (%s, %s, %d, %d, %d, %s, %s, %s, %s)',
		array($wpdb->prefix.'tpfw_timeslot_reservations', $sTimeslotID, $sRes, 1, 1, 1, $sNow, $sValidTo, $sNow, $sNow)
	));
	return 'ok';
});

echo ($m === 'ok' ? 'ok' : 'no')."\n";
