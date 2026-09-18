<?php
/**
 * Worker: one production dashboard-nano or check-in call that may wait on GET_LOCK.
 *
 * Looks the ticket up with deleted IS NULL (REST/AJAX lookup), publishes wait-ready,
 * then runs exactly one business call. No sleep(). No retry.
 *
 * Usage:
 *   php dashboard-nano-wait-worker.php <op> <prefix> <nano> <user> <timeout> [newUserId]
 *
 * op: checkin | checkin-pause-insert | cancel | reset | transfer
 */
if($argc < 6)
{
	fwrite(STDERR, "usage\n");
	exit(2);
}

require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/lib/TicketFunctionsHarness.php';
require_once dirname(__DIR__).'/lib/PauseBeforeStatsWpdb.php';

$sOp      = (string)$argv[1];
$sPrefix  = (string)$argv[2];
$sNano    = (string)$argv[3];
$iUser    = (int)$argv[4];
$iTimeout = (int)$argv[5];
$iNewUser = isset($argv[6]) ? (int)$argv[6] : 42;

$mysqli = TPFW_Test_Credentials::mysqli();
if(!$mysqli)
{
	fwrite(STDERR, "no db\n");
	exit(3);
}

$wpdb = new TPFW_Test_Wpdb($mysqli, $sPrefix);
$GLOBALS['wpdb'] = $wpdb;
$oFn = new TPFW_Test_Ticket_Functions();

$oRow = $wpdb->get_row($wpdb->prepare(
	'SELECT * FROM %i WHERE nano_id = %s AND deleted IS NULL',
	$wpdb->prefix.'tpfw_tickets',
	$sNano
));

$wpdb->query($wpdb->prepare(
	'INSERT INTO %i (k, v) VALUES (%s, %s) ON DUPLICATE KEY UPDATE v = %s',
	$wpdb->prefix.'tpfw_kv',
	'wait-ready-'.$sOp.'-'.$sNano,
	'1',
	'1'
));

if($sOp === 'checkin')
{
	if(!$oRow)
	{
		echo json_encode(array('bMissing' => true))."\n";
		exit(0);
	}
	$m = $oFn->checkin_ticket($wpdb, $oRow, $iUser, false, $iTimeout);
	if($m instanceof WP_REST_Response)
	{
		echo json_encode(array(
			'bResponse' => true,
			'iStatus'   => $m->get_status(),
			'aData'     => $m->get_data(),
		))."\n";
		exit(0);
	}
	echo json_encode(array('bResponse' => false, 'aData' => $m))."\n";
	exit(0);
}

if($sOp === 'checkin-pause-insert')
{
	if(!$oRow)
	{
		echo json_encode(array('bMissing' => true))."\n";
		exit(0);
	}
	$oPause = new TPFW_Pause_Before_Stats_Wpdb($wpdb, $sNano);
	$m = $oFn->checkin_ticket($oPause, $oRow, $iUser, false, $iTimeout);
	if($m instanceof WP_REST_Response)
	{
		echo json_encode(array(
			'bResponse' => true,
			'iStatus'   => $m->get_status(),
			'aData'     => $m->get_data(),
		))."\n";
		exit(0);
	}
	echo json_encode(array('bResponse' => false, 'aData' => $m))."\n";
	exit(0);
}

if($sOp === 'cancel')
{
	echo json_encode($oFn->cancel_ticket($sNano, $iTimeout))."\n";
	exit(0);
}

if($sOp === 'reset')
{
	echo json_encode($oFn->reset_ticket($sNano, $iTimeout))."\n";
	exit(0);
}

if($sOp === 'transfer')
{
	echo json_encode(TPFW_Ticket_Line::transfer_nano($wpdb, $sNano, $iNewUser, $iTimeout))."\n";
	exit(0);
}

fwrite(STDERR, "unknown op\n");
exit(2);
