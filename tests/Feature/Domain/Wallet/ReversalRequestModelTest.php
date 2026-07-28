<?php

use App\Domain\Wallet\Enums\ReversalFailureCode;
use App\Domain\Wallet\Enums\ReversalRequestStatus;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Models\ReversalRequest;
use App\Domain\Wallet\Services\ReversalRequestWriteContext;
use Illuminate\Support\Str;

// ---------------------------------------------------------------
// Direct-write protection (outside the service / outside the context)
// ---------------------------------------------------------------

test('direct creation outside the service is blocked', function () {
    $request = new ReversalRequest;
    $request->original_ledger_transaction_id = $this->insertRawLedgerTransaction();
    $request->idempotency_key = 'idem:'.strtolower((string) Str::ulid());
    $request->fingerprint = str_repeat('a', 64);
    $request->status = ReversalRequestStatus::Pending;
    $request->reason = 'Test';
    $request->correlation_id = (string) Str::uuid();

    expect(fn () => $request->save())->toThrow(LedgerInvariantViolationException::class);
});

test('a direct pending to applied update outside the service is blocked', function () {
    $request = $this->createPendingReversalRequest();
    $reversalTransactionId = $this->insertRawLedgerTransaction();

    $request->status = ReversalRequestStatus::Applied;
    $request->reversal_transaction_id = $reversalTransactionId;
    $request->applied_at = now();

    expect(fn () => $request->save())->toThrow(LedgerInvariantViolationException::class);
});

test('a direct pending to review-required update outside the service is blocked', function () {
    $request = $this->createPendingReversalRequest();

    $request->status = ReversalRequestStatus::ReviewRequired;
    $request->failure_code = ReversalFailureCode::InsufficientBalance;
    $request->review_required_at = now();

    expect(fn () => $request->save())->toThrow(LedgerInvariantViolationException::class);
});

test('a direct outcome-field mutation outside the service is blocked', function () {
    $request = $this->createPendingReversalRequest();

    $request->failure_code = ReversalFailureCode::InsufficientBalance;

    expect(fn () => $request->save())->toThrow(LedgerInvariantViolationException::class);
});

test('mutating an immutable field outside the service is blocked', function () {
    $request = $this->createPendingReversalRequest();

    $request->reason = 'Changed reason';

    expect(fn () => $request->save())->toThrow(LedgerInvariantViolationException::class);
});

test('deletion is always forbidden, both outside and inside an authorized context', function () {
    $request = $this->createPendingReversalRequest();

    expect(fn () => $request->delete())->toThrow(LogicException::class);

    ReversalRequestWriteContext::run(function () use ($request): void {
        expect(fn () => $request->delete())->toThrow(LogicException::class);
    });
});

// ---------------------------------------------------------------
// Immutable fields, even inside an authorized context
// ---------------------------------------------------------------

test('mutating an immutable field is blocked even inside an authorized transition context', function () {
    $request = $this->createPendingReversalRequest();
    $reversalTransactionId = $this->insertRawLedgerTransaction();

    ReversalRequestWriteContext::run(function () use ($request, $reversalTransactionId): void {
        $request->status = ReversalRequestStatus::Applied;
        $request->reversal_transaction_id = $reversalTransactionId;
        $request->applied_at = now();
        $request->reason = 'Changed reason';

        expect(fn () => $request->save())->toThrow(LedgerInvariantViolationException::class);
    });
});

// ---------------------------------------------------------------
// Allowed transitions
// ---------------------------------------------------------------

test('a valid pending to applied transition succeeds inside the context', function () {
    $request = $this->createPendingReversalRequest();
    $reversalTransactionId = $this->insertRawLedgerTransaction();

    ReversalRequestWriteContext::run(function () use ($request, $reversalTransactionId): void {
        $request->status = ReversalRequestStatus::Applied;
        $request->reversal_transaction_id = $reversalTransactionId;
        $request->applied_at = now();
        $request->save();
    });

    expect($request->fresh()->status)->toBe(ReversalRequestStatus::Applied);
});

test('a valid pending to review-required transition succeeds inside the context', function () {
    $request = $this->createPendingReversalRequest();

    ReversalRequestWriteContext::run(function () use ($request): void {
        $request->status = ReversalRequestStatus::ReviewRequired;
        $request->failure_code = ReversalFailureCode::InsufficientBalance;
        $request->review_required_at = now();
        $request->save();
    });

    expect($request->fresh()->status)->toBe(ReversalRequestStatus::ReviewRequired);
});

test('a valid review-required to applied transition succeeds inside the context and preserves review_required_at as history', function () {
    $request = $this->createPendingReversalRequest();

    ReversalRequestWriteContext::run(function () use ($request): void {
        $request->status = ReversalRequestStatus::ReviewRequired;
        $request->failure_code = ReversalFailureCode::InsufficientBalance;
        $request->review_required_at = now();
        $request->save();
    });

    $reversalTransactionId = $this->insertRawLedgerTransaction();

    ReversalRequestWriteContext::run(function () use ($request, $reversalTransactionId): void {
        $request->status = ReversalRequestStatus::Applied;
        $request->reversal_transaction_id = $reversalTransactionId;
        $request->failure_code = null;
        $request->applied_at = now();
        $request->save();
    });

    $fresh = $request->fresh();
    expect($fresh->status)->toBe(ReversalRequestStatus::Applied);
    expect($fresh->review_required_at)->not->toBeNull();
    expect($fresh->failure_code)->toBeNull();
});

