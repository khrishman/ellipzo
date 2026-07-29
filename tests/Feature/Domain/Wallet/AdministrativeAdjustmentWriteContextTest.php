<?php

use App\Domain\Wallet\Enums\AdministrativeAdjustmentDirection;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Exceptions\ConflictingAdministrativeAdjustmentAuditEventException;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Models\LedgerTransaction;
use App\Domain\Wallet\Services\AdministrativeAdjustmentService;
use App\Domain\Wallet\Services\AdministrativeAdjustmentWriteContext;
use App\Domain\Wallet\Services\WalletAccountProvisioner;
use App\Models\User;
use Illuminate\Support\Str;

test('the context is inactive when no run() call is active', function () {
    expect(AdministrativeAdjustmentWriteContext::isActive())->toBeFalse();
});

test('a single run() call activates the context only inside the callback', function () {
    expect(AdministrativeAdjustmentWriteContext::isActive())->toBeFalse();

    $activeDuringCallback = null;
    AdministrativeAdjustmentWriteContext::run(function () use (&$activeDuringCallback): void {
        $activeDuringCallback = AdministrativeAdjustmentWriteContext::isActive();
    });

    expect($activeDuringCallback)->toBeTrue();
    expect(AdministrativeAdjustmentWriteContext::isActive())->toBeFalse();
});

test('nested run() calls maintain correct depth, and only the outermost exit deactivates the context', function () {
    $states = [];

    AdministrativeAdjustmentWriteContext::run(function () use (&$states): void {
        $states[] = AdministrativeAdjustmentWriteContext::isActive(); // depth 1
        AdministrativeAdjustmentWriteContext::run(function () use (&$states): void {
            $states[] = AdministrativeAdjustmentWriteContext::isActive(); // depth 2
        });
        $states[] = AdministrativeAdjustmentWriteContext::isActive(); // back to depth 1, still active
    });
    $states[] = AdministrativeAdjustmentWriteContext::isActive(); // depth 0

    expect($states)->toBe([true, true, true, false]);
});

test('returning normally from run() restores depth to zero', function () {
    AdministrativeAdjustmentWriteContext::run(fn () => 'result');

    expect(AdministrativeAdjustmentWriteContext::isActive())->toBeFalse();
});

test('throwing from the callback restores depth to zero', function () {
    try {
        AdministrativeAdjustmentWriteContext::run(function (): void {
            throw new RuntimeException('forced failure inside run()');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(AdministrativeAdjustmentWriteContext::isActive())->toBeFalse();
});

test('nested run() where the inner call throws still restores depth to zero, not just to the outer level', function () {
    try {
        AdministrativeAdjustmentWriteContext::run(function (): void {
            AdministrativeAdjustmentWriteContext::run(function (): void {
                throw new RuntimeException('forced failure in the inner run()');
            });
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(AdministrativeAdjustmentWriteContext::isActive())->toBeFalse();
});

test('a successful administrative adjustment does not leave the context active', function () {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);

    app(AdministrativeAdjustmentService::class)->submit($this->adjustmentCommand($actor, $target));

    expect(AdministrativeAdjustmentWriteContext::isActive())->toBeFalse();
});

test('a replayed administrative adjustment does not leave the context active', function () {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);

    $service = app(AdministrativeAdjustmentService::class);
    $command = $this->adjustmentCommand($actor, $target);

    $service->submit($command);
    expect(AdministrativeAdjustmentWriteContext::isActive())->toBeFalse();

    $result = $service->submit($command);

    expect($result->wasReplay)->toBeTrue();
    expect(AdministrativeAdjustmentWriteContext::isActive())->toBeFalse();
});

test('an insufficient-balance failure does not leave the context active', function () {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);

    $service = app(AdministrativeAdjustmentService::class);

    try {
        $service->submit($this->adjustmentCommand(
            $actor,
            $target,
            WalletAccountType::EarningAvailable,
            AdministrativeAdjustmentDirection::Decrease,
            10_000_000,
        ));
    } catch (InsufficientBalanceException) {
        // expected - the account has no balance to decrease from
    }

    expect(AdministrativeAdjustmentWriteContext::isActive())->toBeFalse();
});

test('a forced audit failure does not leave the context active', function () {
    $actor = $this->ledgerAdjustActor();
    $target = User::factory()->create();
    (new WalletAccountProvisioner)->provisionUserAccounts($target);

    $forcedTransactionId = strtolower((string) Str::ulid());
    $command = $this->adjustmentCommand($actor, $target);

    $this->insertRawAuditEvent([
        'actor_id' => $actor->id,
        'entity_type' => 'ledger_transaction',
        'entity_key' => $forcedTransactionId,
        'action' => 'ledger.administrative_adjustment',
        'reason' => $command->internalReason,
        'correlation_id' => $command->correlationId,
    ]);

    $this->withIsolatedCreatingListener(
        LedgerTransaction::class,
        function (LedgerTransaction $model) use ($forcedTransactionId, $command): void {
            if ($model->business_reference === $command->businessReference()) {
                $model->id = $forcedTransactionId;
            }
        },
        function () use ($command): void {
            try {
                app(AdministrativeAdjustmentService::class)->submit($command);
            } catch (ConflictingAdministrativeAdjustmentAuditEventException) {
                // expected
            }
        },
    );

    expect(AdministrativeAdjustmentWriteContext::isActive())->toBeFalse();
});
