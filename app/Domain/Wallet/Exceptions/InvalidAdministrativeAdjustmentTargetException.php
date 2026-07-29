<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use RuntimeException;

/**
 * A SubmitAdministrativeAdjustmentCommand was constructed with a target
 * account type outside the approved allowlist (earning_available,
 * advertising_available), or another shape invariant the command can
 * prove without touching the database - never thrown for a database-level
 * lookup failure, see UnknownWalletAccountException for that.
 */
final class InvalidAdministrativeAdjustmentTargetException extends RuntimeException implements LedgerPostingExceptionInterface {}
