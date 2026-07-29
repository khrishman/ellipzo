<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use RuntimeException;

/**
 * A replayed administrative-adjustment ledger transaction (wasReplay ===
 * true) has no corresponding audit_events row. A committed adjustment
 * with no audit trail is a genuine invariant failure, never silently
 * accepted - see AdministrativeAdjustmentService::submit().
 */
final class MissingAdministrativeAdjustmentAuditEventException extends RuntimeException implements LedgerPostingExceptionInterface {}
