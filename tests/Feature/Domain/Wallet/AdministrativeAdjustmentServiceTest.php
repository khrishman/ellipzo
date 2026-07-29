<?php

use App\Domain\Shared\Exceptions\MoneyOverflowException;
use App\Domain\Wallet\Data\PostLedgerTransactionCommand;
use App\Domain\Wallet\Enums\AdministrativeAdjustmentDirection;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Exceptions\ConflictingAdministrativeAdjustmentAuditEventException;
use App\Domain\Wallet\Exceptions\DuplicateFinancialEventException;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\InvalidAdministrativeAdjustmentTargetException;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Domain\Wallet\Exceptions\MissingAdministrativeAdjustmentAuditEventException;
use App\Domain\Wallet\Exceptions\SelfAdjustmentNotPermittedException;
use App\Domain\Wallet\Exceptions\UnknownWalletAccountException;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Models\WalletAccount;
use App\Domain\Wallet\Services\AdministrativeAdjustmentService;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Enums\AccountStatus;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Exceptions\UnauthorizedException;

test('a bare LedgerPostingEngine caller cannot post an administrative adjustment at all, bypassing every AdministrativeAdjustmentService check', function () {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($target);

    // A caller with bare LedgerPostingEngine access - no permission check,
    // no self-adjustment check, no target-account restriction, no audit
    // enforcement - attempts to construct a generic command moving value
    // directly between the target's own two accounts, entirely outside
    // AdministrativeAdjustmentService. This must be impossible to even
    // construct, let alone post.
    expect(fn () => new PostLedgerTransactionCommand(
        LedgerTransactionType::AdministrativeAdjustment,
        'administrative_adjustment:bypass-attempt-'.strtolower((string) Str::ulid()),
        (string) Str::uuid(),
        'Attempted bypass',
        $actor,
        null,
        null,
        [
            $this->debitEntry($accounts->earningAvailable->id, 10_000_000),
            $this->creditEntry($accounts->advertisingAvailable->id, 10_000_000),
        ],
    ))->toThrow(LedgerInvariantViolationException::class);

    // post() itself never even gets a chance to run: it only ever accepts
    // an already-validated PostLedgerTransactionCommand, which - as just
    // proven - can never carry this type. No engine call is possible to
    // even attempt.
    expect(LedgerTransaction::count())->toBe(0);
    expect(LedgerEntry::count())->toBe(0);
    expect(WalletAccount::where('account_type', WalletAccountType::PlatformSuspense->value)->count())->toBe(0);
    expect(AuditEvent::count())->toBe(0);
});

test('an increase credits the target account and debits platform_suspense', function (WalletAccountType $type) {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($target);
    $targetAccount = $type === WalletAccountType::EarningAvailable ? $accounts->earningAvailable : $accounts->advertisingAvailable;

    $result = app(AdministrativeAdjustmentService::class)->submit(
        $this->adjustmentCommand($actor, $target, $type, AdministrativeAdjustmentDirection::Increase, 10_000_000),
    );

    expect($result->wasReplay)->toBeFalse();
    expect(LedgerEntry::where('ledger_transaction_id', $result->transaction->id)->count())->toBe(2);

    $suspenseAccount = (new WalletAccountProvisioner)->platformSuspenseAccount();
    $targetEntry = LedgerEntry::where('wallet_account_id', $targetAccount->id)->where('ledger_transaction_id', $result->transaction->id)->first();
    $suspenseEntry = LedgerEntry::where('wallet_account_id', $suspenseAccount->id)->where('ledger_transaction_id', $result->transaction->id)->first();

    expect($targetEntry->entry_type)->toBe(LedgerEntryType::Credit);
    expect($targetEntry->amount_atomic)->toBe(10_000_000);
    expect($suspenseEntry->entry_type)->toBe(LedgerEntryType::Debit);
    expect($suspenseEntry->amount_atomic)->toBe(10_000_000);
})->with([
    'earning_available' => [WalletAccountType::EarningAvailable],
    'advertising_available' => [WalletAccountType::AdvertisingAvailable],
]);

