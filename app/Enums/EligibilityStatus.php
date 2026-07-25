<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Computed fresh on every assessment, never persisted. Separate from
 * AccountStatus: an ACTIVE account is not automatically ELIGIBLE.
 */
enum EligibilityStatus: string
{
    case Pending = 'pending';
    case Eligible = 'eligible';
    case Ineligible = 'ineligible';
}
