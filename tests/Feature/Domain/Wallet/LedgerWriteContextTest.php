<?php

use App\Domain\Shared\Money\Currency;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\LedgerWriteContext;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use Illuminate\Support\Str;

test('the context is inactive when no run() call is active', function () {
    expect(LedgerWriteContext::isActive())->toBeFalse();
});

test('a single run() call activates the context only inside the callback', function () {
    expect(LedgerWriteContext::isActive())->toBeFalse();

    $activeDuringCallback = null;
    LedgerWriteContext::run(function () use (&$activeDuringCallback): void {
        $activeDuringCallback = LedgerWriteContext::isActive();
    });

    expect($activeDuringCallback)->toBeTrue();
    expect(LedgerWriteContext::isActive())->toBeFalse();
});

test('nested run() calls maintain correct depth, and only the outermost exit deactivates the context', function () {
    $states = [];

    LedgerWriteContext::run(function () use (&$states): void {
        $states[] = LedgerWriteContext::isActive(); // depth 1
        LedgerWriteContext::run(function () use (&$states): void {
            $states[] = LedgerWriteContext::isActive(); // depth 2
        });
        $states[] = LedgerWriteContext::isActive(); // back to depth 1, still active
    });
    $states[] = LedgerWriteContext::isActive(); // depth 0

    expect($states)->toBe([true, true, true, false]);
});

test('returning normally from run() restores depth to zero', function () {
    LedgerWriteContext::run(fn () => 'result');

    expect(LedgerWriteContext::isActive())->toBeFalse();
});

test('throwing from the callback restores depth to zero', function () {
    try {
        LedgerWriteContext::run(function (): void {
            throw new RuntimeException('forced failure inside run()');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(LedgerWriteContext::isActive())->toBeFalse();
});

test('nested run() where the inner call throws still restores depth to zero, not just to the outer level', function () {
    try {
        LedgerWriteContext::run(function (): void {
            LedgerWriteContext::run(function (): void {
                throw new RuntimeException('forced failure in the inner run()');
            });
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(LedgerWriteContext::isActive())->toBeFalse();
});

test('an idempotent replay does not leave the context active', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-context-replay');
    $engine = new LedgerPostingEngine;

    $command = $this->postingCommand(businessReference: 'deposit_credit:context-replay', entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]);

    $engine->post($command);
    expect(LedgerWriteContext::isActive())->toBeFalse();

    $result = $engine->post($command);

    expect($result->wasReplay)->toBeTrue();
    expect(LedgerWriteContext::isActive())->toBeFalse();
});

test('an entry-insertion failure does not leave the context active', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-context-entry-failure');
    $engine = new LedgerPostingEngine;

    $unrelated = $engine->post($this->postingCommand(businessReference: 'deposit_credit:context-entry-failure-setup', entries: [
        $this->debitEntry($clearing->id, 5),
        $this->creditEntry($accounts->earningAvailable->id, 5),
    ]));
    $collisionTargetId = $unrelated->transaction->entries->first(
        fn (LedgerEntry $e) => $e->wallet_account_id === $accounts->earningAvailable->id
    )->id;

    $this->withIsolatedCreatingListener(
        LedgerEntry::class,
        function (LedgerEntry $model) use ($collisionTargetId, $accounts): void {
            if ($model->wallet_account_id === $accounts->earningAvailable->id) {
                $model->id = $collisionTargetId;
            }
        },
        function () use ($engine, $clearing, $accounts): void {
            try {
                $engine->post($this->postingCommand(businessReference: 'deposit_credit:context-entry-failure', entries: [
                    $this->debitEntry($clearing->id, 20),
                    $this->creditEntry($accounts->earningAvailable->id, 20),
                ]));
            } catch (Throwable) {
                // expected
            }
        },
    );

    expect(LedgerWriteContext::isActive())->toBeFalse();
});

test('direct LedgerTransaction creation after any failed posting is still blocked', function () {
    $engine = new LedgerPostingEngine;

    try {
        $engine->post($this->postingCommand(entries: [
            $this->debitEntry((string) Str::ulid(), 100),
            $this->creditEntry((string) Str::ulid(), 100),
        ]));
    } catch (Throwable) {
        // expected - unknown accounts
    }

    expect(LedgerWriteContext::isActive())->toBeFalse();

    $transaction = new LedgerTransaction;
    $transaction->business_reference = 'deposit_credit:context-audit-direct-transaction';
    $transaction->type = LedgerTransactionType::DepositCredit;
    $transaction->currency_code = Currency::USD;
    $transaction->currency_scale = 6;
    $transaction->description = 'direct attempt after failure';
    $transaction->correlation_id = (string) Str::uuid();

    expect(fn () => $transaction->save())->toThrow(LedgerInvariantViolationException::class);
});

test('direct LedgerEntry creation after any failed posting is still blocked', function () {
    $accounts = $this->provisionTestAccounts();
    $engine = new LedgerPostingEngine;

    try {
        $engine->post($this->postingCommand(entries: [
            $this->debitEntry((string) Str::ulid(), 100),
            $this->creditEntry((string) Str::ulid(), 100),
        ]));
    } catch (Throwable) {
        // expected - unknown accounts
    }

    expect(LedgerWriteContext::isActive())->toBeFalse();

    $transactionId = $this->insertRawLedgerTransaction();

    $entry = new LedgerEntry;
    $entry->ledger_transaction_id = $transactionId;
    $entry->wallet_account_id = $accounts->earningAvailable->id;
    $entry->entry_type = LedgerEntryType::Credit;
    $entry->amount_atomic = 100;

    expect(fn () => $entry->save())->toThrow(LedgerInvariantViolationException::class);
});
