<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use InvalidArgumentException;

/**
 * Malformed input to a WalletAccountProvisioner entry point - a bad
 * provider identifier, or a user that isn't a persisted record. Every
 * message here is static; never includes the raw rejected input.
 */
final class InvalidWalletAccountScopeException extends InvalidArgumentException implements WalletAccountExceptionInterface {}