test('a decrease debits the target account and credits platform_suspense', function (WalletAccountType $type) {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($target);
    $targetAccount = $type === WalletAccountType::EarningAvailable ? $accounts->earningAvailable : $accounts->advertisingAvailable;

    $this->fundAccount($targetAccount, 50_000_000);

    $result = app(AdministrativeAdjustmentService::class)->submit(
        $this->adjustmentCommand($actor, $target, $type, AdministrativeAdjustmentDirection::Decrease, 10_000_000),
    );

    $suspenseAccount = (new WalletAccountProvisioner)->platformSuspenseAccount();
    $targetEntry = LedgerEntry::where('wallet_account_id', $targetAccount->id)->where('ledger_transaction_id', $result->transaction->id)->first();
    $suspenseEntry = LedgerEntry::where('wallet_account_id', $suspenseAccount->id)->where('ledger_transaction_id', $result->transaction->id)->first();

    expect($targetEntry->entry_type)->toBe(LedgerEntryType::Debit);
    expect($targetEntry->amount_atomic)->toBe(10_000_000);
    expect($suspenseEntry->entry_type)->toBe(LedgerEntryType::Credit);
    expect($suspenseEntry->amount_atomic)->toBe(10_000_000);
})->with([
    'earning_available' => [WalletAccountType::EarningAvailable],
    'advertising_available' => [WalletAccountType::AdvertisingAvailable],
]);

test('a target account type outside the approved allowlist is rejected before any database write', function (WalletAccountType $type) {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);

    expect(fn () => $this->adjustmentCommand($actor, $target, $type))
        ->toThrow(InvalidAdministrativeAdjustmentTargetException::class);

    expect(LedgerTransaction::count())->toBe(0);
})->with([
    'earning_held' => [WalletAccountType::EarningHeld],
    'advertising_reserved' => [WalletAccountType::AdvertisingReserved],
    'platform_fee' => [WalletAccountType::PlatformFee],
    'provider_settlement_clearing' => [WalletAccountType::ProviderSettlementClearing],
    'platform_suspense' => [WalletAccountType::PlatformSuspense],
]);

test('a target user with no provisioned wallet accounts is rejected as unknown', function () {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create(); // deliberately never provisioned

    expect(fn () => app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand($actor, $target)))
        ->toThrow(UnknownWalletAccountException::class);

    expect(LedgerTransaction::count())->toBe(0);
    expect(AuditEvent::count())->toBe(0);
});

test('an actor without ledger.adjust permission is denied', function () {
    $actor = $this->nonLedgerAdjustActor();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);

    expect(fn () => app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand($actor, $target)))
        ->toThrow(UnauthorizedException::class);

    expect(LedgerTransaction::count())->toBe(0);
    expect(AuditEvent::count())->toBe(0);
});

test('a suspended actor is denied even with ledger.adjust permission', function () {
    $actor = $this->ledgerAdjustActor();
    $actor->forceFill(['account_status' => AccountStatus::Suspended])->save();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);

    expect(fn () => app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand($actor, $target)))
        ->toThrow(UnauthorizedException::class);

    expect(LedgerTransaction::count())->toBe(0);
});

test('a closed actor is denied even with ledger.adjust permission', function () {
    $actor = $this->ledgerAdjustActor();
    $actor->forceFill(['account_status' => AccountStatus::Closed])->save();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);

    expect(fn () => app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand($actor, $target)))
        ->toThrow(UnauthorizedException::class);

    expect(LedgerTransaction::count())->toBe(0);
});

