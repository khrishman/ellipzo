<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support\Scenarios;

use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Tests\Concurrency\Support\ConcurrencyOutcomeCategory;

/**
 * Scenario A - concurrent wallet provisioning. Both workers call
 * provisionUserAccounts() for the same new user at the same instant,
 * contesting WalletAccountProvisioner::resolveAccount()'s insert-first-
 * catch-UniqueConstraintViolationException-then-refetch-and-validate path
 * (wallet_accounts_identity_unique on scope_type+scope_key+account_type+
 * currency_code). Both calls are expected to succeed and converge on the
 * same canonical 4-account set - there is no "loser" here, only a real
 * insert and a real recovered refetch, indistinguishable from the public
 * API's own return value alone. The coordinator's own post-hoc query
 * (exactly 4 accounts exist, no duplicates) is the real proof; each
 * worker's report exists to prove distinct connections converged on an
 * identical result.
 */
final class ConcurrentWalletProvisioningScenario implements ConcurrencyScenario
{
    public function runWorker(string $role, array $payload): array
    {
        $userId = (int) $payload['userId'];
        $user = User::query()->findOrFail($userId);

        $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($user);

        $accountIds = [
            $accounts->earningAvailable->id,
            $accounts->earningHeld->id,
            $accounts->advertisingAvailable->id,
            $accounts->advertisingReserved->id,
        ];
        sort($accountIds);

        return [
            'outcome' => ConcurrencyOutcomeCategory::Created,
            'committedTransactionId' => null,
            'extra' => ['accountIds' => implode(',', $accountIds)],
        ];
    }
}
