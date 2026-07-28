<?php

use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\ReversalRequestStatus;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\ReversalRequest;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\ReversalRequestService;
use App\Domain\Wallet\Services\ReversalRequestWriteContext;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use Illuminate\Support\Str;

test('the context is inactive when no run() call is active', function () {
    expect(ReversalRequestWriteContext::isActive())->toBeFalse();
});

test('a single run() call activates the context only inside the callback', function () {
    expect(ReversalRequestWriteContext::isActive())->toBeFalse();

    $activeDuringCallback = null;
    ReversalRequestWriteContext::run(function () use (&$activeDuringCallback): void {
        $activeDuringCallback = ReversalRequestWriteContext::isActive();
    });

    expect($activeDuringCallback)->toBeTrue();
    expect(ReversalRequestWriteContext::isActive())->toBeFalse();
});

test('nested run() calls maintain correct depth, and only the outermost exit deactivates the context', function () {
    $states = [];

    ReversalRequestWriteContext::run(function () use (&$states): void {
        $states[] = ReversalRequestWriteContext::isActive(); // depth 1
        ReversalRequestWriteContext::run(function () use (&$states): void {
            $states[] = ReversalRequestWriteContext::isActive(); // depth 2
        });
        $states[] = ReversalRequestWriteContext::isActive(); // back to depth 1
    });
    $states[] = ReversalRequestWriteContext::isActive(); // depth 0

    expect($states)->toBe([true, true, true, false]);
});

test('returning normally from run() restores depth to zero', function () {
    ReversalRequestWriteContext::run(fn () => 'result');

    expect(ReversalRequestWriteContext::isActive())->toBeFalse();
});

test('throwing from the callback restores depth to zero', function () {
    try {
        ReversalRequestWriteContext::run(function (): void {
            throw new RuntimeException('forced failure inside run()');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(ReversalRequestWriteContext::isActive())->toBeFalse();
});

test('nested run() where the inner call throws still restores depth to zero, not just to the outer level', function () {
    try {
        ReversalRequestWriteContext::run(function (): void {
            ReversalRequestWriteContext::run(function (): void {
                throw new RuntimeException('forced failure in the inner run()');
            });
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(ReversalRequestWriteContext::isActive())->toBeFalse();
});

test('the context resets after a successful request() call', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-context-request-success');
    $engine = new LedgerPostingEngine;
    $original = $engine->post($this->postingCommand(entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]))->transaction;

    $service = new ReversalRequestService($engine);
    $service->request($this->reversalCommand($original->id));

    expect(ReversalRequestWriteContext::isActive())->toBeFalse();
});

test('the context resets after a successful execute() call', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-context-execute-success');
    $engine = new LedgerPostingEngine;
    $original = $engine->post($this->postingCommand(entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]))->transaction;

    $service = new ReversalRequestService($engine);
    $request = $service->request($this->reversalCommand($original->id));
    $service->execute($request);

    expect(ReversalRequestWriteContext::isActive())->toBeFalse();
});

test('the context resets after execute() produces a durable review-required outcome', function () {
    $accounts = $this->provisionTestAccounts();
    $engine = new LedgerPostingEngine;
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-context-review-required');

    // Fund earning_available with 1,000, then spend 700 of it elsewhere so
    // only 300 remains. Reversing the original 1,000 credit would need to
    // debit it back down by 1,000, taking it to -700 - which
    // earning_available does not allow.
    $original = $engine->post($this->postingCommand(businessReference: 'deposit_credit:context-review-required-fund', entries: [
        $this->debitEntry($clearing->id, 1_000),
        $this->creditEntry($accounts->earningAvailable->id, 1_000),
    ]))->transaction;

    $engine->post($this->postingCommand(type: LedgerTransactionType::AdministrativeAdjustment, businessReference: 'administrative_adjustment:context-review-required-spend', entries: [
        $this->creditEntry($clearing->id, 700),
        $this->debitEntry($accounts->earningAvailable->id, 700),
    ]));

    $service = new ReversalRequestService($engine);
    $request = $service->request($this->reversalCommand($original->id));
    $result = $service->execute($request);

    expect($result->status)->toBe(ReversalRequestStatus::ReviewRequired);
    expect(ReversalRequestWriteContext::isActive())->toBeFalse();
});

test('the context resets when execute() throws because of a forced ledger-entry failure', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-context-forced-failure');
    $engine = new LedgerPostingEngine;
    $original = $engine->post($this->postingCommand(entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]))->transaction;

    $service = new ReversalRequestService($engine);
    $request = $service->request($this->reversalCommand($original->id));

    // Force a primary-key collision on the reversal's own inverse entry so
    // the entry insert throws something other than InsufficientBalanceException.
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

    expect(ReversalRequestWriteContext::isActive())->toBeFalse();

    $fresh = ReversalRequest::find($request->id);
    expect($fresh->status)->toBe(ReversalRequestStatus::Pending);
});

test('a request replay through the context still leaves it inactive afterward', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-context-replay');
    $engine = new LedgerPostingEngine;
    $original = $engine->post($this->postingCommand(entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]))->transaction;

    $service = new ReversalRequestService($engine);
    $command = $this->reversalCommand($original->id);
    $service->request($command);

    // Same idempotency key and fingerprint: a genuine replay.
    $second = $service->request($command);

    expect(ReversalRequestWriteContext::isActive())->toBeFalse();
    expect($second->status)->toBe(ReversalRequestStatus::Pending);
});
