<?php

use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Services\LedgerBalanceReader;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\WalletAccountProvisioner;

test('currentBalance() matches a funded account\'s real balance', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('reader-current-balance');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(businessReference: 'deposit_credit:reader-current-1', entries: [
        $this->debitEntry($clearing->id, 1_000_000),
        $this->creditEntry($accounts->earningAvailable->id, 1_000_000),
    ]));
    $engine->post($this->postingCommand(type: LedgerTransactionType::FundReservation, businessReference: 'fund_reservation:reader-current-2', entries: [
        $this->creditEntry($clearing->id, 300_000),
        $this->debitEntry($accounts->earningAvailable->id, 300_000),
    ]));

    $result = (new LedgerBalanceReader)->currentBalance($accounts->earningAvailable);

    expect($result->balance->atomic())->toBe(700_000);
    expect($result->entryCount)->toBe(2);
    expect($result->lastEntry)->not->toBeNull();
});

test('currentBalance() on a never-funded account returns zero', function () {
    $accounts = $this->provisionTestAccounts();

    $result = (new LedgerBalanceReader)->currentBalance($accounts->earningAvailable);

    expect($result->balance->isZero())->toBeTrue();
    expect($result->entryCount)->toBe(0);
    expect($result->lastEntry)->toBeNull();
});

test('balanceAsOf() excludes entries posted after the cutoff', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('reader-as-of');
    $engine = new LedgerPostingEngine;

    $first = $engine->post($this->postingCommand(businessReference: 'deposit_credit:reader-as-of-1', entries: [
        $this->debitEntry($clearing->id, 500_000),
        $this->creditEntry($accounts->earningAvailable->id, 500_000),
    ]));

    $cutoffEntry = LedgerEntry::where('ledger_transaction_id', $first->transaction->id)
        ->where('wallet_account_id', $accounts->earningAvailable->id)
        ->firstOrFail();

    // Posted strictly after the cutoff - must never appear in balanceAsOf().
    $engine->post($this->postingCommand(businessReference: 'deposit_credit:reader-as-of-2', entries: [
        $this->debitEntry($clearing->id, 200_000),
        $this->creditEntry($accounts->earningAvailable->id, 200_000),
    ]));

    $asOf = (new LedgerBalanceReader)->balanceAsOf($accounts->earningAvailable, $cutoffEntry);
    $current = (new LedgerBalanceReader)->currentBalance($accounts->earningAvailable);

    expect($asOf->balance->atomic())->toBe(500_000);
    expect($asOf->entryCount)->toBe(1);
    expect($asOf->lastEntryId())->toBe($cutoffEntry->id);
    expect($current->balance->atomic())->toBe(700_000);
});

test('balanceAsOf() includes same-timestamp entries up to and including the cutoff id, ordered correctly', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('reader-same-timestamp');
    $engine = new LedgerPostingEngine;

    // Force identical created_at across two postings by freezing time.
    $fixedNow = now();
    $this->travelTo($fixedNow);

    $result1 = $engine->post($this->postingCommand(businessReference: 'deposit_credit:reader-same-ts-1', entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]));
    $result2 = $engine->post($this->postingCommand(businessReference: 'deposit_credit:reader-same-ts-2', entries: [
        $this->debitEntry($clearing->id, 200),
        $this->creditEntry($accounts->earningAvailable->id, 200),
    ]));

    $entryA = LedgerEntry::where('ledger_transaction_id', $result1->transaction->id)
        ->where('wallet_account_id', $accounts->earningAvailable->id)->firstOrFail();
    $entryB = LedgerEntry::where('ledger_transaction_id', $result2->transaction->id)
        ->where('wallet_account_id', $accounts->earningAvailable->id)->firstOrFail();

    expect($entryA->created_at->equalTo($entryB->created_at))->toBeTrue();

    $sortedIds = collect([$entryA->id, $entryB->id])->sort()->values();
    $laterEntry = $sortedIds->last() === $entryA->id ? $entryA : $entryB;

    $asOfLater = (new LedgerBalanceReader)->balanceAsOf($accounts->earningAvailable, $laterEntry);

    expect($asOfLater->entryCount)->toBe(2);
    expect($asOfLater->balance->atomic())->toBe(300);

    $this->travelBack();
});
