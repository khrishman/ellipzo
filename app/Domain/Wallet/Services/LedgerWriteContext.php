<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use Closure;

/**
 * A narrowly scoped runtime flag that LedgerTransaction/LedgerEntry's own
 * creating() guards consult to decide whether a construction attempt is
 * authorized. LedgerPostingEngine is the only intended caller of run().
 *
 * Uses a depth counter, not a boolean, specifically so nested activation
 * (however it might occur) can never leave the context stuck active: each
 * run() call increments on entry and decrements in finally regardless of
 * exception, and isActive() is only true while depth > 0 - the outermost
 * call is the only one that can bring it back to zero, and it always
 * does, even on exception.
 *
 * This stops accidental construction through Eloquent's mass-assignment-
 * unrelated creating() event. It cannot stop a raw DB::table(...)->insert()
 * call, which bypasses Eloquent events entirely - that remains a
 * procedural, code-review boundary, not a language-enforced one.
 */
final class LedgerWriteContext
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
