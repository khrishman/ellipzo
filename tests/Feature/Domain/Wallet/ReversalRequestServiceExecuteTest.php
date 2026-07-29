<?php

use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\ReversalFailureCode;
use App\Domain\Wallet\Enums\ReversalRequestStatus;
use App\Domain\Wallet\Exceptions\DuplicateFinancialEventException;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Models\ReversalRequest;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\ReversalRequestService;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('a successful execution creates a balanced reversal transaction with inverted entries', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-execute-success');
    $engine = new LedgerPostingEngine;
    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:execute-success', amount: 750);

    $actor = User::factory()->create();
    $service = new ReversalRequestService($engine);
    $request = $service->request($this->reversalCommand($original->id, reason: 'Refund issued', actor: $actor));

    $result = $service->execute($request);

    expect($result->status)->toBe(ReversalRequestStatus::Applied);
    expect($result->reversal_transaction_id)->not->toBeNull();
    expect($result->applied_at)->not->toBeNull();
    expect($result->failure_code)->toBeNull();

    $reversal = LedgerTransaction::with('entries')->findOrFail($result->reversal_transaction_id);
    expect($reversal->type)->toBe(LedgerTransactionType::Reversal);
    expect($reversal->reverses_transaction_id)->toBe($original->id);
    expect($reversal->business_reference)->toBe('reversal:'.$original->id);
    expect($reversal->description)->toBe('Refund issued');
    expect($reversal->actor_id)->toBe($actor->id);
    expect($reversal->correlation_id)->toBe($request->correlation_id);
    expect($reversal->entries)->toHaveCount(2);

    $originalEntries = $original->load('entries')->entries->keyBy('wallet_account_id');
    foreach ($reversal->entries as $entry) {
        $originalEntry = $originalEntries[$entry->wallet_account_id];
        expect($entry->amount_atomic)->toBe($originalEntry->amount_atomic);
        expect($entry->entry_type)->not->toBe($originalEntry->entry_type);
    }

    expect(LedgerTransaction::findOrFail($original->id)->entries)->toHaveCount(2);
});

test('a multi-entry original is reversed entry-for-entry', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-execute-multi-entry');
    $engine = new LedgerPostingEngine;

    $original = $engine->post($this->postingCommand(businessReference: 'deposit_credit:execute-multi-entry', entries: [
        $this->debitEntry($clearing->id, 300),
        $this->creditEntry($accounts->earningAvailable->id, 100),
        $this->creditEntry($accounts->advertisingAvailable->id, 200),
    ]))->transaction;

    $service = new ReversalRequestService($engine);
    $result = $service->requestAndExecute($this->reversalCommand($original->id));

    expect($result->status)->toBe(ReversalRequestStatus::Applied);

    $reversal = LedgerTransaction::with('entries')->findOrFail($result->reversal_transaction_id);
    expect($reversal->entries)->toHaveCount(3);
});

test('an insufficient-balance execution creates no ledger transaction or entries and commits a durable review-required outcome', function () {
    $accounts = $this->provisionTestAccounts();
    $engine = new LedgerPostingEngine;
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-execute-insufficient');

    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:execute-insufficient-fund', amount: 1000);
    $engine->post($this->postingCommand(type: LedgerTransactionType::FundReservation, businessReference: 'fund_reservation:execute-insufficient-spend', entries: [
        $this->creditEntry($clearing->id, 700),
        $this->debitEntry($accounts->earningAvailable->id, 700),
    ]));

    $service = new ReversalRequestService($engine);
    $request = $service->request($this->reversalCommand($original->id));
    $result = $service->execute($request);

    expect($result->status)->toBe(ReversalRequestStatus::ReviewRequired);
    expect($result->failure_code)->toBe(ReversalFailureCode::InsufficientBalance);
    expect($result->review_required_at)->not->toBeNull();
    expect($result->reversal_transaction_id)->toBeNull();
    expect($result->applied_at)->toBeNull();

    expect(LedgerTransaction::where('business_reference', 'reversal:'.$original->id)->exists())->toBeFalse();
});

