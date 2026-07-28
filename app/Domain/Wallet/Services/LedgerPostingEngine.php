<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\PostedLedgerTransaction;
use App\Domain\Wallet\Data\PostLedgerEntryCommand;
use App\Domain\Wallet\Data\PostLedgerTransactionCommand;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\RelatedEntityType;
use App\Domain\Wallet\Enums\ReversalRequestStatus;
use App\Domain\Wallet\Exceptions\DuplicateFinancialEventException;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Exceptions\UnknownWalletAccountException;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Models\ReversalRequest;
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

        if (! $this->matchesSemanticPayload(
            $existing,
            $command->type,
            $command->description,
            $command->actorId,
            $command->relatedEntityType,
            $command->relatedEntityId,
            null,
            $command->entries,
        )) {
            throw new DuplicateFinancialEventException('A different ledger transaction already exists under this business reference.');
        }

        return new PostedLedgerTransaction($existing, wasReplay: true);
    }

    /**
     * A single generalized comparison shared by post()'s own replay
     * recovery and writeReversalEntriesWithinTransaction()'s reversal
     * recovery - the expected shape is passed in explicitly rather than
     * read from two different command types, so post()'s own call site
     * (which always passes null for $expectedReversesTransactionId)
     * reproduces its prior hardcoded behavior exactly.
     *
     * Correlation ID is deliberately excluded: it identifies a request
     * attempt, not the financial event itself. An identical retry may
     * legitimately arrive with a fresh correlation ID and must still be
     * recognized as the same posting.
     *
     * @param  list<PostLedgerEntryCommand>  $expectedEntries
     */
    private function matchesSemanticPayload(
        LedgerTransaction $existing,
        LedgerTransactionType $expectedType,
        string $expectedDescription,
        ?int $expectedActorId,
        ?RelatedEntityType $expectedRelatedEntityType,
        ?string $expectedRelatedEntityId,
        ?string $expectedReversesTransactionId,
        array $expectedEntries,
    ): bool {
        if ($existing->type !== $expectedType
            || $existing->currency_code !== Currency::USD
            || $existing->currency_scale !== Currency::USD->scale()
            || $existing->description !== $expectedDescription
            || $existing->actor_id !== $expectedActorId
            || $existing->related_entity_type !== $expectedRelatedEntityType
            || $existing->related_entity_id !== $expectedRelatedEntityId
            || $existing->reverses_transaction_id !== $expectedReversesTransactionId
        ) {
            return false;
        }

        return $this->entriesMatch($existing->entries, $expectedEntries);
    }

    /**
     * @param  iterable<LedgerEntry>  $existingEntries
     * @param  list<PostLedgerEntryCommand>  $expectedEntries
     */
    private function entriesMatch(iterable $existingEntries, array $expectedEntries): bool
    {
        $comparator = fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: (($a[1] <=> $b[1]) ?: ($a[2] <=> $b[2]));

        $existing = collect($existingEntries)
            ->map(fn (LedgerEntry $e): array => [$e->wallet_account_id, $e->entry_type->value, $e->amount_atomic])
            ->all();
        usort($existing, $comparator);

        $expected = array_map(
            fn (PostLedgerEntryCommand $e): array => [$e->walletAccountId, $e->entryType->value, $e->amount->atomic()],
            $expectedEntries,
        );
        usort($expected, $comparator);

        return $existing === $expected;
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

    /**
     * The transaction-aware counterpart to post(), reserved for
     * ReversalRequestService::execute(). Unlike post(), this method opens
     * no transaction and no LedgerWriteContext/ReversalRequestWriteContext
     * of its own - it assumes all three are already active and asserts
     * every one of those preconditions before writing anything. This is
     * what lets execute() catch InsufficientBalanceException narrowly and
     * commit a durable review_required outcome instead of rolling back:
     * the balance check below runs before any row is inserted, so an
     * insufficient projected balance never leaves a partial write behind
     * to roll back in the first place.
     *
     * post()'s own behavior is untouched: the two methods share only
     * matchesSemanticPayload()/entriesMatch()/lockAccountsInOrder()/
     * assertProjectedBalanceAllowed()/assertEntriesBalance(), each already
     * parameterized or self-contained before this method existed.
     */
    public function writeReversalEntriesWithinTransaction(ReversalRequest $request, LedgerTransaction $original): LedgerTransaction
    {
        if (DB::transactionLevel() < 1) {
            throw new LedgerInvariantViolationException('writeReversalEntriesWithinTransaction requires an active database transaction.');
        }

        if (! LedgerWriteContext::isActive()) {
            throw new LedgerInvariantViolationException('writeReversalEntriesWithinTransaction requires an active LedgerWriteContext.');
        }

        if (! ReversalRequestWriteContext::isActive()) {
            throw new LedgerInvariantViolationException('writeReversalEntriesWithinTransaction requires an active ReversalRequestWriteContext.');
        }

        if (! $request->exists) {
            throw new LedgerInvariantViolationException('The reversal request must already be persisted.');
        }

        if (! in_array($request->status, [ReversalRequestStatus::Pending, ReversalRequestStatus::ReviewRequired], true)) {
            throw new LedgerInvariantViolationException('The reversal request is not in an executable state.');
        }

        if (! $original->exists || $request->original_ledger_transaction_id !== $original->id) {
            throw new LedgerInvariantViolationException('The supplied original transaction does not match the reversal request.');
        }

        $businessReference = self::reversalBusinessReference($original->id);
        $inverseEntries = $this->deriveInverseEntries($original);

        // Checked proactively, before any lock or balance derivation: a
        // matching reversal may already exist from an earlier attempt
        // whose own linkage never committed (a resumed execution, or a
        // request replayed after a concurrent execution already applied
        // it). Its entries are already reflected in the accounts' current
        // balances, so deriving a projected balance for a second
        // attempt to insert those same entries would double-count them
        // and could reject as insufficient even though nothing new is
        // about to be written. Checking here, before that derivation,
        // avoids the false rejection entirely rather than working around
        // it after the fact.
        $existing = $this->findExistingReversal($businessReference, $original->id);

        if ($existing !== null) {
            return $this->reconcileExistingReversal($existing, $original, $request, $inverseEntries);
        }

        $accountsById = $this->lockAccountsInOrder($inverseEntries);

        foreach ($inverseEntries as $entryCommand) {
            $this->assertProjectedBalanceAllowed($accountsById[$entryCommand->walletAccountId], $entryCommand);
        }

        try {
            $reversal = new LedgerTransaction;
            $reversal->business_reference = $businessReference;
            $reversal->type = LedgerTransactionType::Reversal;
            $reversal->currency_code = Currency::USD;
            $reversal->currency_scale = Currency::USD->scale();
            $reversal->description = $request->reason;
            $reversal->actor_id = $request->actor_id;
            $reversal->related_entity_type = $original->related_entity_type;
            $reversal->related_entity_id = $original->related_entity_id;
            $reversal->correlation_id = $request->correlation_id;
            $reversal->reverses_transaction_id = $original->id;
            $reversal->save();
        } catch (UniqueConstraintViolationException $exception) {
            return $this->reconcileReversalReplay($original, $request, $inverseEntries, $businessReference, $exception);
        }

        $insertedEntries = [];
        foreach ($inverseEntries as $entryCommand) {
            $entry = new LedgerEntry;
            $entry->ledger_transaction_id = $reversal->id;
            $entry->wallet_account_id = $entryCommand->walletAccountId;
            $entry->entry_type = $entryCommand->entryType;
            $entry->amount_atomic = $entryCommand->amount->atomic();
            $entry->save();
            $insertedEntries[] = $entry;
        }

        $this->assertEntriesBalance($insertedEntries);

        $reversal->setRelation('entries', collect($insertedEntries));

        return $reversal;
    }

    private static function reversalBusinessReference(string $originalTransactionId): string
    {
        return 'reversal:'.$originalTransactionId;
    }

    /**
     * Every original entry, inverted (debit<->credit) at the identical
     * atomic amount. The original's own rows are the sole source of
     * truth - never the request, never re-derived balances - so a
     * reversal is always the exact mechanical opposite of what was
     * actually posted.
     *
     * @return list<PostLedgerEntryCommand>
     */
    private function deriveInverseEntries(LedgerTransaction $original): array
    {
        if (! $original->relationLoaded('entries')) {
            $original->load('entries');
        }

        return $original->entries
            ->map(function (LedgerEntry $entry): PostLedgerEntryCommand {
                $invertedType = $entry->entry_type === LedgerEntryType::Debit
                    ? LedgerEntryType::Credit
                    : LedgerEntryType::Debit;

                $amount = Money::fromAtomic($entry->amount_atomic, Currency::USD)->ensurePositive();

                return new PostLedgerEntryCommand($entry->wallet_account_id, $invertedType, $amount);
            })
            ->all();
    }

    private function findExistingReversal(string $businessReference, string $originalId): ?LedgerTransaction
    {
        $existing = LedgerTransaction::query()
            ->where('business_reference', $businessReference)
            ->with('entries')
            ->lockForUpdate()
            ->first();

        return $existing ?? LedgerTransaction::query()
            ->where('reverses_transaction_id', $originalId)
            ->with('entries')
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  list<PostLedgerEntryCommand>  $inverseEntries
     */
    private function reconcileExistingReversal(
        LedgerTransaction $existing,
        LedgerTransaction $original,
        ReversalRequest $request,
        array $inverseEntries,
    ): LedgerTransaction {
        if (! $this->matchesSemanticPayload(
            $existing,
            LedgerTransactionType::Reversal,
            $request->reason,
            $request->actor_id,
            $original->related_entity_type,
            $original->related_entity_id,
            $original->id,
            $inverseEntries,
        )) {
            throw new DuplicateFinancialEventException('A different reversal transaction already exists for this original transaction.');
        }

        return $existing;
    }

    /**
     * The reactive fallback for the rare window between this method's own
     * proactive findExistingReversal() call and its insert attempt below:
     * a genuinely concurrent writer that inserted the matching reversal in
     * between. Not the primary recovery mechanism - that is the proactive
     * check above, specifically so a resumed/replayed execution never
     * reaches the balance check at all.
     *
     * @param  list<PostLedgerEntryCommand>  $inverseEntries
     */
    private function reconcileReversalReplay(
        LedgerTransaction $original,
        ReversalRequest $request,
        array $inverseEntries,
        string $businessReference,
        UniqueConstraintViolationException $exception,
    ): LedgerTransaction {
        $existing = $this->findExistingReversal($businessReference, $original->id);

        if ($existing === null) {
            // Neither identity that could explain a unique-constraint
            // conflict on this insert was found - an unexplained anomaly,
            // never reinterpreted as a known duplicate-event conflict.
            throw $exception;
        }

        return $this->reconcileExistingReversal($existing, $original, $request, $inverseEntries);
    }
}
