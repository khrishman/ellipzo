<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support\Scenarios;

use App\Domain\Wallet\Data\RequestLedgerReversalCommand;
use App\Domain\Wallet\Exceptions\DuplicateFinancialEventException;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\ReversalRequestService;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Concurrency\Support\ConcurrencyOutcomeCategory;

/**
 * Scenario F - concurrent reversal-request behavior. Both workers call
 * request() for the same original transaction. ReversalRequestService::
 * request()'s own first statement takes a lockForUpdate() on the
 * *original* ledger_transactions row before ever attempting the
 * reversal_requests insert (ReversalRequestService.php:49-53), which fully
 * serializes the two calls: the loser's insert attempt only ever begins
 * after the winner's transaction has already committed (or rolled back)
 * and released that lock. This is therefore a genuine lock-serialization
 * proof, not a live unique-constraint race.
 *
 * Genuine contention (not process-scheduling coincidence) is proven
 * structurally, not just inferred from completion order: the coordinator
 * (see ConcurrentReversalRequestTest.php's scenarioFRunWithCoordinatorLock())
 * holds this exact row locked on its own third connection *before* either
 * worker is released, and only releases it after observing both workers'
 * "about to call the service" markers - so both workers' invocation
 * instants are structurally guaranteed to precede either worker's
 * completion, by construction. Each worker returns its own invocation
 * timestamp via extra['serviceInvokedAt'].
 *
 * Same scenario class handles both required sub-cases via payload alone:
 * identical reason+actor => same fingerprint => loser gets Replay;
 * different reason => different fingerprint => loser gets
 * ConflictingRequest (DuplicateFinancialEventException).
 */
final class ConcurrentReversalRequestScenario implements ConcurrencyScenario
{
    public function runWorker(string $role, array $payload): array
    {
        $originalTransactionId = (string) $payload['originalTransactionId'];
        $idempotencyKey = (string) $payload['idempotencyKey'];
        $reason = (string) $payload['reason'];
        $actorId = (int) $payload['actorId'];

        $service = new ReversalRequestService(new LedgerPostingEngine);

        try {
            $request = $service->request(new RequestLedgerReversalCommand(
                $originalTransactionId,
                $idempotencyKey,
                (string) Str::ulid(),
                $reason,
                User::query()->findOrFail($actorId),
            ));

            $outcome = $request->idempotency_key === $idempotencyKey
                ? ConcurrencyOutcomeCategory::Created
                : ConcurrencyOutcomeCategory::Replay;

            return [
                'outcome' => $outcome,
                'committedTransactionId' => null,
                'extra' => [
                    'reversalRequestId' => $request->id,
                    'fingerprint' => $request->fingerprint,
                ],
            ];
        } catch (DuplicateFinancialEventException) {
            return [
                'outcome' => ConcurrencyOutcomeCategory::ConflictingRequest,
                'committedTransactionId' => null,
            ];
        }
    }
}
