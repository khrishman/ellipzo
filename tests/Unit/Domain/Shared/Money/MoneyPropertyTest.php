<?php

use App\Domain\Shared\Exceptions\MoneyOverflowException;
use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;

/**
 * A fixed, deterministic set of representative atomic values - negative
 * boundary, ordinary negative, zero, ordinary positive, and positive
 * boundary. No randomness anywhere in this file; every sweep below
 * iterates this same fixed list or a fixed bounded loop.
 *
 * @return int[]
 */
function representativeAtomicValues(): array
{
    return [
        PHP_INT_MIN,
        -4_611_686_018_427_387_904,
        -1_000_000,
        -1,
        0,
        1,
        1_000_000,
        4_611_686_018_427_387_904,
        PHP_INT_MAX,
    ];
}

test('decimal format/parse round-trips exactly across every representative value', function (int $atomic) {
    $money = Money::fromAtomic($atomic, Currency::USD);
    $roundTripped = Money::fromDecimalString($money->toDecimalString(), Currency::USD);

    expect($roundTripped->atomic())->toBe($atomic);
    expect($roundTripped->equals($money))->toBeTrue();
})->with(representativeAtomicValues());

test('a->add(b)->subtract(b) returns a, for a fixed bounded sweep of in-range pairs', function () {
    $deltas = [-1_000_000, -1, 0, 1, 1_000_000];

    foreach (representativeAtomicValues() as $atomic) {
        // Skip the two boundary values here: by construction almost every
        // delta would push add() or subtract() out of range, which is
        // already covered precisely by MoneyArithmeticTest's dedicated
        // boundary tests - this sweep is about the *identity* holding for
        // genuinely in-range operations, not about re-proving overflow.
        if ($atomic === PHP_INT_MIN || $atomic === PHP_INT_MAX) {
            continue;
        }

        foreach ($deltas as $delta) {
            $a = Money::fromAtomic($atomic, Currency::USD);
            $b = Money::fromAtomic($delta, Currency::USD);

            expect($a->add($b)->subtract($b)->atomic())->toBe($atomic);
        }
    }
});

test('negating twice returns the original for every representative value except PHP_INT_MIN', function (int $atomic) {
    $money = Money::fromAtomic($atomic, Currency::USD);

    expect($money->negate()->negate()->atomic())->toBe($atomic);
})->with(array_filter(representativeAtomicValues(), fn (int $v): bool => $v !== PHP_INT_MIN));

test('negating PHP_INT_MIN is the one deliberate exclusion from double-negation', function () {
    expect(fn () => Money::fromAtomic(PHP_INT_MIN, Currency::USD)->negate())
        ->toThrow(MoneyOverflowException::class);
});

test('multiplying by zero produces zero for every representative value', function (int $atomic) {
    expect(Money::fromAtomic($atomic, Currency::USD)->multiplyByInteger(0)->atomic())->toBe(0);
})->with(representativeAtomicValues());

test('multiplying by one preserves the value for every representative value', function (int $atomic) {
    expect(Money::fromAtomic($atomic, Currency::USD)->multiplyByInteger(1)->atomic())->toBe($atomic);
})->with(representativeAtomicValues());

test('allocation conserves the exact original amount across representative values and several ratio lists', function () {
    $ratioLists = [[1, 1], [1, 2, 3], [7, 3], [1], [1, 0, 1]];

    foreach (representativeAtomicValues() as $atomic) {
        $money = Money::fromAtomic($atomic, Currency::USD);

        foreach ($ratioLists as $ratios) {
            $shares = $money->allocate($ratios);

            $sum = Money::zero(Currency::USD);
            foreach ($shares as $share) {
                $sum = $sum->add($share);
            }

            expect($sum->atomic())->toBe($atomic);
        }
    }
});

test('allocation is deterministic across repeated calls for every representative value', function () {
    $ratioLists = [[1, 1], [1, 2, 3], [7, 3]];

    foreach (representativeAtomicValues() as $atomic) {
        $money = Money::fromAtomic($atomic, Currency::USD);

        foreach ($ratioLists as $ratios) {
            $first = array_map(fn (Money $m): int => $m->atomic(), $money->allocate($ratios));
            $second = array_map(fn (Money $m): int => $m->atomic(), $money->allocate($ratios));

            expect($second)->toBe($first);
        }
    }
});

test('every allocated share retains Currency::USD for every representative value', function () {
    $ratioLists = [[1, 1], [1, 2, 3], [7, 3]];

    foreach (representativeAtomicValues() as $atomic) {
        $money = Money::fromAtomic($atomic, Currency::USD);

        foreach ($ratioLists as $ratios) {
            foreach ($money->allocate($ratios) as $share) {
                expect($share->currency())->toBe(Currency::USD);
            }
        }
    }
});
