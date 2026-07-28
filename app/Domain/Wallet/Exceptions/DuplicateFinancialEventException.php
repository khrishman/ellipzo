<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use RuntimeException;

/**
 * A posting was attempted under a business_reference that already
 * identifies a committed transaction with a genuinely different
 * semantic payload - never thrown for a true idempotent replay, which
 * returns the original transaction instead. Matches Architecture.md
 * §11's own named exception vocabulary (DuplicateFinancialEvent).
 */
final class DuplicateFinancialEventException extends RuntimeException implements LedgerPostingExceptionInterface {}
