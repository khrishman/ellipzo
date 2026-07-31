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
 * Scenario C - identical financial-event race. Both workers post the exact
 * same semantic payload under the same business_reference but with
 * different (retry) correlation IDs. Contests
 * ledger_transactions.business_reference's unique index +
 * LedgerPostingEngine::reconcileGenericReplay()/matchesSemanticPayload().
 * One worker's insert commits (Created); the other's insert blocks on the
 * unique index's implicit lock until the first commits, then fails and
 * recovers via replay reconciliation (Replay) - both converge on the same
 * committed transaction ID, and the originally committed correlation ID
 * (not the loser's own) is preserved.
 */
final class IdenticalFinancialEventRaceScenario implements ConcurrencyScenario
{
    public function runWorker(string $role, array $payload): array
    {
        $clearingAccountId = (string) $payload['clearingAccountId'];
        $earningAvailableId = (string) $payload['earningAvailableAccountId'];
        $amountAtomic = (int) $payload['amountAtomic'];
        $businessReference = (string) $payload['businessReference'];

        $engine = new LedgerPostingEngine;

        $posted = $engine->post(new PostLedgerTransactionCommand(
            LedgerTransactionType::DepositCredit,
            $businessReference,
            (string) Str::ulid(),
            'Concurrency scenario C identical replay attempt',
            null,
            null,
            null,
            [
                new PostLedgerEntryCommand($clearingAccountId, LedgerEntryType::Debit, Money::fromAtomic($amountAtomic, Currency::USD)),
                new PostLedgerEntryCommand($earningAvailableId, LedgerEntryType::Credit, Money::fromAtomic($amountAtomic, Currency::USD)),
            ],
        ));

        return [
            'outcome' => $posted->wasReplay ? ConcurrencyOutcomeCategory::Replay : ConcurrencyOutcomeCategory::Created,
            'committedTransactionId' => $posted->transaction->id,
            'extra' => ['correlationId' => $posted->transaction->correlation_id],
        ];
    }
}
