<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

use App\Domain\Shared\Exceptions\CurrencyMismatchException;
use App\Domain\Shared\Exceptions\InvalidAllocationException;
use App\Domain\Shared\Exceptions\InvalidDecimalFormatException;
use App\Domain\Shared\Exceptions\InvalidMoneyInputException;
use App\Domain\Shared\Exceptions\MoneyOverflowException;
use App\Domain\Shared\Exceptions\MoneyPrecisionExceededException;
use App\Domain\Shared\Exceptions\NonPositiveAmountException;
use JsonSerializable;

/**
 * An immutable, signed, integer-atomic amount of a single Currency.
 *
 * No float is ever used to parse, construct, compute, compare, or
 * serialize a value - every operation is either exact integer arithmetic
 * or exact string manipulation. Public parameters that would otherwise be
 * `int`/`string` are declared `mixed` and checked at runtime with
 * `is_int()`/`is_string()`: PHP's own scalar-coercion rules for narrower
 * types (including `int|float` unions) can silently convert a numeric
 * string, a bool, or a float into the "right" type before the method
 * body ever runs when the calling file lacks `declare(strict_types=1)` -
 * `mixed` accepts no coercion at all, so the check inspects what the
 * caller genuinely passed.
 *
 * Ledger-entry magnitudes and user-entered amounts must call
 * ensurePositive() explicitly; this class itself may hold a negative or
 * zero value for intermediate computation.
 */
final class Money implements JsonSerializable
{
    /**
     * Longest possible valid decimal string, derived exactly from
     * "-9223372036854.775808" (PHP_INT_MIN at USD's scale of 6) - not a
     * padded guess. Anything longer is rejected before any parsing work.
     */
    private const MAX_INPUT_LENGTH = 21;

    /** |PHP_INT_MAX|, as a normalized digit string. */
    private const POSITIVE_BOUND_MAGNITUDE = '9223372036854775807';

    /** |PHP_INT_MIN|, as a normalized digit string - one more than PHP_INT_MAX. */
    private const NEGATIVE_BOUND_MAGNITUDE = '9223372036854775808';

    /**
     * The largest ratio total allocate() supports. Chosen so that any
     * product of two values each <= this bound cannot exceed PHP_INT_MAX -
     * see MoneyAllocationTest for the overflow-safe proof that this
     * constant squared is in bounds and the next integer's square is not.
     */
    private const ALLOCATION_RATIO_TOTAL_CEILING = 3_037_000_499;

    private function __construct(
        private readonly int $atomic,
        private readonly Currency $currency,
    ) {}

    // ---------------------------------------------------------------
    // Factories
    // ---------------------------------------------------------------

    public static function fromAtomic(mixed $atomic, Currency $currency): self
    {
        if (! is_int($atomic)) {
            throw new InvalidMoneyInputException('Atomic value must be a genuine PHP integer.');
        }

        return new self($atomic, $currency);
    }

