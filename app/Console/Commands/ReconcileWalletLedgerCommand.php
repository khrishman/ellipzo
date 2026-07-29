<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\Exceptions\MoneyOverflowException;
use App\Domain\Shared\Money\Currency;
use App\Domain\Wallet\Data\LedgerDiscrepancy;
use App\Domain\Wallet\Enums\LedgerDiscrepancyCode;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\ReversalRequestStatus;
use App\Domain\Wallet\Enums\WalletAccountScopeType;
use App\Domain\Wallet\Models\BalanceSnapshot;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Models\ReversalRequest;
use App\Domain\Wallet\Models\WalletAccount;
use App\Domain\Wallet\Services\IncrementalLedgerFingerprint;
use App\Domain\Wallet\Services\LedgerBalanceReader;
use App\Models\AuditEvent;
use Illuminate\Console\Command;

/**
 * A full-sweep, strictly read-only integrity check over the immutable
 * ledger, wallet accounts, reversals, administrative-adjustment audit
 * trail, and balance snapshots. Never inserts, updates, deletes, or
 * repairs anything - every finding is reported, never auto-fixed.
 *
 * No --account or --since filter yet: their cross-transaction semantics
 * (a snapshot's own cutoff can reference an entry outside a bounded
 * window; a reversal's original can predate an incremental run) need a
 * separate design and are deliberately out of scope here.
 *
 * This class must never reference LedgerPostingEngine, ReversalRequestService,
 * AdministrativeAdjustmentService, WalletAccountProvisioner, or
 * BalanceSnapshotService - none of their public APIs are read-only-safe
 * to call from here. Every read below goes through a plain Eloquent query
 * or LedgerBalanceReader.
 */
final class ReconcileWalletLedgerCommand extends Command
{
    protected $signature = 'wallet:reconcile';

    protected $description = 'Perform a full, read-only integrity sweep over the ledger, wallet accounts, reversals, administrative-adjustment audits, and balance snapshots.';

    private const string ADJUSTMENT_ACTION = 'ledger.administrative_adjustment';

    private const string ADJUSTMENT_ENTITY_TYPE = 'ledger_transaction';

