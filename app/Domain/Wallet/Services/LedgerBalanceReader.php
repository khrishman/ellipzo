<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Data\LedgerBalanceFoldResult;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\WalletAccount;

/**
 * Read-only balance access - never a write-authorizing lock, never a
 * cached balance, never called by LedgerPostingEngine. Every method
 * streams via cursor() and delegates the actual arithmetic to
 * LedgerBalanceCalculator, so there is no second implementation of
 * debit/credit folding anywhere in this class.
 *
 * Snapshot-specific invariants (cutoff exists, belongs to the right
 * account, its stored timestamp matches the real entry) are deliberately
 * NOT checked here - this class only answers "what is the balance", not
 * "is this a valid snapshot cutoff". That validation belongs to whichever
 * caller has snapshot-specific context (wallet:reconcile), keeping this
 * reader a small, generic, reusable capability.
 */
final class LedgerBalanceReader
{
    public function currentBalance(WalletAccount $account): LedgerBalanceFoldResult
    {
        $entries = LedgerEntry::query()
            ->where('wallet_account_id', $account->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursor();

        return LedgerBalanceCalculator::fold($entries, $account->account_type);
    }

    /**
     * The account's balance using only entries at or before the given
     * cutoff entry, in the same compound (created_at, id) boundary
     * LedgerPostingEngine's own chronological ordering relies on: an
     * entry qualifies if its created_at is strictly earlier than the
     * cutoff's, or if it shares the cutoff's created_at and its id is
     * less than or equal to the cutoff's id. A plain "id <=" comparison
     * alone would be wrong whenever multiple entries share a created_at
     * value - id is only the tiebreaker, not the primary ordering key.
     *
     * $fingerprint is optional, mirroring LedgerBalanceCalculator::fold()
     * - wallet:reconcile's drift-detection is the only caller that
     * supplies one.
     */
    public function balanceAsOf(
        WalletAccount $account,
        LedgerEntry $cutoffEntry,
        ?IncrementalLedgerFingerprint $fingerprint = null,
    ): LedgerBalanceFoldResult {
        $entries = LedgerEntry::query()
            ->where('wallet_account_id', $account->id)
            ->where(function ($query) use ($cutoffEntry): void {
                $query->where('created_at', '<', $cutoffEntry->created_at)
                    ->orWhere(function ($inner) use ($cutoffEntry): void {
                        $inner->where('created_at', $cutoffEntry->created_at)
                            ->where('id', '<=', $cutoffEntry->id);
                    });
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursor();

        return LedgerBalanceCalculator::fold($entries, $account->account_type, $fingerprint);
    }
}
