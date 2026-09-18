<?php
use PHPUnit\Framework\TestCase;

require_once TPFW_TEST_ROOT.'/lib/TicketFunctionsHarness.php';

if(!function_exists('add_filter'))
{
	function add_filter($sHook, $mCb, $iPrio = 10, $iArgs = 1)
	{
		unset($sHook, $mCb, $iPrio, $iArgs);
	}
}
if(!function_exists('wp_next_scheduled'))
{
	function wp_next_scheduled($sHook)
	{
		unset($sHook);
		return time();
	}
}
if(!function_exists('wp_schedule_event'))
{
	function wp_schedule_event($iTime, $sRecurrence, $sHook)
	{
		unset($iTime, $sRecurrence, $sHook);
		return true;
	}
}
if(!class_exists('TPFW_Product_Timeslot_Ticket'))
{
	class TPFW_Product_Timeslot_Ticket extends WC_Product
	{
	}
}

require_once TPFW_PLUGIN_DIR.'inc/cronjobs/class--cronjobs.php';

class TPFW_Test_Cron_Item
{
	public $id;
	public $productId;
	public $meta = array();

	public function get_id()
	{
		return $this->id;
	}

	public function get_product_id()
	{
		return $this->productId;
	}

	public function get_meta($sKey, $bSingle = true)
	{
		unset($bSingle);
		return $this->meta[$sKey] ?? null;
	}
}

class TPFW_Test_Cron_Order
{
	public $status = 'pending';
	public $items = array();
	public $removed = array();
	public $iTotals = 0;
	public $iSaved = 0;

	public function get_status()
	{
		return $this->status;
	}

	public function get_items()
	{
		return $this->items;
	}

	public function remove_item($iId)
	{
		$this->removed[] = (int)$iId;
		unset($this->items[(int)$iId]);
	}

	public function calculate_totals()
	{
		$this->iTotals++;
	}

	public function save()
	{
		$this->iSaved++;
		return $this;
	}
}

class ExpiredReservationCronTest extends TestCase
{
	private ?TPFW_Test_Wpdb $wpdb = null;

	private TPFW_Cronjobs $cron;

	protected function setUp(): void
	{
		$mysqli = TPFW_Test_Credentials::mysqli();
		if(!$mysqli)
		{
			$this->markTestSkipped('No database credentials');
		}
		$this->wpdb = new TPFW_Test_Wpdb($mysqli, 'tpfwercn_');
		TPFW_Test_Schema::install($this->wpdb);
		$GLOBALS['tpfw_test_orders'] = array();
		$GLOBALS['tpfw_test_products'] = array();
		$GLOBALS['tpfw_test_post_meta'] = array();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->cron = new TPFW_Cronjobs(new TPFW_Test_Ticket_Functions());
	}

	protected function tearDown(): void
	{
		$GLOBALS['tpfw_test_orders'] = array();
		$GLOBALS['tpfw_test_products'] = array();
		$GLOBALS['tpfw_test_post_meta'] = array();
		unset($GLOBALS['wpdb']);
		if($this->wpdb)
		{
			TPFW_Test_Schema::drop($this->wpdb);
			$this->wpdb->mysqli()->close();
		}
	}

	public function test_source_uses_get_results_not_query_foreach(): void
	{
		$sSrc = file_get_contents(TPFW_PLUGIN_DIR.'inc/cronjobs/class--cronjobs.php');
		$iFn  = strpos($sSrc, 'function tpfw_minut_delete_expired_reservations_function');
		$this->assertNotFalse($iFn);
		$sBody = substr($sSrc, $iFn, 3500);
		$this->assertNotFalse(strpos($sBody, 'get_results('));
		$this->assertFalse((bool)preg_match('/\$wpdb->query\(\$sUpdateReservationSQL\);\s*\$oOrderCreatedReservationResult/', $sBody));
		$this->assertNotFalse(strpos($sBody, 'TPFW_Db_Read::results_failed'));
	}

	public function test_expired_reservation_with_pending_order_releases_and_removes_line(): void
	{
		$sRes = 'res'.bin2hex(random_bytes(6));
		$oOrder = $this->attachOrder(8801, 'pending', 77, 12, $sRes);
		$this->insertReservation($sRes, 8801, 77, true);
		$this->cron->tpfw_minut_delete_expired_reservations_function();
		$this->assertNotNull($this->deletedAt($sRes));
		$this->assertSame(array(77), $oOrder->removed);
		$this->assertSame(1, $oOrder->iTotals);
		$this->assertSame(1, $oOrder->iSaved);
		$this->assertSame(array(), $oOrder->get_items());
	}

	public function test_expired_reservation_without_order_is_released(): void
	{
		$sRes = 'resn'.bin2hex(random_bytes(5));
		$this->insertReservation($sRes, null, null, false);
		$this->cron->tpfw_minut_delete_expired_reservations_function();
		$this->assertNotNull($this->deletedAt($sRes));
	}

