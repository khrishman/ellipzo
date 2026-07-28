<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use RuntimeException;

/**
 * A posting would take a wallet account below zero and that account's
 * type does not permit a negative balance. Matches Architecture.md §11's
 * own named exception vocabulary (InsufficientBalance).
 */
final class InsufficientBalanceException extends RuntimeException implements LedgerPostingExceptionInterface {}
