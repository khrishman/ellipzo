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
 * Scenario E - opposite account-order contention, the direct AB-BA deadlock
 * proof. Both workers post their own independently-balanced transaction
 * touching the same two accounts, X and Y - the coordinator deliberately
 * assigns worker A "debit X, credit Y" (entries array order [X, Y]) and
 * worker B "debit Y, credit X" (entries array order [Y, X]), the exact
 * opposite array order. LedgerPostingEngine::lockAccountsInOrder() dedupes
 * and sort()s account IDs before locking regardless of entries array
 * order, so both workers always acquire their locks in the same canonical
 * order - no deadlock is structurally possible. Both are expected to
 * commit; the coordinator independently verifies the deterministic
 * combined final balance on each account.
 */
final class OppositeAccountOrderScenario implements ConcurrencyScenario
{
    public function runWorker(string $role, array $payload): array
    {
        $debitAccountId = (string) $payload['debitAccountId'];
        $creditAccountId = (string) $payload['creditAccountId'];
        $amountAtomic = (int) $payload['amountAtomic'];
        $businessReference = (string) $payload['businessReference'];

        $engine = new LedgerPostingEngine;

        $posted = $engine->post(new PostLedgerTransactionCommand(
            LedgerTransactionType::DepositCredit,
            $businessReference,
            (string) Str::ulid(),
            'Concurrency scenario E opposite-order attempt',
            null,
            null,
            null,
            [
                new PostLedgerEntryCommand($debitAccountId, LedgerEntryType::Debit, Money::fromAtomic($amountAtomic, Currency::USD)),
                new PostLedgerEntryCommand($creditAccountId, LedgerEntryType::Credit, Money::fromAtomic($amountAtomic, Currency::USD)),
            ],
        ));

        return [
            'outcome' => ConcurrencyOutcomeCategory::Created,
            'committedTransactionId' => $posted->transaction->id,
        ];
    }
}
