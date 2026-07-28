<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Data;

use App\Domain\Wallet\Models\LedgerTransaction;

/**
 * The result of LedgerPostingEngine::post() - either a genuinely new,
 * freshly committed transaction (wasReplay: false) or the original
 * transaction from an identical prior call (wasReplay: true), returned
 * without creating any new row or taking any wallet-account lock.
 */
final readonly class PostedLedgerTransaction
{
    public function __construct(
        public LedgerTransaction $transaction,
        public bool $wasReplay,
    ) {}
}
