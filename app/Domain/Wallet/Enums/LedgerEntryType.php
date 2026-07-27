<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Enums;

enum LedgerEntryType: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
