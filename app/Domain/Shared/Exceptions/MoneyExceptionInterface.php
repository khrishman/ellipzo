<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use Throwable;

/**
 * Marker implemented by every exception Money itself throws, so callers can
 * catch broadly (MoneyExceptionInterface) or narrowly (a specific class).
 */
interface MoneyExceptionInterface extends Throwable
{
    //
}
