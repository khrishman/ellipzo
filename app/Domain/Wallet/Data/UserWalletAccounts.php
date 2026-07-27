<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Data;

use App\Domain\Shared\Money\Currency;
use App\Domain\Wallet\Enums\WalletAccountScopeType;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Exceptions\WalletAccountInvariantException;
use App\Domain\Wallet\Models\WalletAccount;

/**
 * The four wallet accounts a user needs to participate in earning and
 * advertising. Constructing this DTO re-validates every invariant
 * WalletAccountProvisioner is already expected to guarantee, as a final
 * structural check independent of how the four accounts were resolved.
 */
final readonly class UserWalletAccounts
{
    public function __construct(
        public WalletAccount $earningAvailable,
        public WalletAccount $earningHeld,
        public WalletAccount $advertisingAvailable,
        public WalletAccount $advertisingReserved,
        int $userId,
    ) {
        $expectedTypes = [
            'earningAvailable' => WalletAccountType::EarningAvailable,
            'earningHeld' => WalletAccountType::EarningHeld,
            'advertisingAvailable' => WalletAccountType::AdvertisingAvailable,
            'advertisingReserved' => WalletAccountType::AdvertisingReserved,
        ];

        $accounts = [
            'earningAvailable' => $this->earningAvailable,
            'earningHeld' => $this->earningHeld,
            'advertisingAvailable' => $this->advertisingAvailable,
            'advertisingReserved' => $this->advertisingReserved,
        ];

        $seenIds = [];

        foreach ($accounts as $property => $account) {
            if (! $account->exists) {
                throw new WalletAccountInvariantException('Each wallet account must be a persisted record.');
            }

            if ($account->user_id !== $userId) {
                throw new WalletAccountInvariantException('Each wallet account must belong to the requested user.');
            }

            if ($account->scope_type !== WalletAccountScopeType::User) {
                throw new WalletAccountInvariantException('Each wallet account must be user-scoped.');
            }

            if ($account->scope_key !== (string) $userId) {
                throw new WalletAccountInvariantException('Each wallet account scope key must match the requested user.');
            }

            if ($account->currency_code !== Currency::USD) {
                throw new WalletAccountInvariantException('Each wallet account must use USD.');
            }

            if ($account->currency_scale !== Currency::USD->scale()) {
                throw new WalletAccountInvariantException('Each wallet account must use the canonical USD scale.');
            }

            if ($account->account_type !== $expectedTypes[$property]) {
                throw new WalletAccountInvariantException('Each wallet account must have its expected distinct account type.');
            }

            $id = $account->getKey();

            if (in_array($id, $seenIds, true)) {
                throw new WalletAccountInvariantException('The four wallet accounts must not share an account ID.');
            }

            $seenIds[] = $id;
        }
    }
}