	public function test_completed_order_reservation_is_preserved(): void
	{
		$sRes = 'resc'.bin2hex(random_bytes(5));
		$oOrder = $this->attachOrder(8802, 'completed', 78, 12, $sRes);
		$this->insertReservation($sRes, 8802, 78, true);
		$this->cron->tpfw_minut_delete_expired_reservations_function();
		$this->assertNull($this->deletedAt($sRes));
		$this->assertSame(array(), $oOrder->removed);
		$this->assertSame(0, $oOrder->iTotals);
		$this->assertCount(1, $oOrder->get_items());
	}

	public function test_order_linked_read_error_does_not_touch_those_rows(): void
	{
		$sWith = 'rese'.bin2hex(random_bytes(5));
		$sNone = 'resx'.bin2hex(random_bytes(5));
		$oOrder = $this->attachOrder(8803, 'pending', 79, 12, $sWith);
		$this->insertReservation($sWith, 8803, 79, true);
		$this->insertReservation($sNone, null, null, false);
		$oFail = new TPFW_Failing_Wpdb($this->wpdb, static function($sSql) {
			return (bool)preg_match('/^\s*SELECT/i', $sSql)
				&& str_contains($sSql, 'tpfw_timeslot_reservations')
				&& str_contains($sSql, 'order_id IS NOT NULL');
		});
		$GLOBALS['wpdb'] = $oFail;
		$aWarn = array();
		set_error_handler(static function($iErrno, $sErr) use (&$aWarn) {
			$aWarn[] = $sErr;
			return true;
		});
		$this->cron->tpfw_minut_delete_expired_reservations_function();
		restore_error_handler();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->assertSame(array(), array_values(array_filter($aWarn, static function($s) {
			return str_contains($s, 'foreach') || str_contains($s, 'int given');
		})));
		$this->assertNull($this->deletedAt($sWith));
		$this->assertNotNull($this->deletedAt($sNone));
		$this->assertSame(array(), $oOrder->removed);
	}

	/**
	 * @param string   $sRes
	 * @param int|null $iOrder
	 * @param int|null $iLine
	 * @param bool     $bWithOrder
	 * @return void
	 */
	private function insertReservation($sRes, $iOrder, $iLine, $bWithOrder)
	{
		$sPast = gmdate('Y-m-d H:i:s', time() - 120);
		if($bWithOrder)
		{
			$this->wpdb->query($this->wpdb->prepare(
				'INSERT INTO %i (timeslot_id, reservation_id, product_id, quantity, user_id, order_id, order_line_id, valid_from, valid_to, created, updated)
				VALUES (%s, %s, %d, %d, %d, %d, %d, %s, %s, %s, %s)',
				array($this->wpdb->prefix.'tpfw_timeslot_reservations', 'slotcron', $sRes, 12, 1, 1, $iOrder, $iLine, $sPast, $sPast, $sPast, $sPast)
			));
			return;
		}
		$this->wpdb->query($this->wpdb->prepare(
			'INSERT INTO %i (timeslot_id, reservation_id, product_id, quantity, user_id, valid_from, valid_to, created, updated)
			VALUES (%s, %s, %d, %d, %d, %s, %s, %s, %s)',
			array($this->wpdb->prefix.'tpfw_timeslot_reservations', 'slotcron', $sRes, 12, 1, 1, $sPast, $sPast, $sPast, $sPast)
		));
	}

	/**
	 * @param string $sRes
	 * @return string|null
	 */
	private function deletedAt($sRes)
	{
		$m = $this->wpdb->get_var($this->wpdb->prepare(
			'SELECT deleted FROM %i WHERE reservation_id = %s',
			$this->wpdb->prefix.'tpfw_timeslot_reservations',
			$sRes
		));
		return ($m === null || $m === '') ? null : (string)$m;
	}

	/**
	 * @param int    $iOrder
	 * @param string $sStatus
	 * @param int    $iLine
	 * @param int    $iProduct
	 * @param string $sRes
	 * @return TPFW_Test_Cron_Order
	 */
	private function attachOrder($iOrder, $sStatus, $iLine, $iProduct, $sRes)
	{
		$oItem = new TPFW_Test_Cron_Item();
		$oItem->id = $iLine;
		$oItem->productId = $iProduct;
		$oItem->meta['tpfw_reservation_id'] = $sRes;
		$oOrder = new TPFW_Test_Cron_Order();
		$oOrder->status = $sStatus;
		$oOrder->items = array($iLine => $oItem);
		$GLOBALS['tpfw_test_orders'][$iOrder] = $oOrder;
		$GLOBALS['tpfw_test_products'][$iProduct] = new TPFW_Product_Timeslot_Ticket();
		return $oOrder;
	}
}