    public function __construct(
        private readonly LedgerBalanceReader $balanceReader,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var list<LedgerDiscrepancy> $discrepancies */
        $discrepancies = [];

        $transactionsChecked = 0;
        foreach (LedgerTransaction::query()->with('entries')->lazyById() as $transaction) {
            $transactionsChecked++;
            array_push($discrepancies, ...$this->checkTransaction($transaction));
        }

        $entriesChecked = 0;
        foreach (LedgerEntry::query()->with(['transaction', 'walletAccount'])->lazyById() as $entry) {
            $entriesChecked++;
            array_push($discrepancies, ...$this->checkEntry($entry));
        }

        $accountsChecked = 0;
        foreach (WalletAccount::query()->lazyById() as $account) {
            $accountsChecked++;
            array_push($discrepancies, ...$this->checkAccount($account));
        }

        $reversalsChecked = 0;
        foreach (ReversalRequest::query()->with(['originalTransaction.entries', 'reversalTransaction.entries'])->lazyById() as $reversalRequest) {
            $reversalsChecked++;
            array_push($discrepancies, ...$this->checkReversalRequest($reversalRequest));
        }

        $adjustmentsChecked = 0;
        foreach (LedgerTransaction::query()->where('type', LedgerTransactionType::AdministrativeAdjustment->value)->with('entries.walletAccount')->lazyById() as $adjustment) {
            $adjustmentsChecked++;
            array_push($discrepancies, ...$this->checkAdjustmentTransaction($adjustment));
        }

        foreach (AuditEvent::query()->where('action', self::ADJUSTMENT_ACTION)->lazyById() as $auditEvent) {
            array_push($discrepancies, ...$this->checkAdjustmentAudit($auditEvent));
        }

        $snapshotsChecked = 0;
        foreach (BalanceSnapshot::query()->with(['walletAccount', 'cutoffEntry'])->lazyById() as $snapshot) {
            $snapshotsChecked++;
            array_push($discrepancies, ...$this->checkSnapshot($snapshot));
        }

        $this->newLine();
        $this->line("Transactions checked: {$transactionsChecked}");
        $this->line("Entries checked: {$entriesChecked}");
        $this->line("Accounts checked: {$accountsChecked}");
        $this->line("Reversal requests checked: {$reversalsChecked}");
        $this->line("Administrative adjustments checked: {$adjustmentsChecked}");
        $this->line("Snapshots checked: {$snapshotsChecked}");
        $this->line('Discrepancies: '.count($discrepancies));

        foreach ($discrepancies as $discrepancy) {
            $this->line($discrepancy->toLine());
        }

        return count($discrepancies) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<LedgerDiscrepancy>
     */
    private function checkTransaction(LedgerTransaction $transaction): array
    {
        $found = [];
        $entries = $transaction->entries;

        if ($entries->count() < 2) {
            $found[] = new LedgerDiscrepancy(LedgerDiscrepancyCode::TransactionTooFewEntries, 'ledger_transaction', $transaction->id);
        }

        if ($transaction->currency_code !== Currency::USD || $transaction->currency_scale !== Currency::USD->scale()) {
            $found[] = new LedgerDiscrepancy(LedgerDiscrepancyCode::TransactionInvalidCurrencyOrScale, 'ledger_transaction', $transaction->id);
        }

        $debitTotal = 0;
        $creditTotal = 0;
        foreach ($entries as $entry) {
            if ($entry->entry_type === LedgerEntryType::Debit) {
                $debitTotal += $entry->amount_atomic;
            } else {
                $creditTotal += $entry->amount_atomic;
            }
        }

        if ($debitTotal !== $creditTotal) {
            $found[] = new LedgerDiscrepancy(LedgerDiscrepancyCode::TransactionImbalanced, 'ledger_transaction', $transaction->id);
        }

        return $found;
    }

    /**
     * @return list<LedgerDiscrepancy>
     */
    private function checkEntry(LedgerEntry $entry): array
    {
        $found = [];

        if ($entry->amount_atomic <= 0) {
            $found[] = new LedgerDiscrepancy(LedgerDiscrepancyCode::EntryNonPositiveAmount, 'ledger_entry', $entry->id);
        }

        if (! $entry->relationLoaded('transaction') || $entry->transaction === null) {
            $found[] = new LedgerDiscrepancy(LedgerDiscrepancyCode::EntryTransactionMissing, 'ledger_entry', $entry->id);
        }

        if (! $entry->relationLoaded('walletAccount') || $entry->walletAccount === null) {
            $found[] = new LedgerDiscrepancy(LedgerDiscrepancyCode::EntryAccountMissing, 'ledger_entry', $entry->id);
        }

        return $found;
    }

    /**
     * @return list<LedgerDiscrepancy>
     */
    private function checkAccount(WalletAccount $account): array
    {
        $found = [];

        if ($account->scope_type !== $account->account_type->allowedScope()) {
            $found[] = new LedgerDiscrepancy(LedgerDiscrepancyCode::AccountInvalidScopeType, 'wallet_account', $account->id);

            return $found;
        }

        try {
            $balance = $this->balanceReader->currentBalance($account)->balance;
        } catch (MoneyOverflowException) {
            $found[] = new LedgerDiscrepancy(LedgerDiscrepancyCode::BalanceDerivationOverflow, 'wallet_account', $account->id);

            return $found;
        }

        if ($balance->isNegative() && ! $account->account_type->allowsNegativeBalance()) {
            $found[] = new LedgerDiscrepancy(LedgerDiscrepancyCode::AccountNegativeBalanceDisallowed, 'wallet_account', $account->id);
        }

        return $found;
    }

    /**
     * @return list<LedgerDiscrepancy>
     */
    private function checkReversalRequest(ReversalRequest $request): array
    {
        $found = [];

        $original = $request->relationLoaded('originalTransaction') ? $request->originalTransaction : null;

        if ($original === null) {
            $found[] = new LedgerDiscrepancy(LedgerDiscrepancyCode::ReversalOriginalMissing, 'reversal_request', $request->id);
        }

        if (in_array($request->status, [ReversalRequestStatus::Pending, ReversalRequestStatus::ReviewRequired], true)
            && $request->reversal_transaction_id !== null) {
            $found[] = new LedgerDiscrepancy(LedgerDiscrepancyCode::ReversalPendingButLinked, 'reversal_request', $request->id);
        }

        if ($request->status === ReversalRequestStatus::Applied) {
            $reversal = $request->relationLoaded('reversalTransaction') ? $request->reversalTransaction : null;

            if ($request->reversal_transaction_id === null || $reversal === null) {
                $found[] = new LedgerDiscrepancy(LedgerDiscrepancyCode::ReversalAppliedMissingTransaction, 'reversal_request', $request->id);

                return $found;
            }

            if ($reversal->reverses_transaction_id !== $request->original_ledger_transaction_id) {
                $found[] = new LedgerDiscrepancy(LedgerDiscrepancyCode::ReversalTransactionMismatch, 'reversal_request', $request->id);
            }

            if ($original !== null && ! $this->entriesAreExactInversion($original, $reversal)) {
                $found[] = new LedgerDiscrepancy(LedgerDiscrepancyCode::ReversalEntryMismatch, 'reversal_request', $request->id);
            }
        }

        return $found;
    }

    private function entriesAreExactInversion(LedgerTransaction $original, LedgerTransaction $reversal): bool
    {
        $comparator = fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]) ?: ($a[2] <=> $b[2]);

