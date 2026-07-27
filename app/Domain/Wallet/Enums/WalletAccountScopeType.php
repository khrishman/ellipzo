<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Enums;

/**
 * The kind of owner a wallet account belongs to. Cross-column consistency
 * with scope_key/user_id/account_type is enforced centrally by
 * WalletAccountProvisioner, never by the database.
 */
enum WalletAccountScopeType: string
{
    case User = 'user';
    case Platform = 'platform';
    case Provider = 'provider';
}
