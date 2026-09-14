<?php
/**
 * @package php-svg-lib
 * @link    http://github.com/dompdf/php-svg-lib
 * @license GNU LGPLv3+ http://www.gnu.org/copyleft/lesser.html
 */

namespace Svg\Gradient;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Stop
{
    public $offset;
    public $color;
    public $opacity = 1.0;
}