test('a staff actor cannot submit an adjustment against their own account', function () {
    $actor = $this->ledgerAdjustActor();
    (new WalletAccountProvisioner)->provisionUserAccounts($actor);

    expect(fn () => app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand($actor, $actor)))
        ->toThrow(SelfAdjustmentNotPermittedException::class);

    expect(LedgerTransaction::count())->toBe(0);
});

test('a corrective adjustment is not blocked because the target user is limited, suspended, or closed', function (AccountStatus $status) {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);
    $target->forceFill(['account_status' => $status])->save();

    $result = app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand($actor, $target));

    expect($result->wasReplay)->toBeFalse();
})->with([
    'limited' => [AccountStatus::Limited],
    'suspended' => [AccountStatus::Suspended],
    'closed' => [AccountStatus::Closed],
]);

test('a decrease exceeding the available balance is rejected with zero new rows committed', function () {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($target);
    $this->fundAccount($accounts->earningAvailable, 5_000_000);

    $transactionCountBefore = LedgerTransaction::count();
    $entryCountBefore = LedgerEntry::count();

    expect(fn () => app(AdministrativeAdjustmentService::class)->submit(
        $this->adjustmentCommand($actor, $target, WalletAccountType::EarningAvailable, AdministrativeAdjustmentDirection::Decrease, 50_000_000),
    ))->toThrow(InsufficientBalanceException::class);

    expect(LedgerTransaction::count())->toBe($transactionCountBefore);
    expect(LedgerEntry::count())->toBe($entryCountBefore);
    expect(AuditEvent::count())->toBe(0);
});

test('arithmetic overflow during balance derivation propagates the existing MoneyOverflowException', function () {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($target);
    $this->fundAccount($accounts->earningAvailable, PHP_INT_MAX);

    expect(fn () => app(AdministrativeAdjustmentService::class)->submit(
        $this->adjustmentCommand($actor, $target, WalletAccountType::EarningAvailable, AdministrativeAdjustmentDirection::Increase, 1),
    ))->toThrow(MoneyOverflowException::class);
});

test('a forced audit-insert collision on a genuinely new posting rolls back the ledger transaction, entries, and the newly created suspense account', function () {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);

    $forcedTransactionId = strtolower((string) Str::ulid());
    $command = $this->adjustmentCommand($actor, $target, WalletAccountType::EarningAvailable, AdministrativeAdjustmentDirection::Increase, 10_000_000);

    // Pre-insert a raw audit_events row already claiming that specific,
    // not-yet-existing ledger transaction ID, with a payload deliberately
    // built to match exactly what the real adjustment would produce -
    // proving a "matching" pre-existing audit is still never accepted as
    // success for a genuinely new posting.
    $this->insertRawAuditEvent([
        'actor_id' => $actor->id,
        'entity_type' => 'ledger_transaction',
        'entity_key' => $forcedTransactionId,
        'action' => 'ledger.administrative_adjustment',
        'reason' => $command->internalReason,
        'correlation_id' => $command->correlationId,
    ]);

    expect(WalletAccount::where('account_type', WalletAccountType::PlatformSuspense->value)->count())->toBe(0);

    $this->withIsolatedCreatingListener(
        LedgerTransaction::class,
        function (LedgerTransaction $model) use ($forcedTransactionId, $command): void {
            if ($model->business_reference === $command->businessReference()) {
                $model->id = $forcedTransactionId;
            }
        },
        function () use ($command): void {
            expect(fn () => app(AdministrativeAdjustmentService::class)->submit($command))
                ->toThrow(ConflictingAdministrativeAdjustmentAuditEventException::class);
        },
    );

    expect(LedgerTransaction::where('business_reference', $command->businessReference())->exists())->toBeFalse();
    expect(LedgerEntry::count())->toBe(0);
    expect(WalletAccount::where('account_type', WalletAccountType::PlatformSuspense->value)->count())->toBe(0);
});

