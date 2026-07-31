<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support\Scenarios;

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\PostLedgerEntryCommand;
use App\Domain\Wallet\Data\PostLedgerTransactionCommand;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\WalletAccount;
use App\Domain\Wallet\Services\BalanceSnapshotService;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use Illuminate\Support\Str;
use Tests\Concurrency\Support\ConcurrencyOutcomeCategory;

/**
 * Scenario J - snapshot versus concurrent posting. One worker captures a
 * snapshot of an account while the other posts a transaction touching that
 * same account. BalanceSnapshotService::captureForAccount() and
 * LedgerPostingEngine::lockAccountsInOrder() both take a lockForUpdate() on
 * the very same wallet_accounts row before doing anything else - real
 * contention, genuinely serializing the snapshot fold against the posting
 * rather than racing it. The coordinator independently re-folds ledger
 * history up to the snapshot's own recorded cutoff and proves the stored
 * balance/entry-count/fingerprint describe that exact same boundary - no
 * torn read is structurally possible under this locking, and the test
 * proves it empirically rather than only by argument.
 */
final class SnapshotVersusPostingScenario implements ConcurrencyScenario
{
    public function runWorker(string $role, array $payload): array
    {
        return match ((string) $payload['operation']) {
            'snapshot' => $this->captureSnapshot($payload),
            'post' => $this->postTransaction($payload),
            default => throw new \InvalidArgumentException('Unknown scenario J operation.'),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{outcome: ConcurrencyOutcomeCategory, committedTransactionId: ?string, extra: array<string, string>}
     */
    private function captureSnapshot(array $payload): array
    {
        $account = WalletAccount::query()->findOrFail((string) $payload['walletAccountId']);

        $snapshot = (new BalanceSnapshotService)->captureForAccount($account);

        return [
            'outcome' => ConcurrencyOutcomeCategory::Created,
            'committedTransactionId' => null,
            'extra' => [
                'snapshotId' => $snapshot->id,
                'balanceAtomic' => (string) $snapshot->balance_atomic,
                'entryCount' => (string) $snapshot->entry_count,
                'cutoffLedgerEntryId' => $snapshot->cutoff_ledger_entry_id ?? '',
                'fingerprint' => $snapshot->fingerprint,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{outcome: ConcurrencyOutcomeCategory, committedTransactionId: ?string, extra: array<string, string>}
     */
    private function postTransaction(array $payload): array
    {
        $clearingAccountId = (string) $payload['clearingAccountId'];
        $targetAccountId = (string) $payload['walletAccountId'];
        $amountAtomic = (int) $payload['amountAtomic'];
        $businessReference = (string) $payload['businessReference'];

        $posted = (new LedgerPostingEngine)->post(new PostLedgerTransactionCommand(
            LedgerTransactionType::DepositCredit,
            $businessReference,
            (string) Str::ulid(),
            'Concurrency scenario J concurrent posting',
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
            'extra' => [],
        ];
    }
}
