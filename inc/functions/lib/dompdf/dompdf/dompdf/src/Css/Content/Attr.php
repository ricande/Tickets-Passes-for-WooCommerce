<?php
namespace Dompdf\Css\Content;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

final class Attr extends ContentPart
{
    /**
     * @var string
     */
    public $attribute;

    public function __construct(string $attribute)
    {
        $this->attribute = $attribute;
    }

    public function equals(ContentPart $other): bool
    {
        return $other instanceof self
            && $other->attribute === $this->attribute;
    }

    public function __toString(): string
    {
        return "attr($this->attribute)";
    }
}
