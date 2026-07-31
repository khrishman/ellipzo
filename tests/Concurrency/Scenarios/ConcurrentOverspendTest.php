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
    $this->ensureConcurrencyEnvironmentReady(ScenarioRegistry::Overspend);
});

afterEach(function (): void {
    $remaining = (new ScenarioCleanup(DB::connection('mysql_concurrency'), $this->concurrencyNamespace))->run();
    $this->resetConcurrencyDefaultConnection();
    expect($remaining)->toBe([], 'Scenario B left owned rows behind after cleanup: '.implode(', ', $remaining));
});

test('exactly one of two individually-affordable-but-jointly-excessive reservations commits, balance never goes negative', function (): void {
    $namespace = $this->concurrencyNamespace;

    $user = User::factory()->create(['name' => $namespace->username(), 'email' => $namespace->username().'@concurrency.test']);
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($user);

    $clearing = (new WalletAccountProvisioner)->providerClearingAccount($namespace->idempotencyKey('clearing'));

    // Fund earning_available with $100.00 - each worker's own $60.00
    // reservation is individually affordable, but not both together.
    (new LedgerPostingEngine)->post(new PostLedgerTransactionCommand(
        LedgerTransactionType::DepositCredit,
        $namespace->businessReference('deposit_credit', 'fund'),
        (string) Str::ulid(),
        'Scenario B funding',
        null,
        null,
        null,
        [
            new PostLedgerEntryCommand($clearing->id, LedgerEntryType::Debit, Money::fromAtomic(100_000_000, Currency::USD)),
            new PostLedgerEntryCommand($accounts->earningAvailable->id, LedgerEntryType::Credit, Money::fromAtomic(100_000_000, Currency::USD)),
        ],
    ));

    $payloadA = tempnam(sys_get_temp_dir(), 'ccpayload');
    $payloadB = tempnam(sys_get_temp_dir(), 'ccpayload');
    file_put_contents($payloadA, json_encode([
        'earningAvailableAccountId' => $accounts->earningAvailable->id,
        'earningHeldAccountId' => $accounts->earningHeld->id,
        'amountAtomic' => 60_000_000,
        'businessReference' => $namespace->businessReference('fund_reservation', 'a'),
    ]));
    file_put_contents($payloadB, json_encode([
        'earningAvailableAccountId' => $accounts->earningAvailable->id,
        'earningHeldAccountId' => $accounts->earningHeld->id,
        'amountAtomic' => 60_000_000,
        'businessReference' => $namespace->businessReference('fund_reservation', 'b'),
    ]));

    $barrier = FileBarrier::forRun(storage_path('framework/testing/concurrency'), $namespace->runId, ScenarioRegistry::Overspend);

    $results = (new WorkerLauncher)->spawnAndWait(
        ScenarioRegistry::Overspend,
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

    // Overlap proof: neither worker's window fully preceded the other's -
    // both were genuinely in flight together, not sequential.
    expect($reportA->tBefore)->toBeLessThan($reportB->tAfter);
    expect($reportB->tBefore)->toBeLessThan($reportA->tAfter);

    $outcomes = collect([$reportA->outcome->value, $reportB->outcome->value])->sort()->values()->all();
    expect($outcomes)->toBe(['created', 'insufficient_balance']);

    // Independently re-derived balance and entry count - never trusting the
    // workers' own claims.
    $freshAccount = WalletAccount::query()->findOrFail($accounts->earningAvailable->id);
    $balance = (new LedgerBalanceReader)->currentBalance($freshAccount);
    expect($balance->balance->isNegative())->toBeFalse();
    expect($balance->balance->atomic())->toBe(40_000_000); // 100 - 60 (only the winner's reservation)

    $winnerBusinessReference = $reportA->outcome->value === 'created'
        ? $namespace->businessReference('fund_reservation', 'a')
        : $namespace->businessReference('fund_reservation', 'b');
    $loserBusinessReference = $reportA->outcome->value === 'created'
        ? $namespace->businessReference('fund_reservation', 'b')
        : $namespace->businessReference('fund_reservation', 'a');

    expect(DB::connection('mysql_concurrency')->table('ledger_transactions')->where('business_reference', $winnerBusinessReference)->exists())->toBeTrue();
    expect(DB::connection('mysql_concurrency')->table('ledger_transactions')->where('business_reference', $loserBusinessReference)->exists())->toBeFalse();

    $winnerTransactionId = DB::connection('mysql_concurrency')->table('ledger_transactions')->where('business_reference', $winnerBusinessReference)->value('id');
    expect(DB::connection('mysql_concurrency')->table('ledger_entries')->where('ledger_transaction_id', $winnerTransactionId)->count())->toBe(2);
});
