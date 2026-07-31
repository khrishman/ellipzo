<?php

declare(strict_types=1);

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\PostLedgerEntryCommand;
use App\Domain\Wallet\Data\PostLedgerTransactionCommand;
use App\Domain\Wallet\Data\RequestLedgerReversalCommand;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\ReversalRequestService;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concurrency\Concerns\AssertsConcurrencyEnvironment;
use Tests\Concurrency\Support\FileBarrier;
use Tests\Concurrency\Support\ScenarioCleanup;
use Tests\Concurrency\Support\Scenarios\ScenarioRegistry;
use Tests\Concurrency\Support\WorkerLauncher;
use Tests\Concurrency\Support\WorkerReport;
use Tests\TestCase;

uses(TestCase::class, AssertsConcurrencyEnvironment::class);

beforeEach(function (): void {
    $this->ensureConcurrencyEnvironmentReady(ScenarioRegistry::ReversalExecution);
});

afterEach(function (): void {
    $remaining = (new ScenarioCleanup(DB::connection('mysql_concurrency'), $this->concurrencyNamespace))->run();
    $this->resetConcurrencyDefaultConnection();
    expect($remaining)->toBe([], 'Scenario G left owned rows behind: '.implode(', ', $remaining));
});

test('two workers executing the same pending reversal request converge on exactly one applied reversal', function (): void {
    $namespace = $this->concurrencyNamespace;

    $user = User::factory()->create(['name' => $namespace->username(), 'email' => $namespace->username().'@concurrency.test']);
    $actor = User::factory()->create(['name' => $namespace->username().'act', 'email' => $namespace->username().'act@concurrency.test']);
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($user);
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount($namespace->idempotencyKey('clearing'));
    $engine = new LedgerPostingEngine;

    $original = $engine->post(new PostLedgerTransactionCommand(
        LedgerTransactionType::DepositCredit,
        $namespace->businessReference('deposit_credit', 'original'),
        (string) Str::ulid(),
        'Scenario G original transaction',
        null,
        null,
        null,
        [
            new PostLedgerEntryCommand($clearing->id, LedgerEntryType::Debit, Money::fromAtomic(15_000_000, Currency::USD)),
            new PostLedgerEntryCommand($accounts->earningAvailable->id, LedgerEntryType::Credit, Money::fromAtomic(15_000_000, Currency::USD)),
        ],
    ))->transaction;

    $reversalRequest = (new ReversalRequestService($engine))->request(new RequestLedgerReversalCommand(
        $original->id,
        $namespace->idempotencyKey('req'),
        (string) Str::ulid(),
        'Scenario G reversal request',
        $actor,
    ));

    $payloadPath = tempnam(sys_get_temp_dir(), 'ccpayload');
    file_put_contents($payloadPath, json_encode(['reversalRequestId' => $reversalRequest->id]));

    $barrier = FileBarrier::forRun(storage_path('framework/testing/concurrency'), $namespace->runId, ScenarioRegistry::ReversalExecution);

    $results = (new WorkerLauncher)->spawnAndWait(
        ScenarioRegistry::ReversalExecution,
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

    // Both callers converge on the same applied result.
    expect($reportA->committedTransactionId)->toBe($reportB->committedTransactionId);
    expect($reportA->extra['status'])->toBe('applied');
    expect($reportB->extra['status'])->toBe('applied');

    // Lock-serialization evidence, mirroring scenario F.
    $winnerReport = $reportA->outcome->value === 'created' ? $reportA : $reportB;
    $loserReport = $reportA->outcome->value === 'created' ? $reportB : $reportA;
    expect($loserReport->tAfter)->toBeGreaterThanOrEqual($winnerReport->tAfter);

    // Exactly one reversal transaction, one inverted entry set.
    expect(DB::connection('mysql_concurrency')->table('ledger_transactions')->where('reverses_transaction_id', $original->id)->count())->toBe(1);
    expect(DB::connection('mysql_concurrency')->table('ledger_entries')->where('ledger_transaction_id', $reportA->committedTransactionId)->count())->toBe(2);

    // The request ends applied, exactly once.
    $finalRequest = DB::connection('mysql_concurrency')->table('reversal_requests')->where('id', $reversalRequest->id)->first();
    expect($finalRequest->status)->toBe('applied');
    expect($finalRequest->reversal_transaction_id)->toBe($reportA->committedTransactionId);

    // The original transaction is unchanged.
    $originalStillIntact = DB::connection('mysql_concurrency')->table('ledger_transactions')->where('id', $original->id)->first();
    expect($originalStillIntact->reverses_transaction_id)->toBeNull();
    expect(DB::connection('mysql_concurrency')->table('ledger_entries')->where('ledger_transaction_id', $original->id)->count())->toBe(2);
});
