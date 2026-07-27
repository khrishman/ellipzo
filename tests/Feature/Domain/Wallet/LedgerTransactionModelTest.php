<?php

use App\Domain\Shared\Money\Currency;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\RelatedEntityType;
use App\Domain\Wallet\Models\LedgerTransaction;
use Illuminate\Support\Carbon;

test('casts round-trip through their enum/scalar types', function () {
    $id = $this->insertRawLedgerTransaction([
        'type' => 'deposit_credit',
        'related_entity_type' => 'deposit_intent',
        'related_entity_id' => 'some-external-id',
    ]);

    $transaction = LedgerTransaction::findOrFail($id);

    expect($transaction->type)->toBe(LedgerTransactionType::DepositCredit);
    expect($transaction->currency_code)->toBe(Currency::USD);
    expect($transaction->currency_scale)->toBe(6);
    expect($transaction->related_entity_type)->toBe(RelatedEntityType::DepositIntent);
    expect($transaction->created_at)->toBeInstanceOf(Carbon::class);
});

test('a null related_entity_type casts to null, not an exception', function () {
    $id = $this->insertRawLedgerTransaction(['related_entity_type' => null, 'related_entity_id' => null]);

    $transaction = LedgerTransaction::findOrFail($id);

    expect($transaction->related_entity_type)->toBeNull();
});

test('an invalid raw type throws on attribute access', function () {
    $id = $this->insertRawLedgerTransaction(['type' => 'not-a-real-type']);

    $transaction = LedgerTransaction::findOrFail($id);

    expect(fn () => $transaction->type)->toThrow(ValueError::class);
});

test('an invalid raw related_entity_type throws on attribute access', function () {
    $id = $this->insertRawLedgerTransaction(['related_entity_type' => 'not-a-real-alias']);

    $transaction = LedgerTransaction::findOrFail($id);

    expect(fn () => $transaction->related_entity_type)->toThrow(ValueError::class);
});

test('a ledger transaction cannot be updated, even by direct property assignment', function () {
    $id = $this->insertRawLedgerTransaction();
    $transaction = LedgerTransaction::findOrFail($id);

    $transaction->description = 'tampered';

    expect(fn () => $transaction->save())->toThrow(LogicException::class);
});

test('a ledger transaction cannot be deleted through application code', function () {
    $id = $this->insertRawLedgerTransaction();
    $transaction = LedgerTransaction::findOrFail($id);

    expect(fn () => $transaction->delete())->toThrow(LogicException::class);
});

test('LedgerTransaction does not expose factory behavior', function () {
    expect(method_exists(LedgerTransaction::class, 'factory'))->toBeFalse();
});
