<?php

use App\Domain\Shared\Money\Currency;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

test('a primary-key collision unrelated to the business reference rethrows the original exception unchanged, without creating a row', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-pk-collision');
    $engine = new LedgerPostingEngine;

    // A real, unrelated, already-committed transaction whose primary key
    // we'll force the new insert to collide with.
    $unrelated = $engine->post($this->postingCommand(businessReference: 'deposit_credit:pk-collision-unrelated', entries: [
        $this->debitEntry($clearing->id, 5),
        $this->creditEntry($accounts->earningAvailable->id, 5),
    ]));
    $collisionTargetId = $unrelated->transaction->id;

    $targetReference = 'deposit_credit:pk-collision-target';
    expect(LedgerTransaction::where('business_reference', $targetReference)->exists())->toBeFalse();

    $this->withIsolatedCreatingListener(
        LedgerTransaction::class,
        function (LedgerTransaction $model) use ($collisionTargetId, $targetReference): void {
            if ($model->business_reference === $targetReference) {
                $model->id = $collisionTargetId;
            }
        },
        function () use ($engine, $clearing, $accounts, $targetReference): void {
            $caught = null;

            try {
                $engine->post($this->postingCommand(businessReference: $targetReference, entries: [
                    $this->debitEntry($clearing->id, 7),
                    $this->creditEntry($accounts->earningAvailable->id, 7),
                ]));
            } catch (Throwable $exception) {
                $caught = $exception;
            }

            expect($caught)->toBeInstanceOf(UniqueConstraintViolationException::class);
        },
    );

    // The attempted canonical identity was never actually created - the
    // conflict was a real, unrelated primary-key collision, never
    // reinterpreted as a known duplicate-financial-event conflict.
    expect(LedgerTransaction::where('business_reference', $targetReference)->exists())->toBeFalse();
});

test('the write context still resets correctly after an unexplained unique-constraint anomaly', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-pk-collision-reset');
    $engine = new LedgerPostingEngine;

    $unrelated = $engine->post($this->postingCommand(businessReference: 'deposit_credit:pk-collision-reset-unrelated', entries: [
        $this->debitEntry($clearing->id, 5),
        $this->creditEntry($accounts->earningAvailable->id, 5),
    ]));
    $collisionTargetId = $unrelated->transaction->id;
    $targetReference = 'deposit_credit:pk-collision-reset-target';

    $this->withIsolatedCreatingListener(
        LedgerTransaction::class,
        function (LedgerTransaction $model) use ($collisionTargetId, $targetReference): void {
            if ($model->business_reference === $targetReference) {
                $model->id = $collisionTargetId;
            }
        },
        function () use ($engine, $clearing, $accounts, $targetReference): void {
            try {
                $engine->post($this->postingCommand(businessReference: $targetReference, entries: [
                    $this->debitEntry($clearing->id, 7),
                    $this->creditEntry($accounts->earningAvailable->id, 7),
                ]));
            } catch (Throwable) {
                // expected
            }
        },
    );

    $direct = new LedgerTransaction;
    $direct->business_reference = 'deposit_credit:after-pk-collision';
    $direct->type = LedgerTransactionType::DepositCredit;
    $direct->currency_code = Currency::USD;
    $direct->currency_scale = 6;
    $direct->description = 'after collision';
    $direct->correlation_id = (string) Str::uuid();

    expect(fn () => $direct->save())->toThrow(LedgerInvariantViolationException::class);
});
