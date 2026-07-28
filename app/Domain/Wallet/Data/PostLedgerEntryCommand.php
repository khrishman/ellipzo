<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Data;

use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use Illuminate\Support\Str;

/**
 * A single debit or credit instruction within a posting command.
 * Immutable after construction. amount must already be positive -
 * ensurePositive() is re-asserted here regardless of what the caller did,
 * never trusted from the caller alone.
 */
final readonly class PostLedgerEntryCommand
{
    /**
     * Not constructor-promoted: the raw input must be validated and
     * normalized (trim, ULID case) before this property is set, and a
     * promoted readonly property would be assigned before any
     * constructor-body normalization could run.
     */
    public string $walletAccountId;

    public LedgerEntryType $entryType;

    public Money $amount;

    public function __construct(mixed $walletAccountId, LedgerEntryType $entryType, Money $amount)
    {
        if (! is_string($walletAccountId)) {
            throw new LedgerInvariantViolationException('Wallet account identifier must be a string.');
        }

        $trimmed = trim($walletAccountId);

        if (! Str::isUlid($trimmed)) {
            throw new LedgerInvariantViolationException('Wallet account identifier must be a valid ULID.');
        }

        // HasUlids::newUniqueId() stores strtolower((string) Str::ulid()) -
        // confirmed from its own source, not assumed - so lowercase is the
        // true canonical representation. Normalizing here means a query
        // against this value matches identically whether the caller passed
        // upper or lower case, on every engine (MariaDB/MySQL's default
        // collation is case-insensitive; SQLite's is not).
        $this->walletAccountId = strtolower($trimmed);
        $this->entryType = $entryType;
        $this->amount = $amount->ensurePositive();
    }
}
