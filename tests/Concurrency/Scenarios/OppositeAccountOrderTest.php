<?php

declare(strict_types=1);

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\PostLedgerEntryCommand;
use App\Domain\Wallet\Data\PostLedgerTransactionCommand;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\WalletAccount;
use App\Domain\Wallet\Services\LedgerBalanceReader;
use App\Domain\Wallet\Services\LedgerPostingEngine;
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
    $this->ensureConcurrencyEnvironmentReady(ScenarioRegistry::AccountOrder);
});

afterEach(function (): void {
    $remaining = (new ScenarioCleanup(DB::connection('mysql_concurrency'), $this->concurrencyNamespace))->run();
    $this->resetConcurrencyDefaultConnection();
    expect($remaining)->toBe([], 'Scenario E left owned rows behind: '.implode(', ', $remaining));
});

test('two transactions touching the same two accounts in opposite entry order never deadlock and both commit', function (): void {
    $namespace = $this->concurrencyNamespace;

    $user = User::factory()->create(['name' => $namespace->username(), 'email' => $namespace->username().'@concurrency.test']);
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($user);
    $engine = new LedgerPostingEngine;

    // Fund both accounts generously via ordinary, uncontested postings
    // first (not the contested operation itself).
    $funding = (new WalletAccountProvisioner)->providerClearingAccount($namespace->idempotencyKey('clearing'));
    foreach (['earning' => $accounts->earningAvailable, 'advertising' => $accounts->advertisingAvailable] as $key => $account) {
        $engine->post(new PostLedgerTransactionCommand(
            LedgerTransactionType::DepositCredit,
            $namespace->businessReference('deposit_credit', "fund-{$key}"),
            (string) Str::ulid(),
            'Scenario E funding',
            null,
            null,
            null,
            [
                new PostLedgerEntryCommand($funding->id, LedgerEntryType::Debit, Money::fromAtomic(200_000_000, Currency::USD)),
                new PostLedgerEntryCommand($account->id, LedgerEntryType::Credit, Money::fromAtomic(200_000_000, Currency::USD)),
            ],
        ));
    }

    // Determine canonical (sorted) order once, in the coordinator - worker
    // A is assigned "debit the lexically-first account, credit the
    // second"; worker B is assigned the exact opposite direction, so its
    // PostLedgerEntryCommand array is supplied in the reverse array order
    // relative to worker A's, while LedgerPostingEngine::lockAccountsInOrder()
    // internally re-sorts both to the identical canonical order regardless.
    [$firstId, $secondId] = collect([$accounts->earningAvailable->id, $accounts->advertisingAvailable->id])->sort()->values()->all();

    $payloadA = tempnam(sys_get_temp_dir(), 'ccpayload');
    $payloadB = tempnam(sys_get_temp_dir(), 'ccpayload');
    file_put_contents($payloadA, json_encode([
        'debitAccountId' => $firstId,
        'creditAccountId' => $secondId,
        'amountAtomic' => 50_000_000,
        'businessReference' => $namespace->businessReference('deposit_credit', 'a'),
    ]));
    file_put_contents($payloadB, json_encode([
        'debitAccountId' => $secondId,
        'creditAccountId' => $firstId,
        'amountAtomic' => 30_000_000,
        'businessReference' => $namespace->businessReference('deposit_credit', 'b'),
    ]));

    $barrier = FileBarrier::forRun(storage_path('framework/testing/concurrency'), $namespace->runId, ScenarioRegistry::AccountOrder);

    $results = (new WorkerLauncher)->spawnAndWait(
        ScenarioRegistry::AccountOrder,
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

    // No deadlock, no lock timeout - both genuinely committed.
    expect($reportA->outcome->value)->toBe('created');
    expect($reportB->outcome->value)->toBe('created');

    // Deterministic combined final balances, independently re-derived.
    $firstAccount = WalletAccount::query()->findOrFail($firstId);
    $secondAccount = WalletAccount::query()->findOrFail($secondId);
    $reader = new LedgerBalanceReader;

    // first: +200 funded, -50 (A debits first), +30 (B credits first) = 180
    expect($reader->currentBalance($firstAccount)->balance->atomic())->toBe(180_000_000);
    // second: +200 funded, +50 (A credits second), -30 (B debits second) = 220
    expect($reader->currentBalance($secondAccount)->balance->atomic())->toBe(220_000_000);
});
