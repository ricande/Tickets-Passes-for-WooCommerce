<?php
namespace Dompdf\Css\Content;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

final class NoCloseQuote extends ContentPart
{
    public function __toString(): string
    {
        return "no-close-quote";
    }
}
