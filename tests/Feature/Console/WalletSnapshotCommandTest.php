<?php

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\PostLedgerEntryCommand;
use App\Domain\Wallet\Data\PostLedgerTransactionCommand;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\BalanceSnapshot;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Models\WalletAccount;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

test('zero accounts is a successful no-op', function () {
    $exitCode = Artisan::call('wallet:snapshot');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Mode: all');
    expect($output)->toContain('Inspected: 0');
    expect($output)->toContain('Snapshotted: 0');
    expect($output)->toContain('Failed: 0');
    expect(BalanceSnapshot::count())->toBe(0);
});

test('default mode snapshots every wallet account', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($userA);
    (new WalletAccountProvisioner)->provisionUserAccounts($userB);

    $exitCode = Artisan::call('wallet:snapshot');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Mode: all');
    expect($output)->toContain('Inspected: 8');
    expect($output)->toContain('Snapshotted: 8');
    expect($output)->toContain('Failed: 0');
    expect(BalanceSnapshot::count())->toBe(8);
});

test('--account filters to exactly one wallet account', function () {
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts(User::factory()->create());

    $exitCode = Artisan::call('wallet:snapshot', ['--account' => $accounts->earningAvailable->id]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Mode: single');
    expect($output)->toContain('Inspected: 1');
    expect($output)->toContain('Snapshotted: 1');
    expect(BalanceSnapshot::count())->toBe(1);
    expect(BalanceSnapshot::first()->wallet_account_id)->toBe($accounts->earningAvailable->id);
});

test('a malformed --account value is a uniform controlled failure', function () {
    $exitCode = Artisan::call('wallet:snapshot', ['--account' => 'not-a-real-ulid']);
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain('Failed: 1');
    expect(BalanceSnapshot::count())->toBe(0);
});

test('a syntactically valid but unknown --account ULID fails with the identical message shape as a malformed one', function () {
    Artisan::call('wallet:snapshot', ['--account' => 'not-a-real-ulid']);
    $malformedText = Artisan::output();

    $unknownUlid = strtolower((string) Str::ulid());
    Artisan::call('wallet:snapshot', ['--account' => $unknownUlid]);
    $unknownText = Artisan::output();

    expect($malformedText)->toBe($unknownText);
});

test('multiple runs append rather than replace', function () {
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts(User::factory()->create());

    Artisan::call('wallet:snapshot', ['--account' => $accounts->earningAvailable->id]);
    Artisan::call('wallet:snapshot', ['--account' => $accounts->earningAvailable->id]);

    expect(BalanceSnapshot::where('wallet_account_id', $accounts->earningAvailable->id)->count())->toBe(2);
});

test('the command never mutates ledger, wallet-account, reversal, audit, or user data', function () {
    $user = User::factory()->create();
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($user);
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('snapshot-command-no-mutation');

    (new LedgerPostingEngine)->post(new PostLedgerTransactionCommand(
        LedgerTransactionType::DepositCredit,
        'deposit_credit:snapshot-command-fund',
        (string) Str::uuid(),
        'Test posting',
        null,
        null,
        null,
        [
            new PostLedgerEntryCommand($clearing->id, LedgerEntryType::Debit, Money::fromAtomic(1000, Currency::USD)),
            new PostLedgerEntryCommand($accounts->earningAvailable->id, LedgerEntryType::Credit, Money::fromAtomic(1000, Currency::USD)),
        ],
    ));

    $walletAccountsBefore = WalletAccount::count();
    $ledgerTransactionsBefore = LedgerTransaction::count();
    $ledgerEntriesBefore = LedgerEntry::count();
    $auditEventsBefore = AuditEvent::count();
    $usersBefore = User::count();

    Artisan::call('wallet:snapshot');

    expect(WalletAccount::count())->toBe($walletAccountsBefore);
    expect(LedgerTransaction::count())->toBe($ledgerTransactionsBefore);
    expect(LedgerEntry::count())->toBe($ledgerEntriesBefore);
    expect(AuditEvent::count())->toBe($auditEventsBefore);
    expect(User::count())->toBe($usersBefore);
});

test('command output consists only of the four expected aggregate lines, proving no per-account data ever appears', function () {
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts(User::factory()->create());

    Artisan::call('wallet:snapshot', ['--account' => $accounts->earningAvailable->id]);
    $output = Artisan::output();

    $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), fn (string $line) => $line !== ''));

    foreach ($lines as $line) {
        expect($line)->toMatch('/^(Mode: (all|single)|Inspected: \d+|Snapshotted: \d+|Failed: \d+)$/');
    }

    expect($lines)->toHaveCount(4);
});
