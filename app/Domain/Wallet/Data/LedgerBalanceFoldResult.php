<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Data;

use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Models\LedgerEntry;
use Illuminate\Support\Carbon;

/**
 * The result of folding one wallet account's entry history through
 * LedgerBalanceCalculator::fold(). Carries the full last-seen LedgerEntry
 * (not just its ID/timestamp) so a caller (BalanceSnapshotService) can
 * independently re-verify "this cutoff genuinely belongs to this account"
 * against a real model instance rather than trusting the fold alone -
 * defense-in-depth, mirroring this codebase's existing FK-plus-explicit-
 * check discipline.
 */
final readonly class LedgerBalanceFoldResult
{
    public function __construct(
        public Money $balance,
        public int $entryCount,
        public ?LedgerEntry $lastEntry,
    ) {}

    public function lastEntryId(): ?string
    {
        return $this->lastEntry?->id;
    }

    public function lastEntryCreatedAt(): ?Carbon
    {
        return $this->lastEntry?->created_at;
    }
}
