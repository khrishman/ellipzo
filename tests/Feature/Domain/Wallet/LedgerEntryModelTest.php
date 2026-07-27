<?php

use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Models\LedgerEntry;
use Illuminate\Support\Carbon;

test('casts round-trip through their enum/scalar types', function () {
    $id = $this->insertRawLedgerEntry(['entry_type' => 'debit', 'amount_atomic' => 42]);

    $entry = LedgerEntry::findOrFail($id);

    expect($entry->entry_type)->toBe(LedgerEntryType::Debit);
    expect($entry->amount_atomic)->toBe(42);
    expect($entry->created_at)->toBeInstanceOf(Carbon::class);
});

test('an invalid raw entry_type throws on attribute access', function () {
    $id = $this->insertRawLedgerEntry(['entry_type' => 'not-a-real-side']);

    $entry = LedgerEntry::findOrFail($id);

    expect(fn () => $entry->entry_type)->toThrow(ValueError::class);
});

test('a ledger entry cannot be updated, even by direct property assignment', function () {
    $id = $this->insertRawLedgerEntry();
    $entry = LedgerEntry::findOrFail($id);

    $entry->amount_atomic = 999;

    expect(fn () => $entry->save())->toThrow(LogicException::class);
});

test('a ledger entry cannot be deleted through application code', function () {
    $id = $this->insertRawLedgerEntry();
    $entry = LedgerEntry::findOrFail($id);

    expect(fn () => $entry->delete())->toThrow(LogicException::class);
});

test('LedgerEntry does not expose factory behavior', function () {
    expect(method_exists(LedgerEntry::class, 'factory'))->toBeFalse();
});
