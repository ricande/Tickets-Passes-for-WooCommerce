<?php
/**
 * $wpdb stand-in that returns 0 from query() when the injected predicate matches.
 */
class TPFW_Zero_Write_Wpdb
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
	private $fnZero;

	/**
	 * @param TPFW_Test_Wpdb $inner
	 * @param callable       $fnZero function(string $sql): bool
	 */
	public function __construct($inner, $fnZero)
	{
		$this->inner  = $inner;
		$this->prefix = $inner->prefix;
		$this->fnZero = $fnZero;
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
		if(call_user_func($this->fnZero, (string)$sql))
		{
			$this->last_error = '';
			return 0;
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
}
