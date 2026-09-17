<?php
/**
 * Worker: one production Ticket_Line cancel/reconcile that may wait on GET_LOCK.
 *
 * Signals readiness through tpfw_kv, then takes tpfw_ticket_issue_{line}.
 * Prints one JSON result line when finished. No sleep().
 *
 * Usage:
 *   php ticket-line-wait-worker.php <op> <prefix> <line> <order> <user> <product> <qty> <refunded> <status> <timeout>
 *
 * op: cancel | reconcile | reconcile-kv
 * reconcile-kv reads order status/refund from tpfw_kv after the line lock is held.
 */
if($argc < 11)
{
	fwrite(STDERR, "usage\n");
	exit(2);
}

require_once dirname(__DIR__).'/bootstrap.php';

$sOp        = (string)$argv[1];
$sPrefix    = (string)$argv[2];
$iLine      = (int)$argv[3];
$iOrder     = (int)$argv[4];
$iUser      = (int)$argv[5];
$iProduct   = (int)$argv[6];
$iQty       = (int)$argv[7];
$iRefunded  = (int)$argv[8];
$sStatus    = (string)$argv[9];
$iTimeout   = (int)$argv[10];

$mysqli = TPFW_Test_Credentials::mysqli();
if(!$mysqli)
{
	fwrite(STDERR, "no db\n");
	exit(3);
}

$wpdb  = new TPFW_Test_Wpdb($mysqli, $sPrefix);
$oItem = new TPFW_Test_Refund_Item($iLine, $iQty, $iRefunded);
$oItem->productId = $iProduct;
$oOrder = new TPFW_Test_Refund_Order();
$oOrder->status = $sStatus;
$oOrder->refunded = array($iLine => $iRefunded);
$GLOBALS['tpfw_test_orders'] = array($iOrder => $oOrder);

$wpdb->query($wpdb->prepare(
	'INSERT INTO %i (k, v) VALUES (%s, %s) ON DUPLICATE KEY UPDATE v = %s',
	$wpdb->prefix.'tpfw_kv',
	'wait-ready-'.$iLine,
	'1',
	'1'
));

if($sOp === 'cancel')
{
	$a = TPFW_Ticket_Line::cancel($wpdb, $oItem, $iOrder, null, $iTimeout);
}
elseif($sOp === 'reconcile')
{
	$a = TPFW_Ticket_Line::reconcile($wpdb, $oItem, $iOrder, null, null, $iTimeout);
}
elseif($sOp === 'reconcile-kv')
{
	$a = TPFW_Ticket_Line::with_lock($wpdb, $iLine, function() use ($wpdb, $oItem, $iOrder, $iLine) {
		$sJson = $wpdb->get_var($wpdb->prepare(
			'SELECT v FROM %i WHERE k = %s',
			$wpdb->prefix.'tpfw_kv',
			'order-'.$iOrder
		));
		$aState = json_decode((string)$sJson, true);
		if(!is_array($aState))
		{
			return array(
				'sMessage' => 'missing order kv',
				'bStatus'  => false,
				'sync'     => null,
			);
		}
		$oOrder = new TPFW_Test_Refund_Order();
		$oOrder->status = (string)$aState['status'];
		$oOrder->refunded = array($iLine => (int)$aState['refunded']);
		$GLOBALS['tpfw_test_orders'][$iOrder] = $oOrder;
		$oItem->qtyRefunded = (int)$aState['refunded'];
		return TPFW_Ticket_Line::reconcile_held($wpdb, $oItem, $iOrder);
	}, $iTimeout);
	if($a === null)
	{
		$a = TPFW_Ticket_Line::lock_failed_reconcile();
	}
}
else
{
	fwrite(STDERR, "unknown op\n");
	exit(2);
}

echo json_encode($a)."\n";
