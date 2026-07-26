<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use InvalidArgumentException;

/**
 * A decimal string supplied more fractional digits than the currency's
 * scale allows. Excess precision is rejected, never rounded.
 */
final class MoneyPrecisionExceededException extends InvalidArgumentException implements MoneyExceptionInterface
{
    //
}
