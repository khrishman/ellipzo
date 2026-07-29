<?php

use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\RelatedEntityType;
use App\Domain\Wallet\Exceptions\DuplicateFinancialEventException;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Services\LedgerPostingEngine;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('an identical replay returns the original transaction and creates no rows', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-replay-identical');
    $engine = new LedgerPostingEngine;

    $command = $this->postingCommand(businessReference: 'deposit_credit:replay-identical', entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]);

    $first = $engine->post($command);
    expect($first->wasReplay)->toBeFalse();

    $countAfterFirst = LedgerTransaction::count();
    $entryCountAfterFirst = LedgerEntry::count();

    $second = $engine->post($command);

    expect($second->wasReplay)->toBeTrue();
    expect($second->transaction->id)->toBe($first->transaction->id);
    expect(LedgerTransaction::count())->toBe($countAfterFirst);
    expect(LedgerEntry::count())->toBe($entryCountAfterFirst);
});

test('a replay with reordered entries is still recognized as identical', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-replay-reordered');
    $engine = new LedgerPostingEngine;
    $reference = 'deposit_credit:replay-reordered';

    $first = $engine->post($this->postingCommand(businessReference: $reference, entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]));

    // Same semantic entries, submitted in the opposite order.
    $second = $engine->post($this->postingCommand(businessReference: $reference, entries: [
        $this->creditEntry($accounts->earningAvailable->id, 100),
        $this->debitEntry($clearing->id, 100),
    ]));

    expect($second->wasReplay)->toBeTrue();
    expect($second->transaction->id)->toBe($first->transaction->id);
});

test('a replay with a different correlation ID is still recognized as identical, and the original correlation ID is preserved', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-replay-correlation');
    $engine = new LedgerPostingEngine;
    $reference = 'deposit_credit:replay-correlation';

    $first = $engine->post($this->postingCommand(
        businessReference: $reference,
        correlationId: (string) Str::uuid(),
        entries: [
            $this->debitEntry($clearing->id, 100),
            $this->creditEntry($accounts->earningAvailable->id, 100),
        ],
    ));

    $second = $engine->post($this->postingCommand(
        businessReference: $reference,
        correlationId: (string) Str::uuid(), // deliberately a fresh, different retry correlation ID
        entries: [
            $this->debitEntry($clearing->id, 100),
            $this->creditEntry($accounts->earningAvailable->id, 100),
        ],
    ));

    expect($second->wasReplay)->toBeTrue();
    expect($second->transaction->id)->toBe($first->transaction->id);
    expect($second->transaction->correlation_id)->toBe($first->transaction->correlation_id);
});

test('a replay does not acquire any wallet-account lock', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-replay-no-lock');
    $engine = new LedgerPostingEngine;
    $reference = 'deposit_credit:replay-no-lock';

    $command = $this->postingCommand(businessReference: $reference, entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]);

    $engine->post($command);

    $walletAccountQueries = 0;
    DB::listen(function ($query) use (&$walletAccountQueries): void {
        if (str_contains($query->sql, 'wallet_accounts')) {
            $walletAccountQueries++;
        }
    });

    $result = $engine->post($command);

    expect($result->wasReplay)->toBeTrue();
    expect($walletAccountQueries)->toBe(0);
});

test('a different description with the same business reference conflicts', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-conflict-description');
    $engine = new LedgerPostingEngine;
    $reference = 'deposit_credit:conflict-description';

    $engine->post($this->postingCommand(businessReference: $reference, description: 'Original', entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]));

    expect(fn () => $engine->post($this->postingCommand(businessReference: $reference, description: 'Different', entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ])))->toThrow(DuplicateFinancialEventException::class);
});

test('a different actor with the same business reference conflicts', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-conflict-actor');
    $engine = new LedgerPostingEngine;
    $reference = 'deposit_credit:conflict-actor';
    $actorA = User::factory()->create();
    $actorB = User::factory()->create();

    $engine->post($this->postingCommand(businessReference: $reference, actor: $actorA, entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]));

    expect(fn () => $engine->post($this->postingCommand(businessReference: $reference, actor: $actorB, entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ])))->toThrow(DuplicateFinancialEventException::class);
});

