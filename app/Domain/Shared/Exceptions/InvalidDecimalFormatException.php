<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use InvalidArgumentException;

/**
 * A decimal string did not match Money's accepted grammar (see
 * Money::fromDecimalString()). The message never includes the raw input.
 */
final class InvalidDecimalFormatException extends InvalidArgumentException implements MoneyExceptionInterface
{
    //
}
