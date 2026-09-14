<?php

declare(strict_types=1);

namespace Endroid\QrCode\Encoding;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

interface EncodingInterface
{
    public function __toString(): string;
}
