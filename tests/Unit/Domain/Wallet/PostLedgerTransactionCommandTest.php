<?php

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;
use App\Domain\Wallet\Data\PostLedgerEntryCommand;
use App\Domain\Wallet\Data\PostLedgerTransactionCommand;
use App\Domain\Wallet\Enums\LedgerEntryType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\RelatedEntityType;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Models\User;
use Illuminate\Support\Str;

function makeCreditEntry(?string $id = null, int $amount = 1000): PostLedgerEntryCommand
{
    return new PostLedgerEntryCommand($id ?? (string) Str::ulid(), LedgerEntryType::Credit, Money::fromAtomic($amount, Currency::USD));
}

function makeDebitEntry(?string $id = null, int $amount = 1000): PostLedgerEntryCommand
{
    return new PostLedgerEntryCommand($id ?? (string) Str::ulid(), LedgerEntryType::Debit, Money::fromAtomic($amount, Currency::USD));
}

function makeInMemoryPersistedUser(int $id): User
{
    $user = new User;
    $user->exists = true;
    $user->id = $id;

    return $user;
}

function validCommandArgs(array $overrides = []): array
{
    return array_merge([
        'type' => LedgerTransactionType::DepositCredit,
        'businessReference' => 'deposit_credit:'.Str::lower((string) Str::ulid()),
        'correlationId' => (string) Str::uuid(),
        'description' => 'Test posting',
        'actor' => null,
        'relatedEntityType' => null,
        'relatedEntityId' => null,
        'entries' => [makeDebitEntry(), makeCreditEntry()],
    ], $overrides);
}

function makeCommand(array $overrides = []): PostLedgerTransactionCommand
{
    $args = validCommandArgs($overrides);

    return new PostLedgerTransactionCommand(
        $args['type'],
        $args['businessReference'],
        $args['correlationId'],
        $args['description'],
        $args['actor'],
        $args['relatedEntityType'],
        $args['relatedEntityId'],
        $args['entries'],
    );
}

test('a valid balanced two-entry command constructs successfully', function () {
    $command = makeCommand();

    expect($command->entries)->toHaveCount(2);
    expect($command->type)->toBe(LedgerTransactionType::DepositCredit);
});

test('a valid balanced multi-entry command constructs successfully', function () {
    $command = makeCommand([
        'entries' => [makeDebitEntry(amount: 300), makeCreditEntry(amount: 100), makeCreditEntry(amount: 200)],
    ]);

    expect($command->entries)->toHaveCount(3);
});

