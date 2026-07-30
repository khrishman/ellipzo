<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletAccountScopeType;
use App\Domain\Wallet\Enums\WalletAccountType;

/**
 * The staff-only label source for the admin ledger explorer (Task 2.8) -
 * deliberately separate from TransactionHistoryLabels (Task 2.7), which is
 * scoped to the four user-facing account types only and must never grow
 * platform/provider/internal labels a normal user could ever see. This
 * class is the only place account-type, scope-type, and transaction-type
 * labels are defined for staff-facing pages; nothing here is ever reused
 * on a user-facing page.
 *
 * Every match ends in a safe `default` arm - currently unreachable by
 * construction (every existing enum case is named explicitly), kept so a
 * future enum case never renders raw enum text or crashes with an
 * UnhandledMatchError.
 */
final class AdminLedgerLabels
{
    public static function accountTypeLabel(WalletAccountType $type): string
    {
        return match ($type) {
            WalletAccountType::EarningAvailable => 'Earning available',
            WalletAccountType::EarningHeld => 'Earning held',
            WalletAccountType::AdvertisingAvailable => 'Advertising available',
            WalletAccountType::AdvertisingReserved => 'Advertising reserved',
            WalletAccountType::PlatformFee => 'Platform fee',
            WalletAccountType::PlatformSuspense => 'Platform suspense',
            WalletAccountType::ProviderSettlementClearing => 'Provider settlement clearing',
            default => 'Wallet account',
        };
    }

    public static function scopeTypeLabel(WalletAccountScopeType $type): string
    {
        return match ($type) {
            WalletAccountScopeType::User => 'User',
            WalletAccountScopeType::Platform => 'Platform',
            WalletAccountScopeType::Provider => 'Provider',
            default => 'Scope',
        };
    }

    public static function transactionTypeLabel(LedgerTransactionType $type): string
    {
        return match ($type) {
            LedgerTransactionType::FundReservation => 'Funds reserved',
            LedgerTransactionType::FundRelease => 'Funds released',
            LedgerTransactionType::SubmissionSettlement => 'Task reward',
            LedgerTransactionType::DepositCredit => 'Deposit',
            LedgerTransactionType::WithdrawalHold => 'Withdrawal (hold)',
            LedgerTransactionType::WithdrawalSettlement => 'Withdrawal',
            LedgerTransactionType::WithdrawalRelease => 'Withdrawal (released)',
            LedgerTransactionType::Reversal => 'Reversal',
            LedgerTransactionType::AdministrativeAdjustment => 'Administrative adjustment',
            default => 'Transaction',
        };
    }
}
