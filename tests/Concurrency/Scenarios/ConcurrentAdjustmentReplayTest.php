<?php

declare(strict_types=1);

use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
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
    $this->ensureConcurrencyEnvironmentReady(ScenarioRegistry::AdjustmentReplay);
    (new RolePermissionSeeder)->run();
});

afterEach(function (): void {
    $remaining = (new ScenarioCleanup(DB::connection('mysql_concurrency'), $this->concurrencyNamespace))->run();
    $this->resetConcurrencyDefaultConnection();
    expect($remaining)->toBe([], 'Scenario H left owned rows behind: '.implode(', ', $remaining));
});

test('two workers submitting the identical administrative adjustment converge on exactly one transaction and one audit event', function (): void {
    $namespace = $this->concurrencyNamespace;

    $actor = User::factory()->create(['name' => $namespace->username().'act', 'email' => $namespace->username().'act@concurrency.test']);
    $actor->assignRole('finance-operator');
    $targetUser = User::factory()->create(['name' => $namespace->username(), 'email' => $namespace->username().'@concurrency.test']);
    (new WalletAccountProvisioner)->provisionUserAccounts($targetUser);

    $idempotencyKey = $namespace->idempotencyKey('adj');
    $payload = json_encode([
        'actorId' => $actor->id,
        'targetUserId' => $targetUser->id,
        'targetAccountType' => 'earning_available',
        'direction' => 'increase',
        'amountAtomic' => 5_000_000,
        'internalReason' => 'Scenario H concurrent identical adjustment replay test',
        'idempotencyKey' => $idempotencyKey,
    ]);
    $payloadPath = tempnam(sys_get_temp_dir(), 'ccpayload');
    file_put_contents($payloadPath, $payload);

    $barrier = FileBarrier::forRun(storage_path('framework/testing/concurrency'), $namespace->runId, ScenarioRegistry::AdjustmentReplay);

    $results = (new WorkerLauncher)->spawnAndWait(
        ScenarioRegistry::AdjustmentReplay,
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

    // Both calls converge on the same committed transaction.
    expect($reportA->committedTransactionId)->toBe($reportB->committedTransactionId);

    $businessReference = 'administrative_adjustment:'.$idempotencyKey;

    // Exactly one ledger transaction, one two-entry journal.
    expect(DB::connection('mysql_concurrency')->table('ledger_transactions')->where('business_reference', $businessReference)->count())->toBe(1);
    expect(DB::connection('mysql_concurrency')->table('ledger_entries')->where('ledger_transaction_id', $reportA->committedTransactionId)->count())->toBe(2);

    // Exactly one matching audit event - both workers report the same ID.
    expect($reportA->extra['auditEventId'])->toBe($reportB->extra['auditEventId']);
    expect(DB::connection('mysql_concurrency')->table('audit_events')
        ->where('entity_type', 'ledger_transaction')
        ->where('entity_key', $reportA->committedTransactionId)
        ->where('action', 'ledger.administrative_adjustment')
        ->count())->toBe(1);

    // platform_suspense provisioned exactly once (a shared singleton
    // across the whole suite, not scenario-namespaced - existence proven,
    // never asserted as newly created by this scenario alone).
    expect(DB::connection('mysql_concurrency')->table('wallet_accounts')
        ->where('scope_type', 'platform')
        ->where('account_type', 'platform_suspense')
        ->count())->toBe(1);
});
