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
 * intended caller of. Builds no domain-specific caller for most
 * LedgerTransactionType values; a future action (reservation, settlement,
 * deposit, withdrawal) supplies an already-validated
 * PostLedgerTransactionCommand and calls post(). Two types are the
 * deliberate exception: LedgerTransactionType::Reversal and
 * ::AdministrativeAdjustment both have their own narrowly named,
 * precondition-gated write path
 * (writeReversalEntriesWithinTransaction()/
 * writeAdministrativeAdjustmentWithinTransaction()) and can never be
 * constructed as a generic PostLedgerTransactionCommand at all - so
 * neither type has a path that bypasses its owning service's own
 * authorization/audit logic.
 */
final class LedgerPostingEngine
{
    public function post(PostLedgerTransactionCommand $command): PostedLedgerTransaction
    {
        return DB::transaction(function () use ($command): PostedLedgerTransaction {
            return LedgerWriteContext::run(function () use ($command): PostedLedgerTransaction {
                return $this->postCore($command);
            });
        });
    }

    /**
     * post()'s only caller-facing entry point besides post() itself.
     * PostLedgerTransactionCommand's own constructor rejects both
     * LedgerTransactionType::Reversal and ::AdministrativeAdjustment
     * outright, so this can never receive either special type - there is
     * no generic path capable of constructing one, matching
     * writeReversalEntriesWithinTransaction()'s own established
     * precedent exactly.
     */
    private function postCore(PostLedgerTransactionCommand $command): PostedLedgerTransaction
    {
        return $this->insertTransactionAndEntries(
            $command->type,
            $command->businessReference,
            $command->description,
            $command->actorId,
            $command->relatedEntityType,
            $command->relatedEntityId,
            $command->correlationId,
            $command->entries,
        );
    }

    /**
     * The only path by which an administrative-adjustment
     * LedgerTransaction/LedgerEntry pair is ever created - reserved
     * exclusively for AdministrativeAdjustmentService (Task 2.5.1),
     * mirroring writeReversalEntriesWithinTransaction()'s own narrowly
     * named, narrowly scoped shape. post()/postCore() cannot be used for
     * this: PostLedgerTransactionCommand's own constructor rejects
     * LedgerTransactionType::AdministrativeAdjustment outright, exactly
     * as it already does for Reversal.
     *
     * Opens no transaction of its own - asserts an active database
     * transaction, an active LedgerWriteContext, an active
     * AdministrativeAdjustmentWriteContext, and that $type is exactly
     * LedgerTransactionType::AdministrativeAdjustment are all true before
     * writing anything. That last check is defense-in-depth, not merely
     * decorative: it is the same "verify, don't just assume" discipline
     * applied everywhere else in this class (e.g. the actor-existence
     * recheck alongside the FK) - a caller cannot silently repurpose this
     * method for a different transaction type even if every other
     * precondition happens to be satisfied. The caller supplies the
     * already-resolved entries (exactly two: the target account and
     * platform_suspense, in whichever debit/credit arrangement its
     * direction requires) - this method never decides which accounts or
     * amounts are involved, only that every precondition holds before
     * delegating to the same shared core post() itself uses.
     *
     * @param  list<PostLedgerEntryCommand>  $entries
     */
    public function writeAdministrativeAdjustmentWithinTransaction(
        LedgerTransactionType $type,
        string $businessReference,
        string $description,
        User $actor,
        string $correlationId,
        array $entries,
    ): PostedLedgerTransaction {
        if (DB::transactionLevel() < 1) {
            throw new LedgerInvariantViolationException('writeAdministrativeAdjustmentWithinTransaction requires an active database transaction.');
        }

        if (! LedgerWriteContext::isActive()) {
            throw new LedgerInvariantViolationException('writeAdministrativeAdjustmentWithinTransaction requires an active LedgerWriteContext.');
        }

        if (! AdministrativeAdjustmentWriteContext::isActive()) {
            throw new LedgerInvariantViolationException('writeAdministrativeAdjustmentWithinTransaction requires an active AdministrativeAdjustmentWriteContext.');
        }

        if ($type !== LedgerTransactionType::AdministrativeAdjustment) {
            throw new LedgerInvariantViolationException('writeAdministrativeAdjustmentWithinTransaction only accepts LedgerTransactionType::AdministrativeAdjustment.');
        }

        if (! $actor->exists || $actor->getKey() === null) {
            throw new LedgerInvariantViolationException('The actor must be a persisted user.');
        }

        return $this->insertTransactionAndEntries(
            LedgerTransactionType::AdministrativeAdjustment,
            $businessReference,
            $description,
            $actor->getKey(),
            null,
            null,
            $correlationId,
            $entries,
        );
    }

