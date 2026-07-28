<?php

use App\Domain\Shared\Exceptions\NonPositiveAmountException;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\PostLedgerEntryCommand;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use Illuminate\Support\Str;

test('a valid ULID and positive amount construct successfully', function () {
    $id = (string) Str::ulid();

    $command = new PostLedgerEntryCommand($id, LedgerEntryType::Credit, Money::fromAtomic(1000, Currency::USD));

    expect($command->walletAccountId)->toBe(strtolower($id));
    expect($command->entryType)->toBe(LedgerEntryType::Credit);
    expect($command->amount->atomic())->toBe(1000);
});

test('an uppercase ULID is normalized to the canonical lowercase representation', function () {
    $id = (string) Str::ulid();

    $command = new PostLedgerEntryCommand(strtoupper($id), LedgerEntryType::Debit, Money::fromAtomic(1, Currency::USD));

    expect($command->walletAccountId)->toBe(strtolower($id));
});

test('a wallet account identifier is trimmed before validation', function () {
    $id = (string) Str::ulid();

    $command = new PostLedgerEntryCommand("  {$id}  ", LedgerEntryType::Debit, Money::fromAtomic(1, Currency::USD));

    expect($command->walletAccountId)->toBe(strtolower($id));
});

test('non-string wallet account identifiers are rejected', function (mixed $identifier) {
    expect(fn () => new PostLedgerEntryCommand($identifier, LedgerEntryType::Debit, Money::fromAtomic(1, Currency::USD)))
        ->toThrow(LedgerInvariantViolationException::class);
})->with([
    'integer' => [123],
    'boolean' => [true],
    'float' => [1.5],
    'array' => [['x']],
    'null' => [null],
]);

test('a malformed ULID string is rejected', function () {
    expect(fn () => new PostLedgerEntryCommand('not-a-real-ulid', LedgerEntryType::Debit, Money::fromAtomic(1, Currency::USD)))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a zero amount is rejected', function () {
    $id = (string) Str::ulid();

    expect(fn () => new PostLedgerEntryCommand($id, LedgerEntryType::Debit, Money::zero(Currency::USD)))
        ->toThrow(NonPositiveAmountException::class);
});

test('a negative amount is rejected', function () {
    $id = (string) Str::ulid();

    expect(fn () => new PostLedgerEntryCommand($id, LedgerEntryType::Debit, Money::fromAtomic(-1, Currency::USD)))
        ->toThrow(NonPositiveAmountException::class);
});
