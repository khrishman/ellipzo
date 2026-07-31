<?php

declare(strict_types=1);

use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Concerns\AssertsConcurrencyEnvironment;
use Tests\Concurrency\Support\FileBarrier;
use Tests\Concurrency\Support\ScenarioCleanup;
use Tests\Concurrency\Support\Scenarios\ScenarioRegistry;
use Tests\Concurrency\Support\WorkerLauncher;
use Tests\Concurrency\Support\WorkerReport;
use Tests\TestCase;

uses(TestCase::class, AssertsConcurrencyEnvironment::class);

beforeEach(function (): void {
    $this->ensureConcurrencyEnvironmentReady(ScenarioRegistry::IdenticalFinancialEvent);
});

afterEach(function (): void {
    $remaining = (new ScenarioCleanup(DB::connection('mysql_concurrency'), $this->concurrencyNamespace))->run();
    $this->resetConcurrencyDefaultConnection();
    expect($remaining)->toBe([], 'Scenario C left owned rows behind: '.implode(', ', $remaining));
});

test('two workers posting the identical financial event under the same business reference converge on one transaction', function (): void {
    $namespace = $this->concurrencyNamespace;

    $user = User::factory()->create(['name' => $namespace->username(), 'email' => $namespace->username().'@concurrency.test']);
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($user);
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount($namespace->idempotencyKey('clearing'));

    $businessReference = $namespace->businessReference('deposit_credit', 'race');
    $payload = json_encode([
        'clearingAccountId' => $clearing->id,
        'earningAvailableAccountId' => $accounts->earningAvailable->id,
        'amountAtomic' => 25_000_000,
        'businessReference' => $businessReference,
    ]);
    $payloadPath = tempnam(sys_get_temp_dir(), 'ccpayload');
    file_put_contents($payloadPath, $payload);

    $barrier = FileBarrier::forRun(storage_path('framework/testing/concurrency'), $namespace->runId, ScenarioRegistry::IdenticalFinancialEvent);

    $results = (new WorkerLauncher)->spawnAndWait(
        ScenarioRegistry::IdenticalFinancialEvent,
        [
            'worker-a' => ['runId' => $namespace->runId, 'barrierDir' => $barrier->directory(), 'payloadPath' => $payloadPath],
            'worker-b' => ['runId' => $namespace->runId, 'barrierDir' => $barrier->directory(), 'payloadPath' => $payloadPath],
        ],
        timeoutSeconds: 20.0,
        barrier: $barrier,
    );

    $barrier->cleanup();
    @unlink($payloadPath);

    expect($results['worker-a'])->not->toBeNull();
    expect($results['worker-b'])->not->toBeNull();

    $reportA = WorkerReport::fromProcessOutput($results['worker-a']->output());
    $reportB = WorkerReport::fromProcessOutput($results['worker-b']->output());

    expect($reportA->mysqlConnectionId)->not->toBe($reportB->mysqlConnectionId);

    $outcomes = collect([$reportA->outcome->value, $reportB->outcome->value])->sort()->values()->all();
    expect($outcomes)->toBe(['created', 'replay']);

    // Both converge on the exact same committed transaction ID.
    expect($reportA->committedTransactionId)->toBe($reportB->committedTransactionId);

    // Exactly one transaction, one entry set - independently re-derived.
    expect(DB::connection('mysql_concurrency')->table('ledger_transactions')->where('business_reference', $businessReference)->count())->toBe(1);
    expect(DB::connection('mysql_concurrency')->table('ledger_entries')->where('ledger_transaction_id', $reportA->committedTransactionId)->count())->toBe(2);

    // The original committed correlation ID (the winner's) is preserved -
    // both reports carry it, since the replay reconciliation returns the
    // original row unmodified.
    expect($reportA->extra['correlationId'])->toBe($reportB->extra['correlationId']);
});
