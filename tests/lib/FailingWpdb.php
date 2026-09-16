<?php
/**
 * $wpdb stand-in that returns false from query() when the injected predicate matches.
 */
class TPFW_Failing_Wpdb
{
	/** @var string */
	public $prefix;

	/** @var int */
	public $insert_id = 0;

	/** @var string */
	public $last_error = '';

	/** @var TPFW_Test_Wpdb */
	private $inner;

	/** @var callable */
	private $fnFail;

	/**
	 * @param TPFW_Test_Wpdb $inner
	 * @param callable       $fnFail function(string $sql): bool
	 */
	public function __construct($inner, $fnFail)
	{
		$this->inner  = $inner;
		$this->prefix = $inner->prefix;
		$this->fnFail = $fnFail;
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
		if(call_user_func($this->fnFail, (string)$sql))
		{
			$this->last_error = 'injected failure';
			return false;
		}
		$m = $this->inner->query($sql);
		$this->insert_id = $this->inner->insert_id;
		return $m;
	}

	/**
	 * @param string $sql
	 * @return string|null
	 */
	public function get_var($sql)
	{
		if(call_user_func($this->fnFail, (string)$sql))
		{
			$this->last_error = 'injected failure';
			return str_contains((string)$sql, 'GET_LOCK') ? '0' : null;
		}
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
		return $this->inner->get_row($sql);
	}

	/**
	 * @param string $sql
	 * @return array
	 */
	public function get_col($sql)
	{
		return $this->inner->get_col($sql);
	}

	/**
	 * @param string $sql
	 * @return array
	 */
	public function get_results($sql)
	{
		if(call_user_func($this->fnFail, (string)$sql))
		{
			$this->last_error = 'injected failure';
			return false;
		}
		$m = $this->inner->get_results($sql);
		$this->last_error = $this->inner->last_error;
		$this->insert_id = $this->inner->insert_id;
		return $m;
	}
}
