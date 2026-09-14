<?php

declare(strict_types=1);

namespace Endroid\QrCode\Matrix;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Endroid\QrCode\QrCodeInterface;

interface MatrixFactoryInterface
{
    public function create(QrCodeInterface $qrCode): MatrixInterface;
}
