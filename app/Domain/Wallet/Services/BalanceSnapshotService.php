<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Shared\Money\Currency;
use App\Domain\Wallet\Data\LedgerBalanceFoldResult;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Exceptions\UnknownWalletAccountException;
use App\Domain\Wallet\Models\BalanceSnapshot;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\WalletAccount;
use Illuminate\Support\Facades\DB;

/**
 * The only path by which a BalanceSnapshot row is ever created. Captures
 * exactly one account per call, inside its own transaction, holding at
 * most one wallet-account row lock at any time - the same lockForUpdate()
 * primitive LedgerPostingEngine::lockAccountsInOrder() uses, so a
 * concurrent posting to the same account genuinely serializes against a
 * concurrent snapshot capture rather than racing it. See docs/memory.md
 * for the deadlock-freedom argument: this class never acquires a second
 * account lock while holding the first, so it cannot participate in the
 * classic AB-BA cycle a multi-account locker like lockAccountsInOrder()
 * could in principle form with another multi-account locker.
 */
final class BalanceSnapshotService
{
    public function captureForAccount(WalletAccount $account): BalanceSnapshot
    {
        return DB::transaction(function () use ($account): BalanceSnapshot {
            // Re-fetched and re-verified, never trusting the caller's
            // possibly-stale in-memory object - the locked row is the only
            // one used for everything below.
            $lockedAccount = WalletAccount::query()->whereKey($account->id)->lockForUpdate()->first();

            if ($lockedAccount === null) {
                throw new UnknownWalletAccountException('The wallet account no longer exists.');
            }

            $this->assertAccountInvariants($lockedAccount);

            $fingerprint = new IncrementalLedgerFingerprint($lockedAccount->id);

            $entries = LedgerEntry::query()
                ->where('wallet_account_id', $lockedAccount->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->cursor();

            $fold = LedgerBalanceCalculator::fold($entries, $lockedAccount->account_type, $fingerprint);
            // The cursor is now fully consumed and closed - fold() always
            // walks its iterable to completion before returning, so no
            // partially-read cursor is ever left open on this connection.
            $fingerprintHex = $fingerprint->finalHex();

            $this->assertCutoffBelongsToAccount($fold, $lockedAccount);

            return BalanceSnapshotWriteContext::run(function () use ($lockedAccount, $fold, $fingerprintHex): BalanceSnapshot {
                $snapshot = new BalanceSnapshot;
                $snapshot->wallet_account_id = $lockedAccount->id;
                $snapshot->balance_atomic = $fold->balance->atomic();
                $snapshot->currency_code = $lockedAccount->currency_code;
                $snapshot->currency_scale = $lockedAccount->currency_scale;
                $snapshot->cutoff_ledger_entry_id = $fold->lastEntryId();
                $snapshot->cutoff_entry_created_at = $fold->lastEntryCreatedAt();
                $snapshot->entry_count = $fold->entryCount;
                $snapshot->fingerprint_version = 1;
                $snapshot->fingerprint = $fingerprintHex;
                $snapshot->save();

                return $snapshot;
            });
        });
    }

    /**
     * Mirrors LedgerPostingEngine::assertAccountInvariants() exactly - the
     * locked row's own stored scope/type/currency/scale are verified
     * fresh, never assumed from the caller's object.
     */
    private function assertAccountInvariants(WalletAccount $account): void
    {
        if ($account->scope_type !== $account->account_type->allowedScope()) {
            throw new LedgerInvariantViolationException('A wallet account has an account type inconsistent with its scope.');
        }

        if ($account->currency_code !== Currency::USD || $account->currency_scale !== Currency::USD->scale()) {
            throw new LedgerInvariantViolationException('A wallet account has an unexpected currency or scale.');
        }
    }

    /**
     * Defense-in-depth: the streamed entries were already filtered by
     * wallet_account_id, so this is structurally guaranteed rather than a
     * genuine possibility - but the actual last-seen entry object is
     * still available here, so it costs nothing to assert it explicitly
     * rather than only trust the query filter, mirroring this codebase's
     * established habit of verifying what a query already enforces.
     */
    private function assertCutoffBelongsToAccount(LedgerBalanceFoldResult $fold, WalletAccount $account): void
    {
        if ($fold->lastEntry !== null && $fold->lastEntry->wallet_account_id !== $account->id) {
            throw new LedgerInvariantViolationException('The derived cutoff entry does not belong to the snapshotted account.');
        }
    }
}
