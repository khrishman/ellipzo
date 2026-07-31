<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support\Scenarios;

use App\Domain\Wallet\Models\ReversalRequest;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\ReversalRequestService;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ConcurrencyOutcomeCategory;

/**
 * Scenario G - concurrent reversal execution. Both workers call execute()
 * on the same pending reversal request. execute() re-fetches and
 * lockForUpdate()s the request row by canonical ID before doing anything
 * else (ReversalRequestService.php:98), which - exactly like scenario F -
 * fully serializes the two calls rather than racing them: worker B's
 * execute() blocks on that same row lock until worker A's transaction
 * commits, then observes status already Applied and returns immediately
 * with no further writes. This is therefore also a lock-serialization
 * proof, not a live race - each worker distinguishes "I performed the
 * actual reversal" from "I observed an already-applied result" by
 * listening for its own INSERT into ledger_transactions during the call
 * (a DB::listen() callback registered on this worker's own, independent
 * connection - never visible to the other worker's process).
 */
final class ConcurrentReversalExecutionScenario implements ConcurrencyScenario
{
    public function runWorker(string $role, array $payload): array
    {
        $reversalRequestId = (string) $payload['reversalRequestId'];

        $insertedLedgerTransaction = false;

        DB::connection('mysql_concurrency')->listen(function ($query) use (&$insertedLedgerTransaction): void {
            if (str_starts_with(trim(strtolower($query->sql)), 'insert') && str_contains($query->sql, 'ledger_transactions')) {
                $insertedLedgerTransaction = true;
            }
        });

        $service = new ReversalRequestService(new LedgerPostingEngine);
        $request = ReversalRequest::query()->findOrFail($reversalRequestId);

        $result = $service->execute($request);

        return [
            'outcome' => $insertedLedgerTransaction ? ConcurrencyOutcomeCategory::Created : ConcurrencyOutcomeCategory::Replay,
            'committedTransactionId' => $result->reversal_transaction_id,
            'extra' => ['status' => $result->status->value],
        ];
    }
}
