<?php

use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\WalletAccountScopeType;
use App\Domain\Wallet\Enums\WalletAccountType;

test('each wallet account type has its approved normal entry side, negative-balance permission, and allowed scope', function (
    WalletAccountType $type,
    LedgerEntryType $expectedSide,
    bool $expectedNegativeBalance,
    WalletAccountScopeType $expectedScope,
) {
    expect($type->normalEntrySide())->toBe($expectedSide);
    expect($type->allowsNegativeBalance())->toBe($expectedNegativeBalance);
    expect($type->allowedScope())->toBe($expectedScope);
})->with([
    'earning_available' => [WalletAccountType::EarningAvailable, LedgerEntryType::Credit, false, WalletAccountScopeType::User],
    'earning_held' => [WalletAccountType::EarningHeld, LedgerEntryType::Credit, false, WalletAccountScopeType::User],
    'advertising_available' => [WalletAccountType::AdvertisingAvailable, LedgerEntryType::Credit, false, WalletAccountScopeType::User],
    'advertising_reserved' => [WalletAccountType::AdvertisingReserved, LedgerEntryType::Credit, false, WalletAccountScopeType::User],
    'platform_fee' => [WalletAccountType::PlatformFee, LedgerEntryType::Credit, false, WalletAccountScopeType::Platform],
    'provider_settlement_clearing' => [WalletAccountType::ProviderSettlementClearing, LedgerEntryType::Debit, true, WalletAccountScopeType::Provider],
]);
