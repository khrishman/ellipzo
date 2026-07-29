<?php

use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Services\AdministrativeAdjustmentService;
use App\Domain\Wallet\Services\BalanceSnapshotService;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('a clean, empty ledger returns exit 0 with zero discrepancies', function () {
    $exitCode = Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Discrepancies: 0');
});

test('a real, fully legitimate posted/reversed/adjusted history reconciles clean', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('reconcile-clean-history');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:reconcile-clean-1', entries: [
        $this->debitEntry($clearing->id, 500_000),
        $this->creditEntry($accounts->earningAvailable->id, 500_000),
    ]));

    $actor = $this->ledgerAdjustActor();
    $adjustmentTarget = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($adjustmentTarget);
    app(AdministrativeAdjustmentService::class)->submit(
        $this->adjustmentCommand($actor, $adjustmentTarget)
    );

    (new BalanceSnapshotService)->captureForAccount($accounts->earningAvailable);

    $exitCode = Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Discrepancies: 0');
});

test('a transaction with fewer than two entries is detected', function () {
    $txnId = $this->insertRawLedgerTransaction();
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $txnId]);

    Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($output)->toContain("TRANSACTION_TOO_FEW_ENTRIES ledger_transaction {$txnId}");
});

test('an imbalanced transaction is detected', function () {
    $accountId = $this->insertRawWalletAccount();
    $txnId = $this->insertRawLedgerTransaction();
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $txnId, 'wallet_account_id' => $accountId, 'entry_type' => 'debit', 'amount_atomic' => 100]);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $txnId, 'wallet_account_id' => $accountId, 'entry_type' => 'credit', 'amount_atomic' => 200]);

    Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($output)->toContain("TRANSACTION_IMBALANCED ledger_transaction {$txnId}");
});

test('a transaction with a non-USD currency or wrong scale is detected', function () {
    $accountId = $this->insertRawWalletAccount();
    $txnId = $this->insertRawLedgerTransaction(['currency_scale' => 8]);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $txnId, 'wallet_account_id' => $accountId, 'entry_type' => 'debit', 'amount_atomic' => 100]);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $txnId, 'wallet_account_id' => $accountId, 'entry_type' => 'credit', 'amount_atomic' => 100]);

    Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($output)->toContain("TRANSACTION_INVALID_CURRENCY_OR_SCALE ledger_transaction {$txnId}");
});

test('a non-positive entry amount is detected', function () {
    $entryId = $this->insertRawLedgerEntry(['amount_atomic' => 0]);

    Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($output)->toContain("ENTRY_NON_POSITIVE_AMOUNT ledger_entry {$entryId}");
});

test('an account whose scope does not match its account type is detected', function () {
    $accounts = $this->provisionTestAccounts();

    DB::table('wallet_accounts')
        ->where('id', $accounts->earningAvailable->id)
        ->update(['scope_type' => 'platform']);

    Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($output)->toContain("ACCOUNT_INVALID_SCOPE_TYPE wallet_account {$accounts->earningAvailable->id}");
});

test('an account whose entry history overflows Money arithmetic is detected, not fatally crashed', function () {
    $accounts = $this->provisionTestAccounts();
    $txnId = $this->insertRawLedgerTransaction(['type' => 'fund_reservation', 'business_reference' => 'fund_reservation:reconcile-overflow']);

    // Two credits to a credit-normal account whose sum exceeds PHP_INT_MAX -
    // MoneyOverflowException must be caught and reported, not propagate as
    // a fatal command crash.
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

    $exitCode = Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)->toContain("BALANCE_DERIVATION_OVERFLOW wallet_account {$accounts->earningAvailable->id}");
});

test('a disallowed negative balance is detected', function () {
    $accounts = $this->provisionTestAccounts();
    $txnId = $this->insertRawLedgerTransaction(['type' => 'fund_reservation', 'business_reference' => 'fund_reservation:reconcile-negative']);
    // earning_available is credit-normal; a lone debit entry drives it negative.
    $this->insertRawLedgerEntry([
        'ledger_transaction_id' => $txnId,
        'wallet_account_id' => $accounts->earningAvailable->id,
        'entry_type' => 'debit',
        'amount_atomic' => 500,
    ]);

    Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($output)->toContain("ACCOUNT_NEGATIVE_BALANCE_DISALLOWED wallet_account {$accounts->earningAvailable->id}");
});

