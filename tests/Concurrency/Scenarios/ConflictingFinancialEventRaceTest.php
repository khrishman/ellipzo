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
    $this->ensureConcurrencyEnvironmentReady(ScenarioRegistry::ConflictingFinancialEvent);
});

afterEach(function (): void {
    $remaining = (new ScenarioCleanup(DB::connection('mysql_concurrency'), $this->concurrencyNamespace))->run();
    $this->resetConcurrencyDefaultConnection();
    expect($remaining)->toBe([], 'Scenario D left owned rows behind: '.implode(', ', $remaining));
});

test('two workers posting genuinely different payloads under the same business reference: one commits, the other is rejected as conflicting', function (): void {
    $namespace = $this->concurrencyNamespace;

    $user = User::factory()->create(['name' => $namespace->username(), 'email' => $namespace->username().'@concurrency.test']);
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($user);
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount($namespace->idempotencyKey('clearing'));

    $businessReference = $namespace->businessReference('deposit_credit', 'race');
    $payload = json_encode([
        'clearingAccountId' => $clearing->id,
        'earningAvailableAccountId' => $accounts->earningAvailable->id,
        'amountAtomic_worker-a' => 25_000_000,
        'amountAtomic_worker-b' => 40_000_000, // deliberately different amount
        'businessReference' => $businessReference,
    ]);
    $payloadPath = tempnam(sys_get_temp_dir(), 'ccpayload');
    file_put_contents($payloadPath, $payload);

    $barrier = FileBarrier::forRun(storage_path('framework/testing/concurrency'), $namespace->runId, ScenarioRegistry::ConflictingFinancialEvent);

    $results = (new WorkerLauncher)->spawnAndWait(
        ScenarioRegistry::ConflictingFinancialEvent,
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

    // The winner is not predetermined - either worker may legitimately win.
    $outcomes = collect([$reportA->outcome->value, $reportB->outcome->value])->sort()->values()->all();
    expect($outcomes)->toBe(['created', 'duplicate_event']);

    // Exactly one transaction exists under this business reference, and it
    // is internally consistent (its own two entries balance and belong to
    // exactly one of the two candidate amounts).
    expect(DB::connection('mysql_concurrency')->table('ledger_transactions')->where('business_reference', $businessReference)->count())->toBe(1);

    $transactionId = DB::connection('mysql_concurrency')->table('ledger_transactions')->where('business_reference', $businessReference)->value('id');
    $entries = DB::connection('mysql_concurrency')->table('ledger_entries')->where('ledger_transaction_id', $transactionId)->get();
    expect($entries)->toHaveCount(2);
    expect($entries->pluck('amount_atomic')->unique()->count())->toBe(1);
    expect((int) $entries->first()->amount_atomic)->toBeIn([25_000_000, 40_000_000]);

    // The loser created zero rows of its own.
    $winnerAmount = (int) $entries->first()->amount_atomic;
    $loserAmount = $winnerAmount === 25_000_000 ? 40_000_000 : 25_000_000;
    expect(DB::connection('mysql_concurrency')->table('ledger_entries')->where('amount_atomic', $loserAmount)->count())->toBe(0);
});