test('a repeated insufficient-balance execution preserves the first review-required timestamp and deterministically advances updated_at', function () {
    $accounts = $this->provisionTestAccounts();
    $engine = new LedgerPostingEngine;
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-execute-repeated-insufficient');

    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:execute-repeated-insufficient-fund', amount: 1000);
    $engine->post($this->postingCommand(type: LedgerTransactionType::FundReservation, businessReference: 'fund_reservation:execute-repeated-insufficient-spend', entries: [
        $this->creditEntry($clearing->id, 700),
        $this->debitEntry($accounts->earningAvailable->id, 700),
    ]));

    $service = new ReversalRequestService($engine);
    $request = $service->request($this->reversalCommand($original->id));

    // 1. First execution becomes review-required.
    $first = $service->execute($request);
    expect($first->status)->toBe(ReversalRequestStatus::ReviewRequired);
    expect($first->failure_code)->toBe(ReversalFailureCode::InsufficientBalance);

    // 2. Record review_required_at and updated_at.
    $firstReviewTimestamp = $first->review_required_at;
    $firstUpdatedAt = $first->updated_at;
    expect($firstReviewTimestamp)->not->toBeNull();

    // 3. Advance time beyond database timestamp precision (whole seconds,
    // since these are `timestamp` columns).
    $this->travel(5)->seconds();

    // 4. Execute again while still insufficient - nothing about the
    // account balances changed, so this must fail the same way again.
    $second = $service->execute($request);

    // 5. review_required_at is identical - never overwritten by a later,
    // still-insufficient attempt.
    expect($second->status)->toBe(ReversalRequestStatus::ReviewRequired);
    expect($second->failure_code)->toBe(ReversalFailureCode::InsufficientBalance);
    expect($second->review_required_at->equalTo($firstReviewTimestamp))->toBeTrue();

    // 6. updated_at strictly increased - deterministic, not merely
    // permitted, now that the service explicitly touches it on every
    // attempt rather than relying on some other field also being dirty.
    expect($second->updated_at->greaterThan($firstUpdatedAt))->toBeTrue();

    // 7. No reversal transaction or entries were created by either attempt
    // - only the two real postings from setup (fund + spend, 2 entries
    // each) exist in the database at all.
    expect(LedgerTransaction::where('business_reference', 'reversal:'.$original->id)->exists())->toBeFalse();
    expect(LedgerTransaction::count())->toBe(2);
    expect(LedgerEntry::count())->toBe(4);
});

test('a later successful execution after review-required clears the failure code, applies the reversal, and preserves the review-required timestamp as history', function () {
    $accounts = $this->provisionTestAccounts();
    $engine = new LedgerPostingEngine;
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-execute-later-success');

    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:execute-later-success-fund', amount: 1000);
    $engine->post($this->postingCommand(type: LedgerTransactionType::FundReservation, businessReference: 'fund_reservation:execute-later-success-spend', entries: [
        $this->creditEntry($clearing->id, 700),
        $this->debitEntry($accounts->earningAvailable->id, 700),
    ]));

    $service = new ReversalRequestService($engine);
    $request = $service->request($this->reversalCommand($original->id));
    $reviewRequired = $service->execute($request);
    expect($reviewRequired->status)->toBe(ReversalRequestStatus::ReviewRequired);
    $reviewTimestamp = $reviewRequired->review_required_at;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:execute-later-success-topup', entries: [
        $this->debitEntry($clearing->id, 700),
        $this->creditEntry($accounts->earningAvailable->id, 700),
    ]));

    $applied = $service->execute($request);

    expect($applied->status)->toBe(ReversalRequestStatus::Applied);
    expect($applied->failure_code)->toBeNull();
    expect($applied->applied_at)->not->toBeNull();
    expect($applied->reversal_transaction_id)->not->toBeNull();
    expect($applied->review_required_at->equalTo($reviewTimestamp))->toBeTrue();
});

test('executing an already-applied request returns it unchanged and creates no duplicate reversal', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-execute-already-applied');
    $engine = new LedgerPostingEngine;
    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:execute-already-applied');

    $service = new ReversalRequestService($engine);
    $request = $service->request($this->reversalCommand($original->id));
    $applied = $service->execute($request);

    $again = $service->execute(ReversalRequest::findOrFail($applied->id));

    expect($again->id)->toBe($applied->id);
    expect($again->reversal_transaction_id)->toBe($applied->reversal_transaction_id);
    expect(LedgerTransaction::where('business_reference', 'reversal:'.$original->id)->count())->toBe(1);
});

test('executing a rejected request throws and performs no writes', function () {
    $requestId = $this->insertRawReversalRequest(['status' => 'rejected']);
    $request = ReversalRequest::findOrFail($requestId);

    $engine = new LedgerPostingEngine;
    $service = new ReversalRequestService($engine);

    expect(fn () => $service->execute($request))->toThrow(LedgerInvariantViolationException::class);
});