test('an applied reversal whose reversal transaction does not actually reverse the claimed original is detected', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('reconcile-reversal-mismatch');

    $originalId = $this->insertRawLedgerTransaction(['business_reference' => 'deposit_credit:reconcile-mismatch-original']);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $originalId, 'wallet_account_id' => $clearing->id, 'entry_type' => 'debit', 'amount_atomic' => 300]);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $originalId, 'wallet_account_id' => $accounts->earningAvailable->id, 'entry_type' => 'credit', 'amount_atomic' => 300]);

    $unrelatedId = $this->insertRawLedgerTransaction(['business_reference' => 'deposit_credit:reconcile-mismatch-unrelated']);

    // A reversal transaction that claims to reverse a DIFFERENT (unrelated) transaction.
    $reversalId = $this->insertRawLedgerTransaction([
        'type' => 'reversal',
        'business_reference' => 'reversal:'.$originalId,
        'reverses_transaction_id' => $unrelatedId,
    ]);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $reversalId, 'wallet_account_id' => $clearing->id, 'entry_type' => 'credit', 'amount_atomic' => 300]);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $reversalId, 'wallet_account_id' => $accounts->earningAvailable->id, 'entry_type' => 'debit', 'amount_atomic' => 300]);

    $requestId = $this->insertRawReversalRequest([
        'original_ledger_transaction_id' => $originalId,
        'reversal_transaction_id' => $reversalId,
        'status' => 'applied',
        'applied_at' => now(),
    ]);

    Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($output)->toContain("REVERSAL_TRANSACTION_MISMATCH reversal_request {$requestId}");
});

test('an applied reversal whose entries are not the exact inversion of the original is detected', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('reconcile-entry-mismatch');

    $originalId = $this->insertRawLedgerTransaction(['business_reference' => 'deposit_credit:reconcile-entry-mismatch-original']);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $originalId, 'wallet_account_id' => $clearing->id, 'entry_type' => 'debit', 'amount_atomic' => 300]);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $originalId, 'wallet_account_id' => $accounts->earningAvailable->id, 'entry_type' => 'credit', 'amount_atomic' => 300]);

    $reversalId = $this->insertRawLedgerTransaction([
        'type' => 'reversal',
        'business_reference' => 'reversal:'.$originalId,
        'reverses_transaction_id' => $originalId,
    ]);
    // Wrong amount - not the exact mechanical inversion.
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $reversalId, 'wallet_account_id' => $clearing->id, 'entry_type' => 'credit', 'amount_atomic' => 150]);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $reversalId, 'wallet_account_id' => $accounts->earningAvailable->id, 'entry_type' => 'debit', 'amount_atomic' => 150]);

    $requestId = $this->insertRawReversalRequest([
        'original_ledger_transaction_id' => $originalId,
        'reversal_transaction_id' => $reversalId,
        'status' => 'applied',
        'applied_at' => now(),
    ]);

    Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($output)->toContain("REVERSAL_ENTRY_MISMATCH reversal_request {$requestId}");
});

test('a pending reversal request that is already linked to a reversal transaction is detected', function () {
    $originalId = $this->insertRawLedgerTransaction();
    $reversalId = $this->insertRawLedgerTransaction(['type' => 'reversal', 'business_reference' => 'reversal:'.$originalId, 'reverses_transaction_id' => $originalId]);

    $requestId = $this->insertRawReversalRequest([
        'original_ledger_transaction_id' => $originalId,
        'reversal_transaction_id' => $reversalId,
        'status' => 'pending',
    ]);

    Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($output)->toContain("REVERSAL_PENDING_BUT_LINKED reversal_request {$requestId}");
});

test('an applied reversal request with no reversal transaction is detected', function () {
    $originalId = $this->insertRawLedgerTransaction();

    $requestId = $this->insertRawReversalRequest([
        'original_ledger_transaction_id' => $originalId,
        'reversal_transaction_id' => null,
        'status' => 'applied',
        'applied_at' => now(),
    ]);

    Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($output)->toContain("REVERSAL_APPLIED_MISSING_TRANSACTION reversal_request {$requestId}");
});

