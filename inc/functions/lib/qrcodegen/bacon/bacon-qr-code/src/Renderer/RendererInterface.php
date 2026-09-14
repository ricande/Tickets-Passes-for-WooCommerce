<?php
declare(strict_types = 1);

namespace BaconQrCode\Renderer;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use BaconQrCode\Encoder\QrCode;

interface RendererInterface
{
    public function render(QrCode $qrCode) : string;
}