    /**
     * The single shared write core behind both postCore() and
     * writeAdministrativeAdjustmentWithinTransaction() - insert-first,
     * catch-conflict, lock-in-order, balance-check, insert-entries,
     * assert-balanced. Private: every caller of this class must go
     * through one of the two type-specific, precondition-asserting public
     * methods above (or writeReversalEntriesWithinTransaction() for
     * reversals, which never calls this at all - reversal's own entries
     * are always a mechanical inversion of an existing transaction, never
     * built the same way).
     *
     * @param  list<PostLedgerEntryCommand>  $entries
     */
    private function insertTransactionAndEntries(
        LedgerTransactionType $type,
        string $businessReference,
        string $description,
        ?int $actorId,
        ?RelatedEntityType $relatedEntityType,
        ?string $relatedEntityId,
        string $correlationId,
        array $entries,
    ): PostedLedgerTransaction {
        if ($actorId !== null && ! User::query()->whereKey($actorId)->exists()) {
            // The restrictive FK on ledger_transactions.actor_id would
            // also reject this at insert time - this check exists to
            // fail with a clear, typed exception rather than relying
            // solely on a raw FK-violation QueryException.
            throw new LedgerInvariantViolationException('The actor no longer exists.');
        }

        try {
            $transaction = new LedgerTransaction;
            $transaction->business_reference = $businessReference;
            $transaction->type = $type;
            $transaction->currency_code = Currency::USD;
            $transaction->currency_scale = Currency::USD->scale();
            $transaction->description = $description;
            $transaction->actor_id = $actorId;
            $transaction->related_entity_type = $relatedEntityType;
            $transaction->related_entity_id = $relatedEntityId;
            $transaction->correlation_id = $correlationId;
            $transaction->reverses_transaction_id = null;
            $transaction->save();
        } catch (UniqueConstraintViolationException $exception) {
            return $this->reconcileGenericReplay($type, $businessReference, $description, $actorId, $relatedEntityType, $relatedEntityId, $entries, $exception);
        }

        $accountsById = $this->lockAccountsInOrder($entries);

        foreach ($entries as $entryCommand) {
            $this->assertProjectedBalanceAllowed($accountsById[$entryCommand->walletAccountId], $entryCommand);
        }

        $insertedEntries = [];
        foreach ($entries as $entryCommand) {
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
    }

    /**
     * The reactive replay-recovery path shared by postCore() and
     * writeAdministrativeAdjustmentWithinTransaction() (both insert-first,
     * catch-conflict). Generalized so post()'s own prior hardcoded
     * behavior (type/description/actor/related-entity taken from a
     * PostLedgerTransactionCommand) is reproduced exactly when called
     * from postCore(), while the adjustment path supplies the same shape
     * from its own raw parameters instead.
     *
     * @param  list<PostLedgerEntryCommand>  $expectedEntries
     */
    private function reconcileGenericReplay(
        LedgerTransactionType $expectedType,
        string $businessReference,
        string $expectedDescription,
        ?int $expectedActorId,
        ?RelatedEntityType $expectedRelatedEntityType,
        ?string $expectedRelatedEntityId,
        array $expectedEntries,
        UniqueConstraintViolationException $exception,
    ): PostedLedgerTransaction {
        $existing = LedgerTransaction::query()
            ->where('business_reference', $businessReference)
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
            $expectedType,
            $expectedDescription,
            $expectedActorId,
            $expectedRelatedEntityType,
            $expectedRelatedEntityId,
            null,
            $expectedEntries,
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
     * the ULID id is itself time-ordered) via LedgerBalanceAccumulator -
     * Task 2.6's own single authoritative place for this arithmetic, never
     * a raw SQL SUM(). The query, its lockForUpdate() (a
     * current/locking read: under REPEATABLE READ, a plain read here could
     * still observe a stale snapshot from before this transaction's first
     * query, even after the account row itself was locked), and its
     * ordering are byte-identical to before - only how the result rows are
     * materialized changed: cursor() instead of get(), so an account with
     * a very long history no longer requires a fully hydrated Eloquent
     * Collection in memory. No fingerprint is computed here - passing null
     * (LedgerBalanceCalculator::fold()'s own default) keeps this hot path
     * free of any hashing work.
     */
    private function deriveCurrentBalance(WalletAccount $account): Money
    {
        $entries = LedgerEntry::query()
            ->where('wallet_account_id', $account->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->cursor();

        return LedgerBalanceCalculator::fold($entries, $account->account_type)->balance;
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
