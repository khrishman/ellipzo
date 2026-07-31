<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support\Scenarios;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\PostLedgerEntryCommand;
use App\Domain\Wallet\Data\PostLedgerTransactionCommand;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Exceptions\DuplicateFinancialEventException;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use Illuminate\Support\Str;
use Tests\Concurrency\Support\ConcurrencyOutcomeCategory;

/**
 * Scenario D - conflicting financial-event race. Both workers post under
 * the same business_reference but with genuinely different amounts (a
 * mismatched semantic payload). One insert commits (Created); the other
 * blocks on the same implicit unique-index lock, then fails and - because
 * matchesSemanticPayload() finds the amounts differ - surfaces
 * DuplicateFinancialEventException rather than being treated as a replay.
 * The winner is not predetermined; either worker may legitimately win.
 */
final class ConflictingFinancialEventRaceScenario implements ConcurrencyScenario
{
    public function runWorker(string $role, array $payload): array
    {
        $clearingAccountId = (string) $payload['clearingAccountId'];
        $earningAvailableId = (string) $payload['earningAvailableAccountId'];
        $amountAtomic = (int) $payload["amountAtomic_{$role}"];
        $businessReference = (string) $payload['businessReference'];

        $engine = new LedgerPostingEngine;

        try {
            $posted = $engine->post(new PostLedgerTransactionCommand(
                LedgerTransactionType::DepositCredit,
                $businessReference,
                (string) Str::ulid(),
                'Concurrency scenario D conflicting payload attempt',
                null,
                null,
                null,
                [
                    new PostLedgerEntryCommand($clearingAccountId, LedgerEntryType::Debit, Money::fromAtomic($amountAtomic, Currency::USD)),
                    new PostLedgerEntryCommand($earningAvailableId, LedgerEntryType::Credit, Money::fromAtomic($amountAtomic, Currency::USD)),
                ],
            ));

            return [
                'outcome' => ConcurrencyOutcomeCategory::Created,
                'committedTransactionId' => $posted->transaction->id,
            ];
        } catch (DuplicateFinancialEventException) {
            return [
                'outcome' => ConcurrencyOutcomeCategory::DuplicateEvent,
                'committedTransactionId' => null,
            ];
        }
    }
}
