<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Data;

use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;

/**
 * A validated, normalized pair of optional transaction-history filters.
 * TransactionHistoryFilterRequest already restricts the raw input to this
 * same allowlist - the check below is defense-in-depth against any other
 * caller that might construct this DTO directly, mirroring how
 * SubmitAdministrativeAdjustmentCommand re-validates its own target type
 * even though the caller is expected to have checked already.
 */
final readonly class TransactionHistoryFilters
{
    public function __construct(
        public ?WalletAccountType $accountType = null,
        public ?LedgerTransactionType $transactionType = null,
    ) {
        if ($this->accountType !== null && ! in_array($this->accountType, self::allowedAccountTypes(), true)) {
            throw new LedgerInvariantViolationException('Transaction history account filter must be a user-scoped account type.');
        }
    }

    /**
     * @return list<WalletAccountType>
     */
    public static function allowedAccountTypes(): array
    {
        return [
            WalletAccountType::EarningAvailable,
            WalletAccountType::EarningHeld,
            WalletAccountType::AdvertisingAvailable,
            WalletAccountType::AdvertisingReserved,
        ];
    }
}
