<?php

declare(strict_types=1);

namespace Sabberworm\CSS\Parsing;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Thrown if the CSS parser attempts to print something invalid.
 */
final class OutputException extends SourceException {}
