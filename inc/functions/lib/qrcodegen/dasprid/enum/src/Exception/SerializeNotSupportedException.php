<?php
declare(strict_types = 1);

namespace DASPRiD\Enum\Exception;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Exception;

final class SerializeNotSupportedException extends Exception implements ExceptionInterface
{
}
