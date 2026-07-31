<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support\Scenarios;

use App\Domain\Wallet\Models\WalletAccount;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ConcurrencyOutcomeCategory;
use Tests\Concurrency\Support\FileBarrier;

/**
 * Support for the stuck-worker/kill-and-recover proof. Deliberately does
 * not touch LedgerPostingEngine or any other production write path - it
 * takes a plain lockForUpdate() on one wallet_accounts row, signals a
 * second barrier file the instant the lock is held (so the coordinator
 * knows precisely when it is safe to kill this process mid-transaction,
 * not merely "the process has started"), then sleeps well past the
 * coordinator's own kill deadline. If this method ever returns normally,
 * the coordinator's kill didn't happen in time - the test needs
 * retuning, not a passing result.
 */
final class WorkerTerminationScenario implements ConcurrencyScenario
{
    public function runWorker(string $role, array $payload): array
    {
        $accountId = (string) $payload['walletAccountId'];
        $barrierDir = (string) $payload['barrierDir'];

        DB::connection('mysql_concurrency')->transaction(function () use ($accountId, $barrierDir): void {
            WalletAccount::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();

            (new FileBarrier($barrierDir))->signalReady('lock-acquired');

            sleep(30);
        });

        // Only reached if the coordinator failed to kill this process in
        // time - reported honestly rather than silently treated as success.
        return [
            'outcome' => ConcurrencyOutcomeCategory::UnexpectedFailure,
            'committedTransactionId' => null,
            'extra' => ['reason' => 'not_killed_in_time'],
        ];
    }
}
