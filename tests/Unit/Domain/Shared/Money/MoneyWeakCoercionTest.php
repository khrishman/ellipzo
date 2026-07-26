<?php

// Deliberately NO declare(strict_types=1) in this file. The whole point of
// this suite is proving Money's runtime `mixed` + is_int()/is_string()
// guards work regardless of the CALLER's strict_types setting - PHP's own
// scalar coercion for a narrower type (including an int|float union) can
// silently convert a numeric string, a bool, or a float into the "right"
// type before the method body ever runs when strict_types is off. `mixed`
// accepts no coercion at all, so these calls must reach Money with the
// original, unconverted type every time.

use App\Domain\Shared\Exceptions\InvalidMoneyInputException;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;

test('fromAtomic rejects everything except a genuine int, even from a non-strict caller', function (mixed $value) {
    expect(fn () => Money::fromAtomic($value, Currency::USD))
        ->toThrow(InvalidMoneyInputException::class);
})->with([
    'numeric string' => ['5'],
    'float' => [5.0],
    'non-integral float' => [5.5],
    'bool true' => [true],
    'bool false' => [false],
    'null' => [null],
    'array' => [[5]],
    'object' => [new stdClass],
]);

test('fromAtomic accepts a genuine int from a non-strict caller', function () {
    expect(Money::fromAtomic(5, Currency::USD)->atomic())->toBe(5);
});

test('fromDecimalString rejects everything except a genuine string, even from a non-strict caller', function (mixed $value) {
    expect(fn () => Money::fromDecimalString($value, Currency::USD))
        ->toThrow(InvalidMoneyInputException::class);
})->with([
    'int' => [5],
    'float' => [5.0],
    'bool true' => [true],
    'bool false' => [false],
    'null' => [null],
    'array' => [['5']],
    'object' => [new stdClass],
]);

test('fromDecimalString accepts a genuine numeric string from a non-strict caller', function () {
    expect(Money::fromDecimalString('5', Currency::USD)->atomic())->toBe(5_000_000);
});

test('multiplyByInteger rejects everything except a genuine int, even from a non-strict caller', function (mixed $value) {
    $money = Money::fromAtomic(5, Currency::USD);

    expect(fn () => $money->multiplyByInteger($value))
        ->toThrow(InvalidMoneyInputException::class);
})->with([
    'numeric string' => ['3'],
    'float' => [3.0],
    'bool true' => [true],
    'bool false' => [false],
    'null' => [null],
    'array' => [[3]],
    'object' => [new stdClass],
]);

test('multiplyByInteger accepts a genuine int from a non-strict caller', function () {
    expect(Money::fromAtomic(5, Currency::USD)->multiplyByInteger(3)->atomic())->toBe(15);
});

test('allocate rejects a non-integer ratio element, even from a non-strict caller', function (mixed $value) {
    $money = Money::fromAtomic(10, Currency::USD);

    expect(fn () => $money->allocate([1, $value]))
        ->toThrow(InvalidMoneyInputException::class);
})->with([
    'numeric string' => ['1'],
    'float' => [1.0],
    'bool true' => [true],
    'bool false' => [false],
    'null' => [null],
    'array' => [[1]],
    'object' => [new stdClass],
]);