test('a successful replay with the same idempotency key returns the original transaction and creates no second audit event', function () {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);

    $service = app(AdministrativeAdjustmentService::class);
    $command = $this->adjustmentCommand($actor, $target);

    $first = $service->submit($command);
    $second = $service->submit($command);

    expect($first->wasReplay)->toBeFalse();
    expect($second->wasReplay)->toBeTrue();
    expect($second->transaction->id)->toBe($first->transaction->id);
    expect($second->auditEvent->id)->toBe($first->auditEvent->id);
    expect(AuditEvent::where('entity_key', $first->transaction->id)->count())->toBe(1);
    expect(LedgerTransaction::where('business_reference', $command->businessReference())->count())->toBe(1);
});

test('a replay with a new correlation ID returns the original committed correlation ID', function () {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);

    $service = app(AdministrativeAdjustmentService::class);
    $idempotencyKey = strtolower((string) Str::ulid());

    $first = $service->submit($this->adjustmentCommand($actor, $target, idempotencyKey: $idempotencyKey, correlationId: (string) Str::uuid()));
    $second = $service->submit($this->adjustmentCommand($actor, $target, idempotencyKey: $idempotencyKey, correlationId: (string) Str::uuid()));

    expect($second->wasReplay)->toBeTrue();
    expect($second->transaction->correlation_id)->toBe($first->transaction->correlation_id);
    expect($second->auditEvent->correlation_id)->toBe($first->auditEvent->correlation_id);
});

test('the same idempotency key with a different amount is rejected as a conflicting duplicate event', function () {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);

    $service = app(AdministrativeAdjustmentService::class);
    $idempotencyKey = strtolower((string) Str::ulid());

    $service->submit($this->adjustmentCommand($actor, $target, idempotencyKey: $idempotencyKey, amountAtomic: 10_000_000));

    expect(fn () => $service->submit($this->adjustmentCommand($actor, $target, idempotencyKey: $idempotencyKey, amountAtomic: 20_000_000)))
        ->toThrow(DuplicateFinancialEventException::class);
});

test('a replayed transaction with no corresponding audit event is a typed invariant failure', function () {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($target);
    $suspense = (new WalletAccountProvisioner)->platformSuspenseAccount();

    $command = $this->adjustmentCommand($actor, $target);

    // A ledger transaction matching exactly what the service would
    // produce, inserted directly - bypassing AdministrativeAdjustmentService
    // entirely - to simulate an operational anomaly (a committed
    // adjustment with no audit trail), never a reachable production path
    // through the service itself.
    $transactionId = $this->insertRawLedgerTransaction([
        'business_reference' => $command->businessReference(),
        'type' => 'administrative_adjustment',
        'description' => $command->userVisibleDescription,
        'actor_id' => $actor->id,
        'correlation_id' => $command->correlationId,
    ]);
    $this->insertRawLedgerEntry([
        'ledger_transaction_id' => $transactionId,
        'wallet_account_id' => $accounts->earningAvailable->id,
        'entry_type' => 'credit',
        'amount_atomic' => 10_000_000,
    ]);
    $this->insertRawLedgerEntry([
        'ledger_transaction_id' => $transactionId,
        'wallet_account_id' => $suspense->id,
        'entry_type' => 'debit',
        'amount_atomic' => 10_000_000,
    ]);

    expect(fn () => app(AdministrativeAdjustmentService::class)->submit($command))
        ->toThrow(MissingAdministrativeAdjustmentAuditEventException::class);
});

