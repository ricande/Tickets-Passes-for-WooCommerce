<?php
declare(strict_types = 1);

namespace BaconQrCode\Renderer\Path;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

interface OperationInterface
{
    /**
     * Translates the operation's coordinates.
     */
    public function translate(float $x, float $y) : self;
}
