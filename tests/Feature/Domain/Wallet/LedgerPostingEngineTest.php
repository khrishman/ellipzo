<?php

use App\Domain\Shared\Exceptions\MoneyOverflowException;
use App\Domain\Shared\Money\Currency;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Exceptions\UnknownWalletAccountException;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('a valid balanced two-entry posting creates a transaction and its entries', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-valid-two');
    $engine = new LedgerPostingEngine;

    $result = $engine->post($this->postingCommand(entries: [
        $this->debitEntry($clearing->id, 500_000),
        $this->creditEntry($accounts->earningAvailable->id, 500_000),
    ]));

    expect($result->wasReplay)->toBeFalse();
    expect($result->transaction->exists)->toBeTrue();
    expect(LedgerEntry::where('ledger_transaction_id', $result->transaction->id)->count())->toBe(2);
    expect($result->transaction->relationLoaded('entries'))->toBeTrue();
});

test('a valid balanced multi-entry posting succeeds', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-valid-multi');
    $engine = new LedgerPostingEngine;

    $result = $engine->post($this->postingCommand(entries: [
        $this->debitEntry($clearing->id, 300),
        $this->creditEntry($accounts->earningAvailable->id, 100),
        $this->creditEntry($accounts->advertisingAvailable->id, 200),
    ]));

    expect(LedgerEntry::where('ledger_transaction_id', $result->transaction->id)->count())->toBe(3);
});

test('a missing wallet account throws UnknownWalletAccountException and creates no rows', function () {
    $accounts = $this->provisionTestAccounts();
    $engine = new LedgerPostingEngine;
    $reference = 'deposit_credit:missing-account';

    expect(fn () => $engine->post($this->postingCommand(businessReference: $reference, entries: [
        $this->debitEntry((string) Str::ulid(), 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ])))->toThrow(UnknownWalletAccountException::class);

    expect(LedgerTransaction::where('business_reference', $reference)->exists())->toBeFalse();
});

test('a locked account with a corrupted currency or scale is rejected', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-corrupt-scale');
    $engine = new LedgerPostingEngine;

    DB::table('wallet_accounts')->where('id', $accounts->earningAvailable->id)->update(['currency_scale' => 8]);

    expect(fn () => $engine->post($this->postingCommand(entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ])))->toThrow(LedgerInvariantViolationException::class);
});

test('a locked account whose scope no longer matches its account type is rejected', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-corrupt-scope');
    $engine = new LedgerPostingEngine;

    DB::table('wallet_accounts')->where('id', $accounts->earningAvailable->id)->update(['scope_type' => 'platform']);

    expect(fn () => $engine->post($this->postingCommand(entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ])))->toThrow(LedgerInvariantViolationException::class);
});

test('derived balance correctly folds a mixed history of credits and debits', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-history');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:history-1', entries: [
        $this->debitEntry($clearing->id, 1_000_000),
        $this->creditEntry($accounts->earningAvailable->id, 1_000_000),
    ]));

    $engine->post($this->postingCommand(type: LedgerTransactionType::FundReservation, businessReference: 'fund_reservation:history-2', entries: [
        $this->creditEntry($clearing->id, 400_000),
        $this->debitEntry($accounts->earningAvailable->id, 400_000),
    ]));

    // Balance is now 600,000. Spending 700,000 must fail.
    expect(fn () => $engine->post($this->postingCommand(type: LedgerTransactionType::FundReservation, businessReference: 'fund_reservation:history-3', entries: [
        $this->creditEntry($clearing->id, 700_000),
        $this->debitEntry($accounts->earningAvailable->id, 700_000),
    ])))->toThrow(InsufficientBalanceException::class);

    // Spending exactly 600,000 (down to zero) must succeed.
    $engine->post($this->postingCommand(type: LedgerTransactionType::FundReservation, businessReference: 'fund_reservation:history-4', entries: [
        $this->creditEntry($clearing->id, 600_000),
        $this->debitEntry($accounts->earningAvailable->id, 600_000),
    ]));

    expect(LedgerEntry::where('wallet_account_id', $accounts->earningAvailable->id)->count())->toBe(3);
});

