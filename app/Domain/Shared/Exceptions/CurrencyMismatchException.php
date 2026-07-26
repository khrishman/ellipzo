<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use LogicException;

/**
 * A binary operation was attempted between two Money instances of
 * differing currencies. With only Currency::USD approved, this branch is
 * currently unreachable in practice - it exists as defensive
 * forward-compatibility, not as tested behavior. See docs/memory.md.
 */
final class CurrencyMismatchException extends LogicException implements MoneyExceptionInterface
{
    //
}
