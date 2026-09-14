<?php

declare(strict_types=1);

namespace Endroid\QrCode\Writer\Result;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Endroid\QrCode\Matrix\MatrixInterface;

interface ResultInterface
{
    public function getMatrix(): MatrixInterface;

    public function getString(): string;

    public function getDataUri(): string;

    public function saveToFile(string $path): void;

    public function getMimeType(): string;
}
