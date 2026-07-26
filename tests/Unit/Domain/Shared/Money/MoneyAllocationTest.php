<?php

use App\Domain\Shared\Exceptions\InvalidAllocationException;
use App\Domain\Shared\Exceptions\InvalidMoneyInputException;
use App\Domain\Shared\Exceptions\MoneyOverflowException;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;

function assertAllocationConserves(Money $original, array $shares): void
{
    $sum = Money::zero($original->currency());
    foreach ($shares as $share) {
        $sum = $sum->add($share);
    }

    expect($sum->equals($original))->toBeTrue();
}

test('a genuine sequential list of ratios is accepted', function () {
    $shares = Money::fromAtomic(1_000_000, Currency::USD)->allocate([1, 1]);

    expect($shares)->toHaveCount(2);
});

test('a non-list ratio array is rejected with a static safe message, not silently reindexed', function (array $ratios) {
    $exception = null;
    try {
        Money::fromAtomic(1_000_000, Currency::USD)->allocate($ratios);
    } catch (Throwable $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(InvalidAllocationException::class);
    expect($exception->getMessage())->toBe('Ratios must be a sequential list.');
})->with([
    'associative array' => [['a' => 1, 'b' => 1]],
    'sparse numeric keys' => [[0 => 1, 2 => 1]],
    'keys not starting at zero' => [[1 => 1, 2 => 1]],
    'out-of-order numeric keys' => [[1 => 10, 0 => 20]],
    'mixed string and numeric keys' => [[0 => 1, 'extra' => 1]],
]);

test('the allocation ratio-total bound is exact, proven without an overflowing intermediate', function () {
    // 3_037_000_499^2 must be <= PHP_INT_MAX, and 3_037_000_500^2 must
    // exceed it - proven via intdiv() comparisons, never by actually
    // squaring (which would risk float promotion for the second case).
    $bound = 3_037_000_499;
    $nextInteger = 3_037_000_500;

    expect($bound <= intdiv(PHP_INT_MAX, $bound))->toBeTrue();
    expect($nextInteger <= intdiv(PHP_INT_MAX, $nextInteger))->toBeFalse();
});

test('an even split conserves the total and matches the hand-verified largest-remainder distribution', function () {
    $money = Money::fromDecimalString('10.00', Currency::USD); // 10,000,000 atomic
    $shares = $money->allocate([1, 1, 1]);

    // 10,000,000 / 3 = 3,333,333 remainder 1 -> index 0 (ascending tiebreak) gets the extra unit.
    expect($shares[0]->atomic())->toBe(3_333_334);
    expect($shares[1]->atomic())->toBe(3_333_333);
    expect($shares[2]->atomic())->toBe(3_333_333);
    assertAllocationConserves($money, $shares);
});

test('a zero ratio mixed with positive ratios receives exactly zero, others split the full amount', function () {
    $money = Money::fromAtomic(1_000_000, Currency::USD);
    $shares = $money->allocate([1, 0, 1]);

    expect($shares[1]->atomic())->toBe(0);
    expect($shares[0]->atomic())->toBe(500_000);
    expect($shares[2]->atomic())->toBe(500_000);
    assertAllocationConserves($money, $shares);
});

test('empty, negative, and all-zero ratios are rejected', function (array $ratios) {
    expect(fn () => Money::fromAtomic(1_000_000, Currency::USD)->allocate($ratios))
        ->toThrow(InvalidAllocationException::class);
})->with([
    'empty' => [[]],
    'all zero' => [[0, 0, 0]],
    'negative' => [[1, -1]],
]);

test('a non-integer ratio is rejected as an input-type error, not an allocation error', function () {
    expect(fn () => Money::fromAtomic(1_000_000, Currency::USD)->allocate([1, 1.5]))
        ->toThrow(InvalidMoneyInputException::class);
});

test('a ratio total exactly at the supported bound is accepted', function () {
    // Two ratios summing to exactly the ceiling, on a small amount so the
    // resulting arrays/shares stay cheap to construct and assert on.
    $shares = Money::fromAtomic(10, Currency::USD)->allocate([3_037_000_498, 1]);

    assertAllocationConserves(Money::fromAtomic(10, Currency::USD), $shares);
});

test('a ratio total above the supported bound is rejected', function () {
    expect(fn () => Money::fromAtomic(10, Currency::USD)->allocate([3_037_000_499, 1]))
        ->toThrow(InvalidAllocationException::class);
});

test('checked ratio-sum overflow is a genuine overflow, not an allocation-semantics error', function () {
    expect(fn () => Money::fromAtomic(10, Currency::USD)->allocate([PHP_INT_MAX, 1]))
        ->toThrow(MoneyOverflowException::class);
});

test('PHP_INT_MAX allocated by [1, 1] succeeds without an overflowing intermediate', function () {
    $money = Money::fromAtomic(PHP_INT_MAX, Currency::USD);
    $shares = $money->allocate([1, 1]);

    expect($shares[0]->atomic())->toBe(4611686018427387904);
    expect($shares[1]->atomic())->toBe(4611686018427387903);
    assertAllocationConserves($money, $shares);
});

test('PHP_INT_MIN allocated by a single ratio receives the whole value via the direct short-circuit', function () {
    $money = Money::fromAtomic(PHP_INT_MIN, Currency::USD);
    $shares = $money->allocate([1]);

    expect($shares[0]->atomic())->toBe(PHP_INT_MIN);
    assertAllocationConserves($money, $shares);
});

test('PHP_INT_MIN allocated by [1, 1]', function () {
    $money = Money::fromAtomic(PHP_INT_MIN, Currency::USD);
    $shares = $money->allocate([1, 1]);

    expect($shares[0]->atomic())->toBe(-4611686018427387904);
    expect($shares[1]->atomic())->toBe(-4611686018427387904);
    assertAllocationConserves($money, $shares);
});

test('PHP_INT_MIN allocated by [1, 2, 3]', function () {
    $money = Money::fromAtomic(PHP_INT_MIN, Currency::USD);
    $shares = $money->allocate([1, 2, 3]);

    expect($shares[0]->atomic())->toBe(-1537228672809129301);
    expect($shares[1]->atomic())->toBe(-3074457345618258603);
    expect($shares[2]->atomic())->toBe(-4611686018427387904);
    assertAllocationConserves($money, $shares);
});

test('PHP_INT_MIN allocated with a zero ratio present', function () {
    $money = Money::fromAtomic(PHP_INT_MIN, Currency::USD);
    $shares = $money->allocate([0, 1]);

    expect($shares[0]->atomic())->toBe(0);
    expect($shares[1]->atomic())->toBe(PHP_INT_MIN);
    assertAllocationConserves($money, $shares);
});

test('deterministic tie-breaking on a negative boundary allocation is stable across repeated calls', function () {
    $money = Money::fromAtomic(PHP_INT_MIN, Currency::USD);

    $first = $money->allocate([1, 1, 1]);
    $second = $money->allocate([1, 1, 1]);

    expect(array_map(fn ($m) => $m->atomic(), $first))
        ->toBe(array_map(fn ($m) => $m->atomic(), $second));
    assertAllocationConserves($money, $first);
});

test('signed negative Money allocates into negative (or zero) shares that conserve exactly', function () {
    $money = Money::fromAtomic(-10, Currency::USD);
    $shares = $money->allocate([1, 1, 1]);

    foreach ($shares as $share) {
        expect($share->atomic())->toBeLessThanOrEqual(0);
    }
    assertAllocationConserves($money, $shares);
});

test('conservation holds across a dataset of non-evenly-divisible allocations', function (int $atomic, array $ratios) {
    $money = Money::fromAtomic($atomic, Currency::USD);
    $shares = $money->allocate($ratios);

    assertAllocationConserves($money, $shares);
})->with([
    [100, [1, 1, 1]],
    [10, [7, 3]],
    [1, [1, 1, 1, 1, 1]],
    [999_999, [70, 20, 10]],
    [PHP_INT_MAX, [1, 2, 3, 4, 5]],
    [PHP_INT_MIN, [1, 2, 3, 4, 5]],
]);
