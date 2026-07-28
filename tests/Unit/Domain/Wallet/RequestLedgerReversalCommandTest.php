<?php

use App\Domain\Wallet\Data\RequestLedgerReversalCommand;
use App\Domain\Wallet\Exceptions\LedgerInvariantViolationException;
use App\Models\User;
use Illuminate\Support\Str;

function makeInMemoryPersistedReversalActor(int $id): User
{
    $user = new User;
    $user->exists = true;
    $user->id = $id;

    return $user;
}

function validReversalCommandArgs(array $overrides = []): array
{
    return array_merge([
        'originalTransactionId' => strtolower((string) Str::ulid()),
        'idempotencyKey' => 'reversal-request:'.strtolower((string) Str::ulid()),
        'correlationId' => (string) Str::uuid(),
        'reason' => 'Duplicate charge',
        'actor' => null,
    ], $overrides);
}

function makeReversalCommand(array $overrides = []): RequestLedgerReversalCommand
{
    $args = validReversalCommandArgs($overrides);

    return new RequestLedgerReversalCommand(
        $args['originalTransactionId'],
        $args['idempotencyKey'],
        $args['correlationId'],
        $args['reason'],
        $args['actor'],
    );
}

test('a valid command constructs successfully', function () {
    $command = makeReversalCommand();

    expect($command->originalTransactionId)->toBe(strtolower($command->originalTransactionId));
    expect($command->reason)->toBe('Duplicate charge');
});

test('weak-coercion values for original transaction ID, idempotency key, correlation ID, and reason are rejected', function (mixed $value) {
    expect(fn () => makeReversalCommand(['originalTransactionId' => $value]))->toThrow(LedgerInvariantViolationException::class);
    expect(fn () => makeReversalCommand(['idempotencyKey' => $value]))->toThrow(LedgerInvariantViolationException::class);
    expect(fn () => makeReversalCommand(['correlationId' => $value]))->toThrow(LedgerInvariantViolationException::class);
    expect(fn () => makeReversalCommand(['reason' => $value]))->toThrow(LedgerInvariantViolationException::class);
})->with([
    'integer' => [123],
    'boolean' => [true],
    'float' => [1.5],
    'array' => [['x']],
    'null' => [null],
]);

test('a non-ULID original transaction ID is rejected', function () {
    expect(fn () => makeReversalCommand(['originalTransactionId' => 'not-a-ulid']))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('an original transaction ID is normalized to lowercase', function () {
    $ulid = strtoupper((string) Str::ulid());

    $command = makeReversalCommand(['originalTransactionId' => $ulid]);

    expect($command->originalTransactionId)->toBe(strtolower($ulid));
});

test('an empty idempotency key is rejected', function () {
    expect(fn () => makeReversalCommand(['idempotencyKey' => '']))->toThrow(LedgerInvariantViolationException::class);
});

test('an idempotency key longer than 191 characters is rejected', function () {
    expect(fn () => makeReversalCommand(['idempotencyKey' => str_repeat('a', 192)]))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('an idempotency key with invalid characters is rejected', function () {
    expect(fn () => makeReversalCommand(['idempotencyKey' => 'has a space']))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('an idempotency key is normalized to lowercase', function () {
    $key = 'REVERSAL-REQUEST:'.strtoupper((string) Str::ulid());

    $command = makeReversalCommand(['idempotencyKey' => $key]);

    expect($command->idempotencyKey)->toBe(strtolower($key));
});

test('a correlation ID may be a canonical UUID, normalized to lowercase', function () {
    $uuid = strtoupper((string) Str::uuid());

    $command = makeReversalCommand(['correlationId' => $uuid]);

    expect($command->correlationId)->toBe(strtolower($uuid));
});

test('a correlation ID may be a canonical ULID, normalized to lowercase', function () {
    $ulid = strtoupper((string) Str::ulid());

    $command = makeReversalCommand(['correlationId' => $ulid]);

    expect($command->correlationId)->toBe(strtolower($ulid));
});

test('a correlation ID that is neither a UUID nor a ULID is rejected', function () {
    expect(fn () => makeReversalCommand(['correlationId' => 'not-a-real-id']))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('an empty reason is rejected', function () {
    expect(fn () => makeReversalCommand(['reason' => '']))->toThrow(LedgerInvariantViolationException::class);
});

test('a reason longer than 255 characters is rejected', function () {
    expect(fn () => makeReversalCommand(['reason' => str_repeat('a', 256)]))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a multiline reason is rejected', function () {
    expect(fn () => makeReversalCommand(['reason' => "line one\nline two"]))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a reason containing a control character is rejected', function () {
    expect(fn () => makeReversalCommand(['reason' => "bad\x07reason"]))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('reason casing is preserved exactly', function () {
    $command = makeReversalCommand(['reason' => 'Exact Casing Preserved']);

    expect($command->reason)->toBe('Exact Casing Preserved');
});

test('an unsaved actor is rejected', function () {
    expect(fn () => makeReversalCommand(['actor' => new User]))
        ->toThrow(LedgerInvariantViolationException::class);
});

test('a persisted actor is accepted and its ID is stored', function () {
    $command = makeReversalCommand(['actor' => makeInMemoryPersistedReversalActor(42)]);

    expect($command->actorId)->toBe(42);
});

test('a null actor is accepted', function () {
    $command = makeReversalCommand(['actor' => null]);

    expect($command->actorId)->toBeNull();
});
