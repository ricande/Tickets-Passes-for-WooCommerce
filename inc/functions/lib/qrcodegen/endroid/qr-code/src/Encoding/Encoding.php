<?php

declare(strict_types=1);

namespace Endroid\QrCode\Encoding;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

final class Encoding implements EncodingInterface
{
    public function __construct(
        private string $value
    ) {
        if (!in_array($value, mb_list_encodings())) {
            throw new \Exception(sprintf('Invalid encoding "%s"', $value));
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
