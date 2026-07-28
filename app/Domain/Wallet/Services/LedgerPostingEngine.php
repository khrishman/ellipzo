<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\PostedLedgerTransaction;
use App\Domain\Wallet\Data\PostLedgerEntryCommand;
use App\Domain\Wallet\Data\PostLedgerTransactionCommand;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Exceptions\DuplicateFinancialEventException;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Exceptions\UnknownWalletAccountException;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Models\WalletAccount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only path by which a LedgerTransaction/LedgerEntry row is ever
 * created - enforced by LedgerWriteContext, which this class is the sole
 * intended caller of. Builds no domain-specific caller for any
 * LedgerTransactionType; a future action (reservation, settlement,
 * deposit, withdrawal, adjustment) supplies an already-validated
 * PostLedgerTransactionCommand and calls post() - none of those actions
 * exist yet.
 */
final class LedgerPostingEngine
{
    public function post(PostLedgerTransactionCommand $command): PostedLedgerTransaction
    {
        return DB::transaction(function () use ($command): PostedLedgerTransaction {
            return LedgerWriteContext::run(function () use ($command): PostedLedgerTransaction {
                if ($command->actorId !== null && ! User::query()->whereKey($command->actorId)->exists()) {
                    // The restrictive FK on ledger_transactions.actor_id would
                    // also reject this at insert time - this check exists to
                    // fail with a clear, typed exception rather than relying
                    // solely on a raw FK-violation QueryException.
                    throw new LedgerInvariantViolationException('The actor no longer exists.');
                }

                try {
                    $transaction = new LedgerTransaction;
                    $transaction->business_reference = $command->businessReference;
                    $transaction->type = $command->type;
                    $transaction->currency_code = Currency::USD;
                    $transaction->currency_scale = Currency::USD->scale();
                    $transaction->description = $command->description;
                    $transaction->actor_id = $command->actorId;
                    $transaction->related_entity_type = $command->relatedEntityType;
                    $transaction->related_entity_id = $command->relatedEntityId;
                    $transaction->correlation_id = $command->correlationId;
                    $transaction->reverses_transaction_id = null;
                    $transaction->save();
                } catch (UniqueConstraintViolationException $exception) {
                    return $this->reconcileReplay($command, $exception);
                }

                $accountsById = $this->lockAccountsInOrder($command->entries);

                foreach ($command->entries as $entryCommand) {
                    $this->assertProjectedBalanceAllowed($accountsById[$entryCommand->walletAccountId], $entryCommand);
                }

                $insertedEntries = [];
                foreach ($command->entries as $entryCommand) {
                    $entry = new LedgerEntry;
                    $entry->ledger_transaction_id = $transaction->id;
                    $entry->wallet_account_id = $entryCommand->walletAccountId;
                    $entry->entry_type = $entryCommand->entryType;
                    $entry->amount_atomic = $entryCommand->amount->atomic();
                    $entry->save();
                    $insertedEntries[] = $entry;
                }

                $this->assertEntriesBalance($insertedEntries);

                $transaction->setRelation('entries', collect($insertedEntries));

                return new PostedLedgerTransaction($transaction, wasReplay: false);
            });
        });
    }

    private function reconcileReplay(PostLedgerTransactionCommand $command, UniqueConstraintViolationException $exception): PostedLedgerTransaction
    {
        $existing = LedgerTransaction::query()
            ->where('business_reference', $command->businessReference)
            ->with('entries')
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            // The conflict was real but the canonical row can't be found -
            // an unexplained anomaly, never reinterpreted as a known
            // duplicate-event conflict.
            throw $exception;
        }

        if (! $this->matchesSemanticPayload($existing, $command)) {
            throw new DuplicateFinancialEventException('A different ledger transaction already exists under this business reference.');
        }

