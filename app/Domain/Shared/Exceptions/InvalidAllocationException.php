<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use InvalidArgumentException;

/**
 * Money::allocate() was called with an empty ratio list, a negative
 * ratio, a ratio total of zero, or a ratio total exceeding the
 * dependency-free overflow-safe bound (3_037_000_499).
 */
final class InvalidAllocationException extends InvalidArgumentException implements MoneyExceptionInterface
{
    //
}
