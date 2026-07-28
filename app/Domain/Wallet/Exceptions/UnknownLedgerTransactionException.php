<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use RuntimeException;

/**
 * A reversal-request operation referenced a ledger_transaction_id that
 * does not exist.
 */
final class UnknownLedgerTransactionException extends RuntimeException implements LedgerPostingExceptionInterface {}