test('an administrative-adjustment transaction with no audit event is detected', function () {
    $accounts = $this->provisionTestAccounts();
    $suspense = (new WalletAccountProvisioner)->platformSuspenseAccount();

    $txnId = $this->insertRawLedgerTransaction(['type' => 'administrative_adjustment', 'business_reference' => 'administrative_adjustment:reconcile-audit-missing']);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $txnId, 'wallet_account_id' => $accounts->earningAvailable->id, 'entry_type' => 'credit', 'amount_atomic' => 500]);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $txnId, 'wallet_account_id' => $suspense->id, 'entry_type' => 'debit', 'amount_atomic' => 500]);

    Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($output)->toContain("ADJUSTMENT_AUDIT_MISSING ledger_transaction {$txnId}");
});

test('an administrative-adjustment audit whose payload conflicts with the committed transaction is detected', function () {
    $accounts = $this->provisionTestAccounts();
    $suspense = (new WalletAccountProvisioner)->platformSuspenseAccount();
    $actor = $this->ledgerAdjustActor();

    $txnId = $this->insertRawLedgerTransaction([
        'type' => 'administrative_adjustment',
        'business_reference' => 'administrative_adjustment:reconcile-audit-conflict',
        'actor_id' => $actor->id,
    ]);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $txnId, 'wallet_account_id' => $accounts->earningAvailable->id, 'entry_type' => 'credit', 'amount_atomic' => 500]);
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $txnId, 'wallet_account_id' => $suspense->id, 'entry_type' => 'debit', 'amount_atomic' => 500]);

    // A matching audit row, but with a wrong stored amount in after_state.
    $this->insertRawAuditEvent([
        'actor_id' => $actor->id,
        'entity_type' => 'ledger_transaction',
        'entity_key' => $txnId,
        'action' => 'ledger.administrative_adjustment',
        'after_state' => json_encode([
            'target_wallet_account_id' => $accounts->earningAvailable->id,
            'target_account_type' => 'earning_available',
            'direction' => 'increase',
            'amount_atomic' => '999999',
            'currency' => 'USD',
            'business_reference' => 'administrative_adjustment:reconcile-audit-conflict',
        ]),
    ]);

    Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($output)->toContain("ADJUSTMENT_AUDIT_CONFLICT ledger_transaction {$txnId}");
});

test('an administrative-adjustment audit event pointing to a nonexistent ledger transaction is detected', function () {
    $auditId = $this->insertRawAuditEvent([
        'action' => 'ledger.administrative_adjustment',
        'entity_type' => 'ledger_transaction',
        'entity_key' => strtolower((string) Str::ulid()),
    ]);

    Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($output)->toContain("AUDIT_TRANSACTION_MISSING audit_event {$auditId}");
});

test('a snapshot whose cutoff entry belongs to a different account is detected', function () {
    $accounts = $this->provisionTestAccounts();
    $otherAccounts = (new WalletAccountProvisioner)->provisionUserAccounts(User::factory()->create());
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('reconcile-snapshot-wrong-account');
    $engine = new LedgerPostingEngine;

    $result = $engine->post($this->postingCommand(businessReference: 'deposit_credit:reconcile-snapshot-wrong-account', entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($otherAccounts->earningAvailable->id, 100),
    ]));
    $otherAccountEntry = LedgerEntry::where('ledger_transaction_id', $result->transaction->id)
        ->where('wallet_account_id', $otherAccounts->earningAvailable->id)->firstOrFail();

    $snapshotId = $this->insertRawBalanceSnapshot([
        'wallet_account_id' => $accounts->earningAvailable->id,
        'cutoff_ledger_entry_id' => $otherAccountEntry->id,
        'cutoff_entry_created_at' => $otherAccountEntry->created_at,
        'entry_count' => 1,
        'balance_atomic' => 100,
    ]);

    Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($output)->toContain("SNAPSHOT_CUTOFF_WRONG_ACCOUNT balance_snapshot {$snapshotId}");
});

test('a snapshot whose stored cutoff timestamp does not match the real entry timestamp is detected', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('reconcile-snapshot-ts-mismatch');
    $engine = new LedgerPostingEngine;

    $result = $engine->post($this->postingCommand(businessReference: 'deposit_credit:reconcile-snapshot-ts-mismatch', entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]));
    $entry = LedgerEntry::where('ledger_transaction_id', $result->transaction->id)
        ->where('wallet_account_id', $accounts->earningAvailable->id)->firstOrFail();

    $snapshotId = $this->insertRawBalanceSnapshot([
        'wallet_account_id' => $accounts->earningAvailable->id,
        'cutoff_ledger_entry_id' => $entry->id,
        'cutoff_entry_created_at' => $entry->created_at->clone()->addDay(),
        'entry_count' => 1,
        'balance_atomic' => 100,
    ]);

    Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($output)->toContain("SNAPSHOT_CUTOFF_TIMESTAMP_MISMATCH balance_snapshot {$snapshotId}");
});

