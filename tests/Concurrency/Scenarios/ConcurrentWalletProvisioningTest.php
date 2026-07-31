<?php

declare(strict_types=1);

use App\Domain\Wallet\Enums\WalletAccountType;
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
    $this->ensureConcurrencyEnvironmentReady(ScenarioRegistry::WalletProvisioning);
});

afterEach(function (): void {
    $remaining = (new ScenarioCleanup(DB::connection('mysql_concurrency'), $this->concurrencyNamespace))->run();
    $this->resetConcurrencyDefaultConnection();
    expect($remaining)->toBe([], 'Scenario A left owned rows behind after cleanup: '.implode(', ', $remaining));
});

test('two workers provisioning the same new user concurrently converge on exactly one canonical four-account set', function (): void {
    $namespace = $this->concurrencyNamespace;

    $user = User::factory()->create([
        'name' => $namespace->username(),
        'email' => $namespace->username().'@concurrency.test',
    ]);

    $walletAccountsBefore = DB::connection('mysql_concurrency')->table('wallet_accounts')->count();
    $ledgerTransactionsBefore = DB::connection('mysql_concurrency')->table('ledger_transactions')->count();
    $ledgerEntriesBefore = DB::connection('mysql_concurrency')->table('ledger_entries')->count();

    $payloadPath = tempnam(sys_get_temp_dir(), 'ccpayload');
    file_put_contents($payloadPath, json_encode(['userId' => $user->id]));

    $barrierBaseDir = storage_path('framework/testing/concurrency');

    $barrier = FileBarrier::forRun($barrierBaseDir, $namespace->runId, ScenarioRegistry::WalletProvisioning);

    $results = (new WorkerLauncher)->spawnAndWait(
        ScenarioRegistry::WalletProvisioning,
        [
            'worker-a' => ['runId' => $namespace->runId, 'barrierDir' => $barrier->directory(), 'payloadPath' => $payloadPath],
            'worker-b' => ['runId' => $namespace->runId, 'barrierDir' => $barrier->directory(), 'payloadPath' => $payloadPath],
        ],
        timeoutSeconds: 20.0,
        barrier: $barrier,
    );

    $barrier->cleanup();
    @unlink($payloadPath);

    expect($results['worker-a'])->not->toBeNull('Worker A did not complete in time.');
    expect($results['worker-b'])->not->toBeNull('Worker B did not complete in time.');

    $reportA = WorkerReport::fromProcessOutput($results['worker-a']->output());
    $reportB = WorkerReport::fromProcessOutput($results['worker-b']->output());

    // Distinct process and connection identity - the direct, not inferred, proof.
    expect($reportA->pid)->not->toBe($reportB->pid);
    expect($reportA->mysqlConnectionId)->not->toBe($reportB->mysqlConnectionId);
    expect($reportA->mysqlConnectionId)->toBeGreaterThan(0);
    expect($reportB->mysqlConnectionId)->toBeGreaterThan(0);

    // Both workers succeeded and converged on the identical account set.
    expect($reportA->outcome->value)->toBe('created');
    expect($reportB->outcome->value)->toBe('created');
    expect($reportA->extra['accountIds'])->toBe($reportB->extra['accountIds']);

    // Independently re-derived from the database, not from the workers' own claims.
    $accounts = DB::connection('mysql_concurrency')->table('wallet_accounts')->where('user_id', $user->id)->get();
    expect($accounts)->toHaveCount(4);
    expect($accounts->pluck('account_type')->sort()->values()->all())->toBe(
        collect([
            WalletAccountType::EarningAvailable->value,
            WalletAccountType::EarningHeld->value,
            WalletAccountType::AdvertisingAvailable->value,
            WalletAccountType::AdvertisingReserved->value,
        ])->sort()->values()->all(),
    );
    expect($accounts->pluck('id')->unique())->toHaveCount(4);

    // No partial account set, and no ledger row of any kind was created.
    $walletAccountsAfter = DB::connection('mysql_concurrency')->table('wallet_accounts')->count();
    expect($walletAccountsAfter)->toBe($walletAccountsBefore + 4);
    expect(DB::connection('mysql_concurrency')->table('ledger_transactions')->count())->toBe($ledgerTransactionsBefore);
    expect(DB::connection('mysql_concurrency')->table('ledger_entries')->count())->toBe($ledgerEntriesBefore);
});
