<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support\Scenarios;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\PostLedgerEntryCommand;
use App\Domain\Wallet\Data\PostLedgerTransactionCommand;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use Illuminate\Support\Str;
use Tests\Concurrency\Support\ConcurrencyOutcomeCategory;

/**
 * Scenario K - independent-account progress (negative control). Each
 * worker posts against its own, completely unrelated account - no wallet
 * account ID appears in both workers' payloads. lockAccountsInOrder() only
 * ever locks the specific rows an individual posting's own entries
 * reference, so these two calls should never block on one another. The
 * coordinator's proof is structural, not a speed claim: both workers'
 * self-reported tBefore/tAfter windows measurably overlap (neither
 * finished before the other even started), and both succeed.
 */
final class IndependentAccountProgressScenario implements ConcurrencyScenario
{
    public function runWorker(string $role, array $payload): array
    {
        $clearingAccountId = (string) $payload['clearingAccountId'];
        $targetAccountId = (string) $payload['walletAccountId'];
        $amountAtomic = (int) $payload['amountAtomic'];
        $businessReference = (string) $payload['businessReference'];

        $posted = (new LedgerPostingEngine)->post(new PostLedgerTransactionCommand(
            LedgerTransactionType::DepositCredit,
            $businessReference,
            (string) Str::ulid(),
            'Concurrency scenario K independent-account posting',
            null,
            null,
            null,
            [
                new PostLedgerEntryCommand($clearingAccountId, LedgerEntryType::Debit, Money::fromAtomic($amountAtomic, Currency::USD)),
                new PostLedgerEntryCommand($targetAccountId, LedgerEntryType::Credit, Money::fromAtomic($amountAtomic, Currency::USD)),
            ],
        ));

        return [
            'outcome' => ConcurrencyOutcomeCategory::Created,
            'committedTransactionId' => $posted->transaction->id,
        ];
    }
}
