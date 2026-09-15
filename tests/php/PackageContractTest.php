<?php
use PHPUnit\Framework\TestCase;

class PackageContractTest extends TestCase
{
	public function test_run_sh_fetches_pinned_phpunit(): void
	{
		$sRun = file_get_contents(TPFW_PLUGIN_DIR.'tests/run.sh');
		$sEns = file_get_contents(TPFW_PLUGIN_DIR.'tests/ensure-phpunit.sh');
		$this->assertNotFalse(strpos($sRun, 'ensure-phpunit.sh'));
		$this->assertNotFalse(strpos($sEns, 'VERSION="11.5.42"'));
		$this->assertNotFalse(strpos($sEns, '894c651ee0fd38533649e92756022aa46a093d94c78652a4615aae23c846db60'));
		$this->assertNotFalse(strpos($sEns, 'https://phar.phpunit.de/phpunit-${VERSION}.phar'));
		$sIgnore = file_get_contents(TPFW_PLUGIN_DIR.'.gitignore');
		$this->assertNotFalse(strpos($sIgnore, 'tests/phpunit.phar'));
	}

	public function test_tests_do_not_read_deploy_or_host_trees(): void
	{
		$aHits = array();
		foreach($this->phpAndShUnder(TPFW_PLUGIN_DIR.'tests') as $sFile)
		{
			$s = file_get_contents($sFile);
			if($s === false)
			{
				continue;
			}
			$sDeploy = '..'.'/deploy';
			$sWww    = '/var'.'/www/';
			$sHome   = '/home'.'/ricande/';
			if(str_contains($s, $sDeploy) || str_contains($s, $sWww) || str_contains($s, $sHome))
			{
				$aHits[] = substr($sFile, strlen(TPFW_PLUGIN_DIR));
			}
		}
		$this->assertSame(array(), $aHits, implode("\n", $aHits));
	}

	public function test_production_zip_layout_and_excludes(): void
	{
		$sZip = $this->buildZip();
		$a = $this->zipNames($sZip);
		$this->assertNotSame(array(), $a);
		$sTop = 'tickets-passes-for-woocommerce/';
		foreach($a as $sName)
		{
			$this->assertTrue($sName === rtrim($sTop, '/') || str_starts_with($sName, $sTop), $sName);
		}

		$this->assertContains($sTop.'tickets-passes-for-woocommerce.php', $a);
		$this->assertContains($sTop.'readme.txt', $a);
		$this->assertContains($sTop.'changelog.txt', $a);
		$this->assertContains($sTop.'uninstall.php', $a);
		$this->assertContains($sTop.'docs/server-config/nginx-deny-tpfw-uploads.conf', $a);
		$this->assertContains($sTop.'languages/tickets-passes-for-woocommerce.pot', $a);
		$this->assertContains($sTop.'languages/tickets-passes-for-woocommerce-sv_SE.l10n.php', $a);
		$this->assertContains($sTop.'languages/tickets-passes-for-woocommerce-da_DK.l10n.php', $a);
		$this->assertTrue($this->zipHasPrefix($a, $sTop.'inc/functions/lib/'));
		$this->assertTrue($this->zipHasPrefix($a, $sTop.'lib/air-datepicker/'));

		foreach($a as $sName)
		{
			$this->assertStringNotContainsString('.wp-credentials', $sName);
			$this->assertStringNotContainsString('/.git/', $sName);
			$this->assertFalse(str_starts_with($sName, $sTop.'tests/'), $sName);
			$this->assertFalse(str_starts_with($sName, $sTop.'scripts/'), $sName);
			$this->assertFalse(str_starts_with($sName, $sTop.'vendor/'), $sName);
			$this->assertStringNotContainsString('issue-ticket-worker.php', $sName);
			$this->assertStringNotContainsString('issue-pass-worker.php', $sName);
			$this->assertStringNotContainsString('phpunit.phar', $sName);
			$this->assertStringNotContainsString('phpunit.xml', $sName);
			$this->assertNotSame($sTop.'composer.json', $sName);
			$this->assertNotSame($sTop.'package.json', $sName);
			$this->assertNotSame($sTop.'README.md', $sName);
			$this->assertStringNotContainsString('docs/work-plan.md', $sName);
			$this->assertStringNotContainsString('docs/release.md', $sName);
		}
	}

