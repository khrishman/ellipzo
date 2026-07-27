<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Enums;

/**
 * The final approved transaction-type vocabulary (Task 2.1). Domain-neutral
 * fund_reservation/fund_release replace the superseded campaign-specific
 * names; reversal and administrative_adjustment are kept distinct, never
 * merged. Schema capability only in Task 2.3 - nothing in this task writes
 * a row of any of these types.
 */
enum LedgerTransactionType: string
{
    case FundReservation = 'fund_reservation';
    case FundRelease = 'fund_release';
    case SubmissionSettlement = 'submission_settlement';
    case DepositCredit = 'deposit_credit';
    case WithdrawalHold = 'withdrawal_hold';
    case WithdrawalSettlement = 'withdrawal_settlement';
    case WithdrawalRelease = 'withdrawal_release';
    case Reversal = 'reversal';
    case AdministrativeAdjustment = 'administrative_adjustment';
}
