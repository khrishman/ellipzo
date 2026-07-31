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
    $this->ensureConcurrencyEnvironmentReady(ScenarioRegistry::IndependentAccounts);
});

afterEach(function (): void {
    $remaining = (new ScenarioCleanup(DB::connection('mysql_concurrency'), $this->concurrencyNamespace))->run();
    $this->resetConcurrencyDefaultConnection();
    expect($remaining)->toBe([], 'Scenario K left owned rows behind: '.implode(', ', $remaining));
});

test('two workers posting against completely unrelated accounts both make genuine concurrent progress', function (): void {
    $namespace = $this->concurrencyNamespace;

    $userA = User::factory()->create(['name' => $namespace->username().'a', 'email' => $namespace->username().'a@concurrency.test']);
    $userB = User::factory()->create(['name' => $namespace->username().'b', 'email' => $namespace->username().'b@concurrency.test']);
    $accountsA = (new WalletAccountProvisioner)->provisionUserAccounts($userA);
    $accountsB = (new WalletAccountProvisioner)->provisionUserAccounts($userB);
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount($namespace->idempotencyKey('clearing'));

    $payloadA = tempnam(sys_get_temp_dir(), 'ccpayload');
    $payloadB = tempnam(sys_get_temp_dir(), 'ccpayload');
    file_put_contents($payloadA, json_encode([
        'clearingAccountId' => $clearing->id,
        'walletAccountId' => $accountsA->earningAvailable->id,
        'amountAtomic' => 7_000_000,
        'businessReference' => $namespace->businessReference('deposit_credit', 'a'),
    ]));
    file_put_contents($payloadB, json_encode([
        'clearingAccountId' => $clearing->id,
        'walletAccountId' => $accountsB->earningAvailable->id,
        'amountAtomic' => 9_000_000,
        'businessReference' => $namespace->businessReference('deposit_credit', 'b'),
    ]));

    $barrier = FileBarrier::forRun(storage_path('framework/testing/concurrency'), $namespace->runId, ScenarioRegistry::IndependentAccounts);

    $results = (new WorkerLauncher)->spawnAndWait(
        ScenarioRegistry::IndependentAccounts,
        [
            'worker-a' => ['runId' => $namespace->runId, 'barrierDir' => $barrier->directory(), 'payloadPath' => $payloadA],
            'worker-b' => ['runId' => $namespace->runId, 'barrierDir' => $barrier->directory(), 'payloadPath' => $payloadB],
        ],
        timeoutSeconds: 20.0,
        barrier: $barrier,
    );

    $barrier->cleanup();
    @unlink($payloadA);
    @unlink($payloadB);

    expect($results['worker-a'])->not->toBeNull();
    expect($results['worker-b'])->not->toBeNull();

    $reportA = WorkerReport::fromProcessOutput($results['worker-a']->output());
    $reportB = WorkerReport::fromProcessOutput($results['worker-b']->output());

    expect($reportA->mysqlConnectionId)->not->toBe($reportB->mysqlConnectionId);

    // Both succeed - no shared account, no possible lock contention.
    expect($reportA->outcome->value)->toBe('created');
    expect($reportB->outcome->value)->toBe('created');

    // Structural overlap proof, not a speed claim: neither worker's window
    // fully preceded the other's - both were genuinely in flight together,
    // consistent with neither having to wait on the other's unrelated
    // account row.
    expect($reportA->tBefore)->toBeLessThan($reportB->tAfter);
    expect($reportB->tBefore)->toBeLessThan($reportA->tAfter);

    expect(DB::connection('mysql_concurrency')->table('ledger_transactions')->where('id', $reportA->committedTransactionId)->exists())->toBeTrue();
    expect(DB::connection('mysql_concurrency')->table('ledger_transactions')->where('id', $reportB->committedTransactionId)->exists())->toBeTrue();
});
