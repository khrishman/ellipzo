<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use Closure;

/**
 * A narrowly scoped runtime flag that
 * LedgerPostingEngine::writeAdministrativeAdjustmentWithinTransaction()
 * consults to decide whether a call is authorized. AdministrativeAdjustmentService
 * is the only intended caller of run().
 *
 * Mirrors LedgerWriteContext/ReversalRequestWriteContext exactly - a depth
 * counter, not a boolean, so nested activation can never leave it stuck
 * active: each run() call increments on entry and decrements in finally
 * regardless of exception, and isActive() is only true while depth > 0.
 *
 * This is a deliberately separate context from both LedgerWriteContext and
 * ReversalRequestWriteContext, not a reuse of either: "an administrative
 * adjustment is being written by AdministrativeAdjustmentService" is its
 * own guarantee, distinct from "a ledger row is being written by the
 * posting engine" or "a reversal request is being written by the request
 * service" - conflating them would let any one write path silently
 * authorize another. Existing alongside a plain database transaction and
 * an active LedgerWriteContext, this context is the third and final
 * precondition writeAdministrativeAdjustmentWithinTransaction() requires.
 */
final class AdministrativeAdjustmentWriteContext
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
