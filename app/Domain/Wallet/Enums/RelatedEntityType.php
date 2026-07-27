<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Enums;

/**
 * Stable aliases for ledger_transactions.related_entity_type - never a raw
 * ::class name, so a future rename/namespace move can never silently break
 * a historical row.
 */
enum RelatedEntityType: string
{
    case Campaign = 'campaign';
    case Submission = 'submission';
    case DepositIntent = 'deposit_intent';
    case WithdrawalRequest = 'withdrawal_request';
    case ReversalRequest = 'reversal_request';
    case WalletAccount = 'wallet_account';
}
