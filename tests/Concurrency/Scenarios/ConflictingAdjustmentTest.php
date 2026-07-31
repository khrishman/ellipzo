<?php

declare(strict_types=1);

use App\Domain\Wallet\Models\WalletAccount;
use App\Domain\Wallet\Services\LedgerBalanceReader;
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
    $this->ensureConcurrencyEnvironmentReady(ScenarioRegistry::AdjustmentConflict);
    (new RolePermissionSeeder)->run();
});

afterEach(function (): void {
    $remaining = (new ScenarioCleanup(DB::connection('mysql_concurrency'), $this->concurrencyNamespace))->run();
    $this->resetConcurrencyDefaultConnection();
    expect($remaining)->toBe([], 'Scenario H (conflicting-payload sub-case) left owned rows behind: '.implode(', ', $remaining));
});

/**
 * Scenario H, conflicting-payload sub-case - see ConflictingAdjustmentScenario's
 * own docblock for the full distinction from the dropped, artificial
 * "Scenario I" (a ULID-pre-collision race no independent process could
 * ever construct honestly). The stable, caller-controlled contested
 * identity here is SubmitAdministrativeAdjustmentCommand::businessReference()
 * (the idempotency key, not the transaction's own generated ULID).
 */
test('two workers submitting the same idempotency key with a genuinely different payload: at most one commits, the loser fails safely', function (): void {
    $namespace = $this->concurrencyNamespace;

    $actor = User::factory()->create(['name' => $namespace->username().'act', 'email' => $namespace->username().'act@concurrency.test']);
    $actor->assignRole('finance-operator');
    $targetUser = User::factory()->create(['name' => $namespace->username(), 'email' => $namespace->username().'@concurrency.test']);
    (new WalletAccountProvisioner)->provisionUserAccounts($targetUser);

    $idempotencyKey = $namespace->idempotencyKey('adj');
    $businessReference = 'administrative_adjustment:'.$idempotencyKey;

    $basePayload = [
        'actorId' => $actor->id,
        'targetUserId' => $targetUser->id,
        'targetAccountType' => 'earning_available',
        'idempotencyKey' => $idempotencyKey,
    ];

    // Both workers always increase (never decrease against an unfunded
    // account, which would fail balance validation for an unrelated
    // reason before the contested business_reference is ever reached) -
    // the conflicting payload is a genuinely different amount.
    $sharedPayload = [
        ...$basePayload,
        'amountAtomic_worker-a' => 5_000_000,
        'amountAtomic_worker-b' => 8_000_000,
        'internalReason_worker-a' => 'Scenario H (conflict) worker A reason',
        'internalReason_worker-b' => 'Scenario H (conflict) worker B reason, deliberately different',
    ];

    $payloadA = tempnam(sys_get_temp_dir(), 'ccpayload');
    $payloadB = tempnam(sys_get_temp_dir(), 'ccpayload');
    file_put_contents($payloadA, json_encode($sharedPayload));
    file_put_contents($payloadB, json_encode($sharedPayload));

    $barrier = FileBarrier::forRun(storage_path('framework/testing/concurrency'), $namespace->runId, ScenarioRegistry::AdjustmentConflict);

    $results = (new WorkerLauncher)->spawnAndWait(
        ScenarioRegistry::AdjustmentConflict,
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

    // The winner is not predetermined.
    $outcomes = collect([$reportA->outcome->value, $reportB->outcome->value])->sort()->values()->all();
    expect($outcomes)->toBe(['created', 'duplicate_event']);

    // Exactly one ledger transaction under this business reference.
    expect(DB::connection('mysql_concurrency')->table('ledger_transactions')->where('business_reference', $businessReference)->count())->toBe(1);

    $transactionId = DB::connection('mysql_concurrency')->table('ledger_transactions')->where('business_reference', $businessReference)->value('id');
    expect(DB::connection('mysql_concurrency')->table('ledger_entries')->where('ledger_transaction_id', $transactionId)->count())->toBe(2);

    // Exactly one matching audit event - no orphan audit event for the loser.
    expect(DB::connection('mysql_concurrency')->table('audit_events')
        ->where('entity_type', 'ledger_transaction')
        ->where('action', 'ledger.administrative_adjustment')
        ->where('entity_key', $transactionId)
        ->count())->toBe(1);
    expect(DB::connection('mysql_concurrency')->table('audit_events')
        ->where('entity_type', 'ledger_transaction')
        ->where('action', 'ledger.administrative_adjustment')
        ->count())->toBe(1);

    // No orphan suspense account beyond the one legitimate shared singleton.
    expect(DB::connection('mysql_concurrency')->table('wallet_accounts')
        ->where('scope_type', 'platform')
        ->where('account_type', 'platform_suspense')
        ->count())->toBe(1);

    // One balance effect: the target account's independently re-derived
    // balance equals exactly the winner's own amount - never the loser's,
    // and never both summed (which would prove a double-apply defect).
    $winnerAmountAtomic = $reportA->outcome->value === 'created'
        ? $sharedPayload['amountAtomic_worker-a']
        : $sharedPayload['amountAtomic_worker-b'];
    $targetAccountId = DB::connection('mysql_concurrency')->table('wallet_accounts')
        ->where('user_id', $targetUser->id)
        ->where('account_type', 'earning_available')
        ->value('id');
    $freshAccount = WalletAccount::query()->findOrFail($targetAccountId);
    $balance = (new LedgerBalanceReader)->currentBalance($freshAccount);
    expect($balance->balance->atomic())->toBe($winnerAmountAtomic);
});