// ---------------------------------------------------------------
// Forbidden transitions
// ---------------------------------------------------------------

test('applied is a terminal state with no outgoing transition', function () {
    $request = $this->createPendingReversalRequest();
    $reversalTransactionId = $this->insertRawLedgerTransaction();

    ReversalRequestWriteContext::run(function () use ($request, $reversalTransactionId): void {
        $request->status = ReversalRequestStatus::Applied;
        $request->reversal_transaction_id = $reversalTransactionId;
        $request->applied_at = now();
        $request->save();
    });

    ReversalRequestWriteContext::run(function () use ($request): void {
        $request->status = ReversalRequestStatus::Pending;
        expect(fn () => $request->save())->toThrow(LedgerInvariantViolationException::class);
    });
});

test('review-required cannot transition back to pending', function () {
    $request = $this->createPendingReversalRequest();

    ReversalRequestWriteContext::run(function () use ($request): void {
        $request->status = ReversalRequestStatus::ReviewRequired;
        $request->failure_code = ReversalFailureCode::InsufficientBalance;
        $request->review_required_at = now();
        $request->save();
    });

    ReversalRequestWriteContext::run(function () use ($request): void {
        $request->status = ReversalRequestStatus::Pending;
        $request->failure_code = null;
        $request->review_required_at = null;
        expect(fn () => $request->save())->toThrow(LedgerInvariantViolationException::class);
    });
});

test('pending cannot transition directly to rejected', function () {
    $request = $this->createPendingReversalRequest();

    ReversalRequestWriteContext::run(function () use ($request): void {
        $request->status = ReversalRequestStatus::Rejected;
        expect(fn () => $request->save())->toThrow(LedgerInvariantViolationException::class);
    });
});

// ---------------------------------------------------------------
// Per-status field-consistency invariants
// ---------------------------------------------------------------

test('a pending to applied transition is rejected if the reversal transaction ID is missing', function () {
    $request = $this->createPendingReversalRequest();

    ReversalRequestWriteContext::run(function () use ($request): void {
        $request->status = ReversalRequestStatus::Applied;
        $request->applied_at = now();

        expect(fn () => $request->save())->toThrow(LedgerInvariantViolationException::class);
    });
});

test('a pending to applied transition is rejected if applied_at is missing', function () {
    $request = $this->createPendingReversalRequest();
    $reversalTransactionId = $this->insertRawLedgerTransaction();

    ReversalRequestWriteContext::run(function () use ($request, $reversalTransactionId): void {
        $request->status = ReversalRequestStatus::Applied;
        $request->reversal_transaction_id = $reversalTransactionId;

        expect(fn () => $request->save())->toThrow(LedgerInvariantViolationException::class);
    });
});

test('a pending to review-required transition is rejected without a failure code', function () {
    $request = $this->createPendingReversalRequest();

    ReversalRequestWriteContext::run(function () use ($request): void {
        $request->status = ReversalRequestStatus::ReviewRequired;
        $request->review_required_at = now();

        expect(fn () => $request->save())->toThrow(LedgerInvariantViolationException::class);
    });
});

test('a pending to review-required transition is rejected without a review-required timestamp', function () {
    $request = $this->createPendingReversalRequest();

    ReversalRequestWriteContext::run(function () use ($request): void {
        $request->status = ReversalRequestStatus::ReviewRequired;
        $request->failure_code = ReversalFailureCode::InsufficientBalance;

        expect(fn () => $request->save())->toThrow(LedgerInvariantViolationException::class);
    });
});

test('creating a pending request with a pre-populated outcome field is rejected', function () {
    ReversalRequestWriteContext::run(function (): void {
        $request = new ReversalRequest;
        $request->original_ledger_transaction_id = $this->insertRawLedgerTransaction();
        $request->idempotency_key = 'idem:'.strtolower((string) Str::ulid());
        $request->fingerprint = str_repeat('a', 64);
        $request->status = ReversalRequestStatus::Pending;
        $request->reason = 'Test';
        $request->correlation_id = (string) Str::uuid();
        $request->applied_at = now();

        expect(fn () => $request->save())->toThrow(LedgerInvariantViolationException::class);
    });
});

test('a valid pending request creates successfully inside the context', function () {
    $request = $this->createPendingReversalRequest();

    expect($request->exists)->toBeTrue();
    expect($request->status)->toBe(ReversalRequestStatus::Pending);
    expect($request->reversal_transaction_id)->toBeNull();
    expect($request->failure_code)->toBeNull();
    expect($request->applied_at)->toBeNull();
    expect($request->review_required_at)->toBeNull();
});
