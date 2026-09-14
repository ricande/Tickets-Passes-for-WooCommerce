<?php
namespace Dompdf\Css\Content;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

final class OpenQuote extends ContentPart
{
    public function __toString(): string
    {
        return "open-quote";
    }
}
