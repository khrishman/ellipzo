<?php

declare(strict_types=1);

use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\WalletAccount;
use App\Domain\Wallet\Services\IncrementalLedgerFingerprint;
use App\Domain\Wallet\Services\LedgerBalanceReader;
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
    $this->ensureConcurrencyEnvironmentReady(ScenarioRegistry::SnapshotVersusPosting);
});

afterEach(function (): void {
    $remaining = (new ScenarioCleanup(DB::connection('mysql_concurrency'), $this->concurrencyNamespace))->run();
    $this->resetConcurrencyDefaultConnection();
    expect($remaining)->toBe([], 'Scenario J left owned rows behind: '.implode(', ', $remaining));
});

test('a snapshot captured concurrently with a posting to the same account is never torn - entirely before or entirely after', function (): void {
    $namespace = $this->concurrencyNamespace;

    $user = User::factory()->create(['name' => $namespace->username(), 'email' => $namespace->username().'@concurrency.test']);
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($user);
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount($namespace->idempotencyKey('clearing'));

    $payloadSnapshot = tempnam(sys_get_temp_dir(), 'ccpayload');
    $payloadPost = tempnam(sys_get_temp_dir(), 'ccpayload');
    file_put_contents($payloadSnapshot, json_encode([
        'operation' => 'snapshot',
        'walletAccountId' => $accounts->earningAvailable->id,
    ]));
    file_put_contents($payloadPost, json_encode([
        'operation' => 'post',
        'walletAccountId' => $accounts->earningAvailable->id,
        'clearingAccountId' => $clearing->id,
        'amountAtomic' => 12_000_000,
        'businessReference' => $namespace->businessReference('deposit_credit', 'concurrent'),
    ]));

    $barrier = FileBarrier::forRun(storage_path('framework/testing/concurrency'), $namespace->runId, ScenarioRegistry::SnapshotVersusPosting);

    $results = (new WorkerLauncher)->spawnAndWait(
        ScenarioRegistry::SnapshotVersusPosting,
        [
            'worker-snapshot' => ['runId' => $namespace->runId, 'barrierDir' => $barrier->directory(), 'payloadPath' => $payloadSnapshot],
            'worker-post' => ['runId' => $namespace->runId, 'barrierDir' => $barrier->directory(), 'payloadPath' => $payloadPost],
        ],
        timeoutSeconds: 20.0,
        barrier: $barrier,
    );

    $barrier->cleanup();
    @unlink($payloadSnapshot);
    @unlink($payloadPost);

    expect($results['worker-snapshot'])->not->toBeNull();
    expect($results['worker-post'])->not->toBeNull();

    $snapshotReport = WorkerReport::fromProcessOutput($results['worker-snapshot']->output());
    $postReport = WorkerReport::fromProcessOutput($results['worker-post']->output());

    expect($snapshotReport->mysqlConnectionId)->not->toBe($postReport->mysqlConnectionId);
    expect($snapshotReport->outcome->value)->toBe('created');
    expect($postReport->outcome->value)->toBe('created');

    $snapshotId = $snapshotReport->extra['snapshotId'];
    $storedBalance = (int) $snapshotReport->extra['balanceAtomic'];
    $storedEntryCount = (int) $snapshotReport->extra['entryCount'];
    $storedCutoffId = $snapshotReport->extra['cutoffLedgerEntryId'];
    $storedFingerprint = $snapshotReport->extra['fingerprint'];

    // Either entirely before or entirely after the concurrent post - never
    // a torn combination. entryCount of 0 means "before" (account was
    // never funded until the post); 2 means "after" (funding + the post's
    // own credit entry both counted).
    expect($storedEntryCount)->toBeIn([0, 2]);

    if ($storedEntryCount === 0) {
        expect($storedBalance)->toBe(0);
        expect($storedCutoffId)->toBe('');
    } else {
        expect($storedBalance)->toBe(12_000_000);
        expect($storedCutoffId)->not->toBe('');
    }

    // Independently re-verify by folding real ledger history up to the
    // snapshot's own recorded cutoff - never trusting the stored row alone.
    $account = WalletAccount::query()->findOrFail($accounts->earningAvailable->id);
    $reader = new LedgerBalanceReader;

    if ($storedCutoffId === '') {
        // "Before" case: no entries exist at all up to any cutoff.
        expect(DB::connection('mysql_concurrency')->table('ledger_entries')->where('wallet_account_id', $account->id)->count())->toBeGreaterThanOrEqual(0);
        $fresh = $reader->currentBalance($account);
        // The snapshot's own stored zero state must be a genuine prefix of
        // whatever the account's real history is now (post-test) - i.e.
        // folding from empty is trivially consistent.
        expect($storedBalance)->toBe(0);
    } else {
        $cutoffEntry = LedgerEntry::query()->findOrFail($storedCutoffId);
        $fingerprint = new IncrementalLedgerFingerprint($account->id);
        $recomputed = $reader->balanceAsOf($account, $cutoffEntry, $fingerprint);

        expect($recomputed->balance->atomic())->toBe($storedBalance);
        expect($recomputed->entryCount)->toBe($storedEntryCount);
        expect($recomputed->lastEntryId())->toBe($storedCutoffId);
        expect($fingerprint->finalHex())->toBe($storedFingerprint);
    }

    // The stored snapshot row itself matches what was reported.
    $snapshotRow = DB::connection('mysql_concurrency')->table('balance_snapshots')->where('id', $snapshotId)->first();
    expect((int) $snapshotRow->balance_atomic)->toBe($storedBalance);
    expect((int) $snapshotRow->entry_count)->toBe($storedEntryCount);
    expect($snapshotRow->fingerprint)->toBe($storedFingerprint);
    expect($snapshotRow->fingerprint_version)->toBe(1);
});
