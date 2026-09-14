<?php
namespace Dompdf\Css\Content;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

final class CloseQuote extends ContentPart
{
    public function __toString(): string
    {
        return "close-quote";
    }
}
