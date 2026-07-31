<?php

declare(strict_types=1);

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\PostLedgerEntryCommand;
use App\Domain\Wallet\Data\PostLedgerTransactionCommand;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concurrency\Concerns\AssertsConcurrencyEnvironment;
use Tests\Concurrency\Support\ConcurrencyRunNamespace;
use Tests\Concurrency\Support\FileBarrier;
use Tests\Concurrency\Support\ScenarioCleanup;
use Tests\Concurrency\Support\Scenarios\ScenarioRegistry;
use Tests\Concurrency\Support\WorkerLauncher;
use Tests\Concurrency\Support\WorkerReport;
use Tests\TestCase;

uses(TestCase::class, AssertsConcurrencyEnvironment::class);

beforeEach(function (): void {
    $this->ensureConcurrencyEnvironmentReady(ScenarioRegistry::ReversalRequest);
});

afterEach(function (): void {
    $remaining = (new ScenarioCleanup(DB::connection('mysql_concurrency'), $this->concurrencyNamespace))->run();
    $this->resetConcurrencyDefaultConnection();
    expect($remaining)->toBe([], 'Scenario F left owned rows behind: '.implode(', ', $remaining));
});

/**
 * Coordinator-held-lock proof, per explicit correction: relying on the
 * loser's tAfter landing at-or-after the winner's tAfter cannot distinguish
 * genuine lock contention from ordinary sequential process scheduling (a
 * slow-to-start loser would produce the identical timestamp ordering with
 * zero real contention). This holds the *exact* row
 * ReversalRequestService::request()'s own first statement locks
 * (`LedgerTransaction::query()->whereKey(...)->lockForUpdate()->first()`)
 * on a third, coordinator-owned connection *before* either worker is
 * released, then only releases it after both workers have signalled they
 * are genuinely attempting their own locked call (the "{role}-invoked"
 * marker, written immediately before the scenario's service call - see
 * concurrency-worker.php). Because neither worker's call can return until
 * this lock is released, and the lock is provably still held at the moment
 * both invocation signals are observed, both workers' invocation instants
 * are structurally guaranteed to precede either worker's completion -
 * this is a construction, not an empirical coincidence.
 */
function scenarioFRunWithCoordinatorLock(
    string $originalTransactionId,
    string $scenario,
    FileBarrier $barrier,
    ConcurrencyRunNamespace $namespace,
    string $payloadA,
    string $payloadB,
): array {
    $coordinatorConnection = DB::connection('mysql_concurrency');
    $coordinatorConnection->beginTransaction();

    $lockedRow = $coordinatorConnection->table('ledger_transactions')
        ->where('id', $originalTransactionId)
        ->lockForUpdate()
        ->first();

    if ($lockedRow === null) {
        $coordinatorConnection->rollBack();
        throw new RuntimeException('Coordinator could not lock the original transaction row - it does not exist.');
    }

    try {
        return (new WorkerLauncher)->spawnAndWait(
            $scenario,
            [
                'worker-a' => ['runId' => $namespace->runId, 'barrierDir' => $barrier->directory(), 'payloadPath' => $payloadA],
                'worker-b' => ['runId' => $namespace->runId, 'barrierDir' => $barrier->directory(), 'payloadPath' => $payloadB],
            ],
            timeoutSeconds: 20.0,
            barrier: $barrier,
            afterRelease: function () use ($barrier, $coordinatorConnection): void {
                // Both workers are now genuinely attempting their own
                // lockForUpdate() call against the row this transaction
                // still holds - only now is it safe to release it.
                $barrier->waitForAllReady(['worker-a-invoked', 'worker-b-invoked']);
                $coordinatorConnection->commit();
            },
        );
    } finally {
        // If waitForAllReady() above threw (a worker crashed before
        // reaching its invocation marker) the commit above never ran -
        // the lock must still be released here so the worker isn't left
        // blocked forever and cleanup can proceed.
        if ($coordinatorConnection->transactionLevel() > 0) {
            $coordinatorConnection->rollBack();
        }
    }
}

/**
 * @return array{0: string, 1: int}
 */
function scenarioFSetup(ConcurrencyRunNamespace $namespace): array
{
    $user = User::factory()->create(['name' => $namespace->username(), 'email' => $namespace->username().'@concurrency.test']);
    $actor = User::factory()->create(['name' => $namespace->username().'act', 'email' => $namespace->username().'act@concurrency.test']);
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($user);
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount($namespace->idempotencyKey('clearing'));

    $transaction = (new LedgerPostingEngine)->post(new PostLedgerTransactionCommand(
        LedgerTransactionType::DepositCredit,
        $namespace->businessReference('deposit_credit', 'original'),
        (string) Str::ulid(),
        'Scenario F original transaction',
        null,
        null,
        null,
        [
            new PostLedgerEntryCommand($clearing->id, LedgerEntryType::Debit, Money::fromAtomic(10_000_000, Currency::USD)),
            new PostLedgerEntryCommand($accounts->earningAvailable->id, LedgerEntryType::Credit, Money::fromAtomic(10_000_000, Currency::USD)),
        ],
    ))->transaction;

    return [$transaction->id, $actor->id];
}

