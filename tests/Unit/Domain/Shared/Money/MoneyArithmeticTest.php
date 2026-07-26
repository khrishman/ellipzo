<?php

use App\Domain\Shared\Exceptions\MoneyOverflowException;
use App\Domain\Shared\Exceptions\NonPositiveAmountException;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;

// Currency::USD is the only approved case (D-004) - there is no genuine
// second currency to construct a real cross-currency Money with, and this
// suite deliberately does not fabricate one via reflection or a fake enum
// case. assertSameCurrency()/CurrencyMismatchException exist as defensive
// forward-compatibility and are honestly untested here, not silently
// assumed to work. See docs/memory.md.
test('add produces the correct sum', function () {
    $result = Money::fromAtomic(500_000, Currency::USD)->add(Money::fromAtomic(250_000, Currency::USD));

    expect($result->atomic())->toBe(750_000);
});

test('add at the positive overflow boundary', function () {
    $atBoundary = Money::fromAtomic(PHP_INT_MAX - 1, Currency::USD)->add(Money::fromAtomic(1, Currency::USD));
    expect($atBoundary->atomic())->toBe(PHP_INT_MAX);

    expect(fn () => Money::fromAtomic(PHP_INT_MAX, Currency::USD)->add(Money::fromAtomic(1, Currency::USD)))
        ->toThrow(MoneyOverflowException::class);
});

test('add at the negative overflow (underflow) boundary', function () {
    $atBoundary = Money::fromAtomic(PHP_INT_MIN + 1, Currency::USD)->add(Money::fromAtomic(-1, Currency::USD));
    expect($atBoundary->atomic())->toBe(PHP_INT_MIN);

    expect(fn () => Money::fromAtomic(PHP_INT_MIN, Currency::USD)->add(Money::fromAtomic(-1, Currency::USD)))
        ->toThrow(MoneyOverflowException::class);
});

test('subtract produces the correct difference', function () {
    $result = Money::fromAtomic(500_000, Currency::USD)->subtract(Money::fromAtomic(200_000, Currency::USD));

    expect($result->atomic())->toBe(300_000);
});

test('subtracting PHP_INT_MIN succeeds when a negate-based implementation would wrongly reject it', function () {
    // 0 - PHP_INT_MIN is not representable and must overflow.
    expect(fn () => Money::fromAtomic(0, Currency::USD)->subtract(Money::fromAtomic(PHP_INT_MIN, Currency::USD)))
        ->toThrow(MoneyOverflowException::class);

    // -5 - PHP_INT_MIN = 9223372036854775803, which IS representable. A
    // subtract() implemented as add(other.negate()) would incorrectly
    // throw here, since negating PHP_INT_MIN alone always overflows.
    $result = Money::fromAtomic(-5, Currency::USD)->subtract(Money::fromAtomic(PHP_INT_MIN, Currency::USD));
    expect($result->atomic())->toBe(9223372036854775803);
});

test('subtract at the overflow boundaries', function () {
    expect(fn () => Money::fromAtomic(PHP_INT_MAX, Currency::USD)->subtract(Money::fromAtomic(-1, Currency::USD)))
        ->toThrow(MoneyOverflowException::class);

    expect(fn () => Money::fromAtomic(PHP_INT_MIN, Currency::USD)->subtract(Money::fromAtomic(1, Currency::USD)))
        ->toThrow(MoneyOverflowException::class);
});

test('negate flips the sign of an ordinary value', function () {
    expect(Money::fromAtomic(500_000, Currency::USD)->negate()->atomic())->toBe(-500_000);
    expect(Money::fromAtomic(-500_000, Currency::USD)->negate()->atomic())->toBe(500_000);
});

test('negating zero stays zero', function () {
    expect(Money::zero(Currency::USD)->negate()->atomic())->toBe(0);
});

test('negating PHP_INT_MIN overflows', function () {
    expect(fn () => Money::fromAtomic(PHP_INT_MIN, Currency::USD)->negate())
        ->toThrow(MoneyOverflowException::class);
});

