<?php

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;

test('toDecimalString always uses the fixed six-digit USD scale', function (int $atomic, string $expected) {
    expect(Money::fromAtomic($atomic, Currency::USD)->toDecimalString())->toBe($expected);
})->with([
    'zero' => [0, '0.000000'],
    'whole dollar' => [5_000_000, '5.000000'],
    'fractional' => [5_123_456, '5.123456'],
    'smallest unit' => [1, '0.000001'],
    'negative' => [-5_000_000, '-5.000000'],
    'PHP_INT_MAX' => [PHP_INT_MAX, '9223372036854.775807'],
    'PHP_INT_MIN' => [PHP_INT_MIN, '-9223372036854.775808'],
]);

test('toDecimalString round-trips through fromDecimalString for a varied dataset', function (int $atomic) {
    $money = Money::fromAtomic($atomic, Currency::USD);
    $roundTripped = Money::fromDecimalString($money->toDecimalString(), Currency::USD);

    expect($roundTripped->equals($money))->toBeTrue();
})->with([0, 1, -1, 5_000_000, -5_000_000, 5_123_456, PHP_INT_MAX, PHP_INT_MIN]);

test('atomic() and atomicString() agree', function (int $atomic) {
    $money = Money::fromAtomic($atomic, Currency::USD);

    expect($money->atomicString())->toBe((string) $atomic);
    expect($money->atomicString())->toBeString();
})->with([0, 1, -1, PHP_INT_MAX, PHP_INT_MIN]);

test('jsonSerialize returns atomic as a string and the currency code', function () {
    $money = Money::fromAtomic(PHP_INT_MAX, Currency::USD);

    expect($money->jsonSerialize())->toBe([
        'atomic' => '9223372036854775807',
        'currency' => 'USD',
    ]);
});

test('json_encode calls jsonSerialize automatically and never emits a bare JSON number for the atomic value', function () {
    $money = Money::fromAtomic(PHP_INT_MAX, Currency::USD);

    $encoded = json_encode($money);

    expect($encoded)->toBe('{"atomic":"9223372036854775807","currency":"USD"}');
    // Structurally prove the atomic value is quoted in the JSON text
    // itself, not just correct in the PHP array shape - this is the
    // actual property that protects a JS consumer's Number.MAX_SAFE_INTEGER.
    expect($encoded)->toContain('"9223372036854775807"');
    expect($encoded)->not->toContain(':9223372036854775807');
});

test('json_encode nested inside an array also quotes the atomic value', function () {
    $encoded = json_encode(['balance' => Money::fromAtomic(123, Currency::USD)]);

    expect($encoded)->toBe('{"balance":{"atomic":"123","currency":"USD"}}');
});

// Explicit, individually-named proofs of the exact MIN/MAX serialization
// boundaries, beyond the dataset coverage above - each fact asserted on
// its own so a regression in any single one fails its own named test.

test('PHP_INT_MAX formats to exactly 9223372036854.775807', function () {
    expect(Money::fromAtomic(PHP_INT_MAX, Currency::USD)->toDecimalString())
        ->toBe('9223372036854.775807');
});

test('PHP_INT_MIN formats to exactly -9223372036854.775808', function () {
    expect(Money::fromAtomic(PHP_INT_MIN, Currency::USD)->toDecimalString())
        ->toBe('-9223372036854.775808');
});

test('the PHP_INT_MAX formatted string parses back to exactly PHP_INT_MAX', function () {
    expect(Money::fromDecimalString('9223372036854.775807', Currency::USD)->atomic())
        ->toBe(PHP_INT_MAX);
});

test('the PHP_INT_MIN formatted string parses back to exactly PHP_INT_MIN', function () {
    expect(Money::fromDecimalString('-9223372036854.775808', Currency::USD)->atomic())
        ->toBe(PHP_INT_MIN);
});

test('PHP_INT_MAX serializes its atomic value as a quoted JSON string', function () {
    $encoded = json_encode(Money::fromAtomic(PHP_INT_MAX, Currency::USD));

    expect($encoded)->toBe('{"atomic":"9223372036854775807","currency":"USD"}');
});

test('PHP_INT_MIN serializes its atomic value as a quoted JSON string', function () {
    $encoded = json_encode(Money::fromAtomic(PHP_INT_MIN, Currency::USD));

    expect($encoded)->toBe('{"atomic":"-9223372036854775808","currency":"USD"}');
});

test('zero formats to exactly 0.000000', function () {
    expect(Money::zero(Currency::USD)->toDecimalString())->toBe('0.000000');
});

test('negative zero parses to atomic 0 and formats canonically as 0.000000, never -0.000000', function (string $input) {
    $money = Money::fromDecimalString($input, Currency::USD);

    expect($money->atomic())->toBe(0);
    expect($money->toDecimalString())->toBe('0.000000');
})->with(['-0', '-0.0', '-0.000000']);

test('Money does not implement __toString', function () {
    expect(method_exists(Money::class, '__toString'))->toBeFalse();
});
