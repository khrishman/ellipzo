<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support\Scenarios;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\PostLedgerEntryCommand;
use App\Domain\Wallet\Data\PostLedgerTransactionCommand;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use Illuminate\Support\Str;
use Tests\Concurrency\Support\ConcurrencyOutcomeCategory;

/**
 * Scenario B - concurrent overspend protection. Both workers attempt to
 * reserve funds from the same earning_available account, each individually
 * affordable but together exceeding the funded balance. Contests
 * LedgerPostingEngine::lockAccountsInOrder() (both workers lock the same
 * account row) + assertProjectedBalanceAllowed() (checked after the lock is
 * held, before any entry is inserted). Exactly one worker must succeed;
 * the loser must see InsufficientBalanceException with zero rows written.
 */
final class ConcurrentOverspendScenario implements ConcurrencyScenario
{
    public function runWorker(string $role, array $payload): array
    {
        $earningAvailableId = (string) $payload['earningAvailableAccountId'];
        $earningHeldId = (string) $payload['earningHeldAccountId'];
        $amountAtomic = (int) $payload['amountAtomic'];
        $businessReference = (string) $payload['businessReference'];

        $engine = new LedgerPostingEngine;

        try {
            $posted = $engine->post(new PostLedgerTransactionCommand(
                LedgerTransactionType::FundReservation,
                $businessReference,
                (string) Str::ulid(),
                'Concurrency scenario B reservation attempt',
                null,
                null,
                null,
                [
                    new PostLedgerEntryCommand($earningAvailableId, LedgerEntryType::Debit, Money::fromAtomic($amountAtomic, Currency::USD)),
                    new PostLedgerEntryCommand($earningHeldId, LedgerEntryType::Credit, Money::fromAtomic($amountAtomic, Currency::USD)),
                ],
            ));

            return [
                'outcome' => ConcurrencyOutcomeCategory::Created,
                'committedTransactionId' => $posted->transaction->id,
            ];
        } catch (InsufficientBalanceException) {
            return [
                'outcome' => ConcurrencyOutcomeCategory::InsufficientBalance,
                'committedTransactionId' => null,
            ];
        }
    }
}
