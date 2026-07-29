<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Enums;

/**
 * The seven approved wallet account types. platform_suspense (Task 2.5.1)
 * is the internal balancing account for administrative corrections only -
 * it never represents provider funds, fee revenue, or a real external
 * asset. normalEntrySide()/allowsNegativeBalance()/allowedScope() are
 * defined and unit-tested here but consumed by nothing until Task 2.4's
 * posting engine.
 */
enum WalletAccountType: string
{
    case EarningAvailable = 'earning_available';
    case EarningHeld = 'earning_held';
    case AdvertisingAvailable = 'advertising_available';
    case AdvertisingReserved = 'advertising_reserved';
    case PlatformFee = 'platform_fee';
    case ProviderSettlementClearing = 'provider_settlement_clearing';
    case PlatformSuspense = 'platform_suspense';

    public function normalEntrySide(): LedgerEntryType
    {
        return match ($this) {
            self::ProviderSettlementClearing, self::PlatformSuspense => LedgerEntryType::Debit,
            default => LedgerEntryType::Credit,
        };
    }

    public function allowsNegativeBalance(): bool
    {
        return match ($this) {
            self::ProviderSettlementClearing, self::PlatformSuspense => true,
            default => false,
        };
    }

    public function allowedScope(): WalletAccountScopeType
    {
        return match ($this) {
            self::PlatformFee, self::PlatformSuspense => WalletAccountScopeType::Platform,
            self::ProviderSettlementClearing => WalletAccountScopeType::Provider,
            default => WalletAccountScopeType::User,
        };
    }
}