test('a snapshot with drifted balance, entry count, and fingerprint is detected for all three', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('reconcile-snapshot-drift');
    $engine = new LedgerPostingEngine;

    $result = $engine->post($this->postingCommand(businessReference: 'deposit_credit:reconcile-snapshot-drift', entries: [
        $this->debitEntry($clearing->id, 500),
        $this->creditEntry($accounts->earningAvailable->id, 500),
    ]));
    $entry = LedgerEntry::where('ledger_transaction_id', $result->transaction->id)
        ->where('wallet_account_id', $accounts->earningAvailable->id)->firstOrFail();

    $snapshotId = $this->insertRawBalanceSnapshot([
        'wallet_account_id' => $accounts->earningAvailable->id,
        'cutoff_ledger_entry_id' => $entry->id,
        'cutoff_entry_created_at' => $entry->created_at,
        // Deliberately wrong balance/count relative to the real history.
        'entry_count' => 2,
        'balance_atomic' => 999,
        'fingerprint' => str_repeat('b', 64),
    ]);

    Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($output)->toContain("SNAPSHOT_BALANCE_DRIFT balance_snapshot {$snapshotId}");
    expect($output)->toContain("SNAPSHOT_ENTRY_COUNT_DRIFT balance_snapshot {$snapshotId}");
    expect($output)->toContain("SNAPSHOT_FINGERPRINT_DRIFT balance_snapshot {$snapshotId}");
});

test('a historical value change that preserves balance and count still produces fingerprint drift', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('reconcile-snapshot-fingerprint-only');
    $engine = new LedgerPostingEngine;

    $result = $engine->post($this->postingCommand(businessReference: 'deposit_credit:reconcile-snapshot-fp-only', entries: [
        $this->debitEntry($clearing->id, 500),
        $this->creditEntry($accounts->earningAvailable->id, 500),
    ]));
    $entry = LedgerEntry::where('ledger_transaction_id', $result->transaction->id)
        ->where('wallet_account_id', $accounts->earningAvailable->id)->firstOrFail();

    // Correct balance and count, but a fingerprint that cannot possibly
    // match the real one - proves fingerprint drift is detected even
    // when balance/count alone would look clean.
    $snapshotId = $this->insertRawBalanceSnapshot([
        'wallet_account_id' => $accounts->earningAvailable->id,
        'cutoff_ledger_entry_id' => $entry->id,
        'cutoff_entry_created_at' => $entry->created_at,
        'entry_count' => 1,
        'balance_atomic' => 500,
        'fingerprint' => str_repeat('c', 64),
    ]);

    Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($output)->not->toContain("SNAPSHOT_BALANCE_DRIFT balance_snapshot {$snapshotId}");
    expect($output)->not->toContain("SNAPSHOT_ENTRY_COUNT_DRIFT balance_snapshot {$snapshotId}");
    expect($output)->toContain("SNAPSHOT_FINGERPRINT_DRIFT balance_snapshot {$snapshotId}");
});

test('a legitimate posting made strictly after a snapshot never causes drift for that snapshot', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('reconcile-snapshot-no-drift');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:reconcile-no-drift-before', entries: [
        $this->debitEntry($clearing->id, 200),
        $this->creditEntry($accounts->earningAvailable->id, 200),
    ]));

    $snapshot = (new BalanceSnapshotService)->captureForAccount($accounts->earningAvailable);

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:reconcile-no-drift-after', entries: [
        $this->debitEntry($clearing->id, 300),
        $this->creditEntry($accounts->earningAvailable->id, 300),
    ]));

    Artisan::call('wallet:reconcile');
    $output = Artisan::output();

    expect($output)->not->toContain("balance_snapshot {$snapshot->id}");
});

test('reconciliation never writes anything, even when discrepancies are found', function () {
    $txnId = $this->insertRawLedgerTransaction();
    $this->insertRawLedgerEntry(['ledger_transaction_id' => $txnId]);

    $countBefore = LedgerTransaction::count();

    $exitCode = Artisan::call('wallet:reconcile');

    expect($exitCode)->toBe(1);
    expect(LedgerTransaction::count())->toBe($countBefore);
});
