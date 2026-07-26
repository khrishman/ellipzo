<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use OverflowException;

/**
 * An operation would have produced a value outside [PHP_INT_MIN,
 * PHP_INT_MAX] - in either direction - or attempted an operation (such as
 * negating PHP_INT_MIN) that has no representable result.
 */
final class MoneyOverflowException extends OverflowException implements MoneyExceptionInterface
{
    //
}
