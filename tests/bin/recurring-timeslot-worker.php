<?php
/**
 * Worker: generate children for one recurring series under the per-series lock.
 *
 * Usage: php recurring-timeslot-worker.php <prefix> <series_id> <timeout>
 */
if($argc < 4)
{
	fwrite(STDERR, "usage\n");
	exit(2);
}

require_once dirname(__DIR__).'/bootstrap.php';
require_once TPFW_TEST_ROOT.'/lib/TicketFunctionsHarness.php';

$mysqli = TPFW_Test_Credentials::mysqli();
if(!$mysqli)
{
	fwrite(STDERR, "no db\n");
	exit(3);
}

$wpdb    = new TPFW_Test_Wpdb($mysqli, (string)$argv[1]);
$sSeries = (string)$argv[2];
$iTimeout = (int)$argv[3];
$GLOBALS['wpdb'] = $wpdb;
$GLOBALS['tpfw_test_post_meta'] = array();

$oSeries = $wpdb->get_row($wpdb->prepare(
	'SELECT * FROM %i WHERE id = %s',
	$wpdb->prefix.'tpfw_timeslots_recurring',
	$sSeries
));
if(!$oSeries)
{
	echo "missing\n";
	exit(0);
}
$GLOBALS['tpfw_test_post_meta'][(int)$oSeries->product_id] = array(
	'_tpfw_timeslot_ticket_recurring_enable' => 'yes',
	'_tpfw_timeslot_ticket_recurring_future' => '2',
);
$oFn = new TPFW_Test_Ticket_Functions();
$oFn->create_recurring_timeslots($sSeries, $iTimeout);
echo "ok\n";