        $expected = $original->entries
            ->map(function (LedgerEntry $e) {
                $inverted = $e->entry_type === LedgerEntryType::Debit
                    ? LedgerEntryType::Credit
                    : LedgerEntryType::Debit;

                return [$e->wallet_account_id, $inverted->value, $e->amount_atomic];
            })
            ->all();
        usort($expected, $comparator);

        $actual = $reversal->entries
            ->map(fn (LedgerEntry $e) => [$e->wallet_account_id, $e->entry_type->value, $e->amount_atomic])
            ->all();
        usort($actual, $comparator);

        return $expected === $actual;
    }

    /**
     * @return list<LedgerDiscrepancy>
     */
    private function checkAdjustmentTransaction(LedgerTransaction $adjustment): array
    {
        $matches = AuditEvent::query()
            ->where('entity_type', self::ADJUSTMENT_ENTITY_TYPE)
            ->where('entity_key', $adjustment->id)
            ->where('action', self::ADJUSTMENT_ACTION)
            ->get();

        if ($matches->isEmpty()) {
            return [new LedgerDiscrepancy(LedgerDiscrepancyCode::AdjustmentAuditMissing, 'ledger_transaction', $adjustment->id)];
        }

        if ($matches->count() > 1) {
            return [new LedgerDiscrepancy(LedgerDiscrepancyCode::AdjustmentAuditDuplicate, 'ledger_transaction', $adjustment->id)];
        }

        $auditEvent = $matches->first();

        if (! $this->adjustmentAuditMatchesTransaction($adjustment, $auditEvent)) {
            return [new LedgerDiscrepancy(LedgerDiscrepancyCode::AdjustmentAuditConflict, 'ledger_transaction', $adjustment->id)];
        }

        return [];
    }

    /**
     * Independently reconstructs what the audit payload must say from the
     * committed transaction and its own entries - never merely checks
     * that a row exists. The two entries of a genuine administrative
     * adjustment are always one user-scoped account (the target) and one
     * platform_suspense leg; direction is derived from which side the
     * target entry landed on, exactly the inverse of
     * AdministrativeAdjustmentService::buildEntries()'s own mapping.
     */
    private function adjustmentAuditMatchesTransaction(LedgerTransaction $adjustment, AuditEvent $auditEvent): bool
    {
        $entries = $adjustment->entries;

        if ($entries->count() !== 2) {
            return false;
        }

        $targetEntry = $entries->first(fn (LedgerEntry $e) => $e->walletAccount?->scope_type === WalletAccountScopeType::User);
        $suspenseEntry = $entries->first(fn (LedgerEntry $e) => $e->walletAccount?->scope_type === WalletAccountScopeType::Platform);

        if ($targetEntry === null || $suspenseEntry === null || $targetEntry->walletAccount === null) {
            return false;
        }

        $direction = $targetEntry->entry_type === LedgerEntryType::Credit ? 'increase' : 'decrease';

        $expectedAfter = [
            'target_wallet_account_id' => $targetEntry->wallet_account_id,
            'target_account_type' => $targetEntry->walletAccount->account_type->value,
            'direction' => $direction,
            'amount_atomic' => (string) $targetEntry->amount_atomic,
            'currency' => $adjustment->currency_code->value,
            'business_reference' => $adjustment->business_reference,
        ];

        $afterState = $auditEvent->after_state ?? [];

        foreach ($expectedAfter as $key => $value) {
            if (($afterState[$key] ?? null) !== $value) {
                return false;
            }
        }

        return $auditEvent->actor_id === $adjustment->actor_id
            && $auditEvent->correlation_id === $adjustment->correlation_id;
    }

    /**
     * @return list<LedgerDiscrepancy>
     */
    private function checkAdjustmentAudit(AuditEvent $auditEvent): array
    {
        if ($auditEvent->entity_key === null) {
            return [];
        }

        $exists = LedgerTransaction::query()->whereKey($auditEvent->entity_key)->exists();

        if (! $exists) {
            return [new LedgerDiscrepancy(LedgerDiscrepancyCode::AuditTransactionMissing, 'audit_event', (string) $auditEvent->id)];
        }

        return [];
    }

    /**
     * @return list<LedgerDiscrepancy>
     */
    private function checkSnapshot(BalanceSnapshot $snapshot): array
    {
        if ($snapshot->cutoff_ledger_entry_id === null) {
            return [];
        }

        $cutoffEntry = $snapshot->relationLoaded('cutoffEntry') ? $snapshot->cutoffEntry : null;
        $walletAccount = $snapshot->relationLoaded('walletAccount') ? $snapshot->walletAccount : null;

        if ($cutoffEntry === null) {
            return [new LedgerDiscrepancy(LedgerDiscrepancyCode::SnapshotCutoffMissing, 'balance_snapshot', $snapshot->id)];
        }

        if ($walletAccount === null || $cutoffEntry->wallet_account_id !== $snapshot->wallet_account_id) {
            return [new LedgerDiscrepancy(LedgerDiscrepancyCode::SnapshotCutoffWrongAccount, 'balance_snapshot', $snapshot->id)];
        }

        if (! $cutoffEntry->created_at->equalTo($snapshot->cutoff_entry_created_at)) {
            return [new LedgerDiscrepancy(LedgerDiscrepancyCode::SnapshotCutoffTimestampMismatch, 'balance_snapshot', $snapshot->id)];
        }

        $fingerprint = new IncrementalLedgerFingerprint($walletAccount->id);

        try {
            $recomputed = $this->balanceReader->balanceAsOf($walletAccount, $cutoffEntry, $fingerprint);
        } catch (MoneyOverflowException) {
            return [new LedgerDiscrepancy(LedgerDiscrepancyCode::BalanceDerivationOverflow, 'balance_snapshot', $snapshot->id)];
        }

        $found = [];

        if ($recomputed->balance->atomic() !== $snapshot->balance_atomic) {
            $found[] = new LedgerDiscrepancy(LedgerDiscrepancyCode::SnapshotBalanceDrift, 'balance_snapshot', $snapshot->id);
        }

        if ($recomputed->entryCount !== $snapshot->entry_count) {
            $found[] = new LedgerDiscrepancy(LedgerDiscrepancyCode::SnapshotEntryCountDrift, 'balance_snapshot', $snapshot->id);
        }

        if ($fingerprint->finalHex() !== $snapshot->fingerprint) {
            $found[] = new LedgerDiscrepancy(LedgerDiscrepancyCode::SnapshotFingerprintDrift, 'balance_snapshot', $snapshot->id);
        }

        return $found;
    }
}
