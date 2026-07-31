<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support\Scenarios;

use Tests\Concurrency\Support\ConcurrencyOutcomeCategory;

/**
 * One implementation per scenario (A-K). runWorker() is called by the
 * standalone worker script after the barrier has released - it performs
 * exactly the one contested operation this worker's role is assigned and
 * classifies its own result into the closed outcome enum. Any exception
 * this method lets escape is caught by the worker script's own generic
 * classifyUnexpectedException() safety net, so a scenario only needs to
 * catch the exceptions it specifically expects as *legitimate* outcomes.
 */
interface ConcurrencyScenario
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{outcome: ConcurrencyOutcomeCategory, committedTransactionId: ?string, extra?: array<string, string>}
     */
    public function runWorker(string $role, array $payload): array;
}
