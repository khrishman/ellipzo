<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletAccountType;

/**
 * The single, server-side source of truth for every user-facing label this
 * feature displays - never duplicated as a second switch/map in the
 * frontend. TransactionHistoryPresenter is the only intended caller.
 *
 * Both methods are written as an explicit match with a safe default/fixed
 * fallback for a case this class does not (yet) name - accountLabel()'s
 * default is currently unreachable (callers only ever pass one of the four
 * user-scoped types), and transactionTypeLabel()'s default is currently
 * unreachable too (every existing LedgerTransactionType case is named
 * explicitly), but both exist so a future enum case never renders raw
 * enum text or crashes with an UnhandledMatchError.
 */
final class TransactionHistoryLabels
{
    public static function accountLabel(WalletAccountType $type): string
    {
        return match ($type) {
            WalletAccountType::EarningAvailable => 'Earning available',
            WalletAccountType::EarningHeld => 'Earning held',
            WalletAccountType::AdvertisingAvailable => 'Advertising available',
            WalletAccountType::AdvertisingReserved => 'Advertising reserved',
            default => 'Wallet account',
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