	public function test_production_zip_first_party_php_parses(): void
	{
		$sZip = $this->buildZip();
		$sDir = sys_get_temp_dir().'/tpfw-zip-syntax-'.bin2hex(random_bytes(4));
		mkdir($sDir);
		$oZip = new ZipArchive();
		$this->assertTrue($oZip->open($sZip) === true);
		$oZip->extractTo($sDir);
		$oZip->close();

		$aFail = array();
		$sRoot = $sDir.'/tickets-passes-for-woocommerce';
		foreach($this->phpAndShUnder($sRoot) as $sFile)
		{
			if(substr($sFile, -4) !== '.php')
			{
				continue;
			}
			if(str_contains($sFile, '/inc/functions/lib/') || str_contains($sFile, '/lib/'))
			{
				continue;
			}
			$sOut = array();
			$iCode = 0;
			exec('php -l '.escapeshellarg($sFile).' 2>&1', $sOut, $iCode);
			if($iCode !== 0)
			{
				$aFail[] = substr($sFile, strlen($sRoot) + 1).': '.implode(' ', $sOut);
			}
		}
		$this->assertSame(array(), $aFail, implode("\n", $aFail));
	}

	public function test_version_headers_match(): void
	{
		$sMain = file_get_contents(TPFW_PLUGIN_DIR.'tickets-passes-for-woocommerce.php');
		$sRead = file_get_contents(TPFW_PLUGIN_DIR.'readme.txt');
		$this->assertMatchesRegularExpression('/^\s*\*\s*Version:\s*1\.3\.0\s*$/m', $sMain);
		$this->assertNotFalse(strpos($sMain, "define('TPFW_VERSION', '1.3.0')"));
		$this->assertNotFalse(strpos($sRead, 'Stable tag: 1.3.0'));
	}

	/**
	 * @return string
	 */
	private function buildZip()
	{
		static $sBuilt = null;
		if(is_string($sBuilt) && is_file($sBuilt))
		{
			return $sBuilt;
		}
		$sOut = sys_get_temp_dir().'/tpfw-pkg-'.bin2hex(random_bytes(4)).'.zip';
		$sCmd = 'TPFW_ZIP_OUT='.escapeshellarg($sOut).' bash '.escapeshellarg(TPFW_PLUGIN_DIR.'scripts/build-plugin-zip.sh');
		$aOut = array();
		$iCode = 0;
		exec($sCmd.' 2>&1', $aOut, $iCode);
		$this->assertSame(0, $iCode, implode("\n", $aOut));
		$this->assertFileExists($sOut);
		$sBuilt = $sOut;
		return $sBuilt;
	}

	/**
	 * @param string $sZip
	 * @return string[]
	 */
	private function zipNames($sZip)
	{
		$o = new ZipArchive();
		$this->assertTrue($o->open($sZip) === true);
		$a = array();
		for($i = 0; $i < $o->numFiles; $i++)
		{
			$a[] = $o->getNameIndex($i);
		}
		$o->close();
		return $a;
	}

	/**
	 * @param string[] $a
	 * @param string   $sPrefix
	 * @return bool
	 */
	private function zipHasPrefix(array $a, $sPrefix)
	{
		foreach($a as $s)
		{
			if(str_starts_with($s, $sPrefix))
			{
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $sDir
	 * @return string[]
	 */
	private function phpAndShUnder($sDir)
	{
		$a = array();
		$o = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sDir, FilesystemIterator::SKIP_DOTS));
		foreach($o as $oFile)
		{
			if(!$oFile->isFile())
			{
				continue;
			}
			$s = $oFile->getPathname();
			if(str_contains($s, '/.git/'))
			{
				continue;
			}
			$sExt = strtolower(pathinfo($s, PATHINFO_EXTENSION));
			if(in_array($sExt, array('php', 'sh'), true))
			{
				$a[] = $s;
			}
		}
		return $a;
	}
}
