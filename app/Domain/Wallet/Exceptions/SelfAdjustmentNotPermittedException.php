<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use RuntimeException;

/**
 * A staff actor attempted to submit an administrative adjustment against
 * their own wallet accounts. Mirrors StaffAccessController's own
 * $target->is($actor) self-modification guard.
 */
final class SelfAdjustmentNotPermittedException extends RuntimeException implements LedgerPostingExceptionInterface {}
