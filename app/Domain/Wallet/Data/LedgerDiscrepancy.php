<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Data;

use App\Domain\Wallet\Enums\LedgerDiscrepancyCode;

/**
 * One reconciliation finding - a stable code plus the scope it applies
 * to, identified only by type and ID. Never carries a raw exception
 * message, SQL fragment, stack trace, or any personal data - IDs and
 * codes are the entire safe payload an operator needs to investigate
 * further.
 */
final readonly class LedgerDiscrepancy
{
    public function __construct(
        public LedgerDiscrepancyCode $code,
        public string $scopeType,
        public string $scopeId,
    ) {}

    public function toLine(): string
    {
        return "{$this->code->value} {$this->scopeType} {$this->scopeId}";
    }
}
