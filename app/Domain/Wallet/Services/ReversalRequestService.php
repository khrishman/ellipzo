<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\RequestLedgerReversalCommand;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\ReversalFailureCode;
use App\Domain\Wallet\Enums\ReversalRequestStatus;
use App\Domain\Wallet\Exceptions\DuplicateFinancialEventException;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Exceptions\UnknownLedgerTransactionException;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Models\ReversalRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The two-layer coordinator for reversals: request() owns the durable
 * reversal_requests lifecycle and never touches ledger_transactions/
 * ledger_entries; execute() owns safely applying a pending/review-required
 * request through LedgerPostingEngine, the only class that writes those
 * tables. Nothing here duplicates Task 2.4's balancing, locking, replay,
 * or entry-writing logic - it is all reused via
 * LedgerPostingEngine::writeReversalEntriesWithinTransaction().
 *
 * No AuditEvent row is created here. AuditEvent::record() requires a
 * non-nullable actor and documents itself as covering sensitive staff
 * actions; the durable reversal_requests row plus the immutable reversal
 * ledger transaction it produces are themselves the generic financial
 * record for this task. A future staff-facing controller that exposes
 * rejection or a forced/manual reversal action is the point where
 * AuditEvent involvement belongs, not here.
 */
final class ReversalRequestService
{
    public function __construct(
        private readonly LedgerPostingEngine $ledgerPostingEngine,
    ) {}

    public function request(RequestLedgerReversalCommand $command): ReversalRequest
    {
        return DB::transaction(function () use ($command): ReversalRequest {
            $original = LedgerTransaction::query()
                ->whereKey($command->originalTransactionId)
                ->with('entries')
                ->lockForUpdate()
                ->first();

            if ($original === null) {
                throw new UnknownLedgerTransactionException('The original ledger transaction does not exist.');
            }

            $this->assertOriginalEligible($original);

            $fingerprint = self::computeFingerprint($command->originalTransactionId, $command->actorId, $command->reason);

            return ReversalRequestWriteContext::run(function () use ($command, $original, $fingerprint): ReversalRequest {
                try {
                    $request = new ReversalRequest;
                    $request->original_ledger_transaction_id = $original->id;
                    $request->idempotency_key = $command->idempotencyKey;
                    $request->fingerprint = $fingerprint;
                    $request->status = ReversalRequestStatus::Pending;
                    $request->actor_id = $command->actorId;
                    $request->reason = $command->reason;
                    $request->correlation_id = $command->correlationId;
                    $request->save();

                    return $request;
                } catch (UniqueConstraintViolationException $exception) {
                    return $this->reconcileRequestReplay($command, $fingerprint, $exception);
                }
            });
        });
    }

    public function execute(ReversalRequest $request): ReversalRequest
    {
        if (! $request->exists) {
            throw new LedgerInvariantViolationException('A reversal request must be persisted before it can be executed.');
        }

        $requestId = $request->getKey();

        return DB::transaction(function () use ($requestId): ReversalRequest {
            return ReversalRequestWriteContext::run(function () use ($requestId): ReversalRequest {
                return LedgerWriteContext::run(function () use ($requestId): ReversalRequest {
                    // Never trust the caller's in-memory model: re-fetch and
                    // lock by canonical ID so this method observes the true
                    // committed state, including any outcome a concurrent
                    // or prior call already produced.
                    $locked = ReversalRequest::query()->whereKey($requestId)->lockForUpdate()->first();

                    if ($locked === null) {
                        throw new LedgerInvariantViolationException('The reversal request no longer exists.');
                    }

                    if ($locked->status === ReversalRequestStatus::Applied) {
                        return $locked;
                    }

                    if ($locked->status === ReversalRequestStatus::Rejected) {
                        throw new LedgerInvariantViolationException('A rejected reversal request cannot be executed.');
                    }

                    $original = LedgerTransaction::query()
                        ->whereKey($locked->original_ledger_transaction_id)
                        ->with('entries')
                        ->lockForUpdate()
                        ->first();

                    if ($original === null) {
                        throw new UnknownLedgerTransactionException('The original ledger transaction no longer exists.');
                    }

                    $this->assertOriginalEligible($original, requireNotAlreadyReversed: false);

                    try {
                        $reversal = $this->ledgerPostingEngine->writeReversalEntriesWithinTransaction($locked, $original);
                    } catch (InsufficientBalanceException) {
                        // No ledger transaction/entry was created - the
                        // engine checks balances before inserting anything.
                        // This outcome is saved and returned normally (not
                        // rethrown) so the surrounding transaction commits
                        // the review-required status instead of rolling
                        // back.
                        $locked->status = ReversalRequestStatus::ReviewRequired;
                        $locked->failure_code = ReversalFailureCode::InsufficientBalance;
                        // Set only on the first occurrence, then preserved
                        // forever - never overwritten by a later, still-
                        // insufficient attempt.
                        $locked->review_required_at ??= now();
                        // Explicitly touched on every attempt, including a
                        // repeat that changes nothing else: updated_at is
                        // the record of "latest processing attempt," and
                        // must not depend on whether some other field also
                        // happened to change - Eloquent's save() is
                        // correctly a genuine no-op (it never even calls
                        // updateTimestamps()) when nothing is dirty, so an
                        // identical repeated failure would otherwise leave
                        // updated_at completely untouched.
                        $locked->updated_at = now();
                        $locked->save();

                        return $locked;
                    }

                    $locked->status = ReversalRequestStatus::Applied;
                    $locked->reversal_transaction_id = $reversal->id;
                    $locked->failure_code = null;
                    $locked->applied_at = now();
                    $locked->save();

                    return $locked;
                });
            });
        });
    }

