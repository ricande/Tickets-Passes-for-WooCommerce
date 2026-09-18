<?php
/**
 * Test-only $wpdb stand-in: pauses immediately before a tickets_stats INSERT.
 *
 * Support-only. The production check-in path still runs; this wrapper is the
 * deterministic barrier so another connection can run cancel/reconcile after
 * the authoritative re-read and before the stats write.
 */
class TPFW_Pause_Before_Stats_Wpdb
{
	/** @var string */
	public $prefix;

	/** @var int */
	public $insert_id = 0;

	/** @var string */
	public $last_error = '';

	/** @var TPFW_Test_Wpdb */
	private $inner;

	/** @var string */
	private $sNano;

	/**
	 * @param TPFW_Test_Wpdb $inner
	 * @param string         $sNano
	 */
	public function __construct($inner, $sNano)
	{
		$this->inner  = $inner;
		$this->prefix = $inner->prefix;
		$this->sNano  = (string)$sNano;
	}

	/**
	 * @param string $query
	 * @param mixed  $args
	 * @return string
	 */
	public function prepare($query, $args = null)
	{
		return call_user_func_array(array($this->inner, 'prepare'), func_get_args());
	}

	/**
	 * @param string $sql
	 * @return mixed
	 */
	public function query($sql)
	{
		if($this->is_stats_insert((string)$sql))
		{
			$this->publish('wait-ready-pause-insert-'.$this->sNano);
			$this->waitFor('wait-go-pause-insert-'.$this->sNano);
		}
		$m = $this->inner->query($sql);
		$this->insert_id = $this->inner->insert_id;
		$this->last_error = $this->inner->last_error;
		return $m;
	}

	/**
	 * @param string $sql
	 * @return string|null
	 */
	public function get_var($sql)
	{
		$m = $this->inner->get_var($sql);
		$this->last_error = $this->inner->last_error;
		$this->insert_id = $this->inner->insert_id;
		return $m;
	}

	/**
	 * @param string $sql
	 * @return object|null
	 */
	public function get_row($sql)
	{
		$m = $this->inner->get_row($sql);
		$this->last_error = $this->inner->last_error;
		$this->insert_id = $this->inner->insert_id;
		return $m;
	}

	/**
	 * @param string $sql
	 * @return array
	 */
	public function get_results($sql)
	{
		$m = $this->inner->get_results($sql);
		$this->last_error = $this->inner->last_error;
		$this->insert_id = $this->inner->insert_id;
		return $m;
	}

	/**
	 * @param string $sSql
	 * @return bool
	 */
	private function is_stats_insert($sSql)
	{
		return stripos($sSql, 'INSERT') !== false
			&& strpos($sSql, 'tpfw_tickets_stats') !== false
			&& strpos($sSql, $this->sNano) !== false;
	}

	/**
	 * @param string $sKey
	 * @return void
	 */
	private function publish($sKey)
	{
		$this->inner->query($this->inner->prepare(
			'INSERT INTO %i (k, v) VALUES (%s, %s) ON DUPLICATE KEY UPDATE v = %s',
			$this->inner->prefix.'tpfw_kv',
			$sKey,
			'1',
			'1'
		));
	}

	/**
	 * @param string $sKey
	 * @return void
	 */
	private function waitFor($sKey)
	{
		$tEnd = microtime(true) + 10;
		do
		{
			$m = $this->inner->get_var($this->inner->prepare(
				'SELECT v FROM %i WHERE k = %s',
				$this->inner->prefix.'tpfw_kv',
				$sKey
			));
			if($m !== null && $m !== '')
			{
				return;
			}
		}
		while(microtime(true) < $tEnd);
		throw new RuntimeException('pause-before-stats did not see '.$sKey);
	}
}
