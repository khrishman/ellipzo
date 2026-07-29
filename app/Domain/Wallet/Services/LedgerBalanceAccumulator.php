<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\LedgerBalanceFoldResult;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Models\LedgerEntry;

/**
 * The single authoritative place debit/credit arithmetic is performed to
 * derive a wallet account's balance from its entry history. Every other
 * balance-deriving path in this codebase - LedgerBalanceCalculator::fold()
 * (used by both LedgerPostingEngine's own locked balance check and
 * LedgerBalanceReader) - is a thin wrapper around this class, so the
 * arithmetic itself is never duplicated.
 *
 * A mutable, one-shot, streaming accumulator: accumulate() is called once
 * per entry, in created_at ASC, id ASC order, and holds only O(1) state
 * regardless of how many entries are fed through it - a Money value, an
 * int, a nullable LedgerEntry reference (replaced, not appended, each
 * call), and an optional streaming fingerprint context. It never receives
 * or retains the full entry set.
 *
 * The fingerprint parameter is optional and deliberately opt-in:
 * LedgerPostingEngine's own hot-path balance derivation (every posting)
 * never needs a fingerprint, and passing null costs nothing beyond a
 * single null-coalescing check per entry - no hashing work happens unless
 * a caller (BalanceSnapshotService, wallet:reconcile) explicitly asks for
 * one.
 */
final class LedgerBalanceAccumulator
{
    private Money $balance;

    private int $entryCount = 0;

    private ?LedgerEntry $lastEntry = null;

    private readonly LedgerEntryType $normalSide;

    public function __construct(
        WalletAccountType $accountType,
        private readonly ?IncrementalLedgerFingerprint $fingerprint = null,
    ) {
        $this->balance = Money::zero(Currency::USD);
        $this->normalSide = $accountType->normalEntrySide();
    }

    public function accumulate(LedgerEntry $entry): void
    {
        $amount = Money::fromAtomic($entry->amount_atomic, Currency::USD);

        $this->balance = $entry->entry_type === $this->normalSide
            ? $this->balance->add($amount)
            : $this->balance->subtract($amount);

        $this->entryCount++;
        $this->lastEntry = $entry;

        $this->fingerprint?->update($entry);
    }

    public function result(): LedgerBalanceFoldResult
    {
        return new LedgerBalanceFoldResult($this->balance, $this->entryCount, $this->lastEntry);
    }
}
