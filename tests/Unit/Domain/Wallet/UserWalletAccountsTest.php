<?php

use App\Domain\Shared\Money\Currency;
use App\Domain\Wallet\Data\UserWalletAccounts;
use App\Domain\Wallet\Enums\WalletAccountScopeType;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Exceptions\WalletAccountInvariantException;
use App\Domain\Wallet\Models\WalletAccount;

/**
 * Every account here is an in-memory, unpersisted-by-default WalletAccount
 * instance with exists/id set directly (Model::$exists is public) - this
 * keeps the DTO's own validation logic testable in isolation from the
 * database, without ever hitting it.
 */
function makeInMemoryWalletAccount(
    string $id,
    int $userId,
    WalletAccountType $type,
    ?WalletAccountScopeType $scopeType = null,
    ?string $scopeKey = null,
    ?int $scale = null,
    bool $exists = true,
): WalletAccount {
    $account = new WalletAccount;
    $account->exists = $exists;
    $account->id = $id;
    $account->scope_type = $scopeType ?? WalletAccountScopeType::User;
    $account->scope_key = $scopeKey ?? (string) $userId;
    $account->user_id = $userId;
    $account->account_type = $type;
    $account->currency_code = Currency::USD;
    $account->currency_scale = $scale ?? Currency::USD->scale();

    return $account;
}

test('a valid set of four accounts constructs successfully', function () {
    $userId = 42;

    $dto = new UserWalletAccounts(
        earningAvailable: makeInMemoryWalletAccount('01AAAAAAAAAAAAAAAAAAAAAAAA', $userId, WalletAccountType::EarningAvailable),
        earningHeld: makeInMemoryWalletAccount('01BBBBBBBBBBBBBBBBBBBBBBBB', $userId, WalletAccountType::EarningHeld),
        advertisingAvailable: makeInMemoryWalletAccount('01CCCCCCCCCCCCCCCCCCCCCCCC', $userId, WalletAccountType::AdvertisingAvailable),
        advertisingReserved: makeInMemoryWalletAccount('01DDDDDDDDDDDDDDDDDDDDDDDD', $userId, WalletAccountType::AdvertisingReserved),
        userId: $userId,
    );

    expect($dto->earningAvailable->account_type)->toBe(WalletAccountType::EarningAvailable);
    expect($dto->earningHeld->account_type)->toBe(WalletAccountType::EarningHeld);
    expect($dto->advertisingAvailable->account_type)->toBe(WalletAccountType::AdvertisingAvailable);
    expect($dto->advertisingReserved->account_type)->toBe(WalletAccountType::AdvertisingReserved);
});

test('an unpersisted account is rejected', function () {
    $userId = 42;

    expect(fn () => new UserWalletAccounts(
        earningAvailable: makeInMemoryWalletAccount('01AAAAAAAAAAAAAAAAAAAAAAAA', $userId, WalletAccountType::EarningAvailable, exists: false),
        earningHeld: makeInMemoryWalletAccount('01BBBBBBBBBBBBBBBBBBBBBBBB', $userId, WalletAccountType::EarningHeld),
        advertisingAvailable: makeInMemoryWalletAccount('01CCCCCCCCCCCCCCCCCCCCCCCC', $userId, WalletAccountType::AdvertisingAvailable),
        advertisingReserved: makeInMemoryWalletAccount('01DDDDDDDDDDDDDDDDDDDDDDDD', $userId, WalletAccountType::AdvertisingReserved),
        userId: $userId,
    ))->toThrow(WalletAccountInvariantException::class);
});

test('an account belonging to a different user is rejected', function () {
    $userId = 42;

    expect(fn () => new UserWalletAccounts(
        earningAvailable: makeInMemoryWalletAccount('01AAAAAAAAAAAAAAAAAAAAAAAA', 999, WalletAccountType::EarningAvailable),
        earningHeld: makeInMemoryWalletAccount('01BBBBBBBBBBBBBBBBBBBBBBBB', $userId, WalletAccountType::EarningHeld),
        advertisingAvailable: makeInMemoryWalletAccount('01CCCCCCCCCCCCCCCCCCCCCCCC', $userId, WalletAccountType::AdvertisingAvailable),
        advertisingReserved: makeInMemoryWalletAccount('01DDDDDDDDDDDDDDDDDDDDDDDD', $userId, WalletAccountType::AdvertisingReserved),
        userId: $userId,
    ))->toThrow(WalletAccountInvariantException::class);
});

