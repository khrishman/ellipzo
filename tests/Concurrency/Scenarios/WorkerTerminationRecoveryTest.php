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
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Tests\Concurrency\Concerns\AssertsConcurrencyEnvironment;
use Tests\Concurrency\Support\FileBarrier;
use Tests\Concurrency\Support\ScenarioCleanup;
use Tests\Concurrency\Support\Scenarios\ScenarioRegistry;
use Tests\TestCase;

uses(TestCase::class, AssertsConcurrencyEnvironment::class);

beforeEach(function (): void {
    $this->ensureConcurrencyEnvironmentReady(ScenarioRegistry::WorkerTermination);
});

afterEach(function (): void {
    $remaining = (new ScenarioCleanup(DB::connection('mysql_concurrency'), $this->concurrencyNamespace))->run();
    $this->resetConcurrencyDefaultConnection();
    expect($remaining)->toBe([], 'Worker-termination test left owned rows behind: '.implode(', ', $remaining));
});

test('killing a worker mid-transaction releases its row lock cleanly, with no orphan rows', function (): void {
    $namespace = $this->concurrencyNamespace;

    $user = User::factory()->create(['name' => $namespace->username(), 'email' => $namespace->username().'@concurrency.test']);
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($user);
    $accountId = $accounts->earningAvailable->id;

    $payloadPath = tempnam(sys_get_temp_dir(), 'ccpayload');
    $barrier = FileBarrier::forRun(storage_path('framework/testing/concurrency'), $namespace->runId, ScenarioRegistry::WorkerTermination);
    file_put_contents($payloadPath, json_encode([
        'walletAccountId' => $accountId,
        'barrierDir' => $barrier->directory(),
    ]));

    $phpBinary = (new PhpExecutableFinder)->find();
    expect($phpBinary)->not->toBeFalse();

    $invoked = Process::path(base_path())
        ->timeout(60)
        ->env(['DB_CONNECTION' => 'mysql_concurrency'])
        ->start([
            $phpBinary,
            base_path('tests/Concurrency/bin/concurrency-worker.php'),
            ScenarioRegistry::WorkerTermination,
            'worker-a',
            $namespace->runId,
            $barrier->directory(),
            '--payload='.$payloadPath,
        ]);

    // Standard ready/go handshake first.
    $barrier->waitForAllReady(['worker-a']);
    $barrier->release();

    // Then wait for the worker's own second signal, sent only once it
    // genuinely holds the row lock inside its open transaction - never
    // "the process merely started".
    $barrier->waitForAllReady(['lock-acquired']);

    expect($invoked->running())->toBeTrue('The worker should still be running (deliberately sleeping) at this point.');

    // Force-terminate it now, mid-transaction, exactly as a stuck worker
    // would be handled for real.
    $invoked->stop(5);

    // Give the OS a brief, bounded moment to finish tearing down the
    // process and for MySQL to observe the dropped connection.
    $deadline = microtime(true) + 5.0;
    while ($invoked->running() && microtime(true) < $deadline) {
        usleep(50_000);
    }

    expect($invoked->running())->toBeFalse('The worker process should be confirmed dead after stop().');

    $barrier->cleanup();
    @unlink($payloadPath);

    // No orphan or partial rows: this scenario never writes anything to
    // ledger_transactions/ledger_entries at all (WorkerTerminationScenario
    // only ever takes a plain row lock), so both must still be exactly
    // what they were before this test - scoped to this test's own account
    // to avoid any dependency on suite-wide state.
    expect(DB::connection('mysql_concurrency')->table('ledger_entries')->where('wallet_account_id', $accountId)->count())->toBe(0);

    // The real proof the lock was genuinely released: a completely normal
    // posting against the very same account, run immediately afterward
    // from the coordinator's own connection, must succeed without
    // hanging or timing out.
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount($namespace->idempotencyKey('clearing'));
    $posted = (new LedgerPostingEngine)->post(new PostLedgerTransactionCommand(
        LedgerTransactionType::DepositCredit,
        $namespace->businessReference('deposit_credit', 'post-kill-recovery'),
        (string) Str::ulid(),
        'Post-kill lock-recovery proof',
        null,
        null,
        null,
        [
            new PostLedgerEntryCommand($clearing->id, LedgerEntryType::Debit, Money::fromAtomic(1_000_000, Currency::USD)),
            new PostLedgerEntryCommand($accountId, LedgerEntryType::Credit, Money::fromAtomic(1_000_000, Currency::USD)),
        ],
    ));

    expect($posted->transaction->id)->not->toBeNull();
    // Exactly one of the two entries credits the target account; the other
    // (the debit) belongs to the clearing account, not this one.
    expect(DB::connection('mysql_concurrency')->table('ledger_entries')->where('wallet_account_id', $accountId)->count())->toBe(1);
    expect(DB::connection('mysql_concurrency')->table('ledger_entries')->where('ledger_transaction_id', $posted->transaction->id)->count())->toBe(2);
});
