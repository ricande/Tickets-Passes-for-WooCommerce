<?php

declare(strict_types=1);

namespace Endroid\QrCode\Label\Font;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

interface FontInterface
{
    public function getPath(): string;

    public function getSize(): int;
}
