<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use Closure;

/**
 * A narrowly scoped runtime flag that ReversalRequest's own creating()/
 * updating() guards consult to decide whether a write is authorized.
 * ReversalRequestService is the only intended caller of run().
 *
 * Mirrors LedgerWriteContext exactly - a depth counter, not a boolean, so
 * nested activation can never leave it stuck active: each run() call
 * increments on entry and decrements in finally regardless of exception,
 * and isActive() is only true while depth > 0.
 *
 * This is a deliberately separate context from LedgerWriteContext, not a
 * reuse of it: "a ledger row is being written by the posting engine" and
 * "a reversal request is being written by the request service" are
 * different guarantees, checked by different models, and conflating them
 * would let either write path silently authorize the other.
 */
final class ReversalRequestWriteContext
{
    private static int $depth = 0;

    public static function run(Closure $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }
}
