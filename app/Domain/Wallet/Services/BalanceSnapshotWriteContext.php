<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use Closure;

/**
 * A narrowly scoped runtime flag that BalanceSnapshot's own creating()
 * guard consults to decide whether a call is authorized.
 * BalanceSnapshotService is the only intended caller of run().
 *
 * Mirrors LedgerWriteContext/ReversalRequestWriteContext/
 * AdministrativeAdjustmentWriteContext exactly - a depth counter, not a
 * boolean, so nested activation can never leave it stuck active: each
 * run() call increments on entry and decrements in finally regardless of
 * exception, and isActive() is only true while depth > 0.
 *
 * A deliberately separate context from every other write context in this
 * domain, for the same reason those are kept separate from each other:
 * "a balance snapshot is being written by BalanceSnapshotService" is its
 * own guarantee, distinct from any ledger, reversal, or adjustment write
 * path.
 */
final class BalanceSnapshotWriteContext
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