test('a resumed execution recovers an already-committed matching reversal instead of creating a duplicate', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-execute-recovery');
    $engine = new LedgerPostingEngine;
    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:execute-recovery');

    $service = new ReversalRequestService($engine);
    $request = $service->request($this->reversalCommand($original->id));
    $applied = $service->execute($request);

    // Simulate a resumed/retried execution attempt (e.g. after a crash
    // between the ledger write and the request's own status update
    // committing) by forcing the request's status back to pending via a
    // raw update - a controlled corruption/simulation fixture, bypassing
    // the model guard deliberately, never a production code path.
    DB::table('reversal_requests')->where('id', $applied->id)->update(['status' => 'pending']);

    $resumed = $service->execute(ReversalRequest::findOrFail($applied->id));

    expect($resumed->status)->toBe(ReversalRequestStatus::Applied);
    expect($resumed->reversal_transaction_id)->toBe($applied->reversal_transaction_id);
    expect(LedgerTransaction::where('business_reference', 'reversal:'.$original->id)->count())->toBe(1);
    expect(LedgerEntry::where('ledger_transaction_id', $applied->reversal_transaction_id)->count())->toBe(2);
});

test('a conflicting pre-existing reversal transaction under the same business reference is rejected, not silently linked', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-execute-conflict');
    $engine = new LedgerPostingEngine;
    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:execute-conflict', amount: 500);

    $service = new ReversalRequestService($engine);
    // Eligibility (including "not already reversed") only runs at
    // request() time - request the reversal first, while nothing yet
    // reverses the original, exactly like a real caller would.
    $request = $service->request($this->reversalCommand($original->id));

    // A conflicting row appears afterward (e.g. a corrupted or raced
    // insert) occupying this reversal's identity with a different (wrong)
    // amount - a corruption/race scenario, not a production code path.
    $conflictingId = $this->insertRawLedgerTransaction([
        'business_reference' => 'reversal:'.$original->id,
        'type' => 'reversal',
        'reverses_transaction_id' => $original->id,
    ]);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $conflictingId, 'wallet_account_id' => $clearing->id, 'entry_type' => 'credit', 'amount_atomic' => 999]);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $conflictingId, 'wallet_account_id' => $accounts->earningAvailable->id, 'entry_type' => 'debit', 'amount_atomic' => 999]);

    expect(fn () => $service->execute($request))->toThrow(DuplicateFinancialEventException::class);

    expect(ReversalRequest::findOrFail($request->id)->status)->toBe(ReversalRequestStatus::Pending);
});

test('an unexpected failure during entry insertion leaves the request pending and commits no reversal row', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-execute-forced-failure');
    $engine = new LedgerPostingEngine;
    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:execute-forced-failure');

    $service = new ReversalRequestService($engine);
    $request = $service->request($this->reversalCommand($original->id));

    $collisionId = (string) Str::ulid();

    $this->withIsolatedCreatingListener(
        LedgerEntry::class,
        function (LedgerEntry $model) use ($collisionId): void {
            $model->id = $collisionId;
        },
        function () use ($service, $request): void {
            try {
                $service->execute($request);
            } catch (Throwable) {
                // expected
            }
        },
    );

    expect(LedgerTransaction::where('business_reference', 'reversal:'.$original->id)->exists())->toBeFalse();
    expect(ReversalRequest::findOrFail($request->id)->status)->toBe(ReversalRequestStatus::Pending);
});

test('a stale in-memory request object is never trusted - execute() re-fetches and locks by canonical ID', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-execute-stale-object');
    $engine = new LedgerPostingEngine;
    $original = $this->makeReversibleOriginal($engine, $accounts, $clearing, 'deposit_credit:execute-stale-object');

    $service = new ReversalRequestService($engine);
    $staleRequest = $service->request($this->reversalCommand($original->id));
    expect($staleRequest->status)->toBe(ReversalRequestStatus::Pending);

    $applied = $service->execute($staleRequest);
    expect($applied->status)->toBe(ReversalRequestStatus::Applied);

    // $staleRequest itself is a different in-memory object than the one
    // execute() re-fetched and mutated - it still shows Pending.
    expect($staleRequest->status)->toBe(ReversalRequestStatus::Pending);

    $result = $service->execute($staleRequest);

    expect($result->status)->toBe(ReversalRequestStatus::Applied);
    expect($result->reversal_transaction_id)->toBe($applied->reversal_transaction_id);
    expect(LedgerTransaction::where('business_reference', 'reversal:'.$original->id)->count())->toBe(1);
});
