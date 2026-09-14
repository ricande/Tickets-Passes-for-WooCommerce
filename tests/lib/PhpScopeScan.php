<?php
/**
 * Token walk of first-party PHP: assigned locals, closure use-lists, upsert table args.
 *
 * The ticket-issue miss was `$sTicketTable` imported into a closure and passed to
 * TPFW_Order_Line_Upsert::sync without ever being assigned, which became INSERT INTO ``.
 */
class TPFW_Php_Scope_Scan
{
	/**
	 * First-party plugin PHP, excluding bundled libraries and this test tree.
	 *
	 * @return list<string>
	 */
	public static function first_party_files()
	{
		$aOut  = array();
		$sRoot = TPFW_PLUGIN_DIR;
		$oIt   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sRoot, FilesystemIterator::SKIP_DOTS));
		foreach($oIt as $oFile)
		{
			if(!$oFile->isFile() || strtolower($oFile->getExtension()) !== 'php')
			{
				continue;
			}
			$sPath = $oFile->getPathname();
			$sRel  = substr($sPath, strlen($sRoot));
			if(strpos($sRel, 'tests/') === 0)
			{
				continue;
			}
			if(strpos($sRel, 'inc/functions/lib/') === 0 || strpos($sRel, 'lib/') === 0)
			{
				continue;
			}
			$aOut[] = $sPath;
		}
		sort($aOut);
		return $aOut;
	}

	/**
	 * @param string $sSrc
	 * @param string $sLabel File name used in problem strings.
	 * @return list<string>
	 */
	public static function problems($sSrc, $sLabel)
	{
		$aTokens = token_get_all($sSrc);
		$aProblems = array();
		self::scan_range($aTokens, 0, count($aTokens), self::new_scope('<file>'), $aProblems, $sLabel);
		return $aProblems;
	}

	/**
	 * @return array{name:string,assigned:array<string,true>,prefix:array<string,true>}
	 */
	private static function new_scope($sName)
	{
		return array(
			'name'     => $sName,
			'assigned' => array(),
			'prefix'   => array(),
		);
	}

	/**
	 * @param array $aTokens
	 * @param int   $iFrom
	 * @param int   $iTo      Exclusive.
	 * @param array $aScope
	 * @param list<string> $aProblems
	 * @param string $sLabel
	 * @return void
	 */
	private static function scan_range($aTokens, $iFrom, $iTo, $aScope, &$aProblems, $sLabel)
	{
		$i = $iFrom;
		while($i < $iTo)
		{
			$mTok = $aTokens[$i];
			if(!is_array($mTok))
			{
				$i++;
				continue;
			}

			$iId = $mTok[0];
			if($iId === T_FUNCTION || $iId === T_FN)
			{
				$i = self::enter_function($aTokens, $i, $iTo, $aScope, $aProblems, $sLabel);
				continue;
			}

			if($iId === T_GLOBAL)
			{
				$i++;
				while($i < $iTo)
				{
					$m = $aTokens[$i];
					if($m === ';')
					{
						break;
					}
					if(is_array($m) && $m[0] === T_VARIABLE)
					{
						$aScope['assigned'][$m[1]] = true;
					}
					$i++;
				}
				continue;
			}

			if($iId === T_FOREACH)
			{
				$i = self::mark_foreach_vars($aTokens, $i, $iTo, $aScope);
				continue;
			}

			if($iId === T_CATCH)
			{
				$i = self::mark_catch_var($aTokens, $i, $iTo, $aScope);
				continue;
			}

			if($iId === T_VARIABLE && self::next_significant($aTokens, $i + 1, $iTo) === '=')
			{
				$sVar = $mTok[1];
				$aScope['assigned'][$sVar] = true;
				if(self::rhs_uses_wpdb_prefix($aTokens, $i + 1, $iTo))
				{
					$aScope['prefix'][$sVar] = true;
				}
				$i++;
				continue;
			}

			if($iId === T_STRING && $mTok[1] === 'TPFW_Order_Line_Upsert')
			{
				self::check_upsert_table($aTokens, $i, $iTo, $aScope, $aProblems, $sLabel);
			}

			$i++;
		}
	}

	/**
	 * @return int Index after the function we just scanned.
	 */
	private static function enter_function($aTokens, $iFn, $iTo, $aParent, &$aProblems, $sLabel)
	{
		$i = $iFn + 1;
		$sName = '{closure}';
		while($i < $iTo)
		{
			$m = $aTokens[$i];
			if(is_array($m) && $m[0] === T_STRING)
			{
				$sName = $m[1];
				$i++;
				break;
			}
			if($m === '(')
			{
				break;
			}
			$i++;
		}

		$aChild = self::new_scope($aParent['name'].'::'.$sName);
		$iOpen  = self::index_of($aTokens, $i, $iTo, '(');
		if($iOpen === null)
		{
			return $iFn + 1;
		}
		$iClose = self::matching_paren($aTokens, $iOpen, $iTo);
		if($iClose === null)
		{
			return $iFn + 1;
		}
		foreach(self::variables_in($aTokens, $iOpen + 1, $iClose) as $sVar)
		{
			$aChild['assigned'][$sVar] = true;
		}

		$iAfter = self::skip_insignificant($aTokens, $iClose + 1, $iTo);
		if($iAfter < $iTo && is_array($aTokens[$iAfter]) && $aTokens[$iAfter][0] === T_USE)
		{
			$iUseOpen = self::index_of($aTokens, $iAfter, $iTo, '(');
			if($iUseOpen !== null)
			{
				$iUseClose = self::matching_paren($aTokens, $iUseOpen, $iTo);
				if($iUseClose !== null)
				{
					$iLine = is_array($aTokens[$iAfter]) ? (int)$aTokens[$iAfter][2] : 0;
					foreach(self::variables_in($aTokens, $iUseOpen + 1, $iUseClose) as $sVar)
					{
						if($sVar === '$this')
						{
							continue;
						}
						if(empty($aParent['assigned'][$sVar]))
						{
							$aProblems[] = $sLabel.':'.$iLine.' '.$aParent['name'].' closure use '.$sVar.' is never assigned';
						}
						$aChild['assigned'][$sVar] = true;
						if(!empty($aParent['prefix'][$sVar]))
						{
							$aChild['prefix'][$sVar] = true;
						}
					}
					$iAfter = $iUseClose + 1;
				}
			}
		}

		$iBrace = self::index_of($aTokens, $iAfter, $iTo, '{');
		if($iBrace === null)
		{
			return $iClose + 1;
		}
		$iEnd = self::matching_brace($aTokens, $iBrace, $iTo);
		if($iEnd === null)
		{
			return $iClose + 1;
		}
		self::scan_range($aTokens, $iBrace + 1, $iEnd, $aChild, $aProblems, $sLabel);
		return $iEnd + 1;
	}

	/**
	 * @return void
	 */
	private static function check_upsert_table($aTokens, $iAt, $iTo, $aScope, &$aProblems, $sLabel)
	{
		$i = self::skip_insignificant($aTokens, $iAt + 1, $iTo);
		if($i >= $iTo || !is_array($aTokens[$i]) || $aTokens[$i][0] !== T_DOUBLE_COLON)
		{
			return;
		}
		$i = self::skip_insignificant($aTokens, $i + 1, $iTo);
		if($i >= $iTo || !is_array($aTokens[$i]) || $aTokens[$i][1] !== 'sync')
		{
			return;
		}
		$iOpen = self::index_of($aTokens, $i, $iTo, '(');
		if($iOpen === null)
		{
			return;
		}
		$iComma = self::next_top_level_comma($aTokens, $iOpen + 1, $iTo);
		if($iComma === null)
		{
			return;
		}
		$iArg = self::skip_insignificant($aTokens, $iComma + 1, $iTo);
		if($iArg >= $iTo || !is_array($aTokens[$iArg]) || $aTokens[$iArg][0] !== T_VARIABLE)
		{
			return;
		}
		$sVar  = $aTokens[$iArg][1];
		$iLine = (int)$aTokens[$iArg][2];
		if(empty($aScope['assigned'][$sVar]))
		{
			$aProblems[] = $sLabel.':'.$iLine.' '.$aScope['name'].' upsert table '.$sVar.' is never assigned';
			return;
		}
		if(empty($aScope['prefix'][$sVar]))
		{
			$aProblems[] = $sLabel.':'.$iLine.' '.$aScope['name'].' upsert table '.$sVar.' is not assigned from $wpdb->prefix';
		}
	}

	/**
	 * @return int
	 */
	private static function mark_foreach_vars($aTokens, $iAt, $iTo, &$aScope)
	{
		$iOpen = self::index_of($aTokens, $iAt, $iTo, '(');
		if($iOpen === null)
		{
			return $iAt + 1;
		}
		$iClose = self::matching_paren($aTokens, $iOpen, $iTo);
		if($iClose === null)
		{
			return $iAt + 1;
		}
		$iAs = $iOpen;
		while($iAs < $iClose)
		{
			if(is_array($aTokens[$iAs]) && $aTokens[$iAs][0] === T_AS)
			{
				foreach(self::variables_in($aTokens, $iAs + 1, $iClose) as $sVar)
				{
					$aScope['assigned'][$sVar] = true;
				}
				break;
			}
			$iAs++;
		}
		return $iClose + 1;
	}

	/**
	 * @return int
	 */
	private static function mark_catch_var($aTokens, $iAt, $iTo, &$aScope)
	{
		$iOpen = self::index_of($aTokens, $iAt, $iTo, '(');
		if($iOpen === null)
		{
			return $iAt + 1;
		}
		$iClose = self::matching_paren($aTokens, $iOpen, $iTo);
		if($iClose === null)
		{
			return $iAt + 1;
		}
		$aVars = self::variables_in($aTokens, $iOpen + 1, $iClose);
		if($aVars)
		{
			$aScope['assigned'][end($aVars)] = true;
		}
		return $iClose + 1;
	}

	/**
	 * @return bool
	 */
	private static function rhs_uses_wpdb_prefix($aTokens, $iFrom, $iTo)
	{
		$iEnd   = $iFrom;
		$iParen = 0;
		while($iEnd < $iTo)
		{
			$m = $aTokens[$iEnd];
			if($m === '(')
			{
				$iParen++;
			}
			elseif($m === ')')
			{
				$iParen--;
			}
			elseif($m === ';' && $iParen <= 0)
			{
				break;
			}
			$iEnd++;
		}
		$bWpdb = false;
		$bPref = false;
		for($i = $iFrom; $i < $iEnd; $i++)
		{
			$m = $aTokens[$i];
			if(!is_array($m))
			{
				continue;
			}
			if($m[0] === T_VARIABLE && $m[1] === '$wpdb')
			{
				$bWpdb = true;
			}
			if($m[0] === T_STRING && $m[1] === 'prefix')
			{
				$bPref = true;
			}
		}
		return $bWpdb && $bPref;
	}

	/**
	 * @return list<string>
	 */
	private static function variables_in($aTokens, $iFrom, $iTo)
	{
		$a = array();
		for($i = $iFrom; $i < $iTo; $i++)
		{
			$m = $aTokens[$i];
			if(is_array($m) && $m[0] === T_VARIABLE)
			{
				$a[] = $m[1];
			}
		}
		return $a;
	}

	/**
	 * @return int|null
	 */
	private static function next_top_level_comma($aTokens, $iFrom, $iTo)
	{
		$iDepth = 0;
		for($i = $iFrom; $i < $iTo; $i++)
		{
			$m = $aTokens[$i];
			if($m === '(')
			{
				$iDepth++;
			}
			elseif($m === ')')
			{
				if($iDepth === 0)
				{
					return null;
				}
				$iDepth--;
			}
			elseif($m === ',' && $iDepth === 0)
			{
				return $i;
			}
		}
		return null;
	}

	/**
	 * @return mixed|null
	 */
	private static function next_significant($aTokens, $iFrom, $iTo)
	{
		$i = self::skip_insignificant($aTokens, $iFrom, $iTo);
		if($i >= $iTo)
		{
			return null;
		}
		$m = $aTokens[$i];
		return is_array($m) ? $m[1] : $m;
	}

	/**
	 * @return int
	 */
	private static function skip_insignificant($aTokens, $iFrom, $iTo)
	{
		$i = $iFrom;
		while($i < $iTo)
		{
			$m = $aTokens[$i];
			if(is_array($m) && in_array($m[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true))
			{
				$i++;
				continue;
			}
			break;
		}
		return $i;
	}

	/**
	 * @return int|null
	 */
	private static function index_of($aTokens, $iFrom, $iTo, $sNeedle)
	{
		for($i = $iFrom; $i < $iTo; $i++)
		{
			if($aTokens[$i] === $sNeedle)
			{
				return $i;
			}
		}
		return null;
	}

	/**
	 * @return int|null
	 */
	private static function matching_paren($aTokens, $iOpen, $iTo)
	{
		return self::matching($aTokens, $iOpen, $iTo, '(', ')');
	}

	/**
	 * @return int|null
	 */
	private static function matching_brace($aTokens, $iOpen, $iTo)
	{
		return self::matching($aTokens, $iOpen, $iTo, '{', '}');
	}

	/**
	 * @return int|null
	 */
	private static function matching($aTokens, $iOpen, $iTo, $sOpen, $sClose)
	{
		$iDepth = 0;
		for($i = $iOpen; $i < $iTo; $i++)
		{
			if($aTokens[$i] === $sOpen)
			{
				$iDepth++;
			}
			elseif($aTokens[$i] === $sClose)
			{
				$iDepth--;
				if($iDepth === 0)
				{
					return $i;
				}
			}
		}
		return null;
	}
}
