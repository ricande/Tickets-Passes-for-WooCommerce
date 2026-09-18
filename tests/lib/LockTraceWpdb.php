<?php
/**
 * Test-only $wpdb that records GET_LOCK / RELEASE_LOCK and the live lock depth.
 *
 * Support-only. Production still talks to the real connection; this wrapper
 * exists so ticket operations can prove they never nest named locks.
 */
class TPFW_Lock_Trace_Wpdb
{
	/** @var string */
	public $prefix;

	/** @var int */
	public $insert_id = 0;

	/** @var string */
	public $last_error = '';

	/** @var int */
	public $iDepth = 0;

	/** @var int */
	public $iMaxDepth = 0;

	/** @var list<array{0:string,1:string,2:mixed}> */
	public $aEvents = array();

	/** @var list<string> */
	public $aAcquired = array();

	/** @var TPFW_Test_Wpdb */
	private $inner;

	/**
	 * @param TPFW_Test_Wpdb $inner
	 */
	public function __construct($inner)
	{
		$this->inner  = $inner;
		$this->prefix = $inner->prefix;
	}

	/**
	 * @return TPFW_Test_Wpdb
	 */
	public function inner()
	{
		return $this->inner;
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
		$sSql = (string)$sql;
		$m = $this->inner->get_var($sSql);
		$this->last_error = $this->inner->last_error;
		$this->insert_id = $this->inner->insert_id;
		if(preg_match("/GET_LOCK\\('([^']+)'/", $sSql, $aM))
		{
			$this->aEvents[] = array('get', $aM[1], $m);
			if(TPFW_Named_Lock::is_acquired($m))
			{
				$this->iDepth++;
				if($this->iDepth > $this->iMaxDepth)
				{
					$this->iMaxDepth = $this->iDepth;
				}
				$this->aAcquired[] = $aM[1];
			}
		}
		if(preg_match("/RELEASE_LOCK\\('([^']+)'/", $sSql, $aM))
		{
			$this->aEvents[] = array('release', $aM[1], $m);
			if($this->iDepth > 0)
			{
				$this->iDepth--;
			}
		}
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
	 * @param string $sql
	 * @return array
	 */
	public function get_col($sql)
	{
		$m = $this->inner->get_col($sql);
		$this->last_error = $this->inner->last_error;
		$this->insert_id = $this->inner->insert_id;
		return $m;
	}
}
