<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use RuntimeException;

/**
 * Stored or assembled wallet-account state violates an invariant the
 * provisioner or UserWalletAccounts expected to hold - a conflicting
 * existing row, corrupted currency/scale, or a malformed pre-existing
 * account set. Never thrown for input validation; see
 * InvalidWalletAccountScopeException for that.
 */
final class WalletAccountInvariantException extends RuntimeException implements WalletAccountExceptionInterface {}
