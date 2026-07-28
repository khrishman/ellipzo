<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

/**
 * Marker interface for every exception thrown by the ledger-posting
 * boundary (LedgerPostingEngine and its command DTOs) - distinct from
 * WalletAccountExceptionInterface, which is scoped to account
 * provisioning only.
 */
interface LedgerPostingExceptionInterface {}
