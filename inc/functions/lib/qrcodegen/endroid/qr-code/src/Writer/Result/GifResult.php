<?php

declare(strict_types=1);

namespace Endroid\QrCode\Writer\Result;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

final class GifResult extends GdResult
{
    public function getString(): string
    {
        ob_start();
        imagegif($this->image);

        return strval(ob_get_clean());
    }

    public function getMimeType(): string
    {
        return 'image/gif';
    }
}