test('reversal transactions are rejected outright', function () {
    expect(fn () => makeCommand(['type' => LedgerTransactionType::Reversal, 'businessReference' => 'reversal:x']))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('administrative adjustment transactions are rejected outright', function () {
    expect(fn () => makeCommand(['type' => LedgerTransactionType::AdministrativeAdjustment, 'businessReference' => 'administrative_adjustment:x']))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('an unbalanced command is rejected', function () {
    expect(fn () => makeCommand(['entries' => [makeDebitEntry(amount: 100), makeCreditEntry(amount: 200)]]))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a single entry is rejected', function () {
    expect(fn () => makeCommand(['entries' => [makeDebitEntry()]]))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a non-array entries value is rejected', function (mixed $entries) {
    expect(fn () => makeCommand(['entries' => $entries]))
        ->toThrow(LedgerInvariantViolationException::class);
})->with([
    'string' => ['not-an-array'],
    'integer' => [123],
    'null' => [null],
]);

test('a non-list (associative) entries array is rejected', function () {
    expect(fn () => makeCommand(['entries' => ['a' => makeDebitEntry(), 'b' => makeCreditEntry()]]))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a non-command object inside the entries list is rejected', function () {
    expect(fn () => makeCommand(['entries' => [makeDebitEntry(), 'not-a-command']]))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('duplicate wallet accounts within one posting are rejected, not collapsed', function () {
    $id = (string) Str::ulid();

    expect(fn () => makeCommand(['entries' => [makeDebitEntry($id, 100), makeCreditEntry($id, 100)]]))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('weak-coercion values for business reference, correlation ID, and description are rejected', function (mixed $value) {
    expect(fn () => makeCommand(['businessReference' => $value]))->toThrow(LedgerInvariantViolationException::class);
    expect(fn () => makeCommand(['correlationId' => $value]))->toThrow(LedgerInvariantViolationException::class);
    expect(fn () => makeCommand(['description' => $value]))->toThrow(LedgerInvariantViolationException::class);
})->with([
    'integer' => [123],
    'boolean' => [true],
    'float' => [1.5],
    'array' => [['x']],
]);

test('an empty business reference is rejected', function () {
    expect(fn () => makeCommand(['businessReference' => '']))->toThrow(LedgerInvariantViolationException::class);
});

test('a business reference with invalid characters is rejected', function () {
    expect(fn () => makeCommand(['businessReference' => 'deposit_credit:has a space']))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a business reference missing its transaction-type prefix is rejected', function () {
    expect(fn () => makeCommand(['type' => LedgerTransactionType::DepositCredit, 'businessReference' => 'withdrawal_hold:abc']))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a business reference that is only the prefix with nothing after it is rejected', function () {
    expect(fn () => makeCommand(['businessReference' => 'deposit_credit:']))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a business reference is normalized to lowercase', function () {
    $reference = 'deposit_credit:'.strtoupper((string) Str::ulid());

    $command = makeCommand(['businessReference' => $reference]);

    expect($command->businessReference)->toBe(strtolower($reference));
});

test('a correlation ID may be a canonical UUID, normalized to lowercase', function () {
    $uuid = strtoupper((string) Str::uuid());

    $command = makeCommand(['correlationId' => $uuid]);

    expect($command->correlationId)->toBe(strtolower($uuid));
});

test('a correlation ID may be a canonical ULID, normalized to lowercase', function () {
    $ulid = strtoupper((string) Str::ulid());

    $command = makeCommand(['correlationId' => $ulid]);

    expect($command->correlationId)->toBe(strtolower($ulid));
});

test('a correlation ID that is neither a UUID nor a ULID is rejected', function () {
    expect(fn () => makeCommand(['correlationId' => 'not-a-real-id']))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('an empty description is rejected', function () {
    expect(fn () => makeCommand(['description' => '']))->toThrow(LedgerInvariantViolationException::class);
});

test('a description longer than 255 characters is rejected', function () {
    expect(fn () => makeCommand(['description' => str_repeat('a', 256)]))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a multiline description is rejected', function () {
    expect(fn () => makeCommand(['description' => "line one\nline two"]))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a description containing a control character is rejected', function () {
    expect(fn () => makeCommand(['description' => "bad\x07description"]))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('description casing is preserved exactly', function () {
    $command = makeCommand(['description' => 'Exact Casing Preserved']);

    expect($command->description)->toBe('Exact Casing Preserved');
});

test('related entity type without an ID is rejected', function () {
    expect(fn () => makeCommand(['relatedEntityType' => RelatedEntityType::DepositIntent, 'relatedEntityId' => null]))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('related entity ID without a type is rejected', function () {
    expect(fn () => makeCommand(['relatedEntityType' => null, 'relatedEntityId' => (string) Str::ulid()]))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a valid related entity pair is accepted and its ULID-shaped ID is canonicalized', function () {
    $id = strtoupper((string) Str::ulid());

    $command = makeCommand(['relatedEntityType' => RelatedEntityType::DepositIntent, 'relatedEntityId' => $id]);

    expect($command->relatedEntityType)->toBe(RelatedEntityType::DepositIntent);
    expect($command->relatedEntityId)->toBe(strtolower($id));
});

test('a non-ULID/UUID related entity ID is preserved as-is once trimmed', function () {
    $command = makeCommand(['relatedEntityType' => RelatedEntityType::Campaign, 'relatedEntityId' => '  some-other-id-42  ']);

    expect($command->relatedEntityId)->toBe('some-other-id-42');
});

test('a non-string related entity ID is rejected', function () {
    expect(fn () => makeCommand(['relatedEntityType' => RelatedEntityType::Campaign, 'relatedEntityId' => 123]))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('an unsaved actor is rejected', function () {
    expect(fn () => makeCommand(['actor' => new User]))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a persisted actor is accepted and its ID is stored', function () {
    $command = makeCommand(['actor' => makeInMemoryPersistedUser(42)]);

    expect($command->actorId)->toBe(42);
});

test('a null actor is accepted', function () {
    $command = makeCommand(['actor' => null]);

    expect($command->actorId)->toBeNull();
});
