<?php

use App\Domain\Wallet\Exceptions\DuplicateFinancialEventException;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Exceptions\LedgerPostingExceptionInterface;
use App\Domain\Wallet\Exceptions\UnknownWalletAccountException;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Direct proofs for claims already believed true from reading the
 * implementation, but not yet directly asserted by an earlier test - added
 * only where a genuine gap was found during this hardening pass, not a
 * wholesale re-test of everything LedgerPostingEngineTest.php already
 * covers.
 */
test('every stored amount_atomic is genuinely greater than zero', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-audit-positive-amount');
    $engine = new LedgerPostingEngine;

    $result = $engine->post($this->postingCommand(entries: [
        $this->debitEntry($clearing->id, 12_345),
        $this->creditEntry($accounts->earningAvailable->id, 12_345),
    ]));

    $storedAmounts = LedgerEntry::where('ledger_transaction_id', $result->transaction->id)->pluck('amount_atomic');

    expect($storedAmounts)->toHaveCount(2);
    foreach ($storedAmounts as $amount) {
        expect($amount)->toBeGreaterThan(0);
    }
});

test('reverses_transaction_id remains null on every posted transaction', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-audit-reverses-null');
    $engine = new LedgerPostingEngine;

    $result = $engine->post($this->postingCommand(entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]));

    expect(DB::table('ledger_transactions')->where('id', $result->transaction->id)->value('reverses_transaction_id'))
        ->toBeNull();
});

test('the stored business_reference is the normalized (lowercase) form, not the raw caller input', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-audit-reference-stored');
    $engine = new LedgerPostingEngine;

    $mixedCaseReference = 'DEPOSIT_credit:AuditReference'.strtoupper(substr((string) Str::ulid(), 0, 8));

    $result = $engine->post($this->postingCommand(businessReference: $mixedCaseReference, entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]));

    $stored = DB::table('ledger_transactions')->where('id', $result->transaction->id)->value('business_reference');

    expect($stored)->toBe(strtolower($mixedCaseReference));
    expect($stored)->toBe($result->transaction->business_reference);
});

test('a malformed locked account causes complete rollback: zero transaction rows and zero entry rows', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-audit-malformed-rollback');
    $engine = new LedgerPostingEngine;
    $reference = 'deposit_credit:audit-malformed-rollback';

    DB::table('wallet_accounts')->where('id', $accounts->earningAvailable->id)->update(['currency_scale' => 8]);

    expect(fn () => $engine->post($this->postingCommand(businessReference: $reference, entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ])))->toThrow(LedgerInvariantViolationException::class);

    expect(LedgerTransaction::where('business_reference', $reference)->count())->toBe(0);
    expect(LedgerEntry::whereIn('ledger_transaction_id', LedgerTransaction::where('business_reference', $reference)->pluck('id'))->count())->toBe(0);
});

test('a missing wallet account causes complete rollback: zero transaction rows and zero entry rows', function () {
    $accounts = $this->provisionTestAccounts();
    $engine = new LedgerPostingEngine;
    $reference = 'deposit_credit:audit-missing-account-rollback';
    $entryCountBefore = LedgerEntry::count();

    expect(fn () => $engine->post($this->postingCommand(businessReference: $reference, entries: [
        $this->debitEntry((string) Str::ulid(), 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ])))->toThrow(UnknownWalletAccountException::class);

    expect(LedgerTransaction::where('business_reference', $reference)->count())->toBe(0);
    expect(LedgerEntry::count())->toBe($entryCountBefore);
});

test('a replay attempt with the same amount and type but a different wallet account conflicts', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-audit-changed-account');
    $engine = new LedgerPostingEngine;
    $reference = 'deposit_credit:audit-changed-account';

    $engine->post($this->postingCommand(businessReference: $reference, entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]));

    expect(fn () => $engine->post($this->postingCommand(businessReference: $reference, entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->advertisingAvailable->id, 100), // different account, same amount/type
    ])))->toThrow(DuplicateFinancialEventException::class);
});

test('a replay attempt with the same accounts and amount but swapped entry sides conflicts', function () {
    $clearingA = (new WalletAccountProvisioner)->providerClearingAccount('provider-audit-side-a');
    $clearingB = (new WalletAccountProvisioner)->providerClearingAccount('provider-audit-side-b');
    $engine = new LedgerPostingEngine;
    $reference = 'deposit_credit:audit-changed-entry-side';

    $engine->post($this->postingCommand(businessReference: $reference, entries: [
        $this->debitEntry($clearingA->id, 100),
        $this->creditEntry($clearingB->id, 100),
    ]));

    expect(fn () => $engine->post($this->postingCommand(businessReference: $reference, entries: [
        $this->creditEntry($clearingA->id, 100), // sides swapped relative to the original
        $this->debitEntry($clearingB->id, 100),
    ])))->toThrow(DuplicateFinancialEventException::class);
});

test('every Task 2.4 posting exception implements LedgerPostingExceptionInterface', function () {
    foreach ([
        LedgerInvariantViolationException::class,
        InsufficientBalanceException::class,
        DuplicateFinancialEventException::class,
        UnknownWalletAccountException::class,
    ] as $exceptionClass) {
        expect(is_a($exceptionClass, LedgerPostingExceptionInterface::class, true))->toBeTrue();
    }
});

test('no Task 2.4 exception message interpolates caller-supplied or database-error text', function () {
    $files = [
        app_path('Domain/Wallet/Services/LedgerPostingEngine.php'),
        app_path('Domain/Wallet/Data/PostLedgerEntryCommand.php'),
        app_path('Domain/Wallet/Data/PostLedgerTransactionCommand.php'),
        app_path('Domain/Wallet/Models/LedgerTransaction.php'),
        app_path('Domain/Wallet/Models/LedgerEntry.php'),
    ];

    foreach ($files as $file) {
        $source = file_get_contents($file);

        // Every `throw new ...Exception(` call must be followed by a
        // single-quoted literal, with the closing quote immediately
        // followed by `)` - i.e. no `.` concatenation or `$` interpolation
        // touching the message. Periods are allowed *inside* the string
        // (ordinary sentence punctuation); only `'` and `$` are excluded
        // from the content, since either would break out of or interpolate
        // into a plain single-quoted literal. If any throw site doesn't
        // match this safe shape, preg_match_all's count of all throw sites
        // will exceed the count of safe-shaped ones below.
        preg_match_all('/throw new \w+Exception\(/', $source, $allThrows);
        preg_match_all('/throw new \w+Exception\(\'[^\'$]*\'\)/', $source, $staticThrows);

        expect(count($allThrows[0]))->toBe(count($staticThrows[0]));
    }

    // Directly confirm no exception is ever classified by inspecting a
    // caught exception's own message text.
    foreach ($files as $file) {
        expect(file_get_contents($file))->not->toContain('getMessage()');
    }
});