    public static function fromDecimalString(mixed $decimal, Currency $currency): self
    {
        if (! is_string($decimal)) {
            throw new InvalidMoneyInputException('Decimal input must be a genuine PHP string.');
        }

        if (strlen($decimal) > self::MAX_INPUT_LENGTH) {
            throw new InvalidDecimalFormatException('Decimal input is too long to represent a valid amount.');
        }

        if (! preg_match('/^(-)?(0|[1-9]\d*)(\.(\d+))?$/', $decimal, $matches)) {
            throw new InvalidDecimalFormatException('Decimal input does not match the accepted grammar.');
        }

        $sign = $matches[1] ?? '';
        $integerPart = $matches[2];
        $fractionalPart = $matches[4] ?? '';
        $scale = $currency->scale();

        if ($fractionalPart !== '' && strlen($fractionalPart) > $scale) {
            throw new MoneyPrecisionExceededException("Decimal input exceeds {$scale} fractional digits.");
        }

        $paddedFractional = str_pad($fractionalPart, $scale, '0');

        $magnitudeString = ltrim($integerPart.$paddedFractional, '0');
        if ($magnitudeString === '') {
            $magnitudeString = '0';
        }

        $boundMagnitude = $sign === '-' ? self::NEGATIVE_BOUND_MAGNITUDE : self::POSITIVE_BOUND_MAGNITUDE;

        if (self::compareDigitStrings($magnitudeString, $boundMagnitude) > 0) {
            throw new MoneyOverflowException('Decimal input is outside the representable range.');
        }

        if ($sign === '-' && $magnitudeString === self::NEGATIVE_BOUND_MAGNITUDE) {
            // The exact lower boundary: its positive magnitude (2^63) is
            // not itself representable, so it is never cast - the literal
            // constant is used directly.
            $atomic = PHP_INT_MIN;
        } else {
            $positiveAtomic = (int) $magnitudeString; // safe: proven <= PHP_INT_MAX above
            $atomic = $sign === '-' ? -$positiveAtomic : $positiveAtomic;
        }

        return new self($atomic, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    // ---------------------------------------------------------------
    // Arithmetic
    // ---------------------------------------------------------------

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checkedAdd($this->atomic, $other->atomic), $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(self::checkedSubtract($this->atomic, $other->atomic), $this->currency);
    }

    public function negate(): self
    {
        if ($this->atomic === PHP_INT_MIN) {
            throw new MoneyOverflowException('Negation would overflow.');
        }

        return new self(-$this->atomic, $this->currency);
    }

    public function multiplyByInteger(mixed $factor): self
    {
        if (! is_int($factor)) {
            throw new InvalidMoneyInputException('Multiplication factor must be a genuine PHP integer.');
        }

        return new self(self::checkedMultiply($this->atomic, $factor), $this->currency);
    }

    // ---------------------------------------------------------------
    // Allocation
    // ---------------------------------------------------------------

    /**
     * Split this amount across the given non-negative integer ratios
     * using deterministic largest-remainder distribution. The resulting
     * shares always sum exactly to this amount, including when this
     * amount is PHP_INT_MIN - see the class-level algorithm notes below
     * and docs/memory.md for the full derivation.
     *
     * A zero ratio is allowed (that index receives Money::zero()) as
     * long as at least one ratio is positive. Ties in the largest-
     * remainder distribution are broken by ascending original array
     * index, so $ratios must be a genuine list (sequential integer keys
     * starting at 0, in that order) - an associative array, a sparse or
     * non-zero-based numeric-key array, or out-of-order numeric keys are
     * all rejected outright rather than silently reinterpreted via
     * array_values(), which would accept malformed input and change its
     * meaning.
     *
     * @param  int[]  $ratios
     * @return self[]
     */
    public function allocate(array $ratios): array
    {
        if (! array_is_list($ratios)) {
            throw new InvalidAllocationException('Ratios must be a sequential list.');
        }

        if ($ratios === []) {
            throw new InvalidAllocationException('Ratios must not be empty.');
        }

        foreach ($ratios as $index => $ratio) {
            if (! is_int($ratio)) {
                throw new InvalidMoneyInputException("Ratio at index {$index} must be a genuine PHP integer.");
            }

            if ($ratio < 0) {
                throw new InvalidAllocationException('Ratios must not be negative.');
            }
        }

        $total = 0;
        foreach ($ratios as $ratio) {
            $total = self::checkedAdd($total, $ratio);
        }

        if ($total === 0) {
            throw new InvalidAllocationException('At least one ratio must be positive.');
        }

        if ($total > self::ALLOCATION_RATIO_TOTAL_CEILING) {
            throw new InvalidAllocationException('Ratio total exceeds the supported allocation bound.');
        }

        // A single ratio consuming the entire total receives this whole
        // amount directly - correct for every atomic value including
        // PHP_INT_MIN, and needs none of the quotient/remainder
        // machinery below (which cannot itself represent PHP_INT_MIN's
        // full, unsigned 2^63 magnitude as an intermediate).
        foreach ($ratios as $index => $ratio) {
            if ($ratio === $total) {
                $shares = array_fill_keys(array_keys($ratios), 0);
                $shares[$index] = $this->atomic;

                return array_map(
                    fn (int $share): self => new self($share, $this->currency),
                    $shares,
                );
            }
        }

        // From here every ratio is strictly less than total, so every
        // individual share is strictly less than this amount's full
        // magnitude - always <= PHP_INT_MAX, always safely negatable.
        $sign = $this->atomic < 0 ? -1 : 1;

        if ($this->atomic === PHP_INT_MIN) {
            // The quotient/remainder of the mathematical magnitude 2^63
            // divided by total, computed without ever constructing 2^63
            // as a PHP integer: 2^63 = PHP_INT_MAX + 1, so
            // intdiv(PHP_INT_MAX + 1, total) and (PHP_INT_MAX + 1) % total
            // are derived from PHP_INT_MAX's own quotient/remainder.
            $q = intdiv(PHP_INT_MAX, $total);
            $r = (PHP_INT_MAX % $total) + 1;
            if ($r === $total) {
                $q = self::checkedAdd($q, 1);
                $r = 0;
            }
        } else {
            $magnitude = $sign === -1 ? -$this->atomic : $this->atomic; // safe: not PHP_INT_MIN
            $q = intdiv($magnitude, $total);
            $r = $magnitude % $total;
        }

        $baseShares = [];
        $remainders = [];
        foreach ($ratios as $index => $ratio) {
            if ($ratio === 0) {
                $baseShares[$index] = 0;
                $remainders[$index] = 0;

                continue;
            }

            // magnitude * ratio = (q*total + r) * ratio
            //                   = q*ratio*total + r*ratio
            // so intdiv(magnitude*ratio, total) = q*ratio + intdiv(r*ratio, total)
            // and (magnitude*ratio) mod total = (r*ratio) mod total - both
            // exact identities, and neither ever forms magnitude*ratio
            // directly. q*ratio <= q*total <= magnitude (ratio <= total),
            // and r*ratio < total*total <= PHP_INT_MAX (the allocation
            // ceiling), so every intermediate below is provably in
            // bounds - still routed through the checked helpers anyway.
            $term1 = self::checkedMultiply($q, $ratio);
            $crossProduct = self::checkedMultiply($r, $ratio);
            $term2 = intdiv($crossProduct, $total);
            $baseShares[$index] = self::checkedAdd($term1, $term2);
            $remainders[$index] = $crossProduct % $total;
        }

        // magnitude*total = sum(baseShare_i)*total + sum(remainder_i), and
        // sum(ratio_i) = total, so magnitude = sum(baseShare_i) +
        // sum(remainder_i)/total - meaning sum(remainder_i) divides total
        // exactly. This gives the shortfall without ever computing
        // "magnitude - sum(baseShares)", which would require magnitude
        // itself to exist as a positive int - impossible for PHP_INT_MIN.
        // The sum is safe: each remainder is < total, at most `total`
        // ratios can be positive, so the sum is < total*total <= the
        // allocation ceiling's own overflow-safe bound.
        $remainderSum = 0;
        foreach ($remainders as $remainder) {
            $remainderSum = self::checkedAdd($remainderSum, $remainder);
        }
        $shortfall = intdiv($remainderSum, $total);

        $order = array_keys($ratios);
        usort($order, fn (int $a, int $b): int => ($remainders[$b] <=> $remainders[$a]) ?: ($a <=> $b));

        for ($i = 0; $i < $shortfall; $i++) {
            $index = $order[$i];
            $baseShares[$index] = self::checkedAdd($baseShares[$index], 1);
        }

        return array_map(
            fn (int $share): self => new self($sign === -1 ? -$share : $share, $this->currency),
            $baseShares,
        );
    }

    // ---------------------------------------------------------------
    // Guard
    // ---------------------------------------------------------------

    public function ensurePositive(): self
    {
        if ($this->atomic <= 0) {
            throw new NonPositiveAmountException('Amount must be strictly positive.');
        }

        return $this;
    }

    // ---------------------------------------------------------------
    // Comparison
    // ---------------------------------------------------------------

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->atomic === $other->atomic;
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->atomic <=> $other->atomic;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function isLessThanOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    public function isZero(): bool
    {
        return $this->atomic === 0;
    }

    public function isPositive(): bool
    {
        return $this->atomic > 0;
    }

    public function isNegative(): bool
    {
        return $this->atomic < 0;
    }

    // ---------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------

    public function atomic(): int
    {
        return $this->atomic;
    }

    public function atomicString(): string
    {
        return (string) $this->atomic;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    // ---------------------------------------------------------------
    // Serialization
    // ---------------------------------------------------------------

    public function toDecimalString(): string
    {
        $scale = $this->currency->scale();
        $sign = $this->atomic < 0 ? '-' : '';

        // abs() is unsafe on PHP_INT_MIN (it silently returns a float) -
        // the known boundary magnitude string is used directly instead.
        $magnitudeString = $this->atomic === PHP_INT_MIN
            ? self::NEGATIVE_BOUND_MAGNITUDE
            : (string) abs($this->atomic);

        $magnitudeString = str_pad($magnitudeString, $scale + 1, '0', STR_PAD_LEFT);

        $integerPart = substr($magnitudeString, 0, -$scale);
        $fractionalPart = substr($magnitudeString, -$scale);

        return $sign.$integerPart.'.'.$fractionalPart;
    }

    /**
     * @return array{atomic: string, currency: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'atomic' => $this->atomicString(),
            'currency' => $this->currency->value,
        ];
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new CurrencyMismatchException('Money instances must share the same currency.');
        }
    }

    private static function checkedAdd(int $a, int $b): int
    {
        if ($b >= 0) {
            if ($a > PHP_INT_MAX - $b) {
                throw new MoneyOverflowException('Addition would overflow.');
            }
        } elseif ($a < PHP_INT_MIN - $b) {
            throw new MoneyOverflowException('Addition would overflow.');
        }

        return $a + $b;
    }

    private static function checkedSubtract(int $a, int $b): int
    {
        if ($b < 0) {
            if ($a > PHP_INT_MAX + $b) {
                throw new MoneyOverflowException('Subtraction would overflow.');
            }
        } elseif ($a < PHP_INT_MIN + $b) {
            throw new MoneyOverflowException('Subtraction would overflow.');
        }

        return $a - $b;
    }

    private static function checkedMultiply(int $atomic, int $factor): int
    {
        if ($atomic === 0 || $factor === 0) {
            return 0;
        }

        if ($atomic === PHP_INT_MIN) {
            if ($factor === 1) {
                return PHP_INT_MIN;
            }

            throw new MoneyOverflowException('Multiplication would overflow.');
        }

        if ($factor === PHP_INT_MIN) {
            if ($atomic === 1) {
                return PHP_INT_MIN;
            }

            throw new MoneyOverflowException('Multiplication would overflow.');
        }

        if ($atomic === 1) {
            return $factor;
        }

        if ($atomic === -1) {
            return -$factor; // safe: factor != PHP_INT_MIN, excluded above
        }

        if ($factor === 1) {
            return $atomic;
        }

        if ($factor === -1) {
            return -$atomic; // safe: atomic != PHP_INT_MIN, excluded above
        }

        // |atomic| >= 2 and |factor| >= 2 here - both safely negatable.
        $absAtomic = abs($atomic);
        $absFactor = abs($factor);
        $negativeResult = ($atomic < 0) !== ($factor < 0);

        if ($negativeResult) {
            // intdiv(PHP_INT_MIN, -absFactor) is safe here specifically
            // because absFactor >= 2 - PHP itself throws ArithmeticError
            // only for a divisor of exactly -1.
            $bound = intdiv(PHP_INT_MIN, -$absFactor);
            if ($absAtomic > $bound) {
                throw new MoneyOverflowException('Multiplication would overflow.');
            }
        } else {
            $bound = intdiv(PHP_INT_MAX, $absFactor);
            if ($absAtomic > $bound) {
                throw new MoneyOverflowException('Multiplication would overflow.');
            }
        }

        return $atomic * $factor;
    }

    /**
     * Compares two normalized (no leading zero, except "0" itself),
     * non-negative digit strings. Returns <0, 0, or >0 like <=>.
     */
    private static function compareDigitStrings(string $a, string $b): int
    {
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }

        return $a <=> $b;
    }
}