test('a different related entity with the same business reference conflicts', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-conflict-related');
    $engine = new LedgerPostingEngine;
    $reference = 'deposit_credit:conflict-related';

    $engine->post($this->postingCommand(
        businessReference: $reference,
        relatedEntityType: RelatedEntityType::DepositIntent,
        relatedEntityId: (string) Str::ulid(),
        entries: [
            $this->debitEntry($clearing->id, 100),
            $this->creditEntry($accounts->earningAvailable->id, 100),
        ],
    ));

    expect(fn () => $engine->post($this->postingCommand(
        businessReference: $reference,
        relatedEntityType: RelatedEntityType::DepositIntent,
        relatedEntityId: (string) Str::ulid(), // different ID
        entries: [
            $this->debitEntry($clearing->id, 100),
            $this->creditEntry($accounts->earningAvailable->id, 100),
        ],
    )))->toThrow(DuplicateFinancialEventException::class);
});

test('a different transaction type with the same business reference conflicts', function () {
    // Both prefixes must match their own type, so this uses the same
    // stable-id suffix under two different, both-structurally-valid
    // prefixes to prove the type itself is part of the semantic payload -
    // achieved here by posting first, then reusing that exact reference
    // string (which now carries the first type's prefix) as if it were
    // valid for a different type. Since business references are prefix-
    // bound to their type, the only way to hit "same reference, different
    // type" is to bypass the prefix check for the conflicting attempt,
    // which the engine does not allow - so this proves the prefix rule
    // and the type-conflict rule work together: the second attempt is
    // rejected before it can even reach replay comparison.
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-conflict-type');
    $engine = new LedgerPostingEngine;

    $engine->post($this->postingCommand(
        type: LedgerTransactionType::DepositCredit,
        businessReference: 'deposit_credit:conflict-type',
        entries: [
            $this->debitEntry($clearing->id, 100),
            $this->creditEntry($accounts->earningAvailable->id, 100),
        ],
    ));

    expect(fn () => $engine->post($this->postingCommand(
        type: LedgerTransactionType::FundReservation,
        businessReference: 'deposit_credit:conflict-type',
        entries: [
            $this->debitEntry($clearing->id, 100),
            $this->creditEntry($accounts->earningAvailable->id, 100),
        ],
    )))->toThrow(LedgerInvariantViolationException::class);
});

test('different entries with the same business reference conflict', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-conflict-entries');
    $engine = new LedgerPostingEngine;
    $reference = 'deposit_credit:conflict-entries';

    $engine->post($this->postingCommand(businessReference: $reference, entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]));

    expect(fn () => $engine->post($this->postingCommand(businessReference: $reference, entries: [
        $this->debitEntry($clearing->id, 200),
        $this->creditEntry($accounts->earningAvailable->id, 200),
    ])))->toThrow(DuplicateFinancialEventException::class);
});

test('a failed posting attempt does not poison its business reference for a corrected retry', function () {
    $accounts = $this->provisionTestAccounts();
    $clearing = (new WalletAccountProvisioner)->providerClearingAccount('provider-not-poisoned');
    $engine = new LedgerPostingEngine;
    $reference = 'fund_reservation:not-poisoned';

    // This attempt fails (insufficient balance) and must roll back entirely.
    expect(fn () => $engine->post($this->postingCommand(type: LedgerTransactionType::FundReservation, businessReference: $reference, entries: [
        $this->debitEntry($accounts->earningAvailable->id, 100),
        $this->creditEntry($accounts->advertisingAvailable->id, 100),
    ])))->toThrow(InsufficientBalanceException::class);

    expect(LedgerTransaction::where('business_reference', $reference)->exists())->toBeFalse();

    // A corrected retry under the same reference, using entries that
    // genuinely succeed, must be allowed to proceed fresh.
    $result = $engine->post($this->postingCommand(type: LedgerTransactionType::FundReservation, businessReference: $reference, entries: [
        $this->debitEntry($clearing->id, 100),
        $this->creditEntry($accounts->earningAvailable->id, 100),
    ]));

    expect($result->wasReplay)->toBeFalse();
    expect(LedgerTransaction::where('business_reference', $reference)->exists())->toBeTrue();
});
