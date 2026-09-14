<?php

namespace FontLib\Exception;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class FontNotFoundException extends \Exception
{
    public function __construct($fontPath)
    {
        $this->message = 'Font not found in: ' . $fontPath;
    }
}