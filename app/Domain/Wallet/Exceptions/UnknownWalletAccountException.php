<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use RuntimeException;

/**
 * A posting command referenced a wallet_account_id that does not exist.
 */
final class UnknownWalletAccountException extends RuntimeException implements LedgerPostingExceptionInterface {}
