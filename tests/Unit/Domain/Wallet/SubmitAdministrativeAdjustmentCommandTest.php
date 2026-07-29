<?php

use App\Domain\Shared\Exceptions\NonPositiveAmountException;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\SubmitAdministrativeAdjustmentCommand;
use App\Domain\Wallet\Enums\AdministrativeAdjustmentDirection;
use App\Domain\Wallet\Enums\WalletAccountType;
use App\Domain\Wallet\Exceptions\InvalidAdministrativeAdjustmentTargetException;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * No database needed: a persisted-in-memory-only User (exists/id set
 * directly, never saved) is sufficient for every check this DTO performs,
 * mirroring the same technique already established in
 * WalletAccountProvisionerTransactionPreconditionTest.php.
 */
function makeAdjustmentTestUser(int $id): User
{
    $user = new User;
    $user->exists = true;
    $user->id = $id;

    return $user;
}

function validAdjustmentArgs(): array
{
    return [
        makeAdjustmentTestUser(1),
        makeAdjustmentTestUser(2),
        WalletAccountType::EarningAvailable,
        AdministrativeAdjustmentDirection::Increase,
        Money::fromAtomic(1, Currency::USD),
        'A perfectly valid reason of sufficient length.',
        null,
        'valid-key',
        (string) Str::uuid(),
    ];
}

test('a valid command normalizes its idempotency key and correlation ID, and defaults its description', function () {
    $command = new SubmitAdministrativeAdjustmentCommand(
        makeAdjustmentTestUser(1),
        makeAdjustmentTestUser(2),
        WalletAccountType::EarningAvailable,
        AdministrativeAdjustmentDirection::Increase,
        Money::fromAtomic(10_000_000, Currency::USD),
        'A perfectly valid ten-plus-character reason.',
        null,
        '  Some-Key  ',
        '550E8400-E29B-41D4-A716-446655440000',
    );

    expect($command->idempotencyKey)->toBe('some-key');
    expect($command->correlationId)->toBe('550e8400-e29b-41d4-a716-446655440000');
    expect($command->userVisibleDescription)->toBe(SubmitAdministrativeAdjustmentCommand::DEFAULT_USER_VISIBLE_DESCRIPTION);
    expect($command->userVisibleDescription)->toBe('Administrative balance adjustment');
    expect($command->businessReference())->toBe('administrative_adjustment:some-key');
});

test('an unsaved actor is rejected', function () {
    $args = validAdjustmentArgs();
    $args[0] = new User; // never saved

    expect(fn () => new SubmitAdministrativeAdjustmentCommand(...$args))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('an unsaved target user is rejected', function () {
    $args = validAdjustmentArgs();
    $args[1] = new User; // never saved

    expect(fn () => new SubmitAdministrativeAdjustmentCommand(...$args))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a disallowed target account type is rejected', function (WalletAccountType $type) {
    $args = validAdjustmentArgs();
    $args[2] = $type;

    expect(fn () => new SubmitAdministrativeAdjustmentCommand(...$args))
        ->toThrow(InvalidAdministrativeAdjustmentTargetException::class);
})->with([
    'earning_held' => [WalletAccountType::EarningHeld],
    'advertising_reserved' => [WalletAccountType::AdvertisingReserved],
    'platform_fee' => [WalletAccountType::PlatformFee],
    'provider_settlement_clearing' => [WalletAccountType::ProviderSettlementClearing],
    'platform_suspense' => [WalletAccountType::PlatformSuspense],
]);

test('a zero amount is rejected', function () {
    $args = validAdjustmentArgs();
    $args[4] = Money::fromAtomic(0, Currency::USD);

    expect(fn () => new SubmitAdministrativeAdjustmentCommand(...$args))
        ->toThrow(NonPositiveAmountException::class);
});

test('a negative amount is rejected', function () {
    $args = validAdjustmentArgs();
    $args[4] = Money::fromAtomic(-1, Currency::USD);

    expect(fn () => new SubmitAdministrativeAdjustmentCommand(...$args))
        ->toThrow(NonPositiveAmountException::class);
});

test('a non-Money amount is rejected by PHP\'s own type system before any custom validation runs', function () {
    $args = validAdjustmentArgs();
    $args[4] = '10.00'; // a decimal string, not a Money instance

    expect(fn () => new SubmitAdministrativeAdjustmentCommand(...$args))
        ->toThrow(TypeError::class);
});

test('internal reason shorter than 10 characters is rejected', function () {
    $args = validAdjustmentArgs();
    $args[5] = 'short';

    expect(fn () => new SubmitAdministrativeAdjustmentCommand(...$args))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('internal reason longer than 1000 characters is rejected', function () {
    $args = validAdjustmentArgs();
    $args[5] = str_repeat('a', 1001);

    expect(fn () => new SubmitAdministrativeAdjustmentCommand(...$args))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('internal reason exactly at the 10 and 1000 character boundaries is accepted', function () {
    $shortArgs = validAdjustmentArgs();
    $shortArgs[5] = str_repeat('a', 10);
    $shortArgs[7] = 'boundary-key-short';

    $longArgs = validAdjustmentArgs();
    $longArgs[5] = str_repeat('a', 1000);
    $longArgs[7] = 'boundary-key-long';

    $short = new SubmitAdministrativeAdjustmentCommand(...$shortArgs);
    $long = new SubmitAdministrativeAdjustmentCommand(...$longArgs);

    expect(strlen($short->internalReason))->toBe(10);
    expect(strlen($long->internalReason))->toBe(1000);
});

test('internal reason containing a control character is rejected', function () {
    $args = validAdjustmentArgs();
    $args[5] = "A reason\nwith a newline in it, otherwise long enough.";

    expect(fn () => new SubmitAdministrativeAdjustmentCommand(...$args))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a user-visible description longer than 255 characters is rejected', function () {
    $args = validAdjustmentArgs();
    $args[6] = str_repeat('a', 256);

    expect(fn () => new SubmitAdministrativeAdjustmentCommand(...$args))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a user-visible description containing a control character is rejected', function () {
    $args = validAdjustmentArgs();
    $args[6] = "line one\nline two";

    expect(fn () => new SubmitAdministrativeAdjustmentCommand(...$args))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a user-visible description is never derived from the internal reason', function () {
    $args = validAdjustmentArgs();
    $args[5] = 'This internal reason must never leak into the description field.';
    $args[6] = null;

    $command = new SubmitAdministrativeAdjustmentCommand(...$args);

    expect($command->userVisibleDescription)->not->toContain('internal reason');
    expect($command->userVisibleDescription)->toBe('Administrative balance adjustment');
});

test('an idempotency key at exactly 165 characters is accepted and 166 is rejected', function () {
    $okArgs = validAdjustmentArgs();
    $okArgs[7] = str_repeat('a', 165);

    $ok = new SubmitAdministrativeAdjustmentCommand(...$okArgs);

    expect(strlen($ok->idempotencyKey))->toBe(165);
    // 165 + strlen('administrative_adjustment:') (26) === 191, the exact
    // ledger_transactions.business_reference column width.
    expect(strlen($ok->businessReference()))->toBe(191);

    $tooLongArgs = validAdjustmentArgs();
    $tooLongArgs[7] = str_repeat('a', 166);

    expect(fn () => new SubmitAdministrativeAdjustmentCommand(...$tooLongArgs))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a correlation ID that is not a canonical UUID or ULID is rejected', function () {
    $args = validAdjustmentArgs();
    $args[8] = 'not-a-real-identifier';

    expect(fn () => new SubmitAdministrativeAdjustmentCommand(...$args))
        ->toThrow(LedgerInvariantViolationException::class);
});
