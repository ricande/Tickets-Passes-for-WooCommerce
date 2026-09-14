<?php
use PHPUnit\Framework\TestCase;

class ImageLimitsTest extends TestCase
{
	public function test_huge_claimed_dimensions_rejected(): void
	{
		$this->assertFalse(TPFW_Image_Limits::from_getimagesize(array(10000, 10000, 'mime' => 'image/jpeg')));
		$this->assertFalse(TPFW_Image_Limits::dimensions_allowed(8001, 10));
		$this->assertFalse(TPFW_Image_Limits::dimensions_allowed(5000, 5000));
	}

	public function test_normal_jpeg_header_accepted(): void
	{
		$this->assertTrue(TPFW_Image_Limits::dimensions_allowed(800, 600));
		$this->assertTrue(TPFW_Image_Limits::from_getimagesize(array(1920, 1080)));
	}

	public function test_zero_or_missing_header_rejected(): void
	{
		$this->assertFalse(TPFW_Image_Limits::from_getimagesize(false));
		$this->assertFalse(TPFW_Image_Limits::dimensions_allowed(0, 100));
	}
}
