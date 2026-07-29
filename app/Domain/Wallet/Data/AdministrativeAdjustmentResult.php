<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Data;

use App\Domain\Wallet\Models\LedgerTransaction;
use App\Models\AuditEvent;

/**
 * The result of AdministrativeAdjustmentService::submit() - the committed
 * (or replayed) ledger transaction and its exactly-one audit event.
 */
final readonly class AdministrativeAdjustmentResult
{
    public function __construct(
        public LedgerTransaction $transaction,
        public AuditEvent $auditEvent,
        public bool $wasReplay,
    ) {}
}
