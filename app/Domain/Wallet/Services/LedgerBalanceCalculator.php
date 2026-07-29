<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Data\LedgerBalanceFoldResult;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Models\LedgerEntry;

/**
 * A pure, static entry point around LedgerBalanceAccumulator - no
 * database access, no cached balance, no float, no SQL SUM(). $entries
 * may be any iterable, including a genuinely lazy Illuminate\Support\
 * LazyCollection from Eloquent's own cursor() - in that case this method
 * never materializes more than one hydrated LedgerEntry model at a time,
 * keeping memory use approximately O(1) regardless of how long the
 * account's history is. The iterable is walked exactly once.
 *
 * Reused by both LedgerPostingEngine::deriveCurrentBalance() (its own
 * locked query, order, and behavior are unchanged - only the folding
 * itself is delegated here) and LedgerBalanceReader (unlocked reads).
 */
final class LedgerBalanceCalculator
{
    /**
     * @param  iterable<LedgerEntry>  $entries  Already ordered created_at ASC, id ASC.
     */
    public static function fold(
        iterable $entries,
        WalletAccountType $accountType,
        ?IncrementalLedgerFingerprint $fingerprint = null,
    ): LedgerBalanceFoldResult {
        $accumulator = new LedgerBalanceAccumulator($accountType, $fingerprint);

        foreach ($entries as $entry) {
            $accumulator->accumulate($entry);
        }

        return $accumulator->result();
    }
}
