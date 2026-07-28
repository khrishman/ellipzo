<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Enums;

/**
 * A safe, static classification for why a reversal request could not be
 * applied - never raw balance figures or exception text. Exactly one case
 * exists because exactly one is needed today; no speculative additions.
 */
enum ReversalFailureCode: string
{
    case InsufficientBalance = 'insufficient_balance';
}
