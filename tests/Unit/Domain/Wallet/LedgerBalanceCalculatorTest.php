<?php

use App\Domain\Shared\Exceptions\MoneyOverflowException;
use App\Domain\Shared\Money\Currency;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Services\IncrementalLedgerFingerprint;
use App\Domain\Wallet\Services\LedgerBalanceCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * uses(TestCase::class): tests/Unit has no Laravel bootstrapping bound by
 * default (tests/Pest.php only binds Feature). Constructing an Eloquent
 * model with a cast property (created_at, entry_type) requires the
 * container's connection resolver to be registered, which only happens
 * once the real application boots - no database query is ever made here,
 * mirroring the exact reasoning already established in
 * LedgerPostingEngineTransactionPreconditionTest.php.
 */
uses(TestCase::class);

/**
 * No database access anywhere in this file: LedgerEntry's own creating()
 * guard only fires on save()/create(), never on plain property
 * assignment - every entry here is an in-memory-only instance, exactly
 * the same technique already established for transaction-precondition
 * tests elsewhere in this suite.
 */
function makeInMemoryLedgerEntry(
    LedgerEntryType $type,
    int $amountAtomic,
    ?Carbon $createdAt = null,
    ?string $id = null,
    ?string $walletAccountId = null,
    ?string $transactionId = null,
): LedgerEntry {
    $entry = new LedgerEntry;
    $entry->id = $id ?? strtolower((string) Str::ulid());
    $entry->ledger_transaction_id = $transactionId ?? strtolower((string) Str::ulid());
    $entry->wallet_account_id = $walletAccountId ?? strtolower((string) Str::ulid());
    $entry->entry_type = $type;
    $entry->amount_atomic = $amountAtomic;
    $entry->created_at = $createdAt ?? Carbon::parse('2026-07-29 10:00:00', 'UTC');

    return $entry;
}

test('an empty entry set folds to a zero balance and no last entry', function () {
    $result = LedgerBalanceCalculator::fold([], WalletAccountType::EarningAvailable);

    expect($result->balance->isZero())->toBeTrue();
    expect($result->balance->currency())->toBe(Currency::USD);
    expect($result->entryCount)->toBe(0);
    expect($result->lastEntry)->toBeNull();
    expect($result->lastEntryId())->toBeNull();
    expect($result->lastEntryCreatedAt())->toBeNull();
});

test('a mixed debit/credit history folds correctly for a credit-normal account', function () {
    $entries = [
        makeInMemoryLedgerEntry(LedgerEntryType::Credit, 1_000_000),
        makeInMemoryLedgerEntry(LedgerEntryType::Debit, 400_000),
        makeInMemoryLedgerEntry(LedgerEntryType::Credit, 250_000),
    ];

    $result = LedgerBalanceCalculator::fold($entries, WalletAccountType::EarningAvailable);

    expect($result->balance->atomic())->toBe(850_000);
    expect($result->entryCount)->toBe(3);
    expect($result->lastEntry)->toBe($entries[2]);
});

test('a mixed debit/credit history folds correctly for a debit-normal account', function () {
    $entries = [
        makeInMemoryLedgerEntry(LedgerEntryType::Debit, 500_000),
        makeInMemoryLedgerEntry(LedgerEntryType::Credit, 200_000),
    ];

    $result = LedgerBalanceCalculator::fold($entries, WalletAccountType::ProviderSettlementClearing);

    expect($result->balance->atomic())->toBe(300_000);
});

test('the last entry reflects the final item in iteration order, not insertion order', function () {
    $first = makeInMemoryLedgerEntry(LedgerEntryType::Credit, 100);
    $second = makeInMemoryLedgerEntry(LedgerEntryType::Credit, 200);

    $result = LedgerBalanceCalculator::fold([$first, $second], WalletAccountType::EarningAvailable);

    expect($result->lastEntryId())->toBe($second->id);
});

test('an overflowing history propagates MoneyOverflowException and is not silently caught', function () {
    $entries = [
        makeInMemoryLedgerEntry(LedgerEntryType::Credit, PHP_INT_MAX),
        makeInMemoryLedgerEntry(LedgerEntryType::Credit, 1),
    ];

    expect(fn () => LedgerBalanceCalculator::fold($entries, WalletAccountType::EarningAvailable))
        ->toThrow(MoneyOverflowException::class);
});

test('fold() accepts a genuine PHP generator, proving it never requires an eager array or Collection', function () {
    $generator = (function (): Generator {
        yield makeInMemoryLedgerEntry(LedgerEntryType::Credit, 300);
        yield makeInMemoryLedgerEntry(LedgerEntryType::Debit, 100);
    })();

    $result = LedgerBalanceCalculator::fold($generator, WalletAccountType::EarningAvailable);

    expect($result->balance->atomic())->toBe(200);
    expect($result->entryCount)->toBe(2);
});

test('fold() without a fingerprint argument does no hashing work and returns a result identical to fold() with one', function () {
    $entries = [
        makeInMemoryLedgerEntry(LedgerEntryType::Credit, 500),
        makeInMemoryLedgerEntry(LedgerEntryType::Debit, 200),
    ];

    $withoutFingerprint = LedgerBalanceCalculator::fold($entries, WalletAccountType::EarningAvailable);
    $fingerprint = new IncrementalLedgerFingerprint('some-account-id');
    $withFingerprint = LedgerBalanceCalculator::fold($entries, WalletAccountType::EarningAvailable, $fingerprint);

    expect($withoutFingerprint->balance->atomic())->toBe($withFingerprint->balance->atomic());
    expect($withoutFingerprint->entryCount)->toBe($withFingerprint->entryCount);
});