    public function requestAndExecute(RequestLedgerReversalCommand $command): ReversalRequest
    {
        return $this->execute($this->request($command));
    }

    private function reconcileRequestReplay(
        RequestLedgerReversalCommand $command,
        string $fingerprint,
        UniqueConstraintViolationException $exception,
    ): ReversalRequest {
        $existing = ReversalRequest::query()
            ->where('idempotency_key', $command->idempotencyKey)
            ->lockForUpdate()
            ->first();

        $existing ??= ReversalRequest::query()
            ->where('original_ledger_transaction_id', $command->originalTransactionId)
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            // Neither identity that could explain a unique-constraint
            // conflict on this insert was found - an unexplained anomaly,
            // never reinterpreted as a known duplicate-event conflict.
            throw $exception;
        }

        if ($existing->fingerprint !== $fingerprint) {
            throw new DuplicateFinancialEventException('A different reversal request already exists for this original transaction.');
        }

        return $existing;
    }

    /**
     * Validated on the original at both request() and execute() time - the
     * same immutable facts, checked twice: once before a durable request
     * is ever created, and again (defense-in-depth) against the freshly
     * locked row immediately before execution.
     *
     * $requireNotAlreadyReversed is true only at request() time. Because
     * reversal_requests.original_ledger_transaction_id is itself uniquely
     * constrained, at most one ReversalRequest can ever exist for a given
     * original - so if a reversal already exists by the time execute()
     * re-checks, it can only be this same request's own target (produced
     * by an earlier attempt whose own status update didn't commit), never
     * a different request's. Re-running this check at execute() time
     * would reject that legitimate resumption before
     * writeReversalEntriesWithinTransaction() ever gets a chance to
     * recover and link it - that recovery, not this check, is what's
     * supposed to handle it there.
     */
    private function assertOriginalEligible(LedgerTransaction $original, bool $requireNotAlreadyReversed = true): void
    {
        if ($original->type === LedgerTransactionType::Reversal || $original->reverses_transaction_id !== null) {
            throw new LedgerInvariantViolationException('A reversal transaction cannot itself be reversed.');
        }

        if ($requireNotAlreadyReversed && LedgerTransaction::query()->where('reverses_transaction_id', $original->id)->exists()) {
            throw new LedgerInvariantViolationException('This transaction has already been reversed.');
        }

        if ($original->currency_code !== Currency::USD || $original->currency_scale !== Currency::USD->scale()) {
            throw new LedgerInvariantViolationException('The original transaction has an unexpected currency or scale.');
        }

        if (! $original->relationLoaded('entries')) {
            $original->load('entries');
        }

        $entries = $original->entries;

        if ($entries->count() < 2) {
            throw new LedgerInvariantViolationException('The original transaction does not have enough entries to reverse.');
        }

        if (($original->related_entity_type === null) !== ($original->related_entity_id === null)) {
            throw new LedgerInvariantViolationException('The original transaction has inconsistent related-entity metadata.');
        }

        $debitTotal = Money::zero(Currency::USD);
        $creditTotal = Money::zero(Currency::USD);

        foreach ($entries as $entry) {
            $amount = Money::fromAtomic($entry->amount_atomic, Currency::USD);

            if (! $amount->isPositive()) {
                throw new LedgerInvariantViolationException('The original transaction contains a non-positive entry amount.');
            }

            if ($entry->entry_type === LedgerEntryType::Debit) {
                $debitTotal = $debitTotal->add($amount);
            } else {
                $creditTotal = $creditTotal->add($amount);
            }
        }

        if (! $debitTotal->equals($creditTotal)) {
            throw new LedgerInvariantViolationException('The original transaction entries do not balance.');
        }
    }

    /**
     * A fixed four-key shape - version, original transaction ID, actor ID
     * (or null), normalized reason - encoded via deterministic PHP JSON
     * with a fixed key order and no floats, then hashed. Idempotency key
     * and correlation ID are deliberately excluded: they identify a
     * request attempt, not the reversal's own identity and intent.
     */
    private static function computeFingerprint(string $originalTransactionId, ?int $actorId, string $reason): string
    {
        $payload = [
            'version' => 1,
            'original_transaction_id' => $originalTransactionId,
            'actor_id' => $actorId,
            'reason' => $reason,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return hash('sha256', $json);
    }
}