        return new PostedLedgerTransaction($existing, wasReplay: true);
    }

    /**
     * Correlation ID is deliberately excluded: it identifies a request
     * attempt, not the financial event itself. An identical retry may
     * legitimately arrive with a fresh correlation ID and must still be
     * recognized as the same posting.
     */
    private function matchesSemanticPayload(LedgerTransaction $existing, PostLedgerTransactionCommand $command): bool
    {
        if ($existing->type !== $command->type
            || $existing->currency_code !== Currency::USD
            || $existing->currency_scale !== Currency::USD->scale()
            || $existing->description !== $command->description
            || $existing->actor_id !== $command->actorId
            || $existing->related_entity_type !== $command->relatedEntityType
            || $existing->related_entity_id !== $command->relatedEntityId
            || $existing->reverses_transaction_id !== null
        ) {
            return false;
        }

        $comparator = fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: (($a[1] <=> $b[1]) ?: ($a[2] <=> $b[2]));

        $existingEntries = $existing->entries
            ->map(fn (LedgerEntry $e): array => [$e->wallet_account_id, $e->entry_type->value, $e->amount_atomic])
            ->all();
        usort($existingEntries, $comparator);

        $commandEntries = array_map(
            fn (PostLedgerEntryCommand $e): array => [$e->walletAccountId, $e->entryType->value, $e->amount->atomic()],
            $command->entries,
        );
        usort($commandEntries, $comparator);

        return $existingEntries === $commandEntries;
    }

    /**
     * @param  list<PostLedgerEntryCommand>  $entries
     * @return array<string, WalletAccount>
     */
    private function lockAccountsInOrder(array $entries): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            $ids[$entry->walletAccountId] = true;
        }
        $ids = array_keys($ids);
        sort($ids); // canonical ULID strings sort lexically == chronologically

        $accounts = [];
        foreach ($ids as $id) {
            $account = WalletAccount::query()->whereKey($id)->lockForUpdate()->first();

            if ($account === null) {
                throw new UnknownWalletAccountException('A referenced wallet account does not exist.');
            }

            $this->assertAccountInvariants($account, $id);

            $accounts[$id] = $account;
        }

        return $accounts;
    }

    private function assertAccountInvariants(WalletAccount $account, string $expectedId): void
    {
        if ($account->id !== $expectedId) {
            throw new LedgerInvariantViolationException('A locked wallet account did not match the requested identifier.');
        }

        if ($account->scope_type !== $account->account_type->allowedScope()) {
            throw new LedgerInvariantViolationException('A referenced wallet account has an account type inconsistent with its scope.');
        }

        if ($account->currency_code !== Currency::USD || $account->currency_scale !== Currency::USD->scale()) {
            throw new LedgerInvariantViolationException('A referenced wallet account has an unexpected currency or scale.');
        }
    }

    private function assertProjectedBalanceAllowed(WalletAccount $account, PostLedgerEntryCommand $entryCommand): void
    {
        $currentBalance = $this->deriveCurrentBalance($account);
        $normalSide = $account->account_type->normalEntrySide();

        $projectedBalance = $entryCommand->entryType === $normalSide
            ? $currentBalance->add($entryCommand->amount)
            : $currentBalance->subtract($entryCommand->amount);

        if ($projectedBalance->isNegative() && ! $account->account_type->allowsNegativeBalance()) {
            throw new InsufficientBalanceException('This posting would take a wallet account below zero.');
        }
    }

    /**
     * Folds the account's full entry history in strict chronological order
     * (created_at then id, since same-second timestamps are possible and
     * the ULID id is itself time-ordered) using Money's own checked
     * arithmetic - never a raw SQL SUM(), which would need its own,
     * separately-proven, cross-engine overflow story. A current/locking
     * read: under REPEATABLE READ, a plain read here could still observe
     * a stale snapshot from before this transaction's first query, even
     * after the account row itself was locked.
     */
    private function deriveCurrentBalance(WalletAccount $account): Money
    {
        $entries = LedgerEntry::query()
            ->where('wallet_account_id', $account->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $normalSide = $account->account_type->normalEntrySide();
        $balance = Money::zero(Currency::USD);

        foreach ($entries as $entry) {
            $amount = Money::fromAtomic($entry->amount_atomic, Currency::USD);
            $balance = $entry->entry_type === $normalSide
                ? $balance->add($amount)
                : $balance->subtract($amount);
        }

        return $balance;
    }

    /**
     * @param  list<LedgerEntry>  $insertedEntries
     */
    private function assertEntriesBalance(array $insertedEntries): void
    {
        $debitTotal = Money::zero(Currency::USD);
        $creditTotal = Money::zero(Currency::USD);

        foreach ($insertedEntries as $entry) {
            $amount = Money::fromAtomic($entry->amount_atomic, Currency::USD);

            if ($entry->entry_type === LedgerEntryType::Debit) {
                $debitTotal = $debitTotal->add($amount);
            } else {
                $creditTotal = $creditTotal->add($amount);
            }
        }

        if (! $debitTotal->equals($creditTotal)) {
            throw new LedgerInvariantViolationException('The posted entries no longer balance.');
        }
    }
}
