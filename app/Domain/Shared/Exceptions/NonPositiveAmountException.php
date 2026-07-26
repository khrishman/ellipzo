<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use InvalidArgumentException;

/**
 * Money::ensurePositive() was called on a zero or negative amount.
 */
final class NonPositiveAmountException extends InvalidArgumentException implements MoneyExceptionInterface
{
    //
}
