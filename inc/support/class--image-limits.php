<?php
defined('ABSPATH') or die('No script kiddies please!');

/**
 * Pixel caps applied to profile-image uploads before decode.
 *
 * A tiny file can still claim tens of thousands of pixels in its header. Decoding that is
 * what exhausts memory, so the header is refused first.
 */
class TPFW_Image_Limits
{
	const MAX_SIDE   = 8000;
	const MAX_PIXELS = 20000000;

	/**
	 * @param int $iWidth
	 * @param int $iHeight
	 * @return bool
	 */
	public static function dimensions_allowed($iWidth, $iHeight)
	{
		$iWidth  = (int)$iWidth;
		$iHeight = (int)$iHeight;
		if($iWidth < 1 || $iHeight < 1)
		{
			return false;
		}
		if($iWidth > self::MAX_SIDE || $iHeight > self::MAX_SIDE)
		{
			return false;
		}
		if(($iWidth * $iHeight) > self::MAX_PIXELS)
		{
			return false;
		}
		return true;
	}

	/**
	 * @param array|false $aSize getimagesize() return value.
	 * @return bool
	 */
	public static function from_getimagesize($aSize)
	{
		if(!is_array($aSize) || !isset($aSize[0], $aSize[1]))
		{
			return false;
		}
		return self::dimensions_allowed((int)$aSize[0], (int)$aSize[1]);
	}

	/**
	 * Caps Imagick resource use so a lying header cannot allocate a huge buffer.
	 *
	 * @param object $im Imagick instance.
	 * @return void
	 */
	public static function apply_imagick_limits($im)
	{
		if(!is_object($im) || !class_exists('Imagick'))
		{
			return;
		}
		if(defined('Imagick::RESOURCETYPE_WIDTH'))
		{
			$im->setResourceLimit(\Imagick::RESOURCETYPE_WIDTH, self::MAX_SIDE);
		}
		if(defined('Imagick::RESOURCETYPE_HEIGHT'))
		{
			$im->setResourceLimit(\Imagick::RESOURCETYPE_HEIGHT, self::MAX_SIDE);
		}
		if(defined('Imagick::RESOURCETYPE_AREA'))
		{
			$im->setResourceLimit(\Imagick::RESOURCETYPE_AREA, self::MAX_PIXELS);
		}
		if(defined('Imagick::RESOURCETYPE_MEMORY'))
		{
			$im->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 64 * 1024 * 1024);
		}
	}
}
