<?php
/**
 * Worker: mint guest passes against a parent. Credentials are loaded in PHP, not argv.
 *
 * Usage: php guest-quota-worker.php <parent_nano> <quota> <prefix>
 */
if($argc < 4)
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

$wpdb   = new TPFW_Test_Wpdb($mysqli, $argv[3]);
$parent = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE nano_id = %s LIMIT 1', $wpdb->prefix.'tpfw_pass', $argv[1]));
if(!$parent)
{
	fwrite(STDERR, "no parent\n");
	exit(4);
}

$issuer = new TPFW_Guest_Pass_Issuer();
$issuer->ensure_quota($wpdb, $parent, (int)$argv[2], 86400, 3);
echo "ok\n";