test('the historical entry query is explicitly ordered by created_at then id', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-order');
    $engine = new LedgerPostingEngine;

    $observedOrderClause = null;
    DB::listen(function ($query) use (&$observedOrderClause): void {
        if (str_contains($query->sql, 'ledger_entries') && str_contains(strtolower($query->sql), 'order by')) {
            $observedOrderClause = $query->sql;
        }
    });

    $engine->post($this->postingCommand(entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]));

    expect($observedOrderClause)->not->toBeNull();
    expect(strtolower($observedOrderClause ?? ''))->toContain('order by "created_at" asc, "id" asc');
});

test('insufficient balance is rejected for an account that does not allow negative balances', function () {
    $accounts = $this->provisionTestAccounts();
    $engine = new LedgerPostingEngine;
    $reference = 'fund_reservation:insufficient';

    expect(fn () => $engine->post($this->postingCommand(type: LedgerTransactionType::FundReservation, businessReference: $reference, entries: [
        $this->debitEntry($accounts->earningAvailable->id, 100),
        $this->creditEntry($accounts->advertisingAvailable->id, 100),
    ])))->toThrow(InsufficientBalanceException::class);

    expect(LedgerTransaction::where('business_reference', $reference)->exists())->toBeFalse();
});

test('provider_settlement_clearing is permitted to go negative while other account types are not', function () {
    $clearingA = (new WalletAccountProvisioner)->providerClearingAccount('provider-goes-negative');
    $clearingB = (new WalletAccountProvisioner)->providerClearingAccount('provider-stays-positive');
    $engine = new LedgerPostingEngine;

    $result = $engine->post($this->postingCommand(entries: [
        $this->debitEntry($clearingB->id, 500_000),
        $this->creditEntry($clearingA->id, 500_000),
    ]));

    expect($result->wasReplay)->toBeFalse();
});

test('arithmetic overflow during balance derivation throws MoneyOverflowException', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-overflow');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:overflow-setup', entries: [
        $this->debitEntry($clearing->id, PHP_INT_MAX),
        $this->creditEntry($accounts->earningAvailable->id, PHP_INT_MAX),
    ]));

    expect(fn () => $engine->post($this->postingCommand(businessReference: 'deposit_credit:overflow-trigger', entries: [
        $this->debitEntry($clearing->id, 1),
        $this->creditEntry($accounts->earningAvailable->id, 1),
    ])))->toThrow(MoneyOverflowException::class);
});

test('an actor deleted between command construction and posting causes rollback, not a dangling reference', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-stale-actor');
    $actor = User::factory()->create();

    $command = $this->postingCommand(actor: $actor, entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]);

    DB::table('users')->where('id', $actor->id)->delete();

    $engine = new LedgerPostingEngine;

    expect(fn () => $engine->post($command))->toThrow(LedgerInvariantViolationException::class);
    expect(LedgerTransaction::where('business_reference', $command->businessReference)->exists())->toBeFalse();
});

test('a forced collision on the last entry rolls back the entire posting, including the transaction row and the earlier entry', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-rollback');
    $engine = new LedgerPostingEngine;

    $unrelated = $engine->post($this->postingCommand(businessReference: 'deposit_credit:unrelated-for-collision', entries: [
        $this->debitEntry($clearing->id, 10),
        $this->creditEntry($accounts->earningAvailable->id, 10),
    ]));
    $collisionTargetId = $unrelated->transaction->entries->first(
        fn (LedgerEntry $e) => $e->wallet_account_id === $accounts->earningAvailable->id
    )->id;

    $reference = 'deposit_credit:forced-failure';

    $this->withIsolatedCreatingListener(
        LedgerEntry::class,
        function (LedgerEntry $model) use ($collisionTargetId, $accounts): void {
            if ($model->wallet_account_id === $accounts->earningAvailable->id) {
                $model->id = $collisionTargetId;
            }
        },
        function () use ($engine, $clearing, $accounts, $reference): void {
            $command = $this->postingCommand(businessReference: $reference, entries: [
                $this->debitEntry($clearing->id, 20),
                $this->creditEntry($accounts->earningAvailable->id, 20),
            ]);

            expect(fn () => $engine->post($command))->toThrow(UniqueConstraintViolationException::class);
        },
    );

    expect(LedgerTransaction::where('business_reference', $reference)->exists())->toBeFalse();
});

