<?php

use App\Domain\Shared\Exceptions\MoneyOverflowException;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Exceptions\UnknownWalletAccountException;
use App\Domain\Wallet\Models\BalanceSnapshot;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Services\BalanceSnapshotService;
use App\Domain\Wallet\Services\BalanceSnapshotWriteContext;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

test('capturing a never-funded account produces a zero-balance, zero-cutoff snapshot', function () {
    $accounts = $this->provisionTestAccounts();

    $snapshot = (new BalanceSnapshotService)->captureForAccount($accounts->earningAvailable);

    expect($snapshot->balance_atomic)->toBe(0);
    expect($snapshot->entry_count)->toBe(0);
    expect($snapshot->cutoff_ledger_entry_id)->toBeNull();
    expect($snapshot->cutoff_entry_created_at)->toBeNull();
    expect($snapshot->fingerprint)->toMatch('/^[0-9a-f]{64}$/');
});

test('capturing a funded account produces a balance, count, cutoff, and fingerprint all from the same entry stream', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('snapshot-service-funded');
    $engine = new LedgerPostingEngine;

    $result = $engine->post($this->postingCommand(businessReference: 'deposit_credit:snapshot-service-1', entries: [
        $this->debitEntry($clearing->id, 700_000),
        $this->creditEntry($accounts->earningAvailable->id, 700_000),
    ]));

    $expectedCutoff = LedgerEntry::where('ledger_transaction_id', $result->transaction->id)
        ->where('wallet_account_id', $accounts->earningAvailable->id)
        ->firstOrFail();

    $snapshot = (new BalanceSnapshotService)->captureForAccount($accounts->earningAvailable);

    expect($snapshot->balance_atomic)->toBe(700_000);
    expect($snapshot->entry_count)->toBe(1);
    expect($snapshot->cutoff_ledger_entry_id)->toBe($expectedCutoff->id);
    expect($snapshot->cutoff_entry_created_at->equalTo($expectedCutoff->created_at))->toBeTrue();
});

test('an unknown wallet account is rejected without writing anything', function () {
    $account = $this->provisionTestAccounts()->earningAvailable;
    $fakeAccount = clone $account;
    $fakeAccount->id = strtolower((string) Str::ulid());

    expect(fn () => (new BalanceSnapshotService)->captureForAccount($fakeAccount))
        ->toThrow(UnknownWalletAccountException::class);

    expect(BalanceSnapshot::count())->toBe(0);
});

test('multiple runs append rather than replace', function () {
    $accounts = $this->provisionTestAccounts();
    $service = new BalanceSnapshotService;

    $first = $service->captureForAccount($accounts->earningAvailable);
    $second = $service->captureForAccount($accounts->earningAvailable);

    expect($first->id)->not->toBe($second->id);
    expect(BalanceSnapshot::where('wallet_account_id', $accounts->earningAvailable->id)->count())->toBe(2);
});

test('a posting made after capture does not appear in the already-captured snapshot', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('snapshot-service-no-drift');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:snapshot-service-before', entries: [
        $this->debitEntry($clearing->id, 100_000),
        $this->creditEntry($accounts->earningAvailable->id, 100_000),
    ]));

    $snapshot = (new BalanceSnapshotService)->captureForAccount($accounts->earningAvailable);

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:snapshot-service-after', entries: [
        $this->debitEntry($clearing->id, 50_000),
        $this->creditEntry($accounts->earningAvailable->id, 50_000),
    ]));

    // The already-committed snapshot row is untouched by the later posting.
    expect($snapshot->fresh()->balance_atomic)->toBe(100_000);
    expect($snapshot->fresh()->entry_count)->toBe(1);
});

