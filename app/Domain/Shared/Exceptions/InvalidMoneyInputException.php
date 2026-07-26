<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use InvalidArgumentException;

/**
 * A caller supplied a value that is not the genuine PHP type Money
 * requires (e.g. a float where a real int was needed) - thrown by the
 * runtime `mixed` boundary checks, never by PHP's own type coercion.
 *
 * The message never includes the raw supplied value.
 */
final class InvalidMoneyInputException extends InvalidArgumentException implements MoneyExceptionInterface
{
    //
}
