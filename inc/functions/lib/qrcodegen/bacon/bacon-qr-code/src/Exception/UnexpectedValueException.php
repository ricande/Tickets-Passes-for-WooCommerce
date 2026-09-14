<?php
declare(strict_types = 1);

namespace BaconQrCode\Exception;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

final class UnexpectedValueException extends \UnexpectedValueException implements ExceptionInterface
{
}
