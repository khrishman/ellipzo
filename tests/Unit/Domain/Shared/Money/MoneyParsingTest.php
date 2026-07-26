<?php

use App\Domain\Shared\Exceptions\InvalidDecimalFormatException;
use App\Domain\Shared\Exceptions\MoneyOverflowException;
use App\Domain\Shared\Exceptions\MoneyPrecisionExceededException;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;

test('valid integers and decimals parse to the correct atomic value', function (string $input, int $expectedAtomic) {
    expect(Money::fromDecimalString($input, Currency::USD)->atomic())->toBe($expectedAtomic);
})->with([
    'zero' => ['0', 0],
    'bare integer' => ['5', 5_000_000],
    'multi-digit integer' => ['123', 123_000_000],
    'one fractional digit' => ['5.1', 5_100_000],
    'two fractional digits' => ['5.12', 5_120_000],
    'six fractional digits' => ['5.123456', 5_123_456],
    'fractional only value' => ['0.000001', 1],
    'negative integer' => ['-5', -5_000_000],
    'negative decimal' => ['-5.5', -5_500_000],
    'negative fractional only' => ['-0.000001', -1],
]);

test('negative zero parses to plain atomic zero, identical to positive zero', function (string $input) {
    $money = Money::fromDecimalString($input, Currency::USD);

    expect($money->atomic())->toBe(0);
    expect($money->equals(Money::zero(Currency::USD)))->toBeTrue();
})->with(['-0', '-0.0', '-0.000000']);

test('malformed decimal strings are rejected', function (string $input) {
    expect(fn () => Money::fromDecimalString($input, Currency::USD))
        ->toThrow(InvalidDecimalFormatException::class);
})->with([
    'leading whitespace' => [' 5'],
    'trailing whitespace' => ['5 '],
    'internal whitespace' => ['5 .5'],
    'tab and newline' => ["\t5\n"],
    'leading zeros, integer' => ['007'],
    'leading zeros, decimal' => ['05.5'],
    'missing integer digit' => ['.5'],
    'trailing decimal point' => ['5.'],
    'leading plus' => ['+5'],
    'leading plus, decimal' => ['+5.5'],
    'scientific notation lowercase' => ['5e10'],
    'scientific notation uppercase' => ['1E-3'],
    'thousands separator' => ['1,000.50'],
    'thousands separator, no decimal' => ['1,000'],
    'arabic-indic digit' => ["\u{0665}"],
    'fullwidth digit' => ["\u{FF15}"],
    'NaN text' => ['NaN'],
    'Infinity text' => ['Infinity'],
    'INF text' => ['INF'],
    'negative INF text' => ['-INF'],
    'empty string' => [''],
    'double sign' => ['--5'],
    'sign only' => ['-'],
    'dot only' => ['.'],
    'multiple dots' => ['5.5.5'],
    'trailing garbage' => ['5x'],
]);

test('more than six fractional digits is precision-excess, not a generic format error', function () {
    expect(fn () => Money::fromDecimalString('5.1234567', Currency::USD))
        ->toThrow(MoneyPrecisionExceededException::class);
});

test('an input longer than the exact valid boundary length is rejected before any parsing', function () {
    // Longest possible genuinely valid string is 21 chars: "-9223372036854.775808".
    $tooLong = '-9223372036854.7758080'; // 22 chars, shaped like a valid decimal

    expect(fn () => Money::fromDecimalString($tooLong, Currency::USD))
        ->toThrow(InvalidDecimalFormatException::class);
});

test('an extremely long attacker-controlled numeric string is rejected', function () {
    $huge = str_repeat('9', 5000);

    expect(fn () => Money::fromDecimalString($huge, Currency::USD))
        ->toThrow(InvalidDecimalFormatException::class);
});

test('the exact positive boundary parses to PHP_INT_MAX', function () {
    $money = Money::fromDecimalString('9223372036854.775807', Currency::USD);

    expect($money->atomic())->toBe(PHP_INT_MAX);
});

test('the exact negative boundary parses to PHP_INT_MIN', function () {
    $money = Money::fromDecimalString('-9223372036854.775808', Currency::USD);

    expect($money->atomic())->toBe(PHP_INT_MIN);
});

test('one atomic unit beyond the positive boundary overflows', function () {
    expect(fn () => Money::fromDecimalString('9223372036854.775808', Currency::USD))
        ->toThrow(MoneyOverflowException::class);
});

test('one atomic unit beyond the negative boundary overflows', function () {
    expect(fn () => Money::fromDecimalString('-9223372036854.775809', Currency::USD))
        ->toThrow(MoneyOverflowException::class);
});

test('fromAtomic accepts a genuine int at both exact boundaries', function () {
    expect(Money::fromAtomic(PHP_INT_MAX, Currency::USD)->atomic())->toBe(PHP_INT_MAX);
    expect(Money::fromAtomic(PHP_INT_MIN, Currency::USD)->atomic())->toBe(PHP_INT_MIN);
});

test('fromDecimalString round-trips exactly through toDecimalString', function (string $input) {
    $money = Money::fromDecimalString($input, Currency::USD);
    $roundTripped = Money::fromDecimalString($money->toDecimalString(), Currency::USD);

    expect($roundTripped->equals($money))->toBeTrue();
})->with([
    '0', '5', '5.5', '-5.5', '0.000001', '-0.000001',
    '9223372036854.775807', '-9223372036854.775808',
]);
