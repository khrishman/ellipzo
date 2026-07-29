<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use RuntimeException;

/**
 * An audit_events row already exists for an administrative-adjustment
 * ledger transaction, but either (a) the transaction was genuinely new
 * (wasReplay === false), so no audit event could legitimately pre-exist
 * for it under any circumstance, or (b) the transaction was a replay
 * (wasReplay === true) but the existing audit event's actor, reason,
 * target, direction, amount, currency, business reference, or original
 * committed correlation ID does not match. Never treated as success in
 * either case - see AdministrativeAdjustmentService::submit().
 */
final class ConflictingAdministrativeAdjustmentAuditEventException extends RuntimeException implements LedgerPostingExceptionInterface {}
