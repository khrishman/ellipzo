<?php

use App\Domain\Wallet\Enums\ReversalRequestStatus;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Models\ReversalRequest;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\LedgerWriteContext;
use App\Domain\Wallet\Services\ReversalRequestWriteContext;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use Illuminate\Support\Facades\DB;

// The "requires an active database transaction" precondition
// (DB::transactionLevel() < 1) is asserted in production code but is not
// independently testable here: RefreshDatabase itself wraps every test in
// its own ambient transaction (RefreshDatabase::beginDatabaseTransaction()),
// so DB::transactionLevel() is always >= 1 throughout this entire suite,
// with no way to observe the "no transaction" state from within a test
// without unwinding RefreshDatabase's own wrapping transaction. The other
// two precondition checks (LedgerWriteContext, ReversalRequestWriteContext)
// are independent static flags unaffected by this and are fully testable
// below.

test('it is rejected without an active LedgerWriteContext', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-precondition-no-ledger-context');
    $engine = new LedgerPostingEngine;
    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:precondition-no-ledger-context');
    $request = $this->createPendingReversalRequest($original->id);

    DB::transaction(function () use ($engine, $request, $original): void {
        ReversalRequestWriteContext::run(function () use ($engine, $request, $original): void {
            expect(fn () => $engine->writeReversalEntriesWithinTransaction($request, $original))
                ->toThrow(LedgerInvariantViolationException::class);
        });
    });
});

test('it is rejected without an active ReversalRequestWriteContext', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-precondition-no-reversal-context');
    $engine = new LedgerPostingEngine;
    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:precondition-no-reversal-context');
    $request = $this->createPendingReversalRequest($original->id);

    DB::transaction(function () use ($engine, $request, $original): void {
        LedgerWriteContext::run(function () use ($engine, $request, $original): void {
            expect(fn () => $engine->writeReversalEntriesWithinTransaction($request, $original))
                ->toThrow(LedgerInvariantViolationException::class);
        });
    });
});

test('it is rejected for an unpersisted request', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-precondition-unpersisted');
    $engine = new LedgerPostingEngine;
    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:precondition-unpersisted');

    $unpersisted = new ReversalRequest;
    $unpersisted->original_ledger_transaction_id = $original->id;
    $unpersisted->status = ReversalRequestStatus::Pending;

    DB::transaction(function () use ($engine, $unpersisted, $original): void {
        ReversalRequestWriteContext::run(function () use ($engine, $unpersisted, $original): void {
            LedgerWriteContext::run(function () use ($engine, $unpersisted, $original): void {
                expect(fn () => $engine->writeReversalEntriesWithinTransaction($unpersisted, $original))
                    ->toThrow(LedgerInvariantViolationException::class);
            });
        });
    });
});

test('it is rejected for a request that is not pending or review-required', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-precondition-wrong-status');
    $engine = new LedgerPostingEngine;
    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:precondition-wrong-status');
    $requestId = $this->insertRawReversalRequest(['original_ledger_transaction_id' => $original->id, 'status' => 'rejected']);
    $request = ReversalRequest::findOrFail($requestId);

    DB::transaction(function () use ($engine, $request, $original): void {
        ReversalRequestWriteContext::run(function () use ($engine, $request, $original): void {
            LedgerWriteContext::run(function () use ($engine, $request, $original): void {
                expect(fn () => $engine->writeReversalEntriesWithinTransaction($request, $original))
                    ->toThrow(LedgerInvariantViolationException::class);
            });
        });
    });
});

test('it is rejected when the supplied original does not match the request', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-precondition-mismatch');
    $engine = new LedgerPostingEngine;
    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:precondition-mismatch');
    $unrelatedOriginal = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:precondition-mismatch-unrelated');
    $request = $this->createPendingReversalRequest($original->id);

    DB::transaction(function () use ($engine, $request, $unrelatedOriginal): void {
        ReversalRequestWriteContext::run(function () use ($engine, $request, $unrelatedOriginal): void {
            LedgerWriteContext::run(function () use ($engine, $request, $unrelatedOriginal): void {
                expect(fn () => $engine->writeReversalEntriesWithinTransaction($request, $unrelatedOriginal))
                    ->toThrow(LedgerInvariantViolationException::class);
            });
        });
    });
});

test('all preconditions satisfied together succeed', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-precondition-success');
    $engine = new LedgerPostingEngine;
    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:precondition-success');
    $request = $this->createPendingReversalRequest($original->id);

    $reversal = DB::transaction(function () use ($engine, $request, $original) {
        return ReversalRequestWriteContext::run(function () use ($engine, $request, $original) {
            return LedgerWriteContext::run(function () use ($engine, $request, $original) {
                return $engine->writeReversalEntriesWithinTransaction($request, $original);
            });
        });
    });

    expect($reversal->exists)->toBeTrue();
    expect($reversal->reverses_transaction_id)->toBe($original->id);
});