test('the locked account is re-fetched fresh rather than trusting a stale caller object', function () {
    $accounts = $this->provisionTestAccounts();

    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('snapshot-service-stale');
    $engine = new LedgerPostingEngine;
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:snapshot-service-stale-fund', entries: [
        $this->debitEntry($clearing->id, 42),
        $this->creditEntry($accounts->earningAvailable->id, 42),
    ]));

    // A caller object carrying the SAME real ID but a deliberately WRONG
    // in-memory account_type (the opposite normal-entry-side). If the
    // service trusted this object's fields instead of re-fetching from
    // the database, it would fold using the wrong normal side and derive
    // an incorrect (negative) balance for what is genuinely a credit of
    // 42.
    $staleAccount = clone $accounts->earningAvailable;
    $staleAccount->account_type = WalletAccountType::ProviderSettlementClearing;

    $snapshot = (new BalanceSnapshotService)->captureForAccount($staleAccount);

    // A wrongly-trusted debit-normal fold of one +42 credit entry would
    // have produced -42. The correct, re-fetched credit-normal fold
    // produces +42, proving the stale in-memory type was never consulted.
    expect($snapshot->balance_atomic)->toBe(42);
});

test('an overflow during streaming leaves the write context untouched and writes no snapshot row', function () {
    $accounts = $this->provisionTestAccounts();

    // Two raw entries whose sum overflows PHP_INT_MAX - unreachable
    // through the posting engine itself (its own balance-floor check
    // would reject the second posting before it ever committed), so this
    // corrupted history is constructed directly, mirroring the same
    // technique wallet:reconcile's own overflow test uses.
    $txnId = $this->insertRawLedgerTransaction(['type' => 'fund_reservation', 'business_reference' => 'fund_reservation:snapshot-overflow']);
    $this->insertRawLedgerEntry([
        'ledger_transaction_id' => $txnId,
        'wallet_account_id' => $accounts->earningAvailable->id,
        'entry_type' => 'credit',
        'amount_atomic' => PHP_INT_MAX,
    ]);
    $this->insertRawLedgerEntry([
        'ledger_transaction_id' => $txnId,
        'wallet_account_id' => $accounts->earningAvailable->id,
        'entry_type' => 'credit',
        'amount_atomic' => 1,
    ]);

    expect(BalanceSnapshotWriteContext::isActive())->toBeFalse();

    expect(fn () => (new BalanceSnapshotService)->captureForAccount($accounts->earningAvailable))
        ->toThrow(MoneyOverflowException::class);

    // The failure happens while streaming/folding, entirely before
    // BalanceSnapshotWriteContext::run() is ever called - the context was
    // never activated, so there is nothing to "reset" beyond confirming
    // it never became active in the first place.
    expect(BalanceSnapshotWriteContext::isActive())->toBeFalse();
    expect(BalanceSnapshot::count())->toBe(0);
});

test('a forced database insertion failure rolls back the transaction and leaves the write context inactive', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('snapshot-service-insert-failure');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:snapshot-service-insert-failure', entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]));

    // A pre-existing snapshot whose ID the forced listener below reuses,
    // producing a genuine primary-key collision at insert time - the same
    // established technique used throughout this codebase's own
    // forced-collision tests.
    $collisionTarget = (new BalanceSnapshotService)->captureForAccount(
        (new WalletAccountProvisioner)->provisionUserAccounts(User::factory()->create())->earningAvailable
    );

    expect(BalanceSnapshotWriteContext::isActive())->toBeFalse();

    $this->withIsolatedCreatingListener(
        BalanceSnapshot::class,
        function (BalanceSnapshot $model) use ($collisionTarget): void {
            $model->id = $collisionTarget->id;
        },
        function () use ($accounts): void {
            expect(fn () => (new BalanceSnapshotService)->captureForAccount($accounts->earningAvailable))
                ->toThrow(UniqueConstraintViolationException::class);
        },
    );

    expect(BalanceSnapshotWriteContext::isActive())->toBeFalse();
    // Only the one pre-existing, unrelated snapshot exists - the failed
    // attempt for $accounts->earningAvailable inserted nothing.
    expect(BalanceSnapshot::count())->toBe(1);
    expect(BalanceSnapshot::where('wallet_account_id', $accounts->earningAvailable->id)->count())->toBe(0);
});
