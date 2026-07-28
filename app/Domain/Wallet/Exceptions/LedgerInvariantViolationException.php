<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use RuntimeException;

/**
 * A posting command, or the state it was about to write, violates a
 * structural ledger invariant - malformed/unbalanced entries, invalid
 * metadata, a locked account whose stored data is inconsistent with its
 * own type, or an attempt to post a reversal through the generic engine
 * before Task 2.5 exists. Matches Architecture.md §11's own named
 * exception vocabulary (LedgerInvariantViolation).
 */
final class LedgerInvariantViolationException extends RuntimeException implements LedgerPostingExceptionInterface {}
