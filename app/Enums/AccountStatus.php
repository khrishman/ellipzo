<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The server-controlled account lifecycle state. Never writable through
 * profile input, registration input, Inertia props, or query parameters -
 * AccountStatusTransitioner is the only application write path. Does not
 * by itself mean eligible for any platform activity; see
 * App\Support\EligibilityAssessor.
 */
enum AccountStatus: string
{
    case Active = 'active';
    case Limited = 'limited';
    case Suspended = 'suspended';
    case Closed = 'closed';

    /**
     * True for statuses that must be blocked from protected application
     * and admin routes entirely (see EnsureAccountCanAccessProtectedRoutes).
     */
    public function blocksProtectedRoutes(): bool
    {
        return match ($this) {
            self::Suspended, self::Closed => true,
            self::Active, self::Limited => false,
        };
    }
}