test('direct LedgerTransaction creation outside the posting engine is blocked', function () {
    $transaction = new LedgerTransaction;
    $transaction->business_reference = 'deposit_credit:direct-attempt';
    $transaction->type = LedgerTransactionType::DepositCredit;
    $transaction->currency_code = Currency::USD;
    $transaction->currency_scale = 6;
    $transaction->description = 'direct attempt';
    $transaction->correlation_id = (string) Str::uuid();

    expect(fn () => $transaction->save())->toThrow(LedgerInvariantViolationException::class);
    expect(LedgerTransaction::where('business_reference', 'deposit_credit:direct-attempt')->exists())->toBeFalse();
});

test('direct LedgerEntry creation outside the posting engine is blocked', function () {
    $accounts = $this->provisionTestAccounts();
    $transactionId = $this->insertRawLedgerTransaction();

    $entry = new LedgerEntry;
    $entry->ledger_transaction_id = $transactionId;
    $entry->wallet_account_id = $accounts->earningAvailable->id;
    $entry->entry_type = LedgerEntryType::Credit;
    $entry->amount_atomic = 100;

    expect(fn () => $entry->save())->toThrow(LedgerInvariantViolationException::class);
});

test('the write context resets after a failed posting, so a subsequent direct save is still blocked', function () {
    $engine = new LedgerPostingEngine;

    try {
        $engine->post($this->postingCommand(entries: [
            $this->debitEntry((string) Str::ulid(), 100),
            $this->creditEntry((string) Str::ulid(), 100),
        ]));
    } catch (Throwable) {
        // expected - proceeding to prove the context did not leak "active"
    }

    $transaction = new LedgerTransaction;
    $transaction->business_reference = 'deposit_credit:after-failure';
    $transaction->type = LedgerTransactionType::DepositCredit;
    $transaction->currency_code = Currency::USD;
    $transaction->currency_scale = 6;
    $transaction->description = 'after failure';
    $transaction->correlation_id = (string) Str::uuid();

    expect(fn () => $transaction->save())->toThrow(LedgerInvariantViolationException::class);
});

test('accounts are locked in canonical ascending ULID order regardless of entry submission order', function () {
    $accounts = $this->provisionTestAccounts();
    $idA = $accounts->earningAvailable->id;
    $idB = $accounts->advertisingAvailable->id;
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-lock-order');

    [$larger, $smaller] = $idA > $idB ? [$idA, $idB] : [$idB, $idA];

    $lockedIds = [];
    DB::listen(function ($query) use (&$lockedIds, $idA, $idB): void {
        $sql = strtolower($query->sql);
        if (str_starts_with($sql, 'select') && str_contains($sql, 'wallet_accounts') && str_contains($sql, 'where')) {
            $binding = $query->bindings[0] ?? null;
            if (in_array($binding, [$idA, $idB], true)) {
                $lockedIds[] = $binding;
            }
        }
    });

    // Submit the larger account first, on purpose, to prove lock order is
    // not submission order.
    $engine = new LedgerPostingEngine;
    $engine->post($this->postingCommand(entries: [
        $this->debitEntry($clearing->id, 300),
        $this->creditEntry($larger, 100),
        $this->creditEntry($smaller, 200),
    ]));

    expect($lockedIds)->toBe([$smaller, $larger]);
});