test('negating PHP_INT_MAX succeeds', function () {
    expect(Money::fromAtomic(PHP_INT_MAX, Currency::USD)->negate()->atomic())->toBe(-PHP_INT_MAX);
});

test('multiplyByInteger across sign combinations', function (int $atomic, int $factor, int $expected) {
    expect(Money::fromAtomic($atomic, Currency::USD)->multiplyByInteger($factor)->atomic())->toBe($expected);
})->with([
    'positive x positive' => [1_000_000, 3, 3_000_000],
    'positive x negative' => [1_000_000, -3, -3_000_000],
    'negative x positive' => [-1_000_000, 3, -3_000_000],
    'negative x negative' => [-1_000_000, -3, 3_000_000],
    'by zero' => [1_000_000, 0, 0],
    'zero by anything' => [0, PHP_INT_MAX, 0],
    '1 x PHP_INT_MIN' => [1, PHP_INT_MIN, PHP_INT_MIN],
    'PHP_INT_MIN x 1' => [PHP_INT_MIN, 1, PHP_INT_MIN],
    'exact asymmetric boundary' => [-4611686018427387904, 2, PHP_INT_MIN],
]);

test('multiplyByInteger overflow cases', function (int $atomic, int $factor) {
    expect(fn () => Money::fromAtomic($atomic, Currency::USD)->multiplyByInteger($factor))
        ->toThrow(MoneyOverflowException::class);
})->with([
    '-1 x PHP_INT_MIN' => [-1, PHP_INT_MIN],
    'PHP_INT_MIN x -1' => [PHP_INT_MIN, -1],
    'PHP_INT_MIN x 2' => [PHP_INT_MIN, 2],
    'PHP_INT_MAX x 2' => [PHP_INT_MAX, 2],
    '2 x PHP_INT_MAX' => [2, PHP_INT_MAX],
]);

test('ensurePositive returns the same instance for a strictly positive amount', function () {
    $money = Money::fromAtomic(1, Currency::USD);

    expect($money->ensurePositive())->toBe($money);
});

test('ensurePositive rejects zero and negative amounts', function (int $atomic) {
    expect(fn () => Money::fromAtomic($atomic, Currency::USD)->ensurePositive())
        ->toThrow(NonPositiveAmountException::class);
})->with(['zero' => [0], 'negative' => [-1]]);

test('equals compares currency and atomic value', function () {
    $a = Money::fromAtomic(500_000, Currency::USD);
    $b = Money::fromAtomic(500_000, Currency::USD);
    $c = Money::fromAtomic(500_001, Currency::USD);

    expect($a->equals($b))->toBeTrue();
    expect($a->equals($c))->toBeFalse();
});

test('ordering comparisons', function () {
    $small = Money::fromAtomic(1, Currency::USD);
    $large = Money::fromAtomic(2, Currency::USD);

    expect($large->isGreaterThan($small))->toBeTrue();
    expect($small->isLessThan($large))->toBeTrue();
    expect($small->isGreaterThanOrEqualTo($small))->toBeTrue();
    expect($small->isLessThanOrEqualTo($small))->toBeTrue();
    expect($small->compareTo($large))->toBe(-1);
    expect($large->compareTo($small))->toBe(1);
    expect($small->compareTo($small))->toBe(0);
});

test('isZero, isPositive, isNegative', function () {
    expect(Money::zero(Currency::USD)->isZero())->toBeTrue();
    expect(Money::fromAtomic(1, Currency::USD)->isPositive())->toBeTrue();
    expect(Money::fromAtomic(-1, Currency::USD)->isNegative())->toBeTrue();
    expect(Money::fromAtomic(1, Currency::USD)->isZero())->toBeFalse();
});

test('every arithmetic operation returns a new instance and leaves the original unchanged', function () {
    $original = Money::fromAtomic(500_000, Currency::USD);

    $original->add(Money::fromAtomic(1, Currency::USD));
    $original->subtract(Money::fromAtomic(1, Currency::USD));
    $original->negate();
    $original->multiplyByInteger(2);

    expect($original->atomic())->toBe(500_000);
    expect($original->currency())->toBe(Currency::USD);
});
