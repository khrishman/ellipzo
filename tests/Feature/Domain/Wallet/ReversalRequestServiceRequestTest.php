<?php

use App\Domain\Wallet\Enums\ReversalRequestStatus;
use App\Domain\Wallet\Exceptions\DuplicateFinancialEventException;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Exceptions\UnknownLedgerTransactionException;
use App\Domain\Wallet\Models\ReversalRequest;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\ReversalRequestService;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Support\Str;

test('a fresh request creates a pending reversal request with the expected fields', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-request-fresh');
    $engine = new LedgerPostingEngine;
    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:request-fresh');

    $actor = User::factory()->create();
    $service = new ReversalRequestService($engine);
    $command = $this->reversalCommand($original->id, reason: 'Customer requested refund', actor: $actor);

    $request = $service->request($command);

    expect($request->exists)->toBeTrue();
    expect($request->status)->toBe(ReversalRequestStatus::Pending);
    expect($request->original_ledger_transaction_id)->toBe($original->id);
    expect($request->idempotency_key)->toBe($command->idempotencyKey);
    expect($request->actor_id)->toBe($actor->id);
    expect($request->reason)->toBe('Customer requested refund');
    expect($request->reversal_transaction_id)->toBeNull();
    expect($request->failure_code)->toBeNull();
    expect($request->applied_at)->toBeNull();
    expect($request->review_required_at)->toBeNull();

    $expectedFingerprint = hash('sha256', json_encode([
        'version' => 1,
        'original_transaction_id' => $original->id,
        'actor_id' => $actor->id,
        'reason' => 'Customer requested refund',
    ], JSON_THROW_ON_ERROR));

    expect($request->fingerprint)->toBe($expectedFingerprint);
});

test('a repeated call with the same idempotency key returns the original request unchanged', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-request-same-key');
    $engine = new LedgerPostingEngine;
    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:request-same-key');

    $service = new ReversalRequestService($engine);
    $command = $this->reversalCommand($original->id, reason: 'Same reason');

    $first = $service->request($command);
    $second = $service->request($command);

    expect($second->id)->toBe($first->id);
    expect(ReversalRequest::where('original_ledger_transaction_id', $original->id)->count())->toBe(1);
});

test('a different idempotency key with the same original and same fingerprint returns the existing request', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-request-diff-key-same-fingerprint');
    $engine = new LedgerPostingEngine;
    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:request-diff-key-same-fingerprint');

    $service = new ReversalRequestService($engine);
    $first = $service->request($this->reversalCommand($original->id, idempotencyKey: 'attempt-one', reason: 'Same reason'));
    $second = $service->request($this->reversalCommand($original->id, idempotencyKey: 'attempt-two', reason: 'Same reason'));

    expect($second->id)->toBe($first->id);
    expect(ReversalRequest::where('original_ledger_transaction_id', $original->id)->count())->toBe(1);
});

test('a different idempotency key with the same original but a different fingerprint conflicts', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-request-diff-key-diff-fingerprint');
    $engine = new LedgerPostingEngine;
    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:request-diff-key-diff-fingerprint');

    $service = new ReversalRequestService($engine);
    $service->request($this->reversalCommand($original->id, idempotencyKey: 'attempt-one', reason: 'Reason A'));

    expect(fn () => $service->request($this->reversalCommand($original->id, idempotencyKey: 'attempt-two', reason: 'Reason B')))
        ->toThrow(DuplicateFinancialEventException::class);

    expect(ReversalRequest::where('original_ledger_transaction_id', $original->id)->count())->toBe(1);
});

test('requesting a reversal for an unknown original transaction throws UnknownLedgerTransactionException', function () {
    $engine = new LedgerPostingEngine;
    $service = new ReversalRequestService($engine);

    expect(fn () => $service->request($this->reversalCommand(strtolower((string) Str::ulid()))))
        ->toThrow(UnknownLedgerTransactionException::class);

    expect(ReversalRequest::count())->toBe(0);
});

test('a reversal transaction cannot itself be reversed', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-request-no-double-reversal');
    $engine = new LedgerPostingEngine;
    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:request-no-double-reversal');

    $service = new ReversalRequestService($engine);
    $reversalRequest = $service->request($this->reversalCommand($original->id));
    $applied = $service->execute($reversalRequest);

    expect(fn () => $service->request($this->reversalCommand($applied->reversal_transaction_id)))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a transaction that has already been reversed cannot be reversed again, even as a distinct request attempt', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-request-already-reversed');
    $engine = new LedgerPostingEngine;
    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:request-already-reversed');

    $service = new ReversalRequestService($engine);
    $service->execute($service->request($this->reversalCommand($original->id)));

    expect(ReversalRequest::where('original_ledger_transaction_id', $original->id)->count())->toBe(1);

    // Eligibility (including "not already reversed") is always validated
    // first, before any idempotency-aware lookup - a distinct second
    // request attempt against an already-reversed original is rejected
    // outright, not silently reconciled against the existing applied
    // request.
    expect(fn () => $service->request($this->reversalCommand($original->id, idempotencyKey: 'a-second-distinct-attempt')))
        ->toThrow(LedgerInvariantViolationException::class);

    expect(ReversalRequest::where('original_ledger_transaction_id', $original->id)->count())->toBe(1);
});

test('an original with fewer than two entries is rejected and no request is created', function () {
    $transactionId = $this->insertRawLedgerTransaction();
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $transactionId, 'entry_type' => 'credit', 'amount_atomic' => 100]);

    $engine = new LedgerPostingEngine;
    $service = new ReversalRequestService($engine);

    expect(fn () => $service->request($this->reversalCommand($transactionId)))
        ->toThrow(LedgerInvariantViolationException::class);

    expect(ReversalRequest::count())->toBe(0);
});

test('an original with unbalanced entries is rejected and no request is created', function () {
    $transactionId = $this->insertRawLedgerTransaction();
    $accountA = $this->insertRawWalletAccount(['scope_key' => 'unbalanced-a']);
    $accountB = $this->insertRawWalletAccount(['scope_key' => 'unbalanced-b']);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $transactionId, 'wallet_account_id' => $accountA, 'entry_type' => 'debit', 'amount_atomic' => 100]);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $transactionId, 'wallet_account_id' => $accountB, 'entry_type' => 'credit', 'amount_atomic' => 200]);

    $engine = new LedgerPostingEngine;
    $service = new ReversalRequestService($engine);

    expect(fn () => $service->request($this->reversalCommand($transactionId)))
        ->toThrow(LedgerInvariantViolationException::class);

    expect(ReversalRequest::count())->toBe(0);
});

test('an original with a non-positive entry amount is rejected and no request is created', function () {
    $transactionId = $this->insertRawLedgerTransaction();
    $accountA = $this->insertRawWalletAccount(['scope_key' => 'non-positive-a']);
    $accountB = $this->insertRawWalletAccount(['scope_key' => 'non-positive-b']);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $transactionId, 'wallet_account_id' => $accountA, 'entry_type' => 'debit', 'amount_atomic' => 0]);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $transactionId, 'wallet_account_id' => $accountB, 'entry_type' => 'credit', 'amount_atomic' => 0]);

    $engine = new LedgerPostingEngine;
    $service = new ReversalRequestService($engine);

    expect(fn () => $service->request($this->reversalCommand($transactionId)))
        ->toThrow(LedgerInvariantViolationException::class);

    expect(ReversalRequest::count())->toBe(0);
});
