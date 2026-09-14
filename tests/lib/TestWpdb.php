<?php
/**
 * Minimal wpdb stand-in for tests: %s / %d / %i and the methods the support classes use.
 */
class TPFW_Test_Wpdb
{
	/** @var string */
	public $prefix;

	/** @var mysqli */
	private $mysqli;

	/** @var int */
	public $insert_id = 0;

	/** @var string */
	public $last_error = '';

	/**
	 * @param mysqli $mysqli
	 * @param string $sPrefix
	 */
	public function __construct($mysqli, $sPrefix = 'tpfwtest_')
	{
		$this->mysqli = $mysqli;
		$this->prefix = $sPrefix;
	}

	/**
	 * @return mysqli
	 */
	public function mysqli()
	{
		return $this->mysqli;
	}

	/**
	 * @param string $query
	 * @param mixed  $args
	 * @return string
	 */
	public function prepare($query, $args = null)
	{
		if($args === null)
		{
			return $query;
		}
		if(!is_array($args) || func_num_args() > 2)
		{
			$args = array_slice(func_get_args(), 1);
		}
		if(count($args) === 1 && is_array($args[0]))
		{
			$args = array_values($args[0]);
		}
		else
		{
			$args = array_values($args);
		}

		$i = 0;
		$mysqli = $this->mysqli;
		return preg_replace_callback('/%[%sdi]/', function($m) use (&$i, $args, $mysqli) {
			if($m[0] === '%%')
			{
				return '%';
			}
			if(!array_key_exists($i, $args))
			{
				throw new InvalidArgumentException('TPFW_Test_Wpdb::prepare not enough arguments');
			}
			$v = $args[$i++];
			if($m[0] === '%d')
			{
				return (string)(int)$v;
			}
			if($m[0] === '%i')
			{
				$sIdent = (string)$v;
				if($sIdent === '')
				{
					throw new InvalidArgumentException('TPFW_Test_Wpdb::prepare empty %i identifier');
				}
				return '`'.str_replace('`', '``', $sIdent).'`';
			}
			return "'".$mysqli->real_escape_string((string)$v)."'";
		}, $query);
	}

	/**
	 * @param string $sql
	 * @return mixed
	 */
	public function query($sql)
	{
		$m = $this->mysqli->query($sql);
		if($m === false)
		{
			$this->last_error = $this->mysqli->error;
			return false;
		}
		$this->insert_id = (int)$this->mysqli->insert_id;
		if($m instanceof mysqli_result)
		{
			$i = $m->num_rows;
			$m->free();
			return $i;
		}
		return $this->mysqli->affected_rows;
	}

	/**
	 * @param string $sql
	 * @return string|null
	 */
	public function get_var($sql)
	{
		$m = $this->mysqli->query($sql);
		if($m === false)
		{
			$this->last_error = $this->mysqli->error;
			return null;
		}
		$row = $m->fetch_row();
		$m->free();
		if($row === null)
		{
			return null;
		}
		return $row[0];
	}

	/**
	 * @param string $sql
	 * @return object|null
	 */
	public function get_row($sql)
	{
		$m = $this->mysqli->query($sql);
		if($m === false)
		{
			$this->last_error = $this->mysqli->error;
			return null;
		}
		$row = $m->fetch_object();
		$m->free();
		return $row ?: null;
	}

	/**
	 * @param string $sql
	 * @return array
	 */
	public function get_results($sql)
	{
		$m = $this->mysqli->query($sql);
		if($m === false)
		{
			$this->last_error = $this->mysqli->error;
			return array();
		}
		$a = array();
		while($row = $m->fetch_object())
		{
			$a[] = $row;
		}
		$m->free();
		return $a;
	}
}
