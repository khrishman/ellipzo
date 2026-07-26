<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money;

/**
 * The sole internal ledger currency (D-004). Every earning/advertising
 * balance is denominated in USD - external settlement assets (USDT, USDC,
 * LTC, ...) are converted at the provider boundary and never become a
 * second internal currency here.
 */
enum Currency: string
{
    case USD = 'USD';

    /**
     * Number of fractional digits an atomic unit represents.
     * 1 USD = 10^scale() atomic units.
     */
    public function scale(): int
    {
        return match ($this) {
            self::USD => 6,
        };
    }
}