test('a replayed transaction with a conflicting audit event is a typed conflict failure', function () {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($target);
    $suspense = (new WalletAccountProvisioner)->platformSuspenseAccount();

    $command = $this->adjustmentCommand($actor, $target);

    $transactionId = $this->insertRawLedgerTransaction([
        'business_reference' => $command->businessReference(),
        'type' => 'administrative_adjustment',
        'description' => $command->userVisibleDescription,
        'actor_id' => $actor->id,
        'correlation_id' => $command->correlationId,
    ]);
    $this->insertRawLedgerEntry([
        'ledger_transaction_id' => $transactionId,
        'wallet_account_id' => $accounts->earningAvailable->id,
        'entry_type' => 'credit',
        'amount_atomic' => 10_000_000,
    ]);
    $this->insertRawLedgerEntry([
        'ledger_transaction_id' => $transactionId,
        'wallet_account_id' => $suspense->id,
        'entry_type' => 'debit',
        'amount_atomic' => 10_000_000,
    ]);

    // A pre-existing audit event for the same transaction, but with a
    // reason that does not match the command's own internal reason.
    $this->insertRawAuditEvent([
        'actor_id' => $actor->id,
        'entity_type' => 'ledger_transaction',
        'entity_key' => $transactionId,
        'action' => 'ledger.administrative_adjustment',
        'reason' => 'A completely different reason than the command carries.',
        'correlation_id' => $command->correlationId,
        'after_state' => json_encode([
            'target_wallet_account_id' => $accounts->earningAvailable->id,
            'target_account_type' => 'earning_available',
            'direction' => 'increase',
            'amount_atomic' => '10000000',
            'currency' => 'USD',
            'business_reference' => $command->businessReference(),
        ]),
    ]);

    expect(fn () => app(AdministrativeAdjustmentService::class)->submit($command))
        ->toThrow(ConflictingAdministrativeAdjustmentAuditEventException::class);
});

test('the audit event records exactly the allowlisted safe fields and nothing else', function () {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    $accounts = (new WalletAccountProvisioner)->provisionUserAccounts($target);

    $result = app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand(
        $actor,
        $target,
        WalletAccountType::EarningAvailable,
        AdministrativeAdjustmentDirection::Increase,
        10_000_000,
        internalReason: 'Correcting a duplicated reward payout discovered during audit.',
    ));

    expect($result->auditEvent->action)->toBe('ledger.administrative_adjustment');
    expect($result->auditEvent->entity_type)->toBe('ledger_transaction');
    expect($result->auditEvent->entity_key)->toBe($result->transaction->id);
    expect($result->auditEvent->entity_id)->toBeNull();
    expect($result->auditEvent->actor_id)->toBe($actor->id);
    expect($result->auditEvent->reason)->toBe('Correcting a duplicated reward payout discovered during audit.');
    expect($result->auditEvent->correlation_id)->toBe($result->transaction->correlation_id);
    expect($result->auditEvent->before_state)->toBe([]);
    expect($result->auditEvent->after_state)->toBe([
        'target_wallet_account_id' => $accounts->earningAvailable->id,
        'target_account_type' => 'earning_available',
        'direction' => 'increase',
        'amount_atomic' => '10000000',
        'currency' => 'USD',
        'business_reference' => $result->transaction->business_reference,
    ]);
});

test('audit before/after state never contains a raw model, request, or the target user email', function () {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create(['email' => 'distinctive-adjustment-target@example.com', 'name' => 'Distinctive Adjustment Target']);
    (new WalletAccountProvisioner)->provisionUserAccounts($target);

    $result = app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand($actor, $target));

    $encoded = json_encode([$result->auditEvent->before_state, $result->auditEvent->after_state]);

    expect($encoded)->not->toContain('distinctive-adjustment-target@example.com');
    expect($encoded)->not->toContain('Distinctive Adjustment Target');
});

test('AdministrativeAdjustmentService never uses raw ledger writes or direct model mutation', function () {
    $source = file_get_contents(app_path('Domain/Wallet/Services/AdministrativeAdjustmentService.php'));

    expect($source)->not->toContain('DB::table(');
    expect($source)->not->toContain('DB::insert(');
    expect($source)->not->toContain('DB::statement(');
    expect($source)->not->toContain('DB::unprepared(');
    expect($source)->not->toContain('->update(');
    expect($source)->not->toContain('forceCreate(');
    expect($source)->not->toContain('->create(');
});
