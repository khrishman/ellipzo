<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Enums;

/**
 * The six approved wallet account types. normalEntrySide()/
 * allowsNegativeBalance()/allowedScope() are defined and unit-tested here
 * but consumed by nothing until Task 2.4's posting engine.
 */
enum WalletAccountType: string
{
    case EarningAvailable = 'earning_available';
    case EarningHeld = 'earning_held';
    case AdvertisingAvailable = 'advertising_available';
    case AdvertisingReserved = 'advertising_reserved';
    case PlatformFee = 'platform_fee';
    case ProviderSettlementClearing = 'provider_settlement_clearing';

    public function normalEntrySide(): LedgerEntryType
    {
        return match ($this) {
            self::ProviderSettlementClearing => LedgerEntryType::Debit,
            default => LedgerEntryType::Credit,
        };
    }

    public function allowsNegativeBalance(): bool
    {
        return $this === self::ProviderSettlementClearing;
    }

    public function allowedScope(): WalletAccountScopeType
    {
        return match ($this) {
            self::PlatformFee => WalletAccountScopeType::Platform,
            self::ProviderSettlementClearing => WalletAccountScopeType::Provider,
            default => WalletAccountScopeType::User,
        };
    }
}