test('a non-user-scoped account is rejected', function () {
    $userId = 42;

    expect(fn () => new UserWalletAccounts(
        earningAvailable: makeInMemoryWalletAccount('01AAAAAAAAAAAAAAAAAAAAAAAA', $userId, WalletAccountType::EarningAvailable, scopeType: WalletAccountScopeType::Platform),
        earningHeld: makeInMemoryWalletAccount('01BBBBBBBBBBBBBBBBBBBBBBBB', $userId, WalletAccountType::EarningHeld),
        advertisingAvailable: makeInMemoryWalletAccount('01CCCCCCCCCCCCCCCCCCCCCCCC', $userId, WalletAccountType::AdvertisingAvailable),
        advertisingReserved: makeInMemoryWalletAccount('01DDDDDDDDDDDDDDDDDDDDDDDD', $userId, WalletAccountType::AdvertisingReserved),
        userId: $userId,
    ))->toThrow(WalletAccountInvariantException::class);
});

test('a mismatched scope key is rejected', function () {
    $userId = 42;

    expect(fn () => new UserWalletAccounts(
        earningAvailable: makeInMemoryWalletAccount('01AAAAAAAAAAAAAAAAAAAAAAAA', $userId, WalletAccountType::EarningAvailable, scopeKey: '999'),
        earningHeld: makeInMemoryWalletAccount('01BBBBBBBBBBBBBBBBBBBBBBBB', $userId, WalletAccountType::EarningHeld),
        advertisingAvailable: makeInMemoryWalletAccount('01CCCCCCCCCCCCCCCCCCCCCCCC', $userId, WalletAccountType::AdvertisingAvailable),
        advertisingReserved: makeInMemoryWalletAccount('01DDDDDDDDDDDDDDDDDDDDDDDD', $userId, WalletAccountType::AdvertisingReserved),
        userId: $userId,
    ))->toThrow(WalletAccountInvariantException::class);
});

test('a wrong currency scale is rejected', function () {
    $userId = 42;

    expect(fn () => new UserWalletAccounts(
        earningAvailable: makeInMemoryWalletAccount('01AAAAAAAAAAAAAAAAAAAAAAAA', $userId, WalletAccountType::EarningAvailable, scale: 8),
        earningHeld: makeInMemoryWalletAccount('01BBBBBBBBBBBBBBBBBBBBBBBB', $userId, WalletAccountType::EarningHeld),
        advertisingAvailable: makeInMemoryWalletAccount('01CCCCCCCCCCCCCCCCCCCCCCCC', $userId, WalletAccountType::AdvertisingAvailable),
        advertisingReserved: makeInMemoryWalletAccount('01DDDDDDDDDDDDDDDDDDDDDDDD', $userId, WalletAccountType::AdvertisingReserved),
        userId: $userId,
    ))->toThrow(WalletAccountInvariantException::class);
});

test('an account in the wrong property position is rejected', function () {
    $userId = 42;

    expect(fn () => new UserWalletAccounts(
        earningAvailable: makeInMemoryWalletAccount('01AAAAAAAAAAAAAAAAAAAAAAAA', $userId, WalletAccountType::EarningHeld),
        earningHeld: makeInMemoryWalletAccount('01BBBBBBBBBBBBBBBBBBBBBBBB', $userId, WalletAccountType::EarningAvailable),
        advertisingAvailable: makeInMemoryWalletAccount('01CCCCCCCCCCCCCCCCCCCCCCCC', $userId, WalletAccountType::AdvertisingAvailable),
        advertisingReserved: makeInMemoryWalletAccount('01DDDDDDDDDDDDDDDDDDDDDDDD', $userId, WalletAccountType::AdvertisingReserved),
        userId: $userId,
    ))->toThrow(WalletAccountInvariantException::class);
});

test('a duplicate account ID across two properties is rejected', function () {
    $userId = 42;

    expect(fn () => new UserWalletAccounts(
        earningAvailable: makeInMemoryWalletAccount('01AAAAAAAAAAAAAAAAAAAAAAAA', $userId, WalletAccountType::EarningAvailable),
        earningHeld: makeInMemoryWalletAccount('01AAAAAAAAAAAAAAAAAAAAAAAA', $userId, WalletAccountType::EarningHeld),
        advertisingAvailable: makeInMemoryWalletAccount('01CCCCCCCCCCCCCCCCCCCCCCCC', $userId, WalletAccountType::AdvertisingAvailable),
        advertisingReserved: makeInMemoryWalletAccount('01DDDDDDDDDDDDDDDDDDDDDDDD', $userId, WalletAccountType::AdvertisingReserved),
        userId: $userId,
    ))->toThrow(WalletAccountInvariantException::class);
});
