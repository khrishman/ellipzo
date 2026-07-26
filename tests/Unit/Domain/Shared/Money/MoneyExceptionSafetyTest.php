<?php

use App\Domain\Shared\Money\Currency;
use App\Domain\Shared\Money\Money;

function captureMoneyException(Closure $action): Throwable
{
    try {
        $action();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('Expected an exception to be thrown, but none was.');
}

test('a malformed decimal string never appears in the exception message', function () {
    $canary = 'CANARY_'.bin2hex(random_bytes(6));
    $malformed = '5.5.'.$canary; // shaped to obviously fail the grammar

    $exception = captureMoneyException(fn () => Money::fromDecimalString($malformed, Currency::USD));

    expect($exception->getMessage())->not->toContain($canary);
    expect($exception->getMessage())->not->toContain($malformed);
});

test('a precision-exceeded decimal string never appears in the exception message', function () {
    $input = '5.123456789012345';

    $exception = captureMoneyException(fn () => Money::fromDecimalString($input, Currency::USD));

    expect($exception->getMessage())->not->toContain($input);
});

test('an overly long attacker string never appears in the exception message', function () {
    $huge = str_repeat('7', 5000);

    $exception = captureMoneyException(fn () => Money::fromDecimalString($huge, Currency::USD));

    expect($exception->getMessage())->not->toContain($huge);
    expect(strlen($exception->getMessage()))->toBeLessThan(200);
});

test('an invalid-type input never appears in the exception message', function () {
    $canaryString = 'SENSITIVE_'.bin2hex(random_bytes(6));

    // fromDecimalString wants a string; passing this canary through
    // fromAtomic (which wants an int) exercises the type-guard branch
    // without ever being a validly-shaped decimal that could reach the
    // parser at all.
    $exception = captureMoneyException(fn () => Money::fromAtomic($canaryString, Currency::USD));

    expect($exception->getMessage())->not->toContain($canaryString);
});

test('overflow exception messages never contain a raw numeric value', function () {
    $exception = captureMoneyException(
        fn () => Money::fromAtomic(PHP_INT_MAX, Currency::USD)->add(Money::fromAtomic(1, Currency::USD))
    );

    expect($exception->getMessage())->not->toContain((string) PHP_INT_MAX);
    expect($exception->getMessage())->not->toContain('9223372036854775808');
});

test('allocation exception messages never contain the raw ratio values', function () {
    $exception = captureMoneyException(
        fn () => Money::fromAtomic(10, Currency::USD)->allocate([3_037_000_499, 1])
    );

    expect($exception->getMessage())->not->toContain('3037000499');
    expect($exception->getMessage())->not->toContain('3_037_000_499');
});