test('identical concurrent reversal requests for the same original transaction converge on one durable request', function (): void {
    $namespace = $this->concurrencyNamespace;
    [$originalTransactionId, $actorId] = scenarioFSetup($namespace);

    $sameReason = 'Scenario F identical-reason reversal request';

    $payloadA = tempnam(sys_get_temp_dir(), 'ccpayload');
    $payloadB = tempnam(sys_get_temp_dir(), 'ccpayload');
    file_put_contents($payloadA, json_encode([
        'originalTransactionId' => $originalTransactionId,
        'idempotencyKey' => $namespace->idempotencyKey('req-a'),
        'reason' => $sameReason,
        'actorId' => $actorId,
    ]));
    file_put_contents($payloadB, json_encode([
        'originalTransactionId' => $originalTransactionId,
        'idempotencyKey' => $namespace->idempotencyKey('req-b'),
        'reason' => $sameReason,
        'actorId' => $actorId,
    ]));

    $barrier = FileBarrier::forRun(storage_path('framework/testing/concurrency'), $namespace->runId, ScenarioRegistry::ReversalRequest);

    $results = scenarioFRunWithCoordinatorLock(
        $originalTransactionId,
        ScenarioRegistry::ReversalRequest,
        $barrier,
        $namespace,
        $payloadA,
        $payloadB,
    );

    $barrier->cleanup();
    @unlink($payloadA);
    @unlink($payloadB);

    expect($results['worker-a'])->not->toBeNull();
    expect($results['worker-b'])->not->toBeNull();

    $reportA = WorkerReport::fromProcessOutput($results['worker-a']->output());
    $reportB = WorkerReport::fromProcessOutput($results['worker-b']->output());

    expect($reportA->mysqlConnectionId)->not->toBe($reportB->mysqlConnectionId);

    $outcomes = collect([$reportA->outcome->value, $reportB->outcome->value])->sort()->values()->all();
    expect($outcomes)->toBe(['created', 'replay']);
    expect($reportA->extra['reversalRequestId'])->toBe($reportB->extra['reversalRequestId']);
    expect($reportA->extra['fingerprint'])->toBe($reportB->extra['fingerprint']);

    // Genuine overlapping-invocation proof (not process-scheduling
    // coincidence): both workers' own recorded "about to call the service"
    // instants must precede *either* worker's completion, since neither
    // could complete before the coordinator's held lock was released, and
    // the coordinator only released it after observing both invocation
    // markers - see scenarioFRunWithCoordinatorLock()'s own docblock.
    $invokedAtA = (float) $reportA->extra['serviceInvokedAt'];
    $invokedAtB = (float) $reportB->extra['serviceInvokedAt'];
    expect($invokedAtA)->toBeLessThan($reportB->tAfter);
    expect($invokedAtB)->toBeLessThan($reportA->tAfter);

    // Secondary, weaker evidence retained for continuity: the loser's
    // request() call cannot return until the winner's transaction has
    // committed and released the row lock it was blocked behind.
    $winnerReport = $reportA->outcome->value === 'created' ? $reportA : $reportB;
    $loserReport = $reportA->outcome->value === 'created' ? $reportB : $reportA;
    expect($loserReport->tAfter)->toBeGreaterThanOrEqual($winnerReport->tAfter);

    expect(DB::connection('mysql_concurrency')->table('reversal_requests')->where('original_ledger_transaction_id', $originalTransactionId)->count())->toBe(1);
});

test('conflicting concurrent reversal requests for the same original transaction: one is durably rejected', function (): void {
    $namespace = $this->concurrencyNamespace;
    [$originalTransactionId, $actorId] = scenarioFSetup($namespace);

    $payloadA = tempnam(sys_get_temp_dir(), 'ccpayload');
    $payloadB = tempnam(sys_get_temp_dir(), 'ccpayload');
    file_put_contents($payloadA, json_encode([
        'originalTransactionId' => $originalTransactionId,
        'idempotencyKey' => $namespace->idempotencyKey('conflict-a'),
        'reason' => 'Scenario F conflicting reason from worker A',
        'actorId' => $actorId,
    ]));
    file_put_contents($payloadB, json_encode([
        'originalTransactionId' => $originalTransactionId,
        'idempotencyKey' => $namespace->idempotencyKey('conflict-b'),
        'reason' => 'Scenario F conflicting reason from worker B, deliberately different',
        'actorId' => $actorId,
    ]));

    $barrier = FileBarrier::forRun(storage_path('framework/testing/concurrency'), $namespace->runId, ScenarioRegistry::ReversalRequest);

    $results = scenarioFRunWithCoordinatorLock(
        $originalTransactionId,
        ScenarioRegistry::ReversalRequest,
        $barrier,
        $namespace,
        $payloadA,
        $payloadB,
    );

    $barrier->cleanup();
    @unlink($payloadA);
    @unlink($payloadB);

    expect($results['worker-a'])->not->toBeNull();
    expect($results['worker-b'])->not->toBeNull();

    $reportA = WorkerReport::fromProcessOutput($results['worker-a']->output());
    $reportB = WorkerReport::fromProcessOutput($results['worker-b']->output());

    expect($reportA->mysqlConnectionId)->not->toBe($reportB->mysqlConnectionId);

    $outcomes = collect([$reportA->outcome->value, $reportB->outcome->value])->sort()->values()->all();
    expect($outcomes)->toBe(['conflicting_request', 'created']);

    // Same genuine-overlap proof as the identical-request case above.
    $invokedAtA = (float) $reportA->extra['serviceInvokedAt'];
    $invokedAtB = (float) $reportB->extra['serviceInvokedAt'];
    expect($invokedAtA)->toBeLessThan($reportB->tAfter);
    expect($invokedAtB)->toBeLessThan($reportA->tAfter);

    $winnerReport = $reportA->outcome->value === 'created' ? $reportA : $reportB;
    $loserReport = $reportA->outcome->value === 'created' ? $reportB : $reportA;
    expect($loserReport->tAfter)->toBeGreaterThanOrEqual($winnerReport->tAfter);

    // original_ledger_transaction_id uniqueness holds: exactly one request
    // row exists, no partial/duplicate state remains.
    expect(DB::connection('mysql_concurrency')->table('reversal_requests')->where('original_ledger_transaction_id', $originalTransactionId)->count())->toBe(1);
});
