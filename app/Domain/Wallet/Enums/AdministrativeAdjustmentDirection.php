<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Enums;

/**
 * Which side of the target account's normal balance an administrative
 * adjustment moves. The target account and platform_suspense always
 * receive opposite entry types - see AdministrativeAdjustmentService.
 */
enum AdministrativeAdjustmentDirection: string
{
    case Increase = 'increase';
    case Decrease = 'decrease';
}
